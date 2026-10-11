<?php
/**
 * Field User - upload photos for an assigned area.
 *
 * Only available for a polygon assigned to the logged-in account.
 * Uses the same upload + EXIF handling as Field Photos
 * (saveFieldPhotoUpload()), then links the photo to the polygon.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/polygon_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';
require_once __DIR__ . '/../../includes/polygon_photo_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    header('Location: index.php');
    exit;
}

// A Field User may only upload to their own assigned area.
if (!polygonIsAssignedTo($polygon, $currentUserId)) {
    logActivity(
        $currentUserId,
        (string)($_SESSION['full_name'] ?? 'Unknown'),
        'user',
        'Upload Photo',
        'CENRO Polygons',
        'Blocked photo upload for polygon ID ' . $polygonId . ' which is not assigned to this account.',
        'failed'
    );
    denyAccess();
}

$columnReady = polygonPhotoColumnExists();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$columnReady) {
        $error = 'Photo uploads for assigned areas are not set up yet. '
            . 'Ask your administrator to run database_polygon_photos.sql.';
    } else {
        $photoId = 0;
        try {
            $clientExif = [
                'latitude'  => $_POST['exif_latitude'] ?? '',
                'longitude' => $_POST['exif_longitude'] ?? '',
                'datetime'  => $_POST['exif_datetime'] ?? '',
                'make'      => $_POST['exif_make'] ?? '',
                'model'     => $_POST['exif_model'] ?? '',
            ];

            $photoId = saveFieldPhotoUpload($currentUserId, $_FILES['photo'] ?? [], $clientExif);
            linkPhotoToPolygon($photoId, $polygonId, $currentUserId);

            logActivity(
                $currentUserId,
                (string)$_SESSION['full_name'],
                (string)$_SESSION['role'],
                'Upload Photo',
                'CENRO Polygons',
                "Uploaded field photo #{$photoId} for polygon {$polygon['polygon_code']}.",
                'success'
            );

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Photo uploaded and processed successfully.',
            ];
            header('Location: photos.php?id=' . $polygonId . '&new=' . $photoId);
            exit;
        } catch (Throwable $e) {
            // Don't leave an unlinked photo behind if linking failed.
            if ($photoId > 0) {
                $orphan = findFieldPhotoById($photoId);
                if ($orphan) {
                    deleteFieldPhoto($orphan);
                }
            }

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The photo could not be saved. Please try again.';

            logActivity(
                $currentUserId,
                (string)$_SESSION['full_name'],
                (string)$_SESSION['role'],
                'Upload Photo',
                'CENRO Polygons',
                $e->getMessage(),
                'failed'
            );
        }
    }
}

$photos = $columnReady ? getFieldPhotosByUserAndPolygon($currentUserId, $polygonId) : [];
$photoMarkers = getMappablePhotosForUser($currentUserId, [$polygonId]);
$mapPayload = buildMapPayload([$polygon]);
$highlightId = isset($_GET['new']) ? (int)$_GET['new'] : 0;
$unmappedCount = count($photos) - count($photoMarkers);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Upload Photos - ' . $polygon['polygon_name'];

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">'
    . '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=2">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Upload Photos</h1>

<p class="hint">
    Area: <strong><?= h($polygon['polygon_code']) ?></strong> &mdash; <?= h($polygon['polygon_name']) ?>
</p>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$columnReady): ?>
    <div class="alert alert-warning">
        Photo uploads for assigned areas are not set up yet. Ask your administrator
        to run <code>database_polygon_photos.sql</code>.
    </div>
<?php else: ?>

<form action="photos.php?id=<?= (int)$polygonId ?>" method="post" enctype="multipart/form-data" class="form" id="photo-form">

    <label for="photo">Photo</label>
    <input type="file" id="photo" name="photo" required>

    <p class="hint">
        Select the original photo from your phone.
        The system will read the GPS location, date/time,
        and camera information from the photo metadata and place a
        marker for this tree on the map below. Photos without GPS
        cannot be placed on the map.
    </p>

    <input type="hidden" name="exif_latitude" id="exif_latitude">
    <input type="hidden" name="exif_longitude" id="exif_longitude">
    <input type="hidden" name="exif_datetime" id="exif_datetime">
    <input type="hidden" name="exif_make" id="exif_make">
    <input type="hidden" name="exif_model" id="exif_model">

    <p class="hint" id="exif-preview" style="display: none; white-space: pre-line;"></p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="upload-button" disabled>Upload &amp; Process</button>
        <a class="btn" href="index.php">Back</a>
    </div>
</form>

<?php endif; ?>

<hr class="divider">

<div class="dashboard-map-section">
    <h2>Tree Map</h2>
    <p class="dashboard-map-caption">
        Each marker is a tree photo you uploaded for this area. Tap a marker to see the photo.
    </p>
    <div id="polygon-map" class="dashboard-map"></div>
    <?= polygonPhotoLegendHtml() ?>
    <?php if ($unmappedCount > 0): ?>
        <p class="hint">
            <?= (int)$unmappedCount ?> photo<?= $unmappedCount === 1 ? '' : 's' ?> in the list below
            ha<?= $unmappedCount === 1 ? 's' : 've' ?> no GPS location, so
            <?= $unmappedCount === 1 ? 'it is' : 'they are' ?> not shown on the map.
        </p>
    <?php endif; ?>
</div>

<hr class="divider">

<h2>My Photos for This Area</h2>

<table class="data-table photo-table">
    <thead>
    <tr>
        <th>Photo</th>
        <th>Taken (EXIF)</th>
        <th>Camera</th>
        <th>GPS (EXIF)</th>
        <th>Review Status</th>
        <th>Uploaded</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($photos)): ?>
        <tr>
            <td colspan="7" class="empty-row">No photos uploaded for this area yet.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($photos as $p): ?>
            <tr>
                <td data-label="Photo">
                    <a href="../photos/view.php?id=<?= (int)$p['photo_id'] ?>">
                        <img class="photo-thumb" src="<?= h(PHOTO_UPLOAD_URL . $p['file_name']) ?>" alt="Field photo thumbnail">
                    </a>
                </td>
                <td data-label="Taken"><?= $p['exif_datetime'] ? h(date('F j, Y g:i A', strtotime($p['exif_datetime']))) : '<span class="hint">Not available</span>' ?></td>
                <td data-label="Camera"><?= h(trim(($p['camera_make'] ?? '') . ' ' . ($p['camera_model'] ?? '')) ?: '-') ?></td>
                <td data-label="GPS"><?= $p['exif_latitude'] !== null ? h(round($p['exif_latitude'], 5) . ', ' . round($p['exif_longitude'], 5)) : '<span class="hint">Not available (no marker)</span>' ?></td>
                <td data-label="Status"><span class="status-badge review-<?= h($p['status']) ?>"><?= h(ucfirst($p['status'])) ?></span></td>
                <td data-label="Uploaded"><?= h(date('F j, Y g:i A', strtotime($p['uploaded_at']))) ?></td>
                <td class="actions-cell" data-label="Actions"><a href="../photos/view.php?id=<?= (int)$p['photo_id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div class="toolbar">
    <a class="btn" href="index.php">Back to My Assigned Area</a>
</div>

<?php
$extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
    . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
    . '<script src="/REFO/assets/js/polygon_map.js?v=1"></script>'
    . '<script src="/REFO/assets/js/polygon_photo_map.js?v=1"></script>'
    . '<script>initPolygonPhotoMap("polygon-map", ' . jsonForScript($mapPayload) . ', '
    . jsonForScript($photoMarkers) . ', { highlightId: ' . (int)$highlightId . ' });</script>';

if ($columnReady) {
    $extraScripts .= '<script src="https://cdn.jsdelivr.net/npm/exif-js"></script><script>
(function () {
    var input = document.getElementById("photo");
    var preview = document.getElementById("exif-preview");
    var button = document.getElementById("upload-button");
    var ids = ["exif_latitude", "exif_longitude", "exif_datetime", "exif_make", "exif_model"];

    function setField(id, value) { document.getElementById(id).value = value; }

    input.addEventListener("change", function () {
        var file = this.files[0];
        ids.forEach(function (id) { setField(id, ""); });
        button.disabled = true;

        if (!file) { preview.style.display = "none"; return; }

        preview.style.display = "block";
        preview.textContent = "Reading photo metadata...";

        if (typeof EXIF === "undefined") {
            preview.textContent = "EXIF.js could not be loaded.\n\nThe server will try to read the metadata instead.";
            button.disabled = false;
            return;
        }

        EXIF.getData(file, function () {
            var d = file.exifdata || {};
            var gpsLat = d.GPSLatitude, gpsLon = d.GPSLongitude;
            var dateTime = d.DateTimeOriginal || d.DateTime || "";
            var make = d.Make || "", model = d.Model || "";

            setField("exif_datetime", dateTime);
            setField("exif_make", make);
            setField("exif_model", model);

            if (gpsLat && gpsLon) {
                var lat = gpsLat[0] + gpsLat[1] / 60 + gpsLat[2] / 3600;
                var lon = gpsLon[0] + gpsLon[1] / 60 + gpsLon[2] / 3600;
                if (String(d.GPSLatitudeRef).toUpperCase() === "S") { lat = -lat; }
                if (String(d.GPSLongitudeRef).toUpperCase() === "W") { lon = -lon; }
                if (isFinite(lat) && isFinite(lon)) {
                    setField("exif_latitude", lat);
                    setField("exif_longitude", lon);
                    preview.textContent = "GPS DETECTED\n\nLatitude: " + lat + "\nLongitude: " + lon +
                        "\n\nDate: " + dateTime + "\n\nCamera: " + make + " " + model;
                } else {
                    // Some phones write GPS values the browser cannot calculate (NaN).
                    // Leave the fields empty; the server reads the location from the file itself.
                    preview.textContent = "GPS FOUND, but this browser could not read the numbers.\n\n" +
                        "The server will read the location from the photo when you upload.\n\n" +
                        "Date: " + dateTime + "\n\nCamera: " + make + " " + model;
                }
            } else {
                preview.textContent = "NO GPS FOUND\n\nThis photo has no GPS location, so no marker will be placed on the map. Take the photo with location services on and pick the original file.";
            }

            button.disabled = false;
        });
    });
}());
</script>';
}

require_once __DIR__ . '/../../includes/page_end.php';
?>
