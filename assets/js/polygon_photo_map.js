/**
 * Tree-inventory photo markers for the CENRO polygon maps.
 *
 * Builds on polygon_map.js (must be loaded first): draws the polygon
 * boundary with initPolygonMap(), then puts one marker on the map for
 * every uploaded photo that has GPS coordinates. Tapping a marker shows
 * the photo with its details.
 */
(function (global) {
    'use strict';

    var STATUS_COLORS = {
        pending:   '#e08a00',
        confirmed: '#1a5c2e',
        rejected:  '#b3261e'
    };

    function esc(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function popupHtml(photo) {
        var html = '<div class="photo-popup">';
        html += '<a href="' + esc(photo.url) + '">'
            + '<img src="' + esc(photo.thumb) + '" alt="Tree photo"></a>';
        if (photo.taken) {
            html += '<div><strong>Taken:</strong> ' + esc(photo.taken) + '</div>';
        }
        html += '<div><strong>Uploaded:</strong> ' + esc(photo.uploaded) + '</div>';
        html += '<div><strong>Status:</strong> ' + esc(photo.label) + '</div>';
        html += '<div><a href="' + esc(photo.url) + '">View details</a></div>';
        html += '</div>';
        return html;
    }

    /**
     * Adds one marker per photo to an existing Leaflet map.
     *
     * @param {L.Map}  map
     * @param {Array}  photos   Payload from getMappablePhotosForUser().
     * @param {Object} options  { highlightId: photo id whose popup opens }
     * @return {L.FeatureGroup|null}
     */
    function addPolygonPhotoMarkers(map, photos, options) {
        if (!map || typeof L === 'undefined' || !photos || !photos.length) {
            return null;
        }

        options = options || {};
        var group = L.featureGroup().addTo(map);
        var highlighted = null;

        photos.forEach(function (photo) {
            if (typeof photo.lat !== 'number' || typeof photo.lng !== 'number') {
                return;
            }

            var color = STATUS_COLORS[photo.status] || STATUS_COLORS.pending;
            var marker = L.circleMarker([photo.lat, photo.lng], {
                radius: 10,
                weight: 3,
                color: '#ffffff',
                fillColor: color,
                fillOpacity: 0.95
            });

            marker.bindPopup(popupHtml(photo), { maxWidth: 240 });
            marker.addTo(group);

            if (options.highlightId && photo.id === options.highlightId) {
                marker.setStyle({ radius: 13 });
                highlighted = marker;
            }
        });

        // The map stays centred on the polygon (set by initPolygonMap).
        // Photos are never allowed to move or zoom it; pan the map to see
        // a photo taken outside the boundary.

        if (highlighted) {
            // Open the new photo's popup without panning the map away.
            var popup = highlighted.getPopup();
            var autoPan = popup.options.autoPan;
            popup.options.autoPan = false;
            highlighted.openPopup();
            popup.options.autoPan = autoPan;
        }

        return group;
    }

    /**
     * Polygon boundary + photo markers in one call.
     */
    function initPolygonPhotoMap(elementId, polygons, photos, options) {
        if (typeof global.initPolygonMap !== 'function') {
            return null;
        }
        var map = global.initPolygonMap(elementId, polygons, options);
        addPolygonPhotoMarkers(map, photos, options);
        return map;
    }

    global.addPolygonPhotoMarkers = addPolygonPhotoMarkers;
    global.initPolygonPhotoMap = initPolygonPhotoMap;
}(window));
