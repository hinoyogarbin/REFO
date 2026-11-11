<?php
/**
 * Admin - Seedlings (index).
 * Thin wrapper; the shared implementation lives in
 * includes/seedling_pages/ so Admin and Manager share one codebase.
 */
$seedlingRole = 'admin';
require_once __DIR__ . '/../../includes/seedling_pages/index.php';
