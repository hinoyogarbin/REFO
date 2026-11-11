<?php
/**
 * CENRO Polygon Management - export (Admin and Manager).
 *
 * GET export.php?file=shp&id=12&stamp=20261112_103000
 * GET export.php?file=dbf&search=..&status=..&project=..&stamp=...
 *
 * Returns ONE file per request (shp, shx, dbf, prj, xlsx or sql). The Export
 * button (assets/js/polygon_export.js) requests all six with the same stamp
 * and saves them side by side in a folder the operator picks.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../polygon_export.php';

const POLYGON_EXPORT_EXTENSIONS = ['shp', 'shx', 'dbf', 'prj', 'xlsx', 'sql'];

/** Plain-text error for the export script (it shows the message to the operator). */
function polygonExportFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$ext = strtolower((string)($_GET['file'] ?? ''));
if (!in_array($ext, POLYGON_EXPORT_EXTENSIONS, true)) {
    polygonExportFail(400, 'Unknown export file type.');
}

$stamp = (string)($_GET['stamp'] ?? '');
if (!preg_match('/^\d{8}_\d{6}$/', $stamp)) {
    $stamp = date('Ymd_His');
}

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($polygonId > 0) {
    $polygon = findPolygonById($polygonId);
    $polygons = $polygon !== null ? [$polygon] : [];
    $baseName = $polygon !== null ? polyExpSafeName((string)$polygon['polygon_code']) : 'polygon';
} else {
    $search        = trim($_GET['search'] ?? '');
    $statusFilter  = $_GET['status'] ?? '';
    $projectFilter = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;

    $polygons = getPolygonsList($search, $statusFilter, $projectFilter);
    $baseName = 'cenro_polygons';
}

if (empty($polygons)) {
    polygonExportFail(404, 'There are no polygons to export.');
}

$baseName .= '_' . $stamp;

try {
    $export = buildPolygonExportFiles($polygons, $baseName);
} catch (Throwable $e) {
    error_log('Polygon export failed: ' . $e->getMessage());
    if ($ext === 'shp') {
        logPolygonActivity('Export Polygons', 'Export failed: ' . $e->getMessage(), 'failed');
    }
    polygonExportFail(500, 'The export could not be created. ' . $e->getMessage());
}

// The six requests of one export share a stamp; log once, on the first.
if ($ext === 'shp') {
    logPolygonActivity(
        'Export Polygons',
        'Exported ' . $export['exported'] . ' polygon(s) as ' . $baseName . ' (.shp .shx .dbf .prj .xlsx .sql)'
        . (empty($export['skipped']) ? '' : ' - no shapefile boundary for: ' . implode(', ', $export['skipped'])),
        empty($export['skipped']) ? 'success' : 'warning'
    );
}

$contents = $export['files'][$ext];

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . polyExpMimeType($ext));
header('Content-Disposition: attachment; filename="' . $baseName . '.' . $ext . '"');
header('Content-Length: ' . strlen($contents));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

echo $contents;
exit;
