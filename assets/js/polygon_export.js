/**
 * Polygon export button.
 *
 * Requests the six export files (.shp .shx .dbf .prj .xlsx .sql) one at a time and
 * saves them together in a folder the operator picks. Browsers that cannot write to
 * a folder (Firefox, Safari) fall back to six normal downloads.
 */
(function () {
    'use strict';

    var EXTENSIONS = ['shp', 'shx', 'dbf', 'prj', 'xlsx', 'sql'];

    function fileUrl(base, ext, stamp) {
        return base + (base.indexOf('?') === -1 ? '?' : '&') +
            'file=' + ext + '&stamp=' + encodeURIComponent(stamp);
    }

    function fetchFile(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (text) {
                    throw new Error(text && text.length < 300 ? text : 'Export failed (' + response.status + ').');
                });
            }
            var disposition = response.headers.get('Content-Disposition') || '';
            var match = /filename="([^"]+)"/.exec(disposition);
            return response.blob().then(function (blob) {
                return { blob: blob, name: match ? match[1] : null };
            });
        });
    }

    function saveToFolder(button, base, stamp) {
        return window.showDirectoryPicker({ mode: 'readwrite' }).then(function (parent) {
            var folder = null;
            var chain = Promise.resolve();

            EXTENSIONS.forEach(function (ext, i) {
                chain = chain.then(function () {
                    button.textContent = 'Exporting ' + (i + 1) + '/' + EXTENSIONS.length + '...';
                    return fetchFile(fileUrl(base, ext, stamp));
                }).then(function (file) {
                    var name = file.name || ('polygon_' + stamp + '.' + ext);
                    var step = folder
                        ? Promise.resolve(folder)
                        : parent.getDirectoryHandle(name.replace(/\.[^.]+$/, ''), { create: true })
                            .then(function (handle) { folder = handle; return handle; });

                    return step.then(function (dir) {
                        return dir.getFileHandle(name, { create: true });
                    }).then(function (handle) {
                        return handle.createWritable();
                    }).then(function (writable) {
                        return writable.write(file.blob).then(function () { return writable.close(); });
                    });
                });
            });

            return chain.then(function () { return folder ? folder.name : ''; });
        });
    }

    function downloadAll(base, stamp) {
        EXTENSIONS.forEach(function (ext, i) {
            window.setTimeout(function () {
                var link = document.createElement('a');
                link.href = fileUrl(base, ext, stamp);
                link.download = '';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }, i * 500);
        });
        return Promise.resolve('');
    }

    function onClick(event) {
        var button = event.currentTarget;
        if (button.disabled) { return; }

        var base = button.getAttribute('data-export-url');
        var stamp = button.getAttribute('data-stamp');
        var label = button.textContent;

        button.disabled = true;

        var run = (typeof window.showDirectoryPicker === 'function' && window.isSecureContext)
            ? saveToFolder(button, base, stamp)
            : downloadAll(base, stamp);

        run.then(function (folderName) {
            if (folderName) {
                window.alert('Saved 6 files to the folder "' + folderName + '".');
            }
        }).catch(function (error) {
            if (error && error.name === 'AbortError') { return; } // picker cancelled
            window.alert((error && error.message) ? error.message : 'The export could not be saved.');
        }).then(function () {
            button.disabled = false;
            button.textContent = label;
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-polygon-export]'), function (button) {
        button.addEventListener('click', onClick);
    });
})();
