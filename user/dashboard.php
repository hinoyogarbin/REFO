<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../includes/user_functions.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

$pageTitle = 'User Dashboard';

$extraHead = dashboardHead();

require_once __DIR__ . '/../includes/header.php';

// Assigned areas, photo counts, rejected photos and the map.
require_once __DIR__ . '/../includes/dashboard_pages/user.php';

require_once __DIR__ . '/../includes/page_end.php';
?>
