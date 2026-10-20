/**
 * Shared base-map switcher for every Leaflet map in the platform.
 *
 * addMapLayers(map) puts the standard OpenStreetMap street map on the
 * map and adds a layer switcher (top-right) with:
 *
 *   Street        OpenStreetMap
 *   Satellite     Esri World Imagery
 *   Place names   optional overlay of town / road / boundary labels
 *
 * Satellite tiles use Leaflet's detectRetina, so phones and other
 * high-resolution screens get tiles at twice the detail instead of
 * blown-up ones. The person's last base-map choice is remembered in the
 * browser, so every map opens the way they left it.
 *
 * addMapLayers(map, { defaultBase: 'satellite' }) opens on satellite
 * imagery the first time (street is the default otherwise). A saved
 * choice always wins over the default.
 *
 * Returns { street, satellite, isSatellite() }, or null if
 * Leaflet or the map is missing.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'reforestation_base_layer';

    // Esri's satellite server answers with a grey "Map data not yet available"
    // picture for zoom levels it has no imagery for (common over rural areas
    // past zoom 17). Requesting nothing finer than this makes Leaflet enlarge
    // the last real tiles instead of showing those placeholders.
    var SATELLITE_NATIVE_ZOOM = 17;

    function readPreference() {
        try {
            return global.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function savePreference(value) {
        try {
            global.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) { /* storage blocked: the choice just isn't remembered */ }
    }

    var NOTICE_CLASS = 'map-load-notice';

    /**
     * Puts a visible message inside a map box, so a map that cannot load
     * explains itself instead of staying blank.
     */
    function showMapNotice(container, text) {
        if (!container || container.querySelector('.' + NOTICE_CLASS)) {
            return;
        }
        var note = document.createElement('div');
        note.className = NOTICE_CLASS;
        note.setAttribute('role', 'alert');
        note.style.cssText = 'position:absolute;z-index:1000;left:50%;top:50%;'
            + 'transform:translate(-50%,-50%);max-width:calc(100% - 32px);'
            + 'padding:10px 14px;border-radius:8px;background:#fff;color:#333;'
            + 'box-shadow:0 1px 6px rgba(0,0,0,.3);text-align:center;font-size:14px;';
        note.textContent = text;
        if (getComputedStyle(container).position === 'static') {
            container.style.position = 'relative';
        }
        container.appendChild(note);
    }

    /**
     * Watches a map's tile layers. If tiles keep failing and none ever
     * load (no internet, or the tile server is blocked), say so.
     */
    function watchTiles(map, layers) {
        var loaded = 0;
        var failed = 0;
        layers.forEach(function (layer) {
            layer.on('tileload', function () {
                loaded++;
                var note = map.getContainer().querySelector('.' + NOTICE_CLASS);
                if (note) {
                    note.parentNode.removeChild(note);
                }
            });
            layer.on('tileerror', function () {
                failed++;
                if (loaded === 0 && failed >= 4) {
                    showMapNotice(map.getContainer(),
                        'The map pictures could not be loaded. Check your internet '
                        + 'connection, or try switching between Street and Satellite.');
                }
            });
        });
    }

    function addMapLayers(map, options) {
        if (!map || typeof L === 'undefined') {
            return null;
        }

        options = options || {};

        var street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });

        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 20,
                maxNativeZoom: SATELLITE_NATIVE_ZOOM,
                detectRetina: true,
                attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, '
                    + 'Earthstar Geographics, and the GIS User Community'
            }
        );

        var placeNames = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 20,
                maxNativeZoom: 19,
                detectRetina: true,
                zIndex: 10
            }
        );

        var preference = readPreference();
        var start = options.defaultBase === 'satellite' ? satellite : street;
        // 'satellite-hd' was a removed option; treat it as plain satellite.
        if (preference === 'satellite' || preference === 'satellite-hd') {
            start = satellite;
        } else if (preference === 'street') {
            start = street;
        }
        start.addTo(map);
        watchTiles(map, [street, satellite, placeNames]);

        L.control.layers(
            { 'Street': street, 'Satellite': satellite },
            { 'Place names': placeNames },
            { position: 'topright', collapsed: true }
        ).addTo(map);

        map.on('baselayerchange', function (e) {
            savePreference(e.layer === satellite ? 'satellite' : 'street');
        });

        // The box can get its final size after the map is created (CSS or
        // fonts finishing late), which leaves grey / missing tiles.
        global.addEventListener('load', function () { map.invalidateSize(); });

        return {
            street: street,
            satellite: satellite,
            isSatellite: function () {
                return map.hasLayer(satellite);
            }
        };
    }

    global.addMapLayers = addMapLayers;
    global.showMapNotice = showMapNotice;

    // Leaflet itself failed to load from every source: tell the person
    // instead of leaving every map box empty.
    if (typeof L === 'undefined') {
        var announce = function () {
            var boxes = document.querySelectorAll('.dashboard-map');
            for (var i = 0; i < boxes.length; i++) {
                showMapNotice(boxes[i],
                    'The map could not be loaded because the map library was blocked '
                    + 'or unreachable. Check your internet connection and refresh the page.');
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', announce);
        } else {
            announce();
        }
    }
}(window));
