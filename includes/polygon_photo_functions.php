<?php
/**
 * Polygon photos - helpers for uploading field photos from
 * "My Assigned Area".
 *
 * This file only ADDS to the existing photo code. Uploading itself is
 * still done by saveFieldPhotoUpload() in photo_functions.php; this
 * file links the saved photo to the polygon and reads the links back.
 *
 * Requires the field_photos.polygon_id column
 * (see database_polygon_photos.sql).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/photo_functions.php';

/**
 * True when field_photos.polygon_id exists (migration has been run).
 */
function polygonPhotoColumnExists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SHOW COLUMNS FROM field_photos LIKE 'polygon_id'");
        $exists = $stmt !== false && $stmt->fetch() !== false;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

/**
 * Links an already-saved photo to a polygon. Only touches a photo owned
 * by $userId.
 */
function linkPhotoToPolygon(int $photoId, int $polygonId, int $userId): void
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'UPDATE field_photos SET polygon_id = :polygon_id
         WHERE photo_id = :photo_id AND user_id = :user_id'
    );
    $stmt->execute([
        'polygon_id' => $polygonId,
        'photo_id'   => $photoId,
        'user_id'    => $userId,
    ]);
}

/**
 * The user's own photos uploaded for one polygon, newest first.
 */
function getFieldPhotosByUserAndPolygon(int $userId, int $polygonId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT * FROM field_photos
         WHERE user_id = :user_id AND polygon_id = :polygon_id
         ORDER BY uploaded_at DESC'
    );
    $stmt->execute(['user_id' => $userId, 'polygon_id' => $polygonId]);
    return $stmt->fetchAll();
}

/**
 * Where a photo's detail page lives (existing Field Photos page).
 */
const POLYGON_PHOTO_VIEW_URL = '/REFO/user/photos/view.php?id=';

/**
 * Photos that can be drawn as map markers: the user's own photos linked
 * to one of $polygonIds that carry GPS coordinates. Returned in the
 * shape assets/js/polygon_photo_map.js expects.
 *
 * Photos without GPS can't be placed, so they are left out (they still
 * appear in the photo list).
 */
function getMappablePhotosForUser(int $userId, array $polygonIds): array
{
    $polygonIds = array_values(array_filter(array_map('intval', $polygonIds)));
    if (!$polygonIds || !polygonPhotoColumnExists()) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($polygonIds), '?'));
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT photo_id, polygon_id, file_name, exif_latitude, exif_longitude,
                exif_datetime, status, uploaded_at
         FROM field_photos
         WHERE user_id = ?
           AND polygon_id IN ($placeholders)
           AND exif_latitude IS NOT NULL
           AND exif_longitude IS NOT NULL
         ORDER BY uploaded_at DESC"
    );
    $stmt->execute(array_merge([$userId], $polygonIds));

    $markers = [];
    foreach ($stmt->fetchAll() as $p) {
        $markers[] = photoRowToMarker($p, POLYGON_PHOTO_VIEW_URL);
    }

    return $markers;
}

/**
 * Turns a field_photos row (with GPS) into the marker shape that
 * assets/js/polygon_photo_map.js expects. $viewUrlBase is the detail page
 * for the person looking at the map, e.g. '/REFO/admin/photos/view.php?id='.
 */
function photoRowToMarker(array $p, string $viewUrlBase): array
{
    return [
        'id'         => (int)$p['photo_id'],
        'polygon_id' => (int)($p['polygon_id'] ?? 0),
        'lat'        => (float)$p['exif_latitude'],
        'lng'        => (float)$p['exif_longitude'],
        'thumb'      => PHOTO_UPLOAD_URL . $p['file_name'],
        'taken'      => $p['exif_datetime'] ? date('F j, Y g:i A', strtotime($p['exif_datetime'])) : '',
        'uploaded'   => date('F j, Y g:i A', strtotime($p['uploaded_at'])),
        'status'     => $p['status'],
        'label'      => ucfirst($p['status']),
        'url'        => $viewUrlBase . (int)$p['photo_id'],
    ];
}

/**
 * Small colour key shown under the maps.
 */
function polygonPhotoLegendHtml(): string
{
    return '<p class="photo-legend">Tree photo markers: '
        . '<span class="legend-dot legend-pending"></span> Pending '
        . '<span class="legend-dot legend-confirmed"></span> Confirmed '
        . '<span class="legend-dot legend-rejected"></span> Rejected</p>';
}


/**
 * Number of field photos waiting for an admin/manager decision.
 * Returns 0 instead of failing so it is safe to call from the page header.
 */
function countPendingFieldPhotos(): int
{
    try {
        $pdo = getDbConnection();
        return (int)$pdo->query("SELECT COUNT(*) FROM field_photos WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Which CENRO polygon each photo was uploaded for.
 *
 * @param  int[] $photoIds
 * @return array<int,string>  photo_id => "CODE - Name"
 */
function getPolygonLabelsForPhotos(array $photoIds): array
{
    $photoIds = array_values(array_filter(array_map('intval', $photoIds)));
    if (!$photoIds || !polygonPhotoColumnExists()) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT fp.photo_id, p.polygon_code, p.polygon_name
             FROM field_photos fp
             JOIN polygons p ON p.polygon_id = fp.polygon_id
             WHERE fp.photo_id IN ($placeholders)"
        );
        $stmt->execute($photoIds);

        $labels = [];
        foreach ($stmt->fetchAll() as $row) {
            $labels[(int)$row['photo_id']] = $row['polygon_code'] . ' - ' . $row['polygon_name'];
        }
        return $labels;
    } catch (Throwable $e) {
        return [];
    }
}
