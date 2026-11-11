<?php
/**
 * Seedling request decisions (POST only): approve, reject or release.
 */

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$requestId = (int)($_POST['id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$notes = cleanSeedlingNotes($_POST['notes'] ?? '');

$request = $requestId > 0 ? findSeedlingRequestById($requestId) : null;
$back = 'view.php?id=' . $requestId;

if ($request === null || !in_array($decision, ['approve', 'reject', 'release'], true)) {
    seedlingSetFlash('error', 'Invalid request.');
    header('Location: index.php');
    exit;
}

$actorName = (string)($_SESSION['full_name'] ?? '');

if ($decision === 'approve') {
    $quantity = parseSeedlingQuantity($_POST['quantity'] ?? '');
    if ($quantity === null) {
        seedlingSetFlash('error', 'Quantity must be a whole number of at least 1.');
    } elseif (approveSeedlingRequest($requestId, $quantity, $currentUserId, $notes)) {
        logActivity($currentUserId, $actorName, $currentRole, 'Approve Seedling Request', 'Seedlings',
            "Approved {$quantity} seedlings for request #{$requestId} (requested {$request['quantity_requested']}).", 'success');
        seedlingSetFlash('success', 'Request approved. Mark it as released once the seedlings are handed over.');
    } else {
        seedlingSetFlash('error', 'This request has already been decided.');
    }

} elseif ($decision === 'reject') {
    if ($notes === null) {
        seedlingSetFlash('error', 'Please give a reason for rejecting the request.');
    } elseif (rejectSeedlingRequest($requestId, $currentUserId, $notes)) {
        logActivity($currentUserId, $actorName, $currentRole, 'Reject Seedling Request', 'Seedlings',
            "Rejected seedling request #{$requestId}.", 'success');
        seedlingSetFlash('success', 'Request rejected.');
    } else {
        seedlingSetFlash('error', 'This request has already been decided.');
    }

} else { // release
    $releaseDate = parseSeedlingDate($_POST['release_date'] ?? '');
    if ($releaseDate === null) {
        seedlingSetFlash('error', 'Please enter a valid release date.');
    } elseif ($releaseDate > date('Y-m-d')) {
        seedlingSetFlash('error', 'The release date cannot be in the future.');
    } else {
        $batchId = releaseSeedlingRequest($requestId, $releaseDate, $currentUserId, $notes);
        if ($batchId !== null) {
            logActivity($currentUserId, $actorName, $currentRole, 'Release Seedlings', 'Seedlings',
                "Released request #{$requestId} as batch #{$batchId} ({$request['quantity_approved']} {$request['species']}).", 'success');
            seedlingSetFlash('success', 'Seedlings released and recorded as a new batch.');
        } else {
            seedlingSetFlash('error', 'This request could not be released. It must be approved first.');
        }
    }
}

header('Location: ' . $back);
exit;
