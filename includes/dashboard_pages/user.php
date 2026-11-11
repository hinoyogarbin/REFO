<?php
/**
 * Body of the Field User dashboard.
 *
 * Leaves the map scripts in $extraScripts for includes/page_end.php.
 */

require_once __DIR__ . '/../polygon_functions.php';
require_once __DIR__ . '/../dashboard_functions.php';
require_once __DIR__ . '/../seedling_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

try {
    $myPolygons = getPolygonsForFieldUser($currentUserId);
} catch (Throwable $e) {
    $myPolygons = [];
}

$seedlingsReady = seedlingTablesExist();
$seedlingCounts = $seedlingsReady ? getSeedlingRequestCounts($currentUserId) : [];
$seedlingTotals = $seedlingsReady ? getSeedlingTotals($currentUserId) : ['released' => 0, 'dead' => 0, 'dead_30_days' => 0];

$photoCounts    = getFieldPhotoStatusCounts($currentUserId);
$photosByArea   = getFieldPhotoCountsByPolygon($currentUserId);
$rejectedPhotos = getRejectedFieldPhotosForUser($currentUserId);
$mapPayload     = buildMapPayload($myPolygons);

try {
    $analytics = getPolygonDashboardAnalytics($currentUserId);
} catch (Throwable $e) {
    $analytics = [
        'polygon_count' => 0, 'total_hectares' => 0.0, 'active_count' => 0,
        'completed_count' => 0, 'inactive_count' => 0, 'assigned_count' => 0,
        'source_file_count' => 0, 'formats' => [], 'recent_imports' => [],
    ];
}
$role = 'user';
$photoMarkers   = getDashboardPhotoMarkers($currentUserId, '/REFO/user/photos/view.php?id=');
?>
<h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>

<h2 class="dash-heading">My Assigned Areas</h2>
<?php if (empty($myPolygons)): ?>
    <div class="alert alert-info">
        No area has been assigned to you yet. Please contact your administrator or manager.
    </div>
<?php else: ?>
    <div class="area-grid">
        <?php foreach ($myPolygons as $polygon): ?>
            <?php $pid = (int)$polygon['polygon_id']; ?>
            <div class="area-card">
                <div class="area-code"><?= h($polygon['polygon_code']) ?></div>
                <div class="area-name"><?= h($polygon['polygon_name']) ?></div>
                <div class="stat-label">
                    <?= h(formatHectares($polygon['area_hectares'] ?? null)) ?>
                    &middot; <?= (int)($photosByArea[$pid] ?? 0) ?> photo<?= ($photosByArea[$pid] ?? 0) === 1 ? '' : 's' ?> uploaded
                </div>
                <div class="area-actions">
                    <a class="btn btn-primary" href="/REFO/user/polygons/photos.php?id=<?= $pid ?>">Upload Photos</a>
                    <a class="btn" href="/REFO/user/polygons/view.php?id=<?= $pid ?>">View Map</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// KPI cards shown inside the CENRO data overview (this user's own numbers).
$overviewKpiGroups = [[
    'title' => 'My tree photos',
    'cards' => [
        ['label' => 'Pending review', 'value' => (int)$photoCounts['pending'],   'href' => '/REFO/user/photos/index.php', 'class' => 'stat-pending'],
        ['label' => 'Confirmed',      'value' => (int)$photoCounts['confirmed'], 'href' => '/REFO/user/photos/index.php', 'class' => 'stat-confirmed'],
        ['label' => 'Rejected',       'value' => (int)$photoCounts['rejected'],  'href' => '/REFO/user/photos/index.php', 'class' => 'stat-rejected'],
    ],
]];
if ($seedlingsReady && !empty($myPolygons)) {
    $overviewKpiGroups[] = [
        'title' => 'My seedlings',
        'cards' => [
            ['label' => 'Requests waiting for CENRO', 'value' => (int)$seedlingCounts['pending'],  'href' => '/REFO/user/seedlings/index.php', 'class' => 'stat-pending'],
            ['label' => 'Approved, to be released',   'value' => (int)$seedlingCounts['approved'], 'href' => '/REFO/user/seedlings/index.php', 'class' => ''],
            ['label' => 'Seedlings released to me',   'value' => (int)$seedlingTotals['released'], 'href' => '/REFO/user/seedlings/index.php', 'class' => 'stat-confirmed'],
            ['label' => 'Reported dead',              'value' => (int)$seedlingTotals['dead'],     'href' => '/REFO/user/seedlings/index.php', 'class' => 'stat-rejected'],
        ],
        'actions' => [
            ['label' => 'Request Seedlings',     'href' => '/REFO/user/seedlings/request.php',   'class' => 'btn-primary'],
            ['label' => 'Report Dead Seedlings', 'href' => '/REFO/user/seedlings/mortality.php', 'class' => ''],
        ],
    ];
}

// Polygon overview, limited to this user's own polygons.
$dashboardSkipMap = true;
require __DIR__ . '/../dashboard_polygon_panel.php';
?>

<?php if (!empty($rejectedPhotos)): ?>
    <h2 class="dash-heading">Needs Your Attention</h2>
    <p class="hint">These photos were rejected. Read the reviewer's notes, then retake and upload them again from the area.</p>
    <table class="data-table photo-table">
        <thead>
        <tr>
            <th>Photo</th>
            <th>Area</th>
            <th>Reviewer Notes</th>
            <th>Reviewed</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rejectedPhotos as $p): ?>
            <tr>
                <td data-label="Photo">
                    <a href="/REFO/user/photos/view.php?id=<?= (int)$p['photo_id'] ?>">
                        <img class="photo-thumb" src="<?= h(PHOTO_UPLOAD_URL . $p['file_name']) ?>" alt="Rejected photo thumbnail">
                    </a>
                </td>
                <td data-label="Area"><?= !empty($p['polygon_code']) ? h($p['polygon_code'] . ' - ' . $p['polygon_name']) : '<span class="hint">-</span>' ?></td>
                <td data-label="Reviewer Notes"><?= !empty($p['review_notes']) ? h($p['review_notes']) : '<span class="hint">No notes given</span>' ?></td>
                <td data-label="Reviewed"><?= $p['reviewed_at'] ? h(date('F j, Y g:i A', strtotime($p['reviewed_at']))) : '-' ?></td>
                <td class="actions-cell" data-label="Actions">
                    <?php if (!empty($p['polygon_id'])): ?>
                        <a href="/REFO/user/polygons/photos.php?id=<?= (int)$p['polygon_id'] ?>">Upload again</a>
                    <?php else: ?>
                        <a href="/REFO/user/photos/view.php?id=<?= (int)$p['photo_id'] ?>">View</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../dashboard_map_section.php'; ?>

<?php
$extraScripts = dashboardMapScripts($mapPayload, $photoMarkers, ['locked' => true]);
