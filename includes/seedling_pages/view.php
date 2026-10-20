<?php
/**
 * Seedling request - details and decision (Admin and Manager).
 *
 * pending  -> Approve (quantity adjustable) or Reject (note required)
 * approved -> Release (creates this request's own batch)
 */

require_once __DIR__ . '/_bootstrap.php';

if (!seedlingTablesExist()) {
    seedlingSetFlash('error', 'The seedling tables are not installed yet.');
    header('Location: index.php');
    exit;
}

$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$request = $requestId > 0 ? findSeedlingRequestById($requestId) : null;

if ($request === null) {
    seedlingSetFlash('error', 'Seedling request not found.');
    header('Location: index.php');
    exit;
}

$flash = seedlingTakeFlash();

// What this area has received so far, so the decision has some context.
$areaBatches = [];
if (!empty($request['polygon_id'])) {
    $areaBatches = getSeedlingBatches(null, (int)$request['polygon_id']);
}
$areaReleased = array_sum(array_map('intval', array_column($areaBatches, 'quantity')));
$areaDead = array_sum(array_map('intval', array_column($areaBatches, 'dead_total')));

$linkedReport = !empty($request['mortality_report_id'])
    ? findMortalityReportById((int)$request['mortality_report_id'])
    : null;

$pageTitle = 'Seedling Request';
$extraHead = $seedlingHead;

require_once __DIR__ . '/../header.php';
?>
<h1>Seedling Request #<?= (int)$request['request_id'] ?></h1>
<?= seedlingSubnav($seedlingBase, 'requests') ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Status</span>
        <span><?= seedlingStatusBadge($request['status']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Requested By</span>
        <span><?= h($request['requester_name']) ?> (<?= h($request['requester_username']) ?>)</span>
    </div>
    <div class="details-row">
        <span class="details-label">Area</span>
        <span><?= $request['polygon_code'] ? h($request['polygon_code'] . ' - ' . $request['polygon_name']) : 'Area no longer exists' ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Species</span>
        <span><?= h($request['species']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Quantity Requested</span>
        <span><?= (int)$request['quantity_requested'] ?></span>
    </div>
    <?php if ($request['quantity_approved'] !== null): ?>
        <div class="details-row">
            <span class="details-label">Quantity Approved</span>
            <span><?= (int)$request['quantity_approved'] ?></span>
        </div>
    <?php endif; ?>
    <div class="details-row">
        <span class="details-label">Reason</span>
        <span><?= h(SEEDLING_REASONS[$request['reason']] ?? $request['reason']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Needed By</span>
        <span><?= h(seedlingDate($request['date_needed'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Submitted</span>
        <span><?= h(date('F j, Y g:i A', strtotime($request['created_at']))) ?></span>
    </div>
    <?php if (!empty($request['notes'])): ?>
        <div class="details-row">
            <span class="details-label">User Notes</span>
            <span class="sr-note"><?= h($request['notes']) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($linkedReport): ?>
        <div class="details-row">
            <span class="details-label">Replaces</span>
            <span>
                <?= (int)$linkedReport['quantity_dead'] ?> dead in
                <?= h(seedlingBatchLabel($linkedReport)) ?>,
                <?= h(SEEDLING_CAUSES[$linkedReport['cause']] ?? $linkedReport['cause']) ?>
                (<?= h(seedlingDate($linkedReport['date_observed'])) ?>)
            </span>
        </div>
    <?php endif; ?>
    <?php if ($request['status'] !== 'pending'): ?>
        <div class="details-row">
            <span class="details-label">Decided By</span>
            <span>
                <?= h($request['reviewer_name'] ?? 'Unknown') ?>
                <?= !empty($request['reviewed_at']) ? 'on ' . h(date('F j, Y g:i A', strtotime($request['reviewed_at']))) : '' ?>
            </span>
        </div>
    <?php endif; ?>
    <?php if (!empty($request['review_notes'])): ?>
        <div class="details-row">
            <span class="details-label">Decision Notes</span>
            <span class="sr-note"><?= h($request['review_notes']) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($request['status'] === 'released' && $request['batch_number'] !== null): ?>
        <div class="details-row">
            <span class="details-label">Released As</span>
            <span>Batch <?= (int)$request['batch_number'] ?> on <?= h(seedlingDate($request['batch_release_date'])) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($request['polygon_id'])): ?>
    <p class="hint" style="margin-top:12px;">
        This area has received <strong><?= (int)$areaReleased ?></strong> seedlings in
        <?= count($areaBatches) ?> batch<?= count($areaBatches) === 1 ? '' : 'es' ?>;
        <strong><?= (int)$areaDead ?></strong> reported dead
        (mortality <?= seedlingRateHtml((int)$areaDead, (int)$areaReleased) ?>).
    </p>
<?php endif; ?>

<?php if ($request['status'] === 'pending'): ?>

    <div class="sr-decision">
        <h2>Approve</h2>
        <p class="hint">You can change the quantity if CENRO can only supply a different amount.</p>
        <form action="action.php" method="post" class="form sr-form">
            <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
            <input type="hidden" name="decision" value="approve">

            <label for="quantity">Quantity to approve</label>
            <input type="number" id="quantity" name="quantity" min="1" step="1" required
                   max="<?= (int)SEEDLING_MAX_QUANTITY ?>" value="<?= (int)$request['quantity_requested'] ?>">

            <label for="approve_notes">Notes (optional)</label>
            <textarea id="approve_notes" name="notes" rows="2" maxlength="2000"></textarea>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Approve Request</button>
            </div>
        </form>
    </div>

    <div class="sr-decision">
        <h2>Reject</h2>
        <form action="action.php" method="post" class="form sr-form">
            <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
            <input type="hidden" name="decision" value="reject">

            <label for="reject_notes">Reason for rejecting (the user will see this)</label>
            <textarea id="reject_notes" name="notes" rows="2" maxlength="2000" required></textarea>

            <div class="form-actions">
                <button type="submit" class="btn link-danger"
                        onclick="return confirm('Reject this request?');">Reject Request</button>
            </div>
        </form>
    </div>

<?php elseif ($request['status'] === 'approved'): ?>

    <div class="sr-decision">
        <h2>Release Seedlings</h2>
        <p class="hint">
            Releasing records <strong><?= (int)$request['quantity_approved'] ?></strong>
            <?= h($request['species']) ?> seedlings as a new batch for this area. It is counted
            separately from any earlier or later batch.
        </p>
        <?php if (empty($request['polygon_id'])): ?>
            <div class="alert alert-error">This area no longer exists, so the request cannot be released.</div>
        <?php else: ?>
            <form action="action.php" method="post" class="form sr-form">
                <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
                <input type="hidden" name="decision" value="release">

                <label for="release_date">Release date</label>
                <input type="date" id="release_date" name="release_date" required
                       max="<?= h(date('Y-m-d')) ?>" value="<?= h(date('Y-m-d')) ?>">

                <label for="release_notes">Notes (optional)</label>
                <textarea id="release_notes" name="notes" rows="2" maxlength="2000"></textarea>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Release this batch? This cannot be undone.');">Mark as Released</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<div class="form-actions">
    <a class="btn" href="index.php">Back to Requests</a>
</div>

<?php require_once __DIR__ . '/../page_end.php'; ?>
