<?php
/**
 * Seedling batches (Admin and Manager).
 *
 * Every release is its own batch. Each polygon shows its batches with a
 * total on top. "Initial planting" records a planting that happened
 * before this system, so its mortality rate can still be worked out.
 */

require_once __DIR__ . '/_bootstrap.php';

$tablesReady = seedlingTablesExist();
$errors = [];
$form = ['polygon_id' => 0, 'species' => '', 'quantity' => '', 'planting_date' => date('Y-m-d'), 'notes' => ''];

$polygonChoices = $tablesReady ? seedlingPolygonChoices() : [];

// ---------- Add an initial planting ----------
if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'polygon_id'    => (int)($_POST['polygon_id'] ?? 0),
        'species'       => (string)($_POST['species'] ?? ''),
        'quantity'      => (string)($_POST['quantity'] ?? ''),
        'planting_date' => (string)($_POST['planting_date'] ?? ''),
        'notes'         => (string)($_POST['notes'] ?? ''),
    ];

    $polygon = $form['polygon_id'] > 0 ? findPolygonById($form['polygon_id']) : null;
    if ($polygon === null) {
        $errors[] = 'Please choose an area.';
    }

    $species = cleanSeedlingSpecies($form['species']);
    if ($species === '') {
        $errors[] = 'Please enter the species.';
    }

    $quantity = parseSeedlingQuantity($form['quantity']);
    if ($quantity === null) {
        $errors[] = 'Quantity must be a whole number of at least 1.';
    }

    $plantingDate = parseSeedlingDate($form['planting_date']);
    if ($plantingDate === null) {
        $errors[] = 'Please enter a valid planting date.';
    } elseif ($plantingDate > date('Y-m-d')) {
        $errors[] = 'The planting date cannot be in the future.';
    }

    if (!$errors) {
        $batchId = createInitialSeedlingBatch(
            (int)$polygon['polygon_id'], $species, $quantity, $plantingDate,
            cleanSeedlingNotes($form['notes']), $currentUserId
        );

        if ($batchId !== null) {
            logActivity($currentUserId, (string)($_SESSION['full_name'] ?? ''), $currentRole,
                'Add Initial Planting', 'Seedlings',
                "Recorded initial planting of {$quantity} {$species} for polygon {$polygon['polygon_code']} (batch #{$batchId}).", 'success');
            seedlingSetFlash('success', 'Initial planting recorded as a batch.');
            header('Location: batches.php');
            exit;
        }
        $errors[] = 'The batch could not be saved. Please try again.';
    }
}

$polygonFilter = isset($_GET['polygon']) && $_GET['polygon'] !== '' ? (int)$_GET['polygon'] : null;
$flash = seedlingTakeFlash();
$groups = $tablesReady ? groupSeedlingBatchesByPolygon(getSeedlingBatches(null, $polygonFilter)) : [];

$grandReleased = 0;
$grandDead = 0;
foreach ($groups as $g) {
    $grandReleased += $g['released'];
    $grandDead += $g['dead'];
}

$pageTitle = 'Seedling Batches';
$extraHead = $seedlingHead;

require_once __DIR__ . '/../header.php';
?>
<h1>Seedlings</h1>
<?= seedlingSubnav($seedlingBase, 'batches') ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>

    <?= seedlingMissingTablesNotice() ?>

<?php else: ?>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-number"><?= (int)$grandReleased ?></span>
            <span class="stat-label">Seedlings released<?= $polygonFilter ? ' (this area)' : '' ?></span>
        </div>
        <div class="stat-card stat-rejected">
            <span class="stat-number"><?= (int)$grandDead ?></span>
            <span class="stat-label">Reported dead</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= seedlingRateHtml((int)$grandDead, (int)$grandReleased) ?></span>
            <span class="stat-label">Mortality rate</span>
        </div>
    </div>

    <h2 class="dash-heading">Batches by Area</h2>

    <form action="batches.php" method="get" class="sr-filters">
        <select name="polygon">
            <option value="">All areas</option>
            <?php foreach ($polygonChoices as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= $polygonFilter === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <?php if (empty($groups)): ?>
        <p class="hint">No batches have been released yet. A batch is created when an approved
            request is marked as released, or when you add an initial planting below.</p>
    <?php else: ?>
        <?php foreach ($groups as $polygonId => $group): ?>
            <div class="sr-group">
                <div class="sr-group-head">
                    <h3><?= h($group['polygon_code'] . ' - ' . $group['polygon_name']) ?></h3>
                    <span class="sr-group-totals">
                        <?= (int)$group['released'] ?> released &middot;
                        <?= (int)$group['dead'] ?> dead &middot;
                        mortality <?= seedlingRateHtml((int)$group['dead'], (int)$group['released']) ?>
                    </span>
                </div>
                <table class="data-table photo-table">
                    <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Species</th>
                        <th>Date</th>
                        <th>Quantity</th>
                        <th>Dead</th>
                        <th>Alive</th>
                        <th>Mortality</th>
                        <th>Released By</th>
                        <th>Source</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['batches'] as $b): ?>
                        <tr>
                            <td data-label="Batch"><?= h(seedlingBatchLabel($b)) ?></td>
                            <td data-label="Species"><?= h($b['species']) ?></td>
                            <td data-label="Date"><?= h(seedlingDate($b['release_date'])) ?></td>
                            <td data-label="Quantity"><?= (int)$b['quantity'] ?></td>
                            <td data-label="Dead"><?= (int)$b['dead_total'] ?></td>
                            <td data-label="Alive"><?= (int)$b['quantity'] - (int)$b['dead_total'] ?></td>
                            <td data-label="Mortality"><?= seedlingRateHtml((int)$b['dead_total'], (int)$b['quantity']) ?></td>
                            <td data-label="Released By"><?= h($b['released_by_name'] ?? '-') ?></td>
                            <td data-label="Source">
                                <?php if (!empty($b['request_id'])): ?>
                                    <a href="view.php?id=<?= (int)$b['request_id'] ?>">Request #<?= (int)$b['request_id'] ?></a>
                                <?php elseif ($b['batch_type'] === 'initial'): ?>
                                    Initial planting
                                <?php else: ?>
                                    <span class="hint">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="dash-heading">Add Initial Planting</h2>
    <p class="hint">For an area that was planted before this system. It becomes a batch so the area's
        mortality rate has a total to compare against.</p>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="form sr-form" action="batches.php" method="post">
        <label for="polygon_id">Area</label>
        <select id="polygon_id" name="polygon_id" required>
            <option value="">Choose an area</option>
            <?php foreach ($polygonChoices as $p): ?>
                <option value="<?= (int)$p['polygon_id'] ?>" <?= (int)$form['polygon_id'] === (int)$p['polygon_id'] ? 'selected' : '' ?>>
                    <?= h($p['polygon_code'] . ' - ' . $p['polygon_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="species">Species</label>
        <input type="text" id="species" name="species" maxlength="100" required value="<?= h($form['species']) ?>">

        <label for="quantity">Seedlings planted</label>
        <input type="number" id="quantity" name="quantity" min="1" step="1" required
               max="<?= (int)SEEDLING_MAX_QUANTITY ?>" value="<?= h($form['quantity']) ?>">

        <label for="planting_date">Planting date</label>
        <input type="date" id="planting_date" name="planting_date" required
               max="<?= h(date('Y-m-d')) ?>" value="<?= h($form['planting_date']) ?>">

        <label for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" rows="2" maxlength="2000"><?= h($form['notes']) ?></textarea>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Add Initial Planting</button>
        </div>
    </form>

<?php endif; ?>

<?php require_once __DIR__ . '/../page_end.php'; ?>
