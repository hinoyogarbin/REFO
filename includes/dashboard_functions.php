<?php
/**
 * Data for the Admin / Manager / Field User dashboards.
 *
 * Every function here is read-only and fails soft (returns zeros / empty
 * lists) so a missing table or column can never take the dashboard down.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/photo_functions.php';
require_once __DIR__ . '/polygon_photo_functions.php';

/**
 * CENRO polygon totals: total, assigned to a field user, unassigned.
 */
function getDashboardPolygonStats(): array
{
    $stats = ['total' => 0, 'assigned' => 0, 'unassigned' => 0];

    try {
        $pdo = getDbConnection();
        $row = $pdo->query(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(assigned_user_id IS NOT NULL), 0) AS assigned
             FROM polygons'
        )->fetch();

        $stats['total']      = (int)$row['total'];
        $stats['assigned']   = (int)$row['assigned'];
        $stats['unassigned'] = $stats['total'] - $stats['assigned'];
    } catch (Throwable $e) {
        // leave zeros
    }

    return $stats;
}

/**
 * Field photo counts by review status. Pass a user id for one person's
 * photos, or null for everyone's.
 */
function getFieldPhotoStatusCounts(?int $userId = null): array
{
    $counts = ['pending' => 0, 'confirmed' => 0, 'rejected' => 0, 'total' => 0];

    try {
        $pdo = getDbConnection();
        $sql = 'SELECT status, COUNT(*) AS n FROM field_photos';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' GROUP BY status';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['n'];
                $counts['total'] += (int)$row['n'];
            }
        }
    } catch (Throwable $e) {
        // leave zeros
    }

    return $counts;
}

/**
 * Newest uploads with uploader and area, for the dashboards.
 */
function getRecentFieldPhotos(int $limit = 5, ?int $userId = null): array
{
    try {
        $pdo = getDbConnection();
        $hasArea = polygonPhotoColumnExists();

        $sql = 'SELECT fp.photo_id, fp.file_name, fp.status, fp.uploaded_at, u.full_name AS uploader_name'
            . ($hasArea ? ', p.polygon_code, p.polygon_name' : '')
            . ' FROM field_photos fp JOIN users u ON u.user_id = fp.user_id'
            . ($hasArea ? ' LEFT JOIN polygons p ON p.polygon_id = fp.polygon_id' : '');

        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE fp.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY fp.uploaded_at DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * A field user's rejected photos, with the reviewer's notes, so they know
 * what to retake.
 */
function getRejectedFieldPhotosForUser(int $userId, int $limit = 10): array
{
    try {
        $pdo = getDbConnection();
        $hasArea = polygonPhotoColumnExists();

        $sql = 'SELECT fp.photo_id, fp.file_name, fp.review_notes, fp.reviewed_at'
            . ($hasArea ? ', p.polygon_id, p.polygon_code, p.polygon_name' : '')
            . ' FROM field_photos fp'
            . ($hasArea ? ' LEFT JOIN polygons p ON p.polygon_id = fp.polygon_id' : '')
            . " WHERE fp.user_id = :user_id AND fp.status = 'rejected'"
            . ' ORDER BY fp.reviewed_at DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * How many photos a user has uploaded per polygon: polygon_id => count.
 */
function getFieldPhotoCountsByPolygon(int $userId): array
{
    if (!polygonPhotoColumnExists()) {
        return [];
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT polygon_id, COUNT(*) AS n FROM field_photos
             WHERE user_id = :user_id AND polygon_id IS NOT NULL
             GROUP BY polygon_id'
        );
        $stmt->execute(['user_id' => $userId]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int)$row['polygon_id']] = (int)$row['n'];
        }
        return $counts;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Tree photo markers for a dashboard map: every photo that has GPS
 * (one person's, or everyone's when $userId is null), newest first, capped
 * so the page stays light. $viewUrlBase points at the viewer's own photo
 * detail page.
 */
function getDashboardPhotoMarkers(?int $userId, string $viewUrlBase, int $limit = 500): array
{
    try {
        $pdo = getDbConnection();

        $sql = 'SELECT photo_id, '
            . (polygonPhotoColumnExists() ? 'polygon_id' : 'NULL AS polygon_id')
            . ', file_name, exif_latitude, exif_longitude, exif_datetime, status, uploaded_at
               FROM field_photos
               WHERE exif_latitude IS NOT NULL AND exif_longitude IS NOT NULL';

        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY uploaded_at DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $markers = [];
        foreach ($stmt->fetchAll() as $row) {
            $markers[] = photoRowToMarker($row, $viewUrlBase);
        }
        return $markers;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Leaflet + shared map helpers + the call that draws the polygon
 * boundaries and photo markers on the dashboard map.
 */
function dashboardMapScripts(array $mapPayload, array $photoMarkers): string
{
    require_once __DIR__ . '/polygon_functions.php';

    return '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
        . '<script src="/REFO/assets/js/map_layers.js?v=3"></script>'
        . '<script src="/REFO/assets/js/polygon_map.js?v=1"></script>'
        . '<script src="/REFO/assets/js/polygon_photo_map.js?v=1"></script>'
        . '<script>initPolygonPhotoMap("dashboard-map", ' . jsonForScript($mapPayload)
        . ', ' . jsonForScript($photoMarkers) . ');</script>';
}

/**
 * Stylesheets for the dashboards (Leaflet, mobile layout, dashboard cards).
 */
function dashboardHead(): string
{
    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">'
        . '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=2">'
        . '<link rel="stylesheet" href="/REFO/assets/css/dashboard.css?v=1">';
}
