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
 * Returns { street, satellite, isSatellite() }, or null if
 * Leaflet or the map is missing.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'reforestation_base_layer';

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

    function addMapLayers(map) {
        if (!map || typeof L === 'undefined') {
            return null;
        }

        var street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });

        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 20,
                maxNativeZoom: 19,
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
        var start = street;
        // 'satellite-hd' was a removed option; treat it as plain satellite.
        if (preference === 'satellite' || preference === 'satellite-hd') {
            start = satellite;
        }
        start.addTo(map);

        L.control.layers(
            { 'Street': street, 'Satellite': satellite },
            { 'Place names': placeNames },
            { position: 'topright', collapsed: true }
        ).addTo(map);

        map.on('baselayerchange', function (e) {
            savePreference(e.layer === satellite ? 'satellite' : 'street');
        });

        return {
            street: street,
            satellite: satellite,
            isSatellite: function () {
                return map.hasLayer(satellite);
            }
        };
    }

    global.addMapLayers = addMapLayers;
}(window));
