<?php
/**
 * Field User - request a batch of seedlings for an assigned area.
 *
 * Opening this page from a mortality report ("Request replacement") fills
 * in the area, species and quantity from that report.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/seedling_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (!seedlingTablesExist()) {
    $pageTitle = 'Request Seedlings';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<h1>Request Seedlings</h1>' . seedlingMissingTablesNotice();
    require_once __DIR__ . '/../../includes/page_end.php';
    exit;
}

$polygons = getPolygonsForFieldUser($currentUserId);
$polygonIds = array_map('intval', array_column($polygons, 'polygon_id'));

$errors = [];
$form = [
    'polygon_id'          => count($polygons) === 1 ? (int)$polygons[0]['polygon_id'] : 0,
    'species'             => '',
    'quantity'            => '',
    'reason'              => 'additional',
    'date_needed'         => '',
    'notes'               => '',
    'mortality_report_id' => 0,
];

// Pre-fill from one of the user's own mortality reports.
$reportId = (int)($_GET['report_id'] ?? 0);
if ($reportId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $report = findMortalityReportById($reportId);
    if ($report && (int)$report['user_id'] === $currentUserId) {
        $form['polygon_id'] = (int)$report['polygon_id'];
        $form['species'] = (string)$report['species'];
        $form['quantity'] = (string)(int)$report['quantity_dead'];
        $form['reason'] = 'replacement';
        $form['mortality_report_id'] = (int)$report['report_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$errors, $clean] = validateSeedlingRequest($_POST, $polygonIds);

    $form = [
        'polygon_id'          => (int)($_POST['polygon_id'] ?? 0),
        'species'             => (string)($_POST['species'] ?? ''),
        'quantity'            => (string)($_POST['quantity'] ?? ''),
        'reason'              => (string)($_POST['reason'] ?? 'additional'),
        'date_needed'         => (string)($_POST['date_needed'] ?? ''),
        'notes'               => (string)($_POST['notes'] ?? ''),
        'mortality_report_id' => (int)($_POST['mortality_report_id'] ?? 0),
    ];

    if (!$errors) {
        $requestId = createSeedlingRequest($currentUserId, $clean);
        logActivity(
            $currentUserId, $_SESSION['full_name'], $_SESSION['role'],
            'Request Seedlings', 'Seedlings',
            "Requested {$clean['quantity']} {$clean['species']} seedlings (request #{$requestId}).",
            'success'
        );
        seedlingSetFlash('success', 'Your request was sent to CENRO. You can follow its status below.');
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Request Seedlings';
$extraHead = '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=3">'
    . '<link rel="stylesheet" href="/REFO/assets/css/seedlings.css?v=2">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Request Seedlings</h1>

<?php if (empty($polygons)): ?>

    <div class="alert alert-info">
        You do not have a reforestation area assigned yet. Please contact your
        Administrator or Manager.
    </div>
    <div class="form-actions"><a class="btn" href="index.php">Back</a></div>

<?php else: ?>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="hint">Each approved request is released as its own batch, so a later request is counted
        separately from this one.</p>

    <form class="form sr-form" action="request.php" method="post">
        <input type="hidden" name="mortality_report_id" value="<?= (int)$form['mortality_report_id'] ?>">

        <label for="polygon_id">Area</label>
        <select id="polygon_id" name="polygon_id" required>
            <option value="">Choose an area</option>
            <?php foreach ($polygons as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= (int)$form['polygon_id'] === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="species">Species</label>
        <input type="text" id="species" name="species" maxlength="100" required
               placeholder="e.g. Narra" value="<?= h($form['species']) ?>">

        <label for="quantity">Quantity</label>
        <input type="number" id="quantity" name="quantity" min="1" max="<?= (int)SEEDLING_MAX_QUANTITY ?>"
               step="1" required value="<?= h($form['quantity']) ?>">

        <label for="reason">Reason</label>
        <select id="reason" name="reason" required>
            <?php foreach (SEEDLING_REASONS as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $form['reason'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="date_needed">Date needed (optional)</label>
        <input type="date" id="date_needed" name="date_needed" value="<?= h($form['date_needed']) ?>">

        <label for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= h($form['notes']) ?></textarea>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send Request</button>
            <a class="btn" href="index.php">Cancel</a>
        </div>
    </form>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
