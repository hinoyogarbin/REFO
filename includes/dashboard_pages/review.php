<?php
/**
 * Shared body of the Admin and Manager dashboards.
 *
 * Order: pending photo reviews, polygon overview, tree photo counts,
 * seedling counts, the map (boundaries + photo markers), recent uploads.
 *
 * Expects $role ('admin' or 'manager'), set by the dashboard page, and
 * leaves the map scripts in $extraScripts for includes/page_end.php.
 */

require_once __DIR__ . '/../polygon_functions.php';
require_once __DIR__ . '/../dashboard_functions.php';
require_once __DIR__ . '/../seedling_functions.php';

$photosBase   = "/REFO/{$role}/photos/";
$seedlingsBase = "/REFO/{$role}/seedlings/";
$pendingUrl   = $photosBase . 'index.php?status=pending';
$polygonsUrl  = "/REFO/{$role}/polygons/index.php";

$seedlingsReady  = seedlingTablesExist();
$seedlingCounts  = $seedlingsReady ? getSeedlingRequestCounts() : [];
$seedlingTotals  = $seedlingsReady ? getSeedlingTotals() : ['released' => 0, 'dead' => 0, 'dead_30_days' => 0];

$photoCounts  = getFieldPhotoStatusCounts();
$recentPhotos = getRecentFieldPhotos(5);
$areaLabels   = getPolygonLabelsForPhotos(array_column($recentPhotos, 'photo_id'));

try {
    $polygons   = getPolygonsList();
    $analytics  = getPolygonDashboardAnalytics();
    $mapPayload = buildMapPayload($polygons);
} catch (Throwable $e) {
    $analytics = [
        'polygon_count' => 0, 'total_hectares' => 0.0, 'active_count' => 0,
        'completed_count' => 0, 'inactive_count' => 0, 'assigned_count' => 0,
        'source_file_count' => 0, 'formats' => [], 'recent_imports' => [],
    ];
    $mapPayload = [];
}
$photoMarkers = getDashboardPhotoMarkers(null, $photosBase . 'view.php?id=');
?>
<h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>

<div class="dash-review-card <?= $photoCounts['pending'] > 0 ? 'has-pending' : '' ?>">
    <div class="dash-review-text">
        <span class="stat-number"><?= (int)$photoCounts['pending'] ?></span>
        <div>
            <strong>Pending photo reviews</strong>
            <div class="stat-label">
                <?= $photoCounts['pending'] > 0
                    ? 'Tree photos uploaded by field users are waiting for your decision.'
                    : 'All caught up. No photos are waiting for review.' ?>
            </div>
        </div>
    </div>
    <a class="btn btn-primary" href="<?= h($pendingUrl) ?>">Review now</a>
</div>

<?php
// KPI cards shown inside the CENRO data overview.
$overviewKpiGroups = [[
    'title' => 'Tree photos',
    'cards' => [
        ['label' => 'Pending',   'value' => (int)$photoCounts['pending'],   'href' => $photosBase . 'index.php?status=pending',   'class' => 'stat-pending'],
        ['label' => 'Confirmed', 'value' => (int)$photoCounts['confirmed'], 'href' => $photosBase . 'index.php?status=confirmed', 'class' => 'stat-confirmed'],
        ['label' => 'Rejected',  'value' => (int)$photoCounts['rejected'],  'href' => $photosBase . 'index.php?status=rejected',  'class' => 'stat-rejected'],
    ],
]];
if ($seedlingsReady) {
    $overviewKpiGroups[] = [
        'title' => 'Seedlings',
        'cards' => [
            ['label' => 'Pending requests',            'value' => (int)$seedlingCounts['pending'],  'href' => $seedlingsBase . 'index.php?status=pending',  'class' => 'stat-pending'],
            ['label' => 'Approved, awaiting release',  'value' => (int)$seedlingCounts['approved'], 'href' => $seedlingsBase . 'index.php?status=approved', 'class' => ''],
            ['label' => 'Seedlings released',          'value' => (int)$seedlingTotals['released'], 'href' => $seedlingsBase . 'batches.php',               'class' => ''],
            ['label' => 'Dead, last 30 days',          'value' => (int)$seedlingTotals['dead_30_days'], 'href' => $seedlingsBase . 'mortality.php',         'class' => 'stat-rejected'],
            ['label' => 'Overall mortality rate',      'value' => formatSeedlingRate((int)$seedlingTotals['dead'], (int)$seedlingTotals['released']), 'href' => $seedlingsBase . 'mortality.php', 'class' => ''],
        ],
    ];
}

// Polygon overview: polygon totals, mapped hectares, status bars, imported files.
$dashboardSkipMap = true;
require __DIR__ . '/../dashboard_polygon_panel.php';
?>

<?php require __DIR__ . '/../dashboard_map_section.php'; ?>

<h2 class="dash-heading">Recent Uploads</h2>
<table class="data-table photo-table">
    <thead>
    <tr>
        <th>Photo</th>
        <th>Uploaded By</th>
        <th>Area</th>
        <th>Status</th>
        <th>Uploaded</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($recentPhotos)): ?>
        <tr>
            <td colspan="6" class="empty-row">No photos have been uploaded yet.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($recentPhotos as $p): ?>
            <tr>
                <td data-label="Photo">
                    <a href="<?= h($photosBase) ?>view.php?id=<?= (int)$p['photo_id'] ?>">
                        <img class="photo-thumb" src="<?= h(PHOTO_UPLOAD_URL . $p['file_name']) ?>" alt="Field photo thumbnail">
                    </a>
                </td>
                <td data-label="Uploaded By"><?= h($p['uploader_name']) ?></td>
                <td data-label="Area"><?= isset($areaLabels[(int)$p['photo_id']]) ? h($areaLabels[(int)$p['photo_id']]) : '<span class="hint">-</span>' ?></td>
                <td data-label="Status"><span class="status-badge review-<?= h($p['status']) ?>"><?= h(ucfirst($p['status'])) ?></span></td>
                <td data-label="Uploaded"><?= h(date('F j, Y g:i A', strtotime($p['uploaded_at']))) ?></td>
                <td class="actions-cell" data-label="Actions"><a href="<?= h($photosBase) ?>view.php?id=<?= (int)$p['photo_id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
<div class="toolbar">
    <a class="btn" href="<?= h($photosBase) ?>index.php">View all field photos</a>
</div>

<?php
$extraScripts = dashboardMapScripts($mapPayload, $photoMarkers, ['locked' => true, 'allowOverview' => true]);
