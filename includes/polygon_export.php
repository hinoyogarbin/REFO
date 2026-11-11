<?php
/**
 * CENRO Polygon export.
 *
 * Builds six files for a set of polygons:
 *
 *   <name>.shp   polygon geometry (EPSG:4326)
 *   <name>.shx   shapefile index
 *   <name>.dbf   attribute table (column names match what the importer auto-maps)
 *   <name>.prj   WGS 84 coordinate system
 *   <name>.xlsx  Excel workbook (polygon details + imported attributes)
 *   <name>.sql   MySQL script that restores the polygons into this platform's schema
 *
 * The first four files are the same set the Import Polygon page asks for, so they
 * can be imported straight back in. Pure PHP, no extensions required.
 */

require_once __DIR__ . '/polygon_functions.php';

/** WKT written to the .prj file. Exported geometry is always WGS 84 lat/lon. */
const POLYGON_EXPORT_PRJ_WKT =
    'GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",SPHEROID["WGS_1984",6378137.0,298.257223563]],'
    . 'PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]]';

// ============================================================
// Shapefile (.shp / .shx)
// ============================================================

/** Little-endian IEEE double. */
function polyExpDouble(float $value): string
{
    return pack('e', $value);
}

/**
 * Converts a stored GeoJSON geometry into shapefile parts.
 * Shapefile rule: outer rings run clockwise, holes counter-clockwise.
 *
 * @return array|null ['parts' => [ring, ...], 'bbox' => [minX, minY, maxX, maxY]] or null when unusable
 */
function polyExpShapeParts(?array $geometry): ?array
{
    if ($geometry === null) {
        return null;
    }

    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        $polygons = [$coordinates];
    } elseif ($type === 'MultiPolygon') {
        $polygons = $coordinates;
    } else {
        return null;
    }

    $parts = [];
    $minX = $minY = INF;
    $maxX = $maxY = -INF;

    foreach ($polygons as $polygonRings) {
        if (!is_array($polygonRings)) {
            continue;
        }

        foreach ($polygonRings as $ringIndex => $ring) {
            if (!is_array($ring)) {
                continue;
            }

            $clean = [];
            foreach ($ring as $point) {
                if (is_array($point) && isset($point[0], $point[1])
                    && is_numeric($point[0]) && is_numeric($point[1])) {
                    $clean[] = [(float)$point[0], (float)$point[1]];
                }
            }

            $clean = geoCloseRing($clean);
            if (count($clean) < 4) {
                continue;
            }

            $area = geoRingSignedArea($clean);
            if ($area == 0.0) {
                continue;
            }

            $isOuter = ($ringIndex === 0);
            // positive area = counter-clockwise
            if (($isOuter && $area > 0) || (!$isOuter && $area < 0)) {
                $clean = array_reverse($clean);
            }

            foreach ($clean as $point) {
                $minX = min($minX, $point[0]);
                $maxX = max($maxX, $point[0]);
                $minY = min($minY, $point[1]);
                $maxY = max($maxY, $point[1]);
            }

            $parts[] = $clean;
        }
    }

    if (empty($parts)) {
        return null;
    }

    return ['parts' => $parts, 'bbox' => [$minX, $minY, $maxX, $maxY]];
}

/**
 * Builds the .shp and .shx contents.
 *
 * @param array $shapes Output of polyExpShapeParts(), one per record, in order.
 * @return array ['shp' => string, 'shx' => string]
 */
function polyExpBuildShpShx(array $shapes): array
{
    $records = '';
    $index = '';
    $offsetWords = 50; // the 100-byte header

    $minX = $minY = INF;
    $maxX = $maxY = -INF;

    foreach ($shapes as $i => $shape) {
        $parts = $shape['parts'];
        [$bMinX, $bMinY, $bMaxX, $bMaxY] = $shape['bbox'];

        $minX = min($minX, $bMinX);
        $minY = min($minY, $bMinY);
        $maxX = max($maxX, $bMaxX);
        $maxY = max($maxY, $bMaxY);

        $numParts = count($parts);
        $numPoints = 0;
        $partStarts = [];
        foreach ($parts as $ring) {
            $partStarts[] = $numPoints;
            $numPoints += count($ring);
        }

        $content = pack('V', 5)
            . polyExpDouble($bMinX) . polyExpDouble($bMinY)
            . polyExpDouble($bMaxX) . polyExpDouble($bMaxY)
            . pack('V', $numParts)
            . pack('V', $numPoints);

        foreach ($partStarts as $start) {
            $content .= pack('V', $start);
        }

        foreach ($parts as $ring) {
            foreach ($ring as $point) {
                $content .= polyExpDouble($point[0]) . polyExpDouble($point[1]);
            }
        }

        $contentWords = intdiv(strlen($content), 2);

        $records .= pack('N', $i + 1) . pack('N', $contentWords) . $content;
        $index   .= pack('N', $offsetWords) . pack('N', $contentWords);

        $offsetWords += 4 + $contentWords; // 8-byte record header = 4 words
    }

    $header = static function (int $fileLengthWords) use ($minX, $minY, $maxX, $maxY): string {
        return pack('N', 9994)
            . str_repeat("\0", 20)
            . pack('N', $fileLengthWords)
            . pack('V', 1000)
            . pack('V', 5)
            . polyExpDouble($minX) . polyExpDouble($minY)
            . polyExpDouble($maxX) . polyExpDouble($maxY)
            . str_repeat("\0", 32); // Z and M ranges, unused
    };

    return [
        'shp' => $header(intdiv(100 + strlen($records), 2)) . $records,
        'shx' => $header(intdiv(100 + strlen($index), 2)) . $index,
    ];
}

// ============================================================
// dBASE attribute table (.dbf)
// ============================================================

/**
 * Column layout. Names are <= 10 characters and chosen so
 * suggestAttributeMapping() recognises them when the package is re-imported.
 */
function polyExpDbfFields(): array
{
    return [
        ['name' => 'POLYGON_ID', 'type' => 'C', 'size' => 50,  'dec' => 0],
        ['name' => 'AREA_NAME',  'type' => 'C', 'size' => 150, 'dec' => 0],
        ['name' => 'PROJECT',    'type' => 'C', 'size' => 150, 'dec' => 0],
        ['name' => 'LOCATION',   'type' => 'C', 'size' => 200, 'dec' => 0],
        ['name' => 'STEWARD',    'type' => 'C', 'size' => 150, 'dec' => 0],
        ['name' => 'STATUS',     'type' => 'C', 'size' => 10,  'dec' => 0],
        ['name' => 'AREA_HA',    'type' => 'N', 'size' => 18,  'dec' => 4],
        ['name' => 'REMARKS',    'type' => 'C', 'size' => 254, 'dec' => 0],
    ];
}

/** One DBF character value, cut to $size bytes without splitting a UTF-8 character. */
function polyExpDbfText(?string $value, int $size): string
{
    $value = (string)$value;
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);

    if (strlen($value) > $size) {
        $value = function_exists('mb_strcut')
            ? mb_strcut($value, 0, $size, 'UTF-8')
            : substr($value, 0, $size);
    }

    return str_pad($value, $size, ' ', STR_PAD_RIGHT);
}

/**
 * Builds the .dbf contents, one record per polygon row (same order as the .shp).
 */
function polyExpBuildDbf(array $polygons): string
{
    $fields = polyExpDbfFields();

    $recordLength = 1;
    foreach ($fields as $field) {
        $recordLength += $field['size'];
    }

    $headerLength = 32 + (32 * count($fields)) + 1;

    $header = chr(0x03)
        . chr((int)date('Y') - 1900) . chr((int)date('n')) . chr((int)date('j'))
        . pack('V', count($polygons))
        . pack('v', $headerLength)
        . pack('v', $recordLength)
        . str_repeat("\0", 20);

    foreach ($fields as $field) {
        $header .= str_pad($field['name'], 11, "\0")
            . $field['type']
            . str_repeat("\0", 4)
            . chr($field['size'])
            . chr($field['dec'])
            . str_repeat("\0", 14);
    }
    $header .= "\x0D";

    $body = '';
    foreach ($polygons as $polygon) {
        $areaHa = $polygon['area_hectares'] ?? null;
        $areaCell = is_numeric($areaHa)
            ? str_pad(number_format((float)$areaHa, 4, '.', ''), 18, ' ', STR_PAD_LEFT)
            : str_repeat(' ', 18);

        $body .= ' '
            . polyExpDbfText($polygon['polygon_code'] ?? '', 50)
            . polyExpDbfText($polygon['polygon_name'] ?? '', 150)
            . polyExpDbfText($polygon['project_name'] ?? '', 150)
            . polyExpDbfText($polygon['location'] ?? '', 200)
            . polyExpDbfText($polygon['steward_name'] ?? '', 150)
            . polyExpDbfText($polygon['status'] ?? '', 10)
            . $areaCell
            . polyExpDbfText($polygon['remarks'] ?? '', 254);
    }

    return $header . $body . "\x1A";
}

// ============================================================
// Excel workbook (.xlsx)
// ============================================================

/** Column index (0-based) to Excel letters. */
function polyExpColumnLetters(int $index): string
{
    $letters = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv($index - 1, 26);
    }
    return $letters;
}

/** Escapes text for XML and strips characters XML 1.0 cannot hold. */
function polyExpXml(string $text): string
{
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    if (strlen($text) > 32000) {
        $text = function_exists('mb_strcut') ? mb_strcut($text, 0, 32000, 'UTF-8') : substr($text, 0, 32000);
    }
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * One worksheet as XML. Text goes in as inline strings, so Excel never
 * interprets a value such as "=1+1" as a formula.
 *
 * @param string[] $headers
 * @param array[]  $rows     Each row is a list of int|float|string|null cells.
 * @param int[]    $widths   Column widths in characters.
 */
function polyExpSheetXml(array $headers, array $rows, array $widths): string
{
    $columnCount = count($headers);
    $lastColumn = polyExpColumnLetters(max(0, $columnCount - 1));
    $lastRow = count($rows) + 1;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>';

    foreach ($widths as $i => $width) {
        $n = $i + 1;
        $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . (int)$width . '" customWidth="1"/>';
    }

    $xml .= '</cols><sheetData>';

    // Header row
    $xml .= '<row r="1">';
    foreach ($headers as $c => $title) {
        $xml .= '<c r="' . polyExpColumnLetters($c) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">'
            . polyExpXml((string)$title) . '</t></is></c>';
    }
    $xml .= '</row>';

    // Data rows
    foreach ($rows as $r => $row) {
        $rowNumber = $r + 2;
        $xml .= '<row r="' . $rowNumber . '">';

        foreach ($row as $c => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $ref = polyExpColumnLetters($c) . $rowNumber;

            if (is_int($value)) {
                $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } elseif (is_float($value)) {
                $xml .= '<c r="' . $ref . '"><v>' . rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . polyExpXml((string)$value) . '</t></is></c>';
            }
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '</worksheet>';

    return $xml;
}

/** Number or null from a database decimal string. */
function polyExpNumber($value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null;
}

/**
 * Builds the .xlsx file and returns its bytes.
 *
 * @throws RuntimeException when the zip extension is missing or the file cannot be written
 */
function polyExpBuildXlsx(array $polygons): string
{
    $summaryHeaders = [
        'Polygon ID', 'Polygon Name', 'Project', 'Location', 'Steward', 'Steward Username',
        'Status', 'Area (ha, calculated)', 'Area (sqm, calculated)',
        'Area (from CENRO file)', 'CENRO Area Unit', 'Geometry Type',
        'Centroid Latitude', 'Centroid Longitude',
        'Source File', 'Source Format', 'Coordinate System',
        'Date Imported', 'Date Updated', 'Remarks',
    ];
    $summaryWidths = [16, 28, 26, 26, 24, 18, 12, 18, 20, 20, 14, 16, 16, 16, 30, 14, 36, 20, 20, 40];

    $summaryRows = [];
    $attributeRows = [];

    foreach ($polygons as $polygon) {
        $summaryRows[] = [
            (string)$polygon['polygon_code'],
            (string)$polygon['polygon_name'],
            $polygon['project_name'] ?? null,
            $polygon['location'] ?? null,
            $polygon['steward_name'] ?? null,
            $polygon['steward_username'] ?? null,
            polygonStatusLabel((string)$polygon['status']),
            polyExpNumber($polygon['area_hectares'] ?? null),
            polyExpNumber($polygon['area_sqm'] ?? null),
            polyExpNumber($polygon['original_area_value'] ?? null),
            $polygon['original_area_unit'] ?? null,
            $polygon['geometry_type'] ?? null,
            polyExpNumber($polygon['centroid_lat'] ?? null),
            polyExpNumber($polygon['centroid_lng'] ?? null),
            $polygon['source_file'] ?? null,
            isset($polygon['source_format']) ? ucfirst((string)$polygon['source_format']) : null,
            $polygon['source_crs'] ?? null,
            $polygon['date_imported'] ?? null,
            $polygon['date_updated'] ?? null,
            $polygon['remarks'] ?? null,
        ];

        foreach (decodeStoredAttributes($polygon['original_attributes'] ?? null) as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $attributeRows[] = [(string)$polygon['polygon_code'], (string)$key, $value];
        }
    }

    $sheet1 = polyExpSheetXml($summaryHeaders, $summaryRows, $summaryWidths);
    $sheet2 = polyExpSheetXml(['Polygon ID', 'Attribute', 'Value'], $attributeRows, [16, 28, 50]);

    $files = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Polygons" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Imported Attributes" sheetId="2" r:id="rId2"/>'
            . '</sheets></workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE9D5"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',

        'xl/worksheets/sheet1.xml' => $sheet1,
        'xl/worksheets/sheet2.xml' => $sheet2,
    ];

    return polyExpZip($files);
}

// ============================================================
// SQL script (.sql)
// ============================================================

/** Quotes a value as a MySQL string literal, or NULL. */
function polyExpSqlString(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $escaped = str_replace(
        ['\\', "'", "\0", "\n", "\r", "\x1a"],
        ['\\\\', "\\'", '\\0', '\\n', '\\r', '\\Z'],
        $value
    );

    return "'" . $escaped . "'";
}

/** A numeric literal from a database decimal, or NULL. */
function polyExpSqlNumber($value): string
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (string)(0 + $value) : 'NULL';
}

/**
 * Builds a script that re-creates the polygons in a database that already has this
 * platform's schema (database.sql). Safe to run again: polygons are matched on
 * polygon_code and updated instead of duplicated. Projects and stewards are looked
 * up on the target server (project by name, steward by username), and left empty if
 * they do not exist there.
 */
function polyExpBuildSql(array $polygons): string
{
    $sql = "-- CENRO polygon export\n"
        . '-- Generated: ' . date('Y-m-d H:i:s') . "\n"
        . '-- Polygons: ' . count($polygons) . "\n"
        . "--\n"
        . "-- Run against a database created from database.sql.\n"
        . "-- Re-running is safe: polygons are matched on polygon_code and updated.\n"
        . "-- Projects are matched by name and stewards by username on the target server.\n\n"
        . "SET NAMES utf8mb4;\n"
        . "USE `" . DB_NAME . "`;\n\n";

    // Projects the polygons point to, created only when missing.
    $projectsDone = [];
    foreach ($polygons as $polygon) {
        $projectId = (int)($polygon['project_id'] ?? 0);
        if ($projectId <= 0 || isset($projectsDone[$projectId])) {
            continue;
        }
        $projectsDone[$projectId] = true;

        $project = findProjectById($projectId);
        if ($project === null) {
            continue;
        }

        $name = polyExpSqlString((string)$project['project_name']);
        $sql .= "INSERT IGNORE INTO projects (project_code, project_name, location, status)\n"
            . 'SELECT ' . polyExpSqlString($project['project_code'] ?? null) . ', ' . $name . ', '
            . polyExpSqlString($project['location'] ?? null) . ', ' . polyExpSqlString((string)$project['status']) . "\n"
            . 'WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = ' . $name . ");\n";
    }
    if (!empty($projectsDone)) {
        $sql .= "\n";
    }

    $columns = [
        'polygon_code', 'polygon_name', 'project_id', 'location', 'assigned_user_id', 'status', 'remarks',
        'geometry', 'geometry_type', 'geometry_hash',
        'original_geometry', 'original_attributes', 'original_area_value', 'original_area_unit',
        'area_sqm', 'area_hectares',
        'centroid_lat', 'centroid_lng', 'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng',
        'source_file', 'source_format', 'source_crs', 'source_hash', 'feature_index', 'date_imported',
    ];

    // Columns refreshed when the polygon already exists.
    $updateColumns = array_diff($columns, ['polygon_code', 'date_imported']);

    foreach ($polygons as $polygon) {
        $projectName = $polygon['project_name'] ?? null;
        $projectSql = $projectName !== null
            ? '(SELECT project_id FROM projects WHERE project_name = ' . polyExpSqlString((string)$projectName)
                . ' ORDER BY project_id LIMIT 1)'
            : 'NULL';

        $stewardUsername = $polygon['steward_username'] ?? null;
        $stewardSql = $stewardUsername !== null
            ? '(SELECT user_id FROM users WHERE username = ' . polyExpSqlString((string)$stewardUsername) . ' LIMIT 1)'
            : 'NULL';

        $values = [
            'polygon_code'        => polyExpSqlString((string)$polygon['polygon_code']),
            'polygon_name'        => polyExpSqlString((string)$polygon['polygon_name']),
            'project_id'          => $projectSql,
            'location'            => polyExpSqlString($polygon['location'] ?? null),
            'assigned_user_id'    => $stewardSql,
            'status'              => polyExpSqlString((string)$polygon['status']),
            'remarks'             => polyExpSqlString($polygon['remarks'] ?? null),
            'geometry'            => polyExpSqlString((string)$polygon['geometry']),
            'geometry_type'       => polyExpSqlString((string)$polygon['geometry_type']),
            'geometry_hash'       => polyExpSqlString((string)$polygon['geometry_hash']),
            'original_geometry'   => polyExpSqlString($polygon['original_geometry'] ?? null),
            'original_attributes' => polyExpSqlString($polygon['original_attributes'] ?? null),
            'original_area_value' => polyExpSqlNumber($polygon['original_area_value'] ?? null),
            'original_area_unit'  => polyExpSqlString($polygon['original_area_unit'] ?? null),
            'area_sqm'            => polyExpSqlNumber($polygon['area_sqm'] ?? 0),
            'area_hectares'       => polyExpSqlNumber($polygon['area_hectares'] ?? 0),
            'centroid_lat'        => polyExpSqlNumber($polygon['centroid_lat'] ?? null),
            'centroid_lng'        => polyExpSqlNumber($polygon['centroid_lng'] ?? null),
            'bbox_min_lat'        => polyExpSqlNumber($polygon['bbox_min_lat'] ?? null),
            'bbox_min_lng'        => polyExpSqlNumber($polygon['bbox_min_lng'] ?? null),
            'bbox_max_lat'        => polyExpSqlNumber($polygon['bbox_max_lat'] ?? null),
            'bbox_max_lng'        => polyExpSqlNumber($polygon['bbox_max_lng'] ?? null),
            'source_file'         => polyExpSqlString($polygon['source_file'] ?? null),
            'source_format'       => polyExpSqlString((string)$polygon['source_format']),
            'source_crs'          => polyExpSqlString($polygon['source_crs'] ?? null),
            'source_hash'         => polyExpSqlString($polygon['source_hash'] ?? null),
            'feature_index'       => (string)(int)($polygon['feature_index'] ?? 0),
            'date_imported'       => polyExpSqlString($polygon['date_imported'] ?? null),
        ];

        $updates = [];
        foreach ($updateColumns as $column) {
            $updates[] = $column . ' = VALUES(' . $column . ')';
        }

        $sql .= '-- ' . str_replace(["\r", "\n"], ' ', (string)$polygon['polygon_code']) . "\n"
            . 'INSERT INTO polygons (' . implode(', ', $columns) . ")\nVALUES (\n    "
            . implode(",\n    ", array_map(
                function (string $column) use ($values): string {
                    return $values[$column];
                },
                $columns
            ))
            . "\n)\nON DUPLICATE KEY UPDATE\n    " . implode(",\n    ", $updates) . ";\n\n";
    }

    return $sql;
}

// ============================================================
// Packaging
// ============================================================

/**
 * Zips a name => contents map and returns the zip bytes.
 *
 * Written by hand (stored entries, no compression) so no PHP extension is
 * needed - an .xlsx is just a zip container and these files are small.
 */
function polyExpZip(array $files): string
{
    $now = getdate();
    $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
    $dosDate = (max(0, $now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

    $body = '';
    $central = '';
    $count = 0;

    foreach ($files as $name => $contents) {
        $name = (string)$name;
        $contents = (string)$contents;
        $crc = crc32($contents);
        $size = strlen($contents);
        $offset = strlen($body);

        $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0)
            . $name . $contents;

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, 0, $offset)
            . $name;

        $count++;
    }

    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);

    return $body . $central . $end;
}

/**
 * Builds the six export files.
 *
 * @param array  $polygons Rows from findPolygonById()/getPolygonsList().
 * @param string $baseName File name (without extension) used for every file.
 * @return array ['files' => ext => contents, 'exported' => int, 'skipped' => string[]]
 *               skipped lists polygon codes whose boundary could not be written to the shapefile.
 * @throws RuntimeException
 */
function buildPolygonExportFiles(array $polygons, string $baseName): array
{
    $shapes = [];
    $shapeRows = [];
    $skipped = [];

    foreach ($polygons as $polygon) {
        $shape = polyExpShapeParts(decodeStoredGeometry($polygon['geometry'] ?? null));

        if ($shape === null) {
            $skipped[] = (string)$polygon['polygon_code'];
            continue;
        }

        $shapes[] = $shape;
        $shapeRows[] = $polygon;
    }

    if (empty($shapes)) {
        throw new RuntimeException('None of the selected polygons has a boundary that can be exported.');
    }

    $shpShx = polyExpBuildShpShx($shapes);

    return [
        'files' => [
            'shp'  => $shpShx['shp'],
            'shx'  => $shpShx['shx'],
            'dbf'  => polyExpBuildDbf($shapeRows),
            'prj'  => POLYGON_EXPORT_PRJ_WKT,
            'xlsx' => polyExpBuildXlsx($polygons),
            'sql'  => polyExpBuildSql($polygons),
        ],
        'exported' => count($polygons),
        'skipped'  => $skipped,
    ];
}

/** Content-Type for each export extension. */
function polyExpMimeType(string $ext): string
{
    switch ($ext) {
        case 'xlsx':
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        case 'sql':
            return 'application/sql; charset=utf-8';
        case 'prj':
            return 'text/plain; charset=utf-8';
        default:
            return 'application/octet-stream';
    }
}

/** File-name-safe version of a label. */
function polyExpSafeName(string $label): string
{
    $label = preg_replace('/[^A-Za-z0-9._-]+/', '_', $label) ?? '';
    $label = trim($label, '._-');
    return $label !== '' ? substr($label, 0, 60) : 'polygon';
}
