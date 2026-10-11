<?php
/**
 * Shared body of the Admin and Manager dashboards.
 *
 * Expects $role ('admin' or 'manager'), set by the dashboard page, and
 * leaves the map scripts in $extraScripts for includes/page_end.php.
 */

require_once __DIR__ . '/../polygon_functions.php';
require_once __DIR__ . '/../dashboard_functions.php';

$photosBase   = "/REFO/{$role}/photos/";
$pendingUrl   = $photosBase . 'index.php?status=pending';
$polygonsUrl  = "/REFO/{$role}/polygons/index.php";

$polygonStats = getDashboardPolygonStats();
$photoCounts  = getFieldPhotoStatusCounts();
$recentPhotos = getRecentFieldPhotos(5);
$areaLabels   = getPolygonLabelsForPhotos(array_column($recentPhotos, 'photo_id'));

try {
    $mapPayload = buildMapPayload(getPolygonsList());
} catch (Throwable $e) {
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

<h2 class="dash-heading">CENRO Polygons</h2>
<div class="stat-grid">
    <a class="stat-card" href="<?= h($polygonsUrl) ?>">
        <span class="stat-number"><?= (int)$polygonStats['total'] ?></span>
        <span class="stat-label">Total polygons</span>
    </a>
    <a class="stat-card" href="<?= h($polygonsUrl) ?>">
        <span class="stat-number"><?= (int)$polygonStats['assigned'] ?></span>
        <span class="stat-label">Assigned to a field user</span>
    </a>
    <a class="stat-card" href="<?= h($polygonsUrl) ?>">
        <span class="stat-number"><?= (int)$polygonStats['unassigned'] ?></span>
        <span class="stat-label">Unassigned</span>
    </a>
</div>

<h2 class="dash-heading">Tree Photos</h2>
<div class="stat-grid">
    <a class="stat-card stat-pending" href="<?= h($photosBase) ?>index.php?status=pending">
        <span class="stat-number"><?= (int)$photoCounts['pending'] ?></span>
        <span class="stat-label">Pending</span>
    </a>
    <a class="stat-card stat-confirmed" href="<?= h($photosBase) ?>index.php?status=confirmed">
        <span class="stat-number"><?= (int)$photoCounts['confirmed'] ?></span>
        <span class="stat-label">Confirmed</span>
    </a>
    <a class="stat-card stat-rejected" href="<?= h($photosBase) ?>index.php?status=rejected">
        <span class="stat-number"><?= (int)$photoCounts['rejected'] ?></span>
        <span class="stat-label">Rejected</span>
    </a>
</div>

<div class="dashboard-map-section">
    <h2>Reforestation Areas</h2>
    <p class="dashboard-map-caption">
        All CENRO polygon boundaries with the tree photos uploaded by field users.
        Tap a marker to see the photo.
    </p>
    <div id="dashboard-map" class="dashboard-map"></div>
    <?= polygonPhotoLegendHtml() ?>
</div>

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
$extraScripts = dashboardMapScripts($mapPayload, $photoMarkers);
