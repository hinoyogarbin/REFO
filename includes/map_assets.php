<?php
/**
 * Leaflet loader shared by every map page.
 *
 * Why this exists: every page used to load Leaflet from a single CDN
 * (unpkg.com) with an integrity hash. If that one host was slow, blocked
 * or rejected by the browser, `L` was never defined and the map code
 * quietly did nothing - the map area stayed blank with no error.
 *
 * Now each file is tried from several sources in turn:
 *   1. unpkg.com      (original source, with its integrity hash)
 *   2. cdn.jsdelivr.net
 *   3. cdnjs.cloudflare.com
 *   4. /REFO/assets/vendor/leaflet/   (your own copy - works offline)
 *
 * If all of them fail, the map box shows a clear message instead of
 * staying empty (see showMapUnavailable() in assets/js/map_layers.js).
 */

const LEAFLET_VERSION = '1.9.4';
const LEAFLET_LOCAL   = '/REFO/assets/vendor/leaflet/';

/** <link> tags for Leaflet's stylesheet, falling back host by host. */
function leafletHeadTags(): string
{
    $v = LEAFLET_VERSION;
    $fallbacks = [
        "https://cdn.jsdelivr.net/npm/leaflet@{$v}/dist/leaflet.css",
        "https://cdnjs.cloudflare.com/ajax/libs/leaflet/{$v}/leaflet.css",
        LEAFLET_LOCAL . 'leaflet.css',
    ];

    // onerror walks to the next URL in data-fallbacks.
    $onerror = "var l=this,f=(l.getAttribute('data-fallbacks')||'').split('|').filter(Boolean);"
        . "if(!f.length){l.onerror=null;return;}"
        . "l.setAttribute('data-fallbacks',f.slice(1).join('|'));"
        . "l.href=f[0];";

    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@' . $v . '/dist/leaflet.css" '
        . 'data-fallbacks="' . htmlspecialchars(implode('|', $fallbacks), ENT_QUOTES) . '" '
        . 'onerror="' . htmlspecialchars($onerror, ENT_QUOTES) . '">';
}

/**
 * <script> tags for Leaflet itself. Each fallback is only written to the
 * page when `window.L` is still missing, so on a normal connection only
 * the first request is made. These are parser-blocking on purpose: the
 * inline map-init scripts that follow can then rely on `L` existing.
 */
function leafletScriptTags(): string
{
    $v = LEAFLET_VERSION;
    $sources = [
        "https://cdn.jsdelivr.net/npm/leaflet@{$v}/dist/leaflet.js",
        "https://cdnjs.cloudflare.com/ajax/libs/leaflet/{$v}/leaflet.js",
        LEAFLET_LOCAL . 'leaflet.js',
    ];

    $html = '<script src="https://unpkg.com/leaflet@' . $v . '/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>';

    foreach ($sources as $src) {
        $html .= '<script>window.L||document.write(\'<script src="' . $src . '"><\\/script>\');</script>';
    }

    return $html;
}

/**
 * Leaflet + the shared map scripts (base-map switcher, polygon map,
 * and optionally the photo-marker layer).
 */
function leafletMapScripts(bool $withPhotoMarkers = false, bool $withPolygonMap = true): string
{
    $html = leafletScriptTags()
        . '<script src="/REFO/assets/js/map_layers.js?v=6"></script>';

    if ($withPolygonMap) {
        $html .= '<script src="/REFO/assets/js/polygon_map.js?v=7"></script>';
    }
    if ($withPhotoMarkers) {
        $html .= '<script src="/REFO/assets/js/polygon_photo_map.js?v=6"></script>';
    }

    return $html;
}
