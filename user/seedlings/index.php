<?php
/**
 * Field User - My Seedlings.
 *
 * Shows the seedling batches CENRO released to the user's areas (with how
 * many died and the mortality rate), the user's requests with their
 * status, and the user's mortality reports. A user only ever sees their
 * own assigned polygons and their own requests and reports.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/seedling_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$tablesReady = seedlingTablesExist();
$flash = seedlingTakeFlash();

$polygons = [];
$batchGroups = [];
$requests = [];
$reports = [];
$totals = ['released' => 0, 'dead' => 0, 'dead_30_days' => 0];

if ($tablesReady) {
    $polygons = getPolygonsForFieldUser($currentUserId);
    $polygonIds = array_map('intval', array_column($polygons, 'polygon_id'));
    $batchGroups = groupSeedlingBatchesByPolygon(getSeedlingBatches($polygonIds));
    $requests = getSeedlingRequestsForUser($currentUserId);
    $reports = getMortalityReports($currentUserId);
    $totals = getSeedlingTotals($currentUserId);
}

$pageTitle = 'My Seedlings';
$extraHead = '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=3">'
    . '<link rel="stylesheet" href="/REFO/assets/css/dashboard.css?v=2">'
    . '<link rel="stylesheet" href="/REFO/assets/css/seedlings.css?v=2">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>My Seedlings</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>

    <?= seedlingMissingTablesNotice() ?>

<?php elseif (empty($polygons)): ?>

    <div class="alert alert-info">
        You do not have a reforestation area assigned yet, so there is nothing to
        request or report. Please contact your Administrator or Manager.
    </div>

<?php else: ?>

    <div class="toolbar">
        <a class="btn btn-primary" href="request.php">Request Seedlings</a>
        <a class="btn" href="mortality.php">Report Dead Seedlings</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-number"><?= (int)$totals['released'] ?></span>
            <span class="stat-label">Seedlings released to you</span>
        </div>
        <div class="stat-card stat-rejected">
            <span class="stat-number"><?= (int)$totals['dead'] ?></span>
            <span class="stat-label">Reported dead</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= seedlingRateHtml((int)$totals['dead'], (int)$totals['released']) ?></span>
            <span class="stat-label">Mortality rate</span>
        </div>
    </div>

    <h2 class="dash-heading">Batches Released to My Areas</h2>
    <?php if (empty($batchGroups)): ?>
        <p class="hint">No seedlings have been released to your areas yet. Send a request and CENRO will
            release a batch once it is approved.</p>
    <?php else: ?>
        <?php foreach ($batchGroups as $group): ?>
            <div class="sr-group">
                <div class="sr-group-head">
                    <h3><?= h($group['polygon_code'] . ' - ' . $group['polygon_name']) ?></h3>
                    <span class="sr-group-totals">
                        <?= (int)$group['released'] ?> released &middot;
                        <?= (int)$group['dead'] ?> dead &middot;
                        mortality <?= seedlingRateHtml((int)$group['dead'], (int)$group['released']) ?>
                    </span>
                </div>
                <table class="data-table photo-table">
                    <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Species</th>
                        <th>Released</th>
                        <th>Quantity</th>
                        <th>Dead</th>
                        <th>Mortality</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['batches'] as $b): ?>
                        <tr>
                            <td data-label="Batch"><?= h(seedlingBatchLabel($b)) ?></td>
                            <td data-label="Species"><?= h($b['species']) ?></td>
                            <td data-label="Released"><?= h(seedlingDate($b['release_date'])) ?></td>
                            <td data-label="Quantity"><?= (int)$b['quantity'] ?></td>
                            <td data-label="Dead"><?= (int)$b['dead_total'] ?></td>
                            <td data-label="Mortality"><?= seedlingRateHtml((int)$b['dead_total'], (int)$b['quantity']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="dash-heading">My Requests</h2>
    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Submitted</th>
            <th>Area</th>
            <th>Species</th>
            <th>Requested</th>
            <th>Approved</th>
            <th>Status</th>
            <th>Notes from CENRO</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($requests)): ?>
            <tr><td colspan="8" class="empty-row">You have not requested any seedlings yet.</td></tr>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td data-label="Submitted"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></td>
                    <td data-label="Area"><?= $r['polygon_code'] ? h($r['polygon_code']) : '<span class="hint">-</span>' ?></td>
                    <td data-label="Species"><?= h($r['species']) ?></td>
                    <td data-label="Requested"><?= (int)$r['quantity_requested'] ?></td>
                    <td data-label="Approved"><?= $r['quantity_approved'] !== null ? (int)$r['quantity_approved'] : '<span class="hint">-</span>' ?></td>
                    <td data-label="Status">
                        <?= seedlingStatusBadge($r['status']) ?>
                        <?php if ($r['status'] === 'released' && $r['batch_number'] !== null): ?>
                            <div class="hint">Batch <?= (int)$r['batch_number'] ?>, <?= h(seedlingDate($r['batch_release_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Notes" class="sr-note"><?= !empty($r['review_notes']) ? h($r['review_notes']) : '<span class="hint">-</span>' ?></td>
                    <td class="actions-cell" data-label="Actions">
                        <?php if ($r['status'] === 'pending'): ?>
                            <form action="cancel.php" method="post" class="inline-form"
                                  onsubmit="return confirm('Cancel this request?');">
                                <input type="hidden" name="id" value="<?= (int)$r['request_id'] ?>">
                                <button type="submit" class="link-button link-danger">Cancel</button>
                            </form>
                        <?php else: ?>
                            <span class="hint">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 class="dash-heading">My Mortality Reports</h2>
    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Observed</th>
            <th>Area</th>
            <th>Batch</th>
            <th>Dead</th>
            <th>Cause</th>
            <th>Notes</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($reports)): ?>
            <tr><td colspan="7" class="empty-row">You have not reported any dead seedlings.</td></tr>
        <?php else: ?>
            <?php foreach ($reports as $m): ?>
                <tr>
                    <td data-label="Observed"><?= h(seedlingDate($m['date_observed'])) ?></td>
                    <td data-label="Area"><?= h($m['polygon_code']) ?></td>
                    <td data-label="Batch"><?= h(seedlingBatchLabel($m)) ?> &middot; <?= h($m['species']) ?></td>
                    <td data-label="Dead"><?= (int)$m['quantity_dead'] ?></td>
                    <td data-label="Cause"><?= h(SEEDLING_CAUSES[$m['cause']] ?? $m['cause']) ?></td>
                    <td data-label="Notes" class="sr-note"><?= !empty($m['notes']) ? h($m['notes']) : '<span class="hint">-</span>' ?></td>
                    <td class="actions-cell" data-label="Actions">
                        <a href="request.php?report_id=<?= (int)$m['report_id'] ?>">Request replacement</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
