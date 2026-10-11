<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/user_functions.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

$pageTitle = 'Admin Dashboard';
$role = 'admin';

$extraHead = dashboardHead();

require_once __DIR__ . '/../includes/header.php';

// Pending reviews, polygon and photo numbers, map and recent uploads.
require_once __DIR__ . '/../includes/dashboard_pages/review.php';

require_once __DIR__ . '/../includes/page_end.php';
?>
