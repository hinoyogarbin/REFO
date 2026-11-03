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

    var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * Date a photo belongs to for the calendar: the day it was taken
     * (EXIF), else the day it was uploaded. photo.date is "YYYY-MM-DD"
     * from photoRowToMarker(); it is read as text so time zones can
     * never shift a photo into the wrong month.
     */
    function parseDate(photo) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(photo.date || '');
        if (!m) {
            return null;
        }
        return { y: parseInt(m[1], 10), m: parseInt(m[2], 10), key: m[1] + m[2] + m[3] };
    }

    function periodLabel(f) {
        if (!f.year) {
            return 'all time';
        }
        return f.month ? MONTH_NAMES[f.month - 1] + ' ' + f.year : 'all of ' + f.year;
    }

    function capitalise(text) {
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    /**
     * Calendar button + date panel, placed with the "Reset view" and
     * "View all polygons" buttons in the toolbar above the map (not on the
     * map itself). The button shows the period being viewed; clicking it
     * opens a panel with month / year pickers just under the toolbar.
     *
     * state = { year, month } where 0 means "all".
     */
    function createCalendarToolbar(map, state, minYear, maxYear, onChange) {
        var container = map.getContainer();
        var bar = container.previousElementSibling;
        var host;

        if (bar && bar.classList && bar.classList.contains('polygon-nav')) {
            host = bar.querySelector('.polygon-nav-center') || bar;
        } else {
            // No polygon toolbar on this map: make a small one of our own.
            bar = document.createElement('div');
            bar.className = 'photo-calendar-toolbar';
            container.parentNode.insertBefore(bar, container);
            host = bar;
        }

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn polygon-nav-reset polygon-nav-calendar';
        toggle.setAttribute('aria-expanded', 'false');
        host.appendChild(toggle);

        var html = '<div class="photo-calendar-row">'
            + '<button type="button" class="btn pc-prev" aria-label="Previous month">&#8249;</button>'
            + '<select class="pc-month" aria-label="Month"><option value="0">All months</option>';
        MONTH_NAMES.forEach(function (name, i) {
            html += '<option value="' + (i + 1) + '">' + name + '</option>';
        });
        html += '</select><select class="pc-year" aria-label="Year"><option value="0">All years</option>';
        for (var y = maxYear; y >= minYear; y--) {
            html += '<option value="' + y + '">' + y + '</option>';
        }
        html += '</select>'
            + '<button type="button" class="btn pc-next" aria-label="Next month">&#8250;</button>'
            + '</div>'
            + '<div class="photo-calendar-row">'
            + '<button type="button" class="btn pc-now">This month</button>'
            + '<button type="button" class="btn pc-all">All time</button>'
            + '</div>'
            + '<div class="photo-calendar-count" aria-live="polite"></div>';

        var panel = document.createElement('div');
        panel.className = 'photo-calendar-panel';
        panel.hidden = true;
        panel.innerHTML = html;
        bar.parentNode.insertBefore(panel, bar.nextSibling);

        var monthSel = panel.querySelector('.pc-month');
        var yearSel  = panel.querySelector('.pc-year');
        var prevBtn  = panel.querySelector('.pc-prev');
        var nextBtn  = panel.querySelector('.pc-next');
        var countEl  = panel.querySelector('.photo-calendar-count');

        function sync() {
            yearSel.value = String(state.year);
            monthSel.value = String(state.month);
            monthSel.disabled = !state.year;
            var stepable = state.year && state.month;
            prevBtn.disabled = !stepable || (state.year === minYear && state.month === 1);
            nextBtn.disabled = !stepable || (state.year === maxYear && state.month === 12);
        }

        function changed() {
            sync();
            onChange();
        }

        toggle.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
        yearSel.addEventListener('change', function () {
            state.year = parseInt(yearSel.value, 10);
            if (!state.year) {
                state.month = 0;
            }
            changed();
        });
        monthSel.addEventListener('change', function () {
            state.month = parseInt(monthSel.value, 10);
            changed();
        });
        prevBtn.addEventListener('click', function () {
            state.month -= 1;
            if (state.month < 1) { state.month = 12; state.year -= 1; }
            changed();
        });
        nextBtn.addEventListener('click', function () {
            state.month += 1;
            if (state.month > 12) { state.month = 1; state.year += 1; }
            changed();
        });
        panel.querySelector('.pc-now').addEventListener('click', function () {
            var now = new Date();
            state.year = now.getFullYear();
            state.month = now.getMonth() + 1;
            changed();
        });
        panel.querySelector('.pc-all').addEventListener('click', function () {
            state.year = 0;
            state.month = 0;
            changed();
        });

        sync();

        return {
            setCount: function (shown) {
                var label = periodLabel(state);
                toggle.textContent = 'Photo dates: ' + capitalise(label) + ' (' + shown + ') \u25BE';
                countEl.textContent = shown
                    ? shown + (shown === 1 ? ' photo' : ' photos') + ' (' + label + ')'
                    : 'No photos for ' + label;
                countEl.className = 'photo-calendar-count' + (shown ? '' : ' is-empty');
            }
        };
    }

    /**
     * Adds one marker per photo to an existing Leaflet map, with a
     * month / year calendar so only the chosen period is shown.
     *
     * The map opens on the current month. Photos are drawn oldest to
     * newest, so when several sit on the same spot the newest one is on
     * top and shows that spot's current state.
     *
     * @param {L.Map}  map
     * @param {Array}  photos   Payload from getMappablePhotosForUser().
     * @param {Object} options  { highlightId: photo id whose popup opens,
     *                            calendar: false to turn the calendar off }
     * @return {L.FeatureGroup|null}
     */
    function addPolygonPhotoMarkers(map, photos, options) {
        if (!map || typeof L === 'undefined') {
            return null;
        }

        // No photos yet is fine: the calendar still shows (with a
        // "No photos" note) so the control is always visible.
        photos = photos || [];
        options = options || {};
        var useCalendar = options.calendar !== false;
        var group = L.featureGroup().addTo(map);

        var items = [];
        var minYear = new Date().getFullYear();
        var maxYear = minYear;
        photos.forEach(function (photo) {
            if (typeof photo.lat !== 'number' || typeof photo.lng !== 'number') {
                return;
            }
            var d = parseDate(photo);
            if (d) {
                minYear = Math.min(minYear, d.y);
                maxYear = Math.max(maxYear, d.y);
            }
            items.push({ photo: photo, d: d });
        });

        // Oldest first: newest is added last, so it ends up on top.
        items.sort(function (a, b) {
            var ka = a.d ? a.d.key : '';
            var kb = b.d ? b.d.key : '';
            return ka < kb ? -1 : ka > kb ? 1 : a.photo.id - b.photo.id;
        });

        // Start on the current month. If a photo was just uploaded
        // (highlightId), start on its month so it is not hidden.
        var now = new Date();
        var state = useCalendar
            ? { year: now.getFullYear(), month: now.getMonth() + 1 }
            : { year: 0, month: 0 };
        var highlightItem = null;
        if (options.highlightId) {
            items.forEach(function (it) {
                if (it.photo.id === options.highlightId) { highlightItem = it; }
            });
            if (useCalendar && highlightItem && highlightItem.d) {
                state.year = highlightItem.d.y;
                state.month = highlightItem.d.m;
            }
        }

        var control = null;

        function matches(it) {
            if (!state.year) { return true; }
            if (!it.d || it.d.y !== state.year) { return false; }
            return !state.month || it.d.m === state.month;
        }

        function render(openHighlight) {
            group.clearLayers();
            var shown = 0;
            var highlighted = null;

            items.forEach(function (it) {
                if (!matches(it)) { return; }
                shown++;

                var photo = it.photo;
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

            if (control) { control.setCount(shown); }

            if (openHighlight && highlighted) {
                // Open the new photo's popup without panning the map away.
                var popup = highlighted.getPopup();
                var autoPan = popup.options.autoPan;
                popup.options.autoPan = false;
                highlighted.openPopup();
                popup.options.autoPan = autoPan;
            }
        }

        if (useCalendar) {
            control = createCalendarToolbar(map, state, minYear, maxYear, function () {
                render(false);
            });
        }

        render(true);

        // The polygon map redraws its boundaries when you move to another
        // area or switch the base map; keep the photo markers above them
        // so they stay clickable. Layers are visited oldest to newest, so
        // the newest photo stays on top of the stack.
        map.on('polygons:redrawn', function () {
            group.eachLayer(function (layer) { layer.bringToFront(); });
        });

        // The map stays centred on the polygon (set by initPolygonMap).
        // Photos are never allowed to move or zoom it. On a locked map only
        // the current area is shown; use "View all polygons" (where offered)
        // to pan and see a photo taken outside the boundary.

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
