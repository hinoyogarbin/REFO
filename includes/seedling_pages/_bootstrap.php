<?php
/**
 * Common bootstrap for the Admin / Manager seedling pages.
 *
 * admin/seedlings/* and manager/seedlings/* are thin wrappers that set
 * $seedlingRole and include a shared page from this folder, so the logic
 * is written once. Access control runs on every request, server-side:
 * a wrapper only lets in the role it belongs to.
 */

require_once __DIR__ . '/../auth_check.php';
requireLogin();

require_once __DIR__ . '/../role_check.php';
require_once __DIR__ . '/../seedling_functions.php';
require_once __DIR__ . '/../log_functions.php';

requireRole($seedlingRole ?? ['admin', 'manager']);

/** Current actor. */
$currentRole = (string)($_SESSION['role'] ?? '');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/** Base URL of this role's seedling pages, e.g. /REFO/admin/seedlings/ */
$seedlingBase = '/REFO/' . $currentRole . '/seedlings/';

$seedlingHead = '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=3">'
    . '<link rel="stylesheet" href="/REFO/assets/css/dashboard.css?v=2">'
    . '<link rel="stylesheet" href="/REFO/assets/css/seedlings.css?v=2">';

/**
 * Requests | Batches | Mortality tabs shown at the top of every page.
 */
function seedlingSubnav(string $base, string $active): string
{
    $tabs = [
        'requests'  => ['Requests', 'index.php'],
        'batches'   => ['Batches', 'batches.php'],
        'mortality' => ['Mortality', 'mortality.php'],
    ];

    $html = '<div class="sr-subnav">';
    foreach ($tabs as $key => [$label, $file]) {
        $html .= '<a class="' . ($key === $active ? 'active' : '') . '" href="'
            . h($base . $file) . '">' . h($label) . '</a>';
    }
    return $html . '</div>';
}

/**
 * Polygons for filter / form dropdowns.
 */
function seedlingPolygonChoices(): array
{
    try {
        return getPolygonsList();
    } catch (Throwable $e) {
        return [];
    }
}
