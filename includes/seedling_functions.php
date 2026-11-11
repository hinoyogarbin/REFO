<?php
/**
 * Seedling requests, release batches and mortality reports.
 *
 * - A field user REQUESTS seedlings for an assigned polygon.
 * - Admin / Manager approve (quantity adjustable), reject, or release.
 *   Every release creates its OWN batch; batches are never merged.
 * - A field user REPORTS MORTALITY against a specific batch, so deaths
 *   are traced to one release and the survival numbers need no extra
 *   "planted" figure: the batch quantity is the total.
 *
 * Tables are created by database.sql.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/polygon_functions.php';
require_once __DIR__ . '/photo_functions.php';           // findFieldPhotoById(), PHOTO_UPLOAD_URL
require_once __DIR__ . '/polygon_photo_functions.php';   // polygonPhotoColumnExists()

const SEEDLING_REASONS = [
    'replacement' => 'Replace dead seedlings',
    'additional'  => 'Additional planting',
];

const SEEDLING_CAUSES = [
    'drought'       => 'Drought / dry spell',
    'pests_disease' => 'Pests or disease',
    'flooding'      => 'Flooding',
    'animals'       => 'Animals / trampling',
    'fire'          => 'Fire',
    'unknown'       => 'Unknown',
    'other'         => 'Other',
];

const SEEDLING_REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'released'];

/** Largest quantity accepted in one form field (guards against typos). */
const SEEDLING_MAX_QUANTITY = 1000000;

// ============================================================
// Setup / formatting helpers
// ============================================================

/**
 * True when the seedling tables exist (database.sql was run).
 */
function seedlingTablesExist(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo = getDbConnection();
        $needed = ['seedling_requests', 'seedling_batches', 'seedling_mortality_reports'];
        $found = 0;
        foreach ($needed as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if ($stmt !== false && $stmt->fetch() !== false) {
                $found++;
            }
        }
        $exists = ($found === count($needed));
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

/**
 * Dead / released as a fraction, or null when nothing was released.
 */
function seedlingRate(int $dead, int $released): ?float
{
    if ($released <= 0) {
        return null;
    }
    return $dead / $released;
}

/**
 * "12.5%" or "-" when there is nothing to compare against.
 */
function formatSeedlingRate(int $dead, int $released): string
{
    $rate = seedlingRate($dead, $released);
    if ($rate === null) {
        return '-';
    }
    return number_format($rate * 100, 1) . '%';
}

/**
 * "Batch 2" style label, with "Initial planting" for pre-system plantings.
 */
function seedlingBatchLabel(array $batch): string
{
    $label = 'Batch ' . (int)$batch['batch_number'];
    if (($batch['batch_type'] ?? '') === 'initial') {
        $label .= ' (Initial planting)';
    }
    return $label;
}

/**
 * Accepts a whole number from a form field. Returns null when the value
 * is not a positive integer inside the allowed range.
 */
function parseSeedlingQuantity($value): ?int
{
    $value = trim((string)$value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $number = (int)$value;
    if ($number < 1 || $number > SEEDLING_MAX_QUANTITY) {
        return null;
    }
    return $number;
}

/**
 * Accepts a YYYY-MM-DD date. Returns null when it is not a real date.
 */
function parseSeedlingDate($value): ?string
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function cleanSeedlingSpecies($value): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string)$value));
    return mb_substr($value, 0, 100);
}

function cleanSeedlingNotes($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, 2000);
}

// ============================================================
// Requests
// ============================================================

/**
 * Validates and cleans a seedling request form.
 *
 * @param array $input            Raw POST data.
 * @param array $allowedPolygonIds Polygons the person may request for.
 * @return array [errors[], clean[]]
 */
function validateSeedlingRequest(array $input, array $allowedPolygonIds): array
{
    $errors = [];

    $polygonId = (int)($input['polygon_id'] ?? 0);
    if (!in_array($polygonId, array_map('intval', $allowedPolygonIds), true)) {
        $errors[] = 'Please choose one of your assigned areas.';
    }

    $species = cleanSeedlingSpecies($input['species'] ?? '');
    if ($species === '') {
        $errors[] = 'Please enter the seedling species.';
    }

    $quantity = parseSeedlingQuantity($input['quantity'] ?? '');
    if ($quantity === null) {
        $errors[] = 'Quantity must be a whole number of at least 1.';
    }

    $reason = (string)($input['reason'] ?? '');
    if (!isset(SEEDLING_REASONS[$reason])) {
        $errors[] = 'Please choose a reason for the request.';
    }

    $dateNeeded = null;
    if (trim((string)($input['date_needed'] ?? '')) !== '') {
        $dateNeeded = parseSeedlingDate($input['date_needed']);
        if ($dateNeeded === null) {
            $errors[] = 'The date needed is not a valid date.';
        }
    }

    $reportId = (int)($input['mortality_report_id'] ?? 0);

    return [$errors, [
        'polygon_id'          => $polygonId,
        'species'             => $species,
        'quantity'            => $quantity,
        'reason'              => $reason,
        'date_needed'         => $dateNeeded,
        'notes'               => cleanSeedlingNotes($input['notes'] ?? ''),
        'mortality_report_id' => $reportId > 0 ? $reportId : null,
    ]];
}

/**
 * Saves a request for $userId. Returns the new request_id.
 */
function createSeedlingRequest(int $userId, array $clean): int
{
    $pdo = getDbConnection();

    // Only link a mortality report that really belongs to this person.
    $reportId = null;
    if (!empty($clean['mortality_report_id'])) {
        $report = findMortalityReportById((int)$clean['mortality_report_id']);
        if ($report && (int)$report['user_id'] === $userId) {
            $reportId = (int)$report['report_id'];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO seedling_requests
            (user_id, polygon_id, species, quantity_requested, reason,
             date_needed, notes, mortality_report_id)
         VALUES
            (:user_id, :polygon_id, :species, :quantity, :reason,
             :date_needed, :notes, :report_id)'
    );
    $stmt->execute([
        'user_id'     => $userId,
        'polygon_id'  => $clean['polygon_id'],
        'species'     => $clean['species'],
        'quantity'    => $clean['quantity'],
        'reason'      => $clean['reason'],
        'date_needed' => $clean['date_needed'],
        'notes'       => $clean['notes'],
        'report_id'   => $reportId,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * SELECT shared by every request lookup (joins names + polygon + batch).
 */
function seedlingRequestSelectSql(): string
{
    return 'SELECT r.*,
                   u.full_name AS requester_name, u.username AS requester_username,
                   rv.full_name AS reviewer_name,
                   p.polygon_code, p.polygon_name,
                   b.batch_id, b.batch_number, b.release_date AS batch_release_date
            FROM seedling_requests r
            JOIN users u ON u.user_id = r.user_id
            LEFT JOIN users rv ON rv.user_id = r.reviewed_by
            LEFT JOIN polygons p ON p.polygon_id = r.polygon_id
            LEFT JOIN seedling_batches b ON b.request_id = r.request_id';
}

function findSeedlingRequestById(int $requestId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(seedlingRequestSelectSql() . ' WHERE r.request_id = :id');
    $stmt->execute(['id' => $requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * A person's own requests, newest first.
 */
function getSeedlingRequestsForUser(int $userId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        seedlingRequestSelectSql() . ' WHERE r.user_id = :user_id ORDER BY r.created_at DESC, r.request_id DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Every request, optionally filtered. Pending ones are listed first so
 * Admin / Manager see what needs a decision at the top.
 */
function getAllSeedlingRequests(string $status = '', ?int $polygonId = null, ?int $userId = null): array
{
    $pdo = getDbConnection();
    $conditions = [];
    $params = [];

    if ($status !== '' && in_array($status, SEEDLING_REQUEST_STATUSES, true)) {
        $conditions[] = 'r.status = :status';
        $params['status'] = $status;
    }
    if ($polygonId !== null && $polygonId > 0) {
        $conditions[] = 'r.polygon_id = :polygon_id';
        $params['polygon_id'] = $polygonId;
    }
    if ($userId !== null && $userId > 0) {
        $conditions[] = 'r.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql = seedlingRequestSelectSql();
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY (r.status = 'pending') DESC, r.created_at DESC, r.request_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Approves a pending request. $quantity may differ from what was asked.
 */
function approveSeedlingRequest(int $requestId, int $quantity, int $reviewerId, ?string $notes): bool
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "UPDATE seedling_requests
         SET status = 'approved', quantity_approved = :quantity,
             reviewed_by = :reviewer, reviewed_at = NOW(), review_notes = :notes
         WHERE request_id = :id AND status = 'pending'"
    );
    $stmt->execute([
        'quantity' => $quantity,
        'reviewer' => $reviewerId,
        'notes'    => $notes,
        'id'       => $requestId,
    ]);
    return $stmt->rowCount() === 1;
}

/**
 * Rejects a pending request.
 */
function rejectSeedlingRequest(int $requestId, int $reviewerId, string $notes): bool
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "UPDATE seedling_requests
         SET status = 'rejected', quantity_approved = NULL,
             reviewed_by = :reviewer, reviewed_at = NOW(), review_notes = :notes
         WHERE request_id = :id AND status = 'pending'"
    );
    $stmt->execute(['reviewer' => $reviewerId, 'notes' => $notes, 'id' => $requestId]);
    return $stmt->rowCount() === 1;
}

/**
 * Releases an approved request: creates its own batch (next number for
 * the polygon) and marks the request released. All or nothing.
 *
 * @return int|null The new batch_id, or null when the request cannot be released.
 */
function releaseSeedlingRequest(int $requestId, string $releaseDate, int $releasedBy, ?string $notes): ?int
{
    $pdo = getDbConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM seedling_requests WHERE request_id = :id FOR UPDATE');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request
            || $request['status'] !== 'approved'
            || empty($request['polygon_id'])
            || (int)$request['quantity_approved'] < 1
        ) {
            $pdo->rollBack();
            return null;
        }

        $batchId = insertSeedlingBatch(
            $pdo,
            (int)$request['polygon_id'],
            'release',
            (string)$request['species'],
            (int)$request['quantity_approved'],
            $releaseDate,
            $notes,
            $releasedBy,
            $requestId
        );

        $upd = $pdo->prepare("UPDATE seedling_requests SET status = 'released' WHERE request_id = :id");
        $upd->execute(['id' => $requestId]);

        $pdo->commit();
        return $batchId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Seedling release failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * A person may withdraw their own request while it is still pending.
 */
function cancelSeedlingRequest(int $requestId, int $userId): bool
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "DELETE FROM seedling_requests
         WHERE request_id = :id AND user_id = :user_id AND status = 'pending'"
    );
    $stmt->execute(['id' => $requestId, 'user_id' => $userId]);
    return $stmt->rowCount() === 1;
}

function countPendingSeedlingRequests(): int
{
    try {
        $pdo = getDbConnection();
        return (int)$pdo->query("SELECT COUNT(*) FROM seedling_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ============================================================
// Batches
// ============================================================

/**
 * Inserts a batch using the caller's transaction. The batch number is the
 * next free number for that polygon (1, 2, 3...). The polygon row is
 * locked first so two simultaneous releases cannot take the same number.
 */
function insertSeedlingBatch(
    PDO $pdo,
    int $polygonId,
    string $type,
    string $species,
    int $quantity,
    string $releaseDate,
    ?string $notes,
    ?int $releasedBy,
    ?int $requestId
): int {
    $lock = $pdo->prepare('SELECT polygon_id FROM polygons WHERE polygon_id = :id FOR UPDATE');
    $lock->execute(['id' => $polygonId]);
    if ($lock->fetch() === false) {
        throw new RuntimeException('Polygon not found.');
    }

    $next = $pdo->prepare(
        'SELECT COALESCE(MAX(batch_number), 0) + 1 FROM seedling_batches WHERE polygon_id = :id'
    );
    $next->execute(['id' => $polygonId]);
    $batchNumber = (int)$next->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO seedling_batches
            (polygon_id, batch_number, batch_type, request_id, species, quantity,
             release_date, notes, released_by)
         VALUES
            (:polygon_id, :batch_number, :type, :request_id, :species, :quantity,
             :release_date, :notes, :released_by)'
    );
    $stmt->execute([
        'polygon_id'   => $polygonId,
        'batch_number' => $batchNumber,
        'type'         => $type,
        'request_id'   => $requestId,
        'species'      => $species,
        'quantity'     => $quantity,
        'release_date' => $releaseDate,
        'notes'        => $notes,
        'released_by'  => $releasedBy,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Records a planting that happened before this system, so its
 * mortality rate can still be worked out. Returns the new batch_id.
 */
function createInitialSeedlingBatch(
    int $polygonId,
    string $species,
    int $quantity,
    string $plantingDate,
    ?string $notes,
    int $createdBy
): ?int {
    $pdo = getDbConnection();

    try {
        $pdo->beginTransaction();
        $batchId = insertSeedlingBatch(
            $pdo, $polygonId, 'initial', $species, $quantity, $plantingDate, $notes, $createdBy, null
        );
        $pdo->commit();
        return $batchId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Initial seedling batch failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Batches with the dead total and rate worked out, oldest batch first
 * within each polygon.
 *
 * @param array|null $polygonIds Limit to these polygons (null = all).
 */
function getSeedlingBatches(?array $polygonIds = null, ?int $polygonFilter = null): array
{
    $pdo = getDbConnection();
    $conditions = [];
    $params = [];

    if ($polygonIds !== null) {
        if (!$polygonIds) {
            return [];
        }
        $placeholders = [];
        foreach (array_values($polygonIds) as $i => $id) {
            $key = 'pid' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (int)$id;
        }
        $conditions[] = 'b.polygon_id IN (' . implode(',', $placeholders) . ')';
    }
    if ($polygonFilter !== null && $polygonFilter > 0) {
        $conditions[] = 'b.polygon_id = :polygon_filter';
        $params['polygon_filter'] = $polygonFilter;
    }

    $sql = 'SELECT b.*, p.polygon_code, p.polygon_name, u.full_name AS released_by_name,
                   COALESCE((SELECT SUM(m.quantity_dead)
                             FROM seedling_mortality_reports m
                             WHERE m.batch_id = b.batch_id), 0) AS dead_total
            FROM seedling_batches b
            JOIN polygons p ON p.polygon_id = b.polygon_id
            LEFT JOIN users u ON u.user_id = b.released_by';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY p.polygon_code ASC, b.batch_number ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function findSeedlingBatchById(int $batchId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT b.*,
                COALESCE((SELECT SUM(m.quantity_dead)
                          FROM seedling_mortality_reports m
                          WHERE m.batch_id = b.batch_id), 0) AS dead_total
         FROM seedling_batches b WHERE b.batch_id = :id'
    );
    $stmt->execute(['id' => $batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Groups batches by polygon with released / dead totals on top:
 * [polygon_id => ['polygon_code', 'polygon_name', 'released', 'dead', 'batches' => [...]]]
 */
function groupSeedlingBatchesByPolygon(array $batches): array
{
    $groups = [];
    foreach ($batches as $batch) {
        $pid = (int)$batch['polygon_id'];
        if (!isset($groups[$pid])) {
            $groups[$pid] = [
                'polygon_code' => $batch['polygon_code'],
                'polygon_name' => $batch['polygon_name'],
                'released'     => 0,
                'dead'         => 0,
                'batches'      => [],
            ];
        }
        $groups[$pid]['released'] += (int)$batch['quantity'];
        $groups[$pid]['dead']     += (int)$batch['dead_total'];
        $groups[$pid]['batches'][] = $batch;
    }
    return $groups;
}

// ============================================================
// Mortality reports
// ============================================================

/**
 * Validates a mortality report form.
 *
 * @param array $input            Raw POST data.
 * @param int   $userId           The person reporting.
 * @param array $allowedPolygonIds Polygons assigned to them.
 * @return array [errors[], clean[]]
 */
function validateMortalityReport(array $input, int $userId, array $allowedPolygonIds): array
{
    $errors = [];

    $polygonId = (int)($input['polygon_id'] ?? 0);
    if (!in_array($polygonId, array_map('intval', $allowedPolygonIds), true)) {
        $errors[] = 'Please choose one of your assigned areas.';
    }

    $batchId = (int)($input['batch_id'] ?? 0);
    $batch = $batchId > 0 ? findSeedlingBatchById($batchId) : null;
    if ($batch === null || (int)$batch['polygon_id'] !== $polygonId) {
        $errors[] = 'Please choose a batch that belongs to that area.';
        $batch = null;
    }

    $dead = parseSeedlingQuantity($input['quantity_dead'] ?? '');
    if ($dead === null) {
        $errors[] = 'Number of dead seedlings must be a whole number of at least 1.';
    } elseif ($batch !== null) {
        $remaining = (int)$batch['quantity'] - (int)$batch['dead_total'];
        if ($dead > $remaining) {
            $errors[] = 'That is more than the seedlings still alive in this batch ('
                . $remaining . ' of ' . (int)$batch['quantity'] . ' released).';
        }
    }

    $cause = (string)($input['cause'] ?? '');
    if (!isset(SEEDLING_CAUSES[$cause])) {
        $errors[] = 'Please choose a cause.';
    }

    $observed = parseSeedlingDate($input['date_observed'] ?? '');
    if ($observed === null) {
        $errors[] = 'Please enter the date you observed the loss.';
    } elseif ($observed > date('Y-m-d')) {
        $errors[] = 'The date observed cannot be in the future.';
    }

    // Optional photo evidence: must be one of the person's own photos.
    $photoId = (int)($input['photo_id'] ?? 0);
    if ($photoId > 0) {
        $photo = findFieldPhotoById($photoId);
        if (!$photo || (int)$photo['user_id'] !== $userId) {
            $errors[] = 'The selected photo could not be found.';
            $photoId = 0;
        }
    }

    return [$errors, [
        'polygon_id'    => $polygonId,
        'batch_id'      => $batchId,
        'quantity_dead' => $dead,
        'cause'         => $cause,
        'date_observed' => $observed,
        'notes'         => cleanSeedlingNotes($input['notes'] ?? ''),
        'photo_id'      => $photoId > 0 ? $photoId : null,
    ]];
}

/**
 * Saves a mortality report. The batch is locked and re-checked so two
 * reports at once cannot together exceed the seedlings released.
 *
 * @return int|null The new report_id, or null if the batch no longer has enough living seedlings.
 */
function createMortalityReport(int $userId, array $clean): ?int
{
    $pdo = getDbConnection();

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT quantity FROM seedling_batches WHERE batch_id = :id FOR UPDATE');
        $lock->execute(['id' => $clean['batch_id']]);
        $batch = $lock->fetch();
        if (!$batch) {
            $pdo->rollBack();
            return null;
        }

        $sum = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity_dead), 0) FROM seedling_mortality_reports WHERE batch_id = :id'
        );
        $sum->execute(['id' => $clean['batch_id']]);
        $alreadyDead = (int)$sum->fetchColumn();

        if ($alreadyDead + (int)$clean['quantity_dead'] > (int)$batch['quantity']) {
            $pdo->rollBack();
            return null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO seedling_mortality_reports
                (user_id, polygon_id, batch_id, quantity_dead, cause, date_observed, notes, photo_id)
             VALUES
                (:user_id, :polygon_id, :batch_id, :dead, :cause, :observed, :notes, :photo_id)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'polygon_id' => $clean['polygon_id'],
            'batch_id'   => $clean['batch_id'],
            'dead'       => $clean['quantity_dead'],
            'cause'      => $clean['cause'],
            'observed'   => $clean['date_observed'],
            'notes'      => $clean['notes'],
            'photo_id'   => $clean['photo_id'],
        ]);
        $reportId = (int)$pdo->lastInsertId();

        $pdo->commit();
        return $reportId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Mortality report failed: ' . $e->getMessage());
        return null;
    }
}

function mortalitySelectSql(): string
{
    return 'SELECT m.*, u.full_name AS reporter_name, u.username AS reporter_username,
                   p.polygon_code, p.polygon_name,
                   b.batch_number, b.batch_type, b.species, b.quantity AS batch_quantity,
                   fp.file_name AS photo_file
            FROM seedling_mortality_reports m
            JOIN users u ON u.user_id = m.user_id
            JOIN polygons p ON p.polygon_id = m.polygon_id
            JOIN seedling_batches b ON b.batch_id = m.batch_id
            LEFT JOIN field_photos fp ON fp.photo_id = m.photo_id';
}

function findMortalityReportById(int $reportId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(mortalitySelectSql() . ' WHERE m.report_id = :id');
    $stmt->execute(['id' => $reportId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Mortality reports, newest first. Pass $userId to see one person's own.
 *
 * @param array $filters polygon_id, cause, date_from, date_to
 */
function getMortalityReports(?int $userId = null, array $filters = []): array
{
    $pdo = getDbConnection();
    $conditions = [];
    $params = [];

    if ($userId !== null) {
        $conditions[] = 'm.user_id = :user_id';
        $params['user_id'] = $userId;
    }
    if (!empty($filters['polygon_id'])) {
        $conditions[] = 'm.polygon_id = :polygon_id';
        $params['polygon_id'] = (int)$filters['polygon_id'];
    }
    if (!empty($filters['cause']) && isset(SEEDLING_CAUSES[$filters['cause']])) {
        $conditions[] = 'm.cause = :cause';
        $params['cause'] = $filters['cause'];
    }
    if (!empty($filters['date_from']) && parseSeedlingDate($filters['date_from']) !== null) {
        $conditions[] = 'm.date_observed >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to']) && parseSeedlingDate($filters['date_to']) !== null) {
        $conditions[] = 'm.date_observed <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }

    $sql = mortalitySelectSql();
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY m.date_observed DESC, m.report_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function deleteMortalityReport(int $reportId): bool
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM seedling_mortality_reports WHERE report_id = :id');
    $stmt->execute(['id' => $reportId]);
    return $stmt->rowCount() === 1;
}

/**
 * A person's own photos that can be attached as evidence. Includes the
 * polygon each one was uploaded for when that column exists.
 */
function getMortalityPhotoChoices(int $userId): array
{
    try {
        $pdo = getDbConnection();
        $columns = polygonPhotoColumnExists() ? 'photo_id, polygon_id, uploaded_at' : 'photo_id, uploaded_at';
        $stmt = $pdo->prepare(
            'SELECT ' . $columns . ' FROM field_photos
             WHERE user_id = :user_id ORDER BY uploaded_at DESC LIMIT 100'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// ============================================================
// Dashboard numbers (read-only, fail soft)
// ============================================================

/**
 * Released / dead totals and the overall mortality rate.
 * Pass a user id to limit it to that person's assigned polygons.
 */
function getSeedlingTotals(?int $userId = null): array
{
    $totals = ['released' => 0, 'dead' => 0, 'dead_30_days' => 0];

    try {
        $pdo = getDbConnection();

        $batchSql = 'SELECT COALESCE(SUM(b.quantity), 0) FROM seedling_batches b';
        $deadSql = 'SELECT COALESCE(SUM(m.quantity_dead), 0) FROM seedling_mortality_reports m';
        $recentSql = $deadSql . ' WHERE m.date_observed >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        $params = [];

        if ($userId !== null) {
            $batchSql .= ' JOIN polygons p ON p.polygon_id = b.polygon_id WHERE p.assigned_user_id = :uid';
            $deadSql .= ' JOIN polygons p ON p.polygon_id = m.polygon_id WHERE p.assigned_user_id = :uid';
            $recentSql = 'SELECT COALESCE(SUM(m.quantity_dead), 0) FROM seedling_mortality_reports m
                          JOIN polygons p ON p.polygon_id = m.polygon_id
                          WHERE p.assigned_user_id = :uid
                            AND m.date_observed >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
            $params = ['uid' => $userId];
        }

        foreach (['released' => $batchSql, 'dead' => $deadSql, 'dead_30_days' => $recentSql] as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $totals[$key] = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // leave zeros
    }

    return $totals;
}

/**
 * Request counts by status for one person (or everyone when null).
 */
function getSeedlingRequestCounts(?int $userId = null): array
{
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'released' => 0];

    try {
        $pdo = getDbConnection();
        $sql = 'SELECT status, COUNT(*) AS n FROM seedling_requests';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :uid';
            $params['uid'] = $userId;
        }
        $sql .= ' GROUP BY status';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['n'];
            }
        }
    } catch (Throwable $e) {
        // leave zeros
    }

    return $counts;
}

// ============================================================
// Small page helpers
// ============================================================

/**
 * Coloured status label for a request.
 */
function seedlingStatusBadge(string $status): string
{
    return '<span class="status-badge sr-status sr-' . h($status) . '">' . h(ucfirst($status)) . '</span>';
}

/**
 * Reads and clears the one-time message shown after a form submit.
 */
function seedlingTakeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function seedlingSetFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Shown on every seedling page when database.sql has not been run.
 */
function seedlingMissingTablesNotice(): string
{
    return '<div class="alert alert-warning">The seedling tables are not installed yet. '
        . 'Run <strong>database.sql</strong> once in phpMyAdmin (database '
        . '<strong>reforestation_db</strong>), then reload this page.</div>';
}

/**
 * "Oct 12, 2026" for a DATE value, or "-" when empty.
 */
function seedlingDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }
    $time = strtotime($date);
    return $time === false ? '-' : date('M j, Y', $time);
}

/**
 * CSS class for a mortality rate: green below 10%, amber below 25%, red above.
 */
function seedlingRateClass(int $dead, int $released): string
{
    $rate = seedlingRate($dead, $released);
    if ($rate === null) {
        return '';
    }
    if ($rate < 0.10) {
        return 'sr-rate-low';
    }
    if ($rate < 0.25) {
        return 'sr-rate-mid';
    }
    return 'sr-rate-high';
}

/**
 * Mortality rate as coloured HTML (already escaped).
 */
function seedlingRateHtml(int $dead, int $released): string
{
    $text = h(formatSeedlingRate($dead, $released));
    $class = seedlingRateClass($dead, $released);
    return $class === '' ? $text : '<span class="' . $class . '">' . $text . '</span>';
}
