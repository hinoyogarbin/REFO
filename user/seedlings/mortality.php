<?php
/**
 * Field User - report seedlings that died.
 *
 * The user picks the area, then the batch the dead seedlings came from,
 * so the loss is traced to one release and the mortality rate is worked
 * out against what was released.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/seedling_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (!seedlingTablesExist()) {
    $pageTitle = 'Report Dead Seedlings';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<h1>Report Dead Seedlings</h1>' . seedlingMissingTablesNotice();
    require_once __DIR__ . '/../../includes/page_end.php';
    exit;
}

$polygons = getPolygonsForFieldUser($currentUserId);
$polygonIds = array_map('intval', array_column($polygons, 'polygon_id'));
$batches = getSeedlingBatches($polygonIds);
$photos = getMortalityPhotoChoices($currentUserId);

$polygonCodes = [];
foreach ($polygons as $p) {
    $polygonCodes[(int)$p['polygon_id']] = $p['polygon_code'];
}

$errors = [];
$form = [
    'polygon_id'    => isset($_GET['polygon']) ? (int)$_GET['polygon'] : (count($polygons) === 1 ? (int)$polygons[0]['polygon_id'] : 0),
    'batch_id'      => 0,
    'quantity_dead' => '',
    'cause'         => '',
    'date_observed' => date('Y-m-d'),
    'notes'         => '',
    'photo_id'      => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$errors, $clean] = validateMortalityReport($_POST, $currentUserId, $polygonIds);

    $form = [
        'polygon_id'    => (int)($_POST['polygon_id'] ?? 0),
        'batch_id'      => (int)($_POST['batch_id'] ?? 0),
        'quantity_dead' => (string)($_POST['quantity_dead'] ?? ''),
        'cause'         => (string)($_POST['cause'] ?? ''),
        'date_observed' => (string)($_POST['date_observed'] ?? ''),
        'notes'         => (string)($_POST['notes'] ?? ''),
        'photo_id'      => (int)($_POST['photo_id'] ?? 0),
    ];

    if (!$errors) {
        $reportId = createMortalityReport($currentUserId, $clean);
        if ($reportId === null) {
            $errors[] = 'That is more than the seedlings still alive in this batch. Please check the number and try again.';
        } else {
            logActivity(
                $currentUserId, $_SESSION['full_name'], $_SESSION['role'],
                'Report Seedling Mortality', 'Seedlings',
                "Reported {$clean['quantity_dead']} dead seedlings in batch #{$clean['batch_id']} (report #{$reportId}).",
                'success'
            );
            seedlingSetFlash('success', 'Report saved. If you need replacements, use "Request replacement" next to it.');
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Report Dead Seedlings';
$extraHead = '<link rel="stylesheet" href="/REFO/assets/css/polygon_photos.css?v=3">'
    . '<link rel="stylesheet" href="/REFO/assets/css/seedlings.css?v=2">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Report Dead Seedlings</h1>

<?php if (empty($polygons)): ?>

    <div class="alert alert-info">
        You do not have a reforestation area assigned yet. Please contact your
        Administrator or Manager.
    </div>
    <div class="form-actions"><a class="btn" href="index.php">Back</a></div>

<?php elseif (empty($batches)): ?>

    <div class="alert alert-info">
        No seedlings have been released to your areas yet, so there is no batch to report
        against. Once CENRO releases a batch you can report losses here.
    </div>
    <div class="form-actions">
        <a class="btn btn-primary" href="request.php">Request Seedlings</a>
        <a class="btn" href="index.php">Back</a>
    </div>

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

    <form class="form sr-form" action="mortality.php" method="post">

        <label for="polygon_id">Area</label>
        <select id="polygon_id" name="polygon_id" required>
            <option value="">Choose an area</option>
            <?php foreach ($polygons as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= (int)$form['polygon_id'] === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="batch_id">Batch the seedlings came from</label>
        <select id="batch_id" name="batch_id" required>
            <option value="">Choose a batch</option>
            <?php foreach ($batches as $b): ?>
                <?php $alive = (int)$b['quantity'] - (int)$b['dead_total']; ?>
                <option value="<?= (int)$b['batch_id'] ?>" data-polygon="<?= (int)$b['polygon_id'] ?>"
                    <?= (int)$form['batch_id'] === (int)$b['batch_id'] ? 'selected' : '' ?>>
                    <?= h(seedlingBatchLabel($b) . ' - ' . $b['species'] . ' - '
                        . seedlingDate($b['release_date']) . ' (' . $alive . ' of ' . (int)$b['quantity'] . ' alive)') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="quantity_dead">Number of dead seedlings</label>
        <input type="number" id="quantity_dead" name="quantity_dead" min="1" step="1" required
               value="<?= h($form['quantity_dead']) ?>">

        <label for="cause">Cause</label>
        <select id="cause" name="cause" required>
            <option value="">Choose a cause</option>
            <?php foreach (SEEDLING_CAUSES as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $form['cause'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="date_observed">Date observed</label>
        <input type="date" id="date_observed" name="date_observed" required max="<?= h(date('Y-m-d')) ?>"
               value="<?= h($form['date_observed']) ?>">

        <label for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= h($form['notes']) ?></textarea>

        <?php if (!empty($photos)): ?>
            <label for="photo_id">Photo evidence (optional)</label>
            <select id="photo_id" name="photo_id">
                <option value="">No photo</option>
                <?php foreach ($photos as $ph): ?>
                    <?php $photoPolygon = isset($ph['polygon_id']) ? (int)$ph['polygon_id'] : 0; ?>
                    <option value="<?= (int)$ph['photo_id'] ?>" data-polygon="<?= $photoPolygon ?>"
                        <?= (int)$form['photo_id'] === (int)$ph['photo_id'] ? 'selected' : '' ?>>
                        Photo #<?= (int)$ph['photo_id'] ?>
                        <?= $photoPolygon && isset($polygonCodes[$photoPolygon]) ? ' - ' . h($polygonCodes[$photoPolygon]) : '' ?>
                        - <?= h(date('M j, Y g:i A', strtotime($ph['uploaded_at']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Upload the photo first from My Assigned Area, then pick it here.</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Report</button>
            <a class="btn" href="index.php">Cancel</a>
        </div>
    </form>

    <?php
    // Show only the batches (and photos) that belong to the chosen area.
    $extraScripts = '<script>
    (function () {
        var area = document.getElementById("polygon_id");
        var batch = document.getElementById("batch_id");
        var photo = document.getElementById("photo_id");

        function filter(select, keepUnassigned) {
            if (!select) { return; }
            var chosen = area.value;
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.value === "") { option.hidden = false; option.disabled = false; return; }
                var belongs = option.getAttribute("data-polygon");
                var show = chosen !== "" && (belongs === chosen || (keepUnassigned && belongs === "0"));
                option.hidden = !show;
                option.disabled = !show;
                if (!show && option.selected) { select.value = ""; }
            });
        }

        function refresh() { filter(batch, false); filter(photo, true); }
        area.addEventListener("change", refresh);
        refresh();
    }());
    </script>';
    ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
