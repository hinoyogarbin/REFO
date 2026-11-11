<?php
/**
 * Dashboard map card: CENRO boundaries (one polygon at a time, with the
 * previous / next controls drawn by polygon_map.js) plus tree-photo markers.
 *
 * Expects $role. The map itself is drawn by the scripts the dashboard puts
 * in $extraScripts (see dashboardMapScripts() in dashboard_functions.php).
 */
$isFieldUserDashboard = ($role ?? '') === 'user';
?>
<section class="dashboard-map-section polygon-map-section" aria-labelledby="dashboard-map-heading">
    <h2 id="dashboard-map-heading"><?= $isFieldUserDashboard ? 'Your assigned reforestation areas' : 'CENRO reforestation areas' ?></h2>
    <p class="dashboard-map-caption">
        <?= $isFieldUserDashboard
            ? 'Only the official CENRO boundaries assigned to your account are shown, with the tree photos you uploaded. Tap a marker to see the photo.'
            : 'Official imported CENRO boundaries with the tree photos uploaded by field users. Select a polygon for its details, or a marker to see the photo.' ?>
    </p>
    <div id="dashboard-map" class="dashboard-map"></div>
    <?php if (function_exists('polygonPhotoLegendHtml')): ?>
        <?= polygonPhotoLegendHtml() ?>
    <?php endif; ?>
</section>
