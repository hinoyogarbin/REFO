<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/seedling_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$requestId = (int)($_POST['id'] ?? 0);

// Only the person's own request, and only while it is still pending.
if ($requestId > 0 && cancelSeedlingRequest($requestId, (int)$_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'Cancel Seedling Request', 'Seedlings', "Cancelled seedling request #{$requestId}.", 'success');
    seedlingSetFlash('success', 'Request cancelled.');
} else {
    seedlingSetFlash('error', 'That request could not be cancelled. It may already have been decided.');
}

header('Location: index.php');
exit;
