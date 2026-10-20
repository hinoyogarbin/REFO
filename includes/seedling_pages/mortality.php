<?php
/**
 * Seedling mortality reports (Admin and Manager).
 *
 * Counts and rates per area, plus every report with filters. A report
 * entered by mistake can be deleted, which also removes it from the totals.
 */

require_once __DIR__ . '/_bootstrap.php';

$tablesReady = seedlingTablesExist();

// ---------- Delete a report (POST) ----------
if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['delete_id'] ?? 0);
    $report = $reportId > 0 ? findMortalityReportById($reportId) : null;

    if ($report && deleteMortalityReport($reportId)) {
        logActivity($currentUserId, (string)($_SESSION['full_name'] ?? ''), $currentRole,
            'Delete Mortality Report', 'Seedlings',
            "Deleted mortality report #{$reportId} ({$report['quantity_dead']} dead, polygon {$report['polygon_code']}).", 'success');
        seedlingSetFlash('success', 'Mortality report deleted.');
    } else {
        seedlingSetFlash('error', 'Mortality report not found.');
    }

    header('Location: mortality.php');
    exit;
}

$filters = [
    'polygon_id' => isset($_GET['polygon']) && $_GET['polygon'] !== '' ? (int)$_GET['polygon'] : 0,
    'cause'      => (string)($_GET['cause'] ?? ''),
    'date_from'  => (string)($_GET['from'] ?? ''),
    'date_to'    => (string)($_GET['to'] ?? ''),
];

$flash = seedlingTakeFlash();
$polygonChoices = $tablesReady ? seedlingPolygonChoices() : [];
$reports = $tablesReady ? getMortalityReports(null, $filters) : [];
$areaGroups = $tablesReady
    ? groupSeedlingBatchesByPolygon(getSeedlingBatches(null, $filters['polygon_id'] ?: null))
    : [];

$filteredDead = array_sum(array_map('intval', array_column($reports, 'quantity_dead')));

$pageTitle = 'Seedling Mortality';
$extraHead = $seedlingHead;

require_once __DIR__ . '/../header.php';
?>
<h1>Seedlings</h1>
<?= seedlingSubnav($seedlingBase, 'mortality') ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>

    <?= seedlingMissingTablesNotice() ?>

<?php else: ?>

    <h2 class="dash-heading">Mortality by Area</h2>
    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Area</th>
            <th>Batches</th>
            <th>Released</th>
            <th>Dead</th>
            <th>Alive</th>
            <th>Mortality</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($areaGroups)): ?>
            <tr><td colspan="6" class="empty-row">No batches released yet.</td></tr>
        <?php else: ?>
            <?php foreach ($areaGroups as $g): ?>
                <tr>
                    <td data-label="Area"><?= h($g['polygon_code'] . ' - ' . $g['polygon_name']) ?></td>
                    <td data-label="Batches"><?= count($g['batches']) ?></td>
                    <td data-label="Released"><?= (int)$g['released'] ?></td>
                    <td data-label="Dead"><?= (int)$g['dead'] ?></td>
                    <td data-label="Alive"><?= (int)$g['released'] - (int)$g['dead'] ?></td>
                    <td data-label="Mortality"><?= seedlingRateHtml((int)$g['dead'], (int)$g['released']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="hint">Rates are dead seedlings divided by seedlings released. Per-batch rates are on the Batches tab.</p>

    <h2 class="dash-heading">Mortality Reports</h2>

    <form action="mortality.php" method="get" class="sr-filters">
        <select name="polygon">
            <option value="">All areas</option>
            <?php foreach ($polygonChoices as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= (int)$filters['polygon_id'] === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="cause">
            <option value="">All causes</option>
            <?php foreach (SEEDLING_CAUSES as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $filters['cause'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= h($filters['date_from']) ?>">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= h($filters['date_to']) ?>">
        <button type="submit" class="btn">Filter</button>
    </form>

    <p class="hint"><?= count($reports) ?> report<?= count($reports) === 1 ? '' : 's' ?> shown,
        <strong><?= (int)$filteredDead ?></strong> seedlings dead.</p>

    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Observed</th>
            <th>Reported By</th>
            <th>Area</th>
            <th>Batch</th>
            <th>Dead</th>
            <th>Cause</th>
            <th>Notes</th>
            <th>Photo</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($reports)): ?>
            <tr><td colspan="9" class="empty-row">No mortality reports found.</td></tr>
        <?php else: ?>
            <?php foreach ($reports as $m): ?>
                <tr>
                    <td data-label="Observed"><?= h(seedlingDate($m['date_observed'])) ?></td>
                    <td data-label="Reported By"><?= h($m['reporter_name']) ?> (<?= h($m['reporter_username']) ?>)</td>
                    <td data-label="Area"><?= h($m['polygon_code']) ?></td>
                    <td data-label="Batch"><?= h(seedlingBatchLabel($m)) ?> &middot; <?= h($m['species']) ?></td>
                    <td data-label="Dead"><?= (int)$m['quantity_dead'] ?> of <?= (int)$m['batch_quantity'] ?></td>
                    <td data-label="Cause"><?= h(SEEDLING_CAUSES[$m['cause']] ?? $m['cause']) ?></td>
                    <td data-label="Notes" class="sr-note"><?= !empty($m['notes']) ? h($m['notes']) : '<span class="hint">-</span>' ?></td>
                    <td data-label="Photo">
                        <?php if (!empty($m['photo_id']) && !empty($m['photo_file'])): ?>
                            <a href="/REFO/<?= h($currentRole) ?>/photos/view.php?id=<?= (int)$m['photo_id'] ?>">
                                <img class="sr-small-photo" src="<?= h(PHOTO_UPLOAD_URL . $m['photo_file']) ?>" alt="Evidence photo">
                            </a>
                        <?php else: ?>
                            <span class="hint">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions-cell" data-label="Actions">
                        <form action="mortality.php" method="post" class="inline-form"
                              onsubmit="return confirm('Delete this mortality report? It will be removed from the totals.');">
                            <input type="hidden" name="delete_id" value="<?= (int)$m['report_id'] ?>">
                            <button type="submit" class="link-button link-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php require_once __DIR__ . '/../page_end.php'; ?>
