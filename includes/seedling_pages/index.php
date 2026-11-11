<?php
/**
 * Seedling requests - list (Admin and Manager).
 */

require_once __DIR__ . '/_bootstrap.php';

$tablesReady = seedlingTablesExist();
$statusFilter = (string)($_GET['status'] ?? '');
$polygonFilter = isset($_GET['polygon']) && $_GET['polygon'] !== '' ? (int)$_GET['polygon'] : null;
$flash = seedlingTakeFlash();

$requests = $tablesReady ? getAllSeedlingRequests($statusFilter, $polygonFilter) : [];
$polygonChoices = $tablesReady ? seedlingPolygonChoices() : [];
$counts = $tablesReady ? getSeedlingRequestCounts() : [];

$pageTitle = 'Seedlings';
$extraHead = $seedlingHead;

require_once __DIR__ . '/../header.php';
?>
<h1>Seedlings</h1>
<?= seedlingSubnav($seedlingBase, 'requests') ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>

    <?= seedlingMissingTablesNotice() ?>

<?php else: ?>

    <div class="stat-grid">
        <a class="stat-card stat-pending" href="?status=pending">
            <span class="stat-number"><?= (int)$counts['pending'] ?></span>
            <span class="stat-label">Pending</span>
        </a>
        <a class="stat-card" href="?status=approved">
            <span class="stat-number"><?= (int)$counts['approved'] ?></span>
            <span class="stat-label">Approved, awaiting release</span>
        </a>
        <a class="stat-card stat-confirmed" href="?status=released">
            <span class="stat-number"><?= (int)$counts['released'] ?></span>
            <span class="stat-label">Released</span>
        </a>
        <a class="stat-card stat-rejected" href="?status=rejected">
            <span class="stat-number"><?= (int)$counts['rejected'] ?></span>
            <span class="stat-label">Rejected</span>
        </a>
    </div>

    <h2 class="dash-heading">Seedling Requests</h2>

    <form action="index.php" method="get" class="sr-filters">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (SEEDLING_REQUEST_STATUSES as $s): ?>
                <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="polygon">
            <option value="">All areas</option>
            <?php foreach ($polygonChoices as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= $polygonFilter === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Submitted</th>
            <th>Requested By</th>
            <th>Area</th>
            <th>Species</th>
            <th>Requested</th>
            <th>Approved</th>
            <th>Reason</th>
            <th>Needed By</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($requests)): ?>
            <tr><td colspan="10" class="empty-row">No seedling requests found.</td></tr>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td data-label="Submitted"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></td>
                    <td data-label="Requested By"><?= h($r['requester_name']) ?> (<?= h($r['requester_username']) ?>)</td>
                    <td data-label="Area"><?= $r['polygon_code'] ? h($r['polygon_code'] . ' - ' . $r['polygon_name']) : '<span class="hint">-</span>' ?></td>
                    <td data-label="Species"><?= h($r['species']) ?></td>
                    <td data-label="Requested"><?= (int)$r['quantity_requested'] ?></td>
                    <td data-label="Approved"><?= $r['quantity_approved'] !== null ? (int)$r['quantity_approved'] : '<span class="hint">-</span>' ?></td>
                    <td data-label="Reason"><?= h(SEEDLING_REASONS[$r['reason']] ?? $r['reason']) ?></td>
                    <td data-label="Needed By"><?= h(seedlingDate($r['date_needed'])) ?></td>
                    <td data-label="Status"><?= seedlingStatusBadge($r['status']) ?></td>
                    <td class="actions-cell" data-label="Actions">
                        <a href="view.php?id=<?= (int)$r['request_id'] ?>"><?= in_array($r['status'], ['pending', 'approved'], true) ? 'Review' : 'View' ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php require_once __DIR__ . '/../page_end.php'; ?>
