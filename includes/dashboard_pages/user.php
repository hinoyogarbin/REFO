<?php
/**
 * Body of the Field User dashboard.
 *
 * Leaves the map scripts in $extraScripts for includes/page_end.php.
 */

require_once __DIR__ . '/../polygon_functions.php';
require_once __DIR__ . '/../dashboard_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

try {
    $myPolygons = getPolygonsForFieldUser($currentUserId);
} catch (Throwable $e) {
    $myPolygons = [];
}

$photoCounts    = getFieldPhotoStatusCounts($currentUserId);
$photosByArea   = getFieldPhotoCountsByPolygon($currentUserId);
$rejectedPhotos = getRejectedFieldPhotosForUser($currentUserId);
$mapPayload     = buildMapPayload($myPolygons);
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

<h2 class="dash-heading">My Tree Photos</h2>
<div class="stat-grid">
    <a class="stat-card stat-pending" href="/REFO/user/photos/index.php">
        <span class="stat-number"><?= (int)$photoCounts['pending'] ?></span>
        <span class="stat-label">Pending review</span>
    </a>
    <a class="stat-card stat-confirmed" href="/REFO/user/photos/index.php">
        <span class="stat-number"><?= (int)$photoCounts['confirmed'] ?></span>
        <span class="stat-label">Confirmed</span>
    </a>
    <a class="stat-card stat-rejected" href="/REFO/user/photos/index.php">
        <span class="stat-number"><?= (int)$photoCounts['rejected'] ?></span>
        <span class="stat-label">Rejected</span>
    </a>
</div>

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

<div class="dashboard-map-section">
    <h2>My Reforestation Areas</h2>
    <p class="dashboard-map-caption">
        Your assigned area boundaries with the tree photos you uploaded. Tap a marker to see the photo.
    </p>
    <div id="dashboard-map" class="dashboard-map"></div>
    <?= polygonPhotoLegendHtml() ?>
</div>

<?php
$extraScripts = dashboardMapScripts($mapPayload, $photoMarkers);
