<?php
/**
 * Shared page header + top navigation bar.
 * Expects $pageTitle to be set before including this file.
 * Requires auth_check.php to already have run (so $_SESSION is available).
 */
$pageTitle = $pageTitle ?? 'Reforestation Management Platform';
$role = $_SESSION['role'] ?? '';
$fullName = $_SESSION['full_name'] ?? '';

// Auto-detect the active nav item from the current script path,
// so individual pages don't need to set anything extra.
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isDashboard = (strpos($currentScript, 'dashboard.php') !== false);
$isUsers     = (strpos($currentScript, '/users/') !== false);
$isPolygons  = (strpos($currentScript, '/polygons/') !== false);
$isLogs      = (strpos($currentScript, 'logs.php') !== false);
$isPhotos    = (strpos($currentScript, '/photos/') !== false);
$isSeedlings = (strpos($currentScript, '/seedlings/') !== false);

$dashboardUrl = "/REFO/{$role}/dashboard.php";
$usersUrl = "/REFO/{$role}/users/index.php";
$polygonsUrl = "/REFO/{$role}/polygons/index.php";
$logsUrl = "/REFO/admin/logs.php";
$photosUrl = "/REFO/{$role}/photos/index.php?status=pending";
$seedlingsUrl = "/REFO/{$role}/seedlings/index.php";

// Pending field-photo uploads waiting for review (admin / manager only).
$pendingPhotoCount = 0;
if (in_array($role, ['admin', 'manager'], true)) {
    require_once __DIR__ . '/polygon_photo_functions.php';
    $pendingPhotoCount = countPendingFieldPhotos();
}

// Seedling requests waiting for a decision (admin / manager only).
$pendingSeedlingCount = 0;
if (in_array($role, ['admin', 'manager'], true)) {
    require_once __DIR__ . '/seedling_functions.php';
    $pendingSeedlingCount = countPendingSeedlingRequests();
}

// Log every page navigation by a logged-in user (admin or manager).
require_once __DIR__ . '/log_functions.php';
if (!empty($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], $fullName, $role, 'Page View', 'Navigation', "Visited {$currentScript}", 'success');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/REFO/assets/css/style.css?v=15">
    <script src="/REFO/assets/js/header-navigation.js?v=1" defer></script>
    <?= $extraHead ?? '' ?>
</head>
<body>
<nav class="topbar">
    <a class="topbar-brand" href="<?= h($dashboardUrl) ?>">
        <img class="topbar-logo" src="/REFO/assets/Logo.png" alt="">
        <span class="topbar-brand-text">Reforestation Management Platform</span>
    </a>

    <button class="nav-toggle" type="button" aria-controls="site-navigation"
            aria-expanded="false" aria-label="Open navigation menu">
        <span class="nav-toggle-icon" aria-hidden="true"></span>
    </button>

    <div class="topbar-center" id="site-navigation">
        <div class="topbar-nav">
            <?php if (in_array($role, ['admin', 'manager', 'user'], true)): ?>
                <a class="topbar-item <?= $isDashboard ? 'active' : '' ?>" href="<?= h($dashboardUrl) ?>">Dashboard</a>
            <?php endif; ?>
            <?php if (in_array($role, ['admin', 'manager'], true)): ?>
                <a class="topbar-item <?= $isUsers ? 'active' : '' ?>" href="<?= h($usersUrl) ?>">User Management</a>
                <a class="topbar-item <?= $isPolygons ? 'active' : '' ?>" href="<?= h($polygonsUrl) ?>">CENRO Polygons</a>
                <a class="topbar-item <?= $isPhotos ? 'active' : '' ?>" href="<?= h($photosUrl) ?>">Photo Reviews<?php if ($pendingPhotoCount > 0): ?> <span class="nav-badge"><?= (int)$pendingPhotoCount ?></span><?php endif; ?></a>
                <a class="topbar-item <?= $isSeedlings ? 'active' : '' ?>" href="<?= h($seedlingsUrl) ?>">Seedlings<?php if ($pendingSeedlingCount > 0): ?> <span class="nav-badge"><?= (int)$pendingSeedlingCount ?></span><?php endif; ?></a>
            <?php endif; ?>
            <?php if ($role === 'user'): ?>
                <a class="topbar-item <?= $isPolygons ? 'active' : '' ?>" href="<?= h($polygonsUrl) ?>">My Assigned Area</a>
                <a class="topbar-item <?= $isSeedlings ? 'active' : '' ?>" href="<?= h($seedlingsUrl) ?>">Seedlings</a>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
                <a class="topbar-item <?= $isLogs ? 'active' : '' ?>" href="<?= h($logsUrl) ?>">Activity Logs</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($fullName): ?>
        <div class="topbar-right">
            <span class="topbar-user"><?= h($fullName) ?> (<?= h(ucfirst($role)) ?>)</span>
            <a class="topbar-logout" href="/REFO/auth/logout.php">Logout</a>
        </div>
    <?php endif; ?>
</nav>

<main class="site-main">
