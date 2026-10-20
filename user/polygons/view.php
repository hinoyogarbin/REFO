<?php
require_once __DIR__ . '/../../includes/map_assets.php';
/**
 * Field User - view an assigned polygon boundary (read-only).
 *
 * Access is granted only when the polygon is actually assigned to the
 * logged-in account. Requesting another polygon's ID directly returns
 * the platform's Access Denied page rather than the record.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/polygon_functions.php';
require_once __DIR__ . '/../../includes/polygon_photo_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    header('Location: index.php');
    exit;
}

// A Field User may only open their own assigned area.
if (!polygonIsAssignedTo($polygon, $currentUserId)) {
    logActivity(
        $currentUserId,
        (string)($_SESSION['full_name'] ?? 'Unknown'),
        'user',
        'View Polygon',
        'CENRO Polygons',
        'Blocked attempt to open polygon ID ' . $polygonId . ' which is not assigned to this account.',
        'failed'
    );
    denyAccess();
}

$mapPayload = buildMapPayload([$polygon]);
$photoMarkers = getMappablePhotosForUser($currentUserId, [$polygonId]);

$pageTitle = 'Assigned Area';

$extraHead = leafletHeadTags()
    . '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=3">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1><?= h($polygon['polygon_name']) ?></h1>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Polygon</span>
        <span><?= h($polygon['polygon_code']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Project</span>
        <span><?= h($polygon['project_name'] ?? 'Not associated') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Location</span>
        <span><?= h($polygon['location'] ?? '-') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Area</span>
        <span><?= h(formatHectares($polygon['area_hectares'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Status</span>
        <span class="status-badge status-<?= h($polygon['status']) ?>">
            <?= h(polygonStatusLabel($polygon['status'])) ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Steward</span>
        <span><?= h($polygon['steward_name'] ?? '-') ?></span>
    </div>
</div>

<div class="dashboard-map-section polygon-map-section">
    <h2>Area Boundary</h2>
    <p class="dashboard-map-caption">
        Official CENRO boundary. This is the geographic area for your field activities.
    </p>
    <div id="polygon-map" class="dashboard-map"></div>
    <?= polygonPhotoLegendHtml() ?>
</div>

<div class="toolbar">
    <a class="btn btn-primary" href="photos.php?id=<?= (int)$polygon['polygon_id'] ?>">Upload Photos</a>
    <a class="btn" href="index.php">Back</a>
</div>

<?php
$extraScripts = leafletScriptTags()
    . '<script src="/REFO/assets/js/map_layers.js?v=6"></script><script src="/REFO/assets/js/polygon_map.js?v=7"></script>'
    . '<script src="/REFO/assets/js/polygon_photo_map.js?v=3"></script>'
        . '<script>initPolygonPhotoMap("polygon-map", ' . jsonForScript($mapPayload) . ', ' . jsonForScript($photoMarkers) . ', {locked: true});</script>';

require_once __DIR__ . '/../../includes/page_end.php';
?>
