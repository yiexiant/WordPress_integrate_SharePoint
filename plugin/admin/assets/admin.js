/*
 * SharePoint Backup admin: small progressive enhancements. Every form still works without JavaScript;
 * server-side checks (nonces, capability, required confirmations) are the real safeguards.
 */
(function () {
    'use strict';

    var root = document.querySelector('.spsync');
    if (!root) {
        return;
    }

    // Live status: refresh while a backup/restore is running (paused while the tab is hidden).
    var refresh = parseInt(root.getAttribute('data-autorefresh'), 10);
    if (refresh > 0) {
        setInterval(function () {
            if (!document.hidden && !document.querySelector('form.is-dirty')) {
                window.location.reload();
            }
        }, refresh * 1000);
    }

    // Confirm risky actions. A hidden [data-spsync-confirmed] field is only filled after "OK",
    // so the server rejects the request if the dialog was bypassed.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        // A double-click must never send the same action twice.
        if (form.getAttribute('data-spsync-submitted')) {
            event.preventDefault();
            return;
        }
        var message = form.getAttribute('data-spsync-confirm');
        if (message) {
            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
            var flag = form.querySelector('[data-spsync-confirmed]');
            if (flag) {
                flag.value = '1';
            }
        }
        // Busy state for slow actions; deferred so the button's own value is still submitted.
        var button = event.submitter || form.querySelector('[type="submit"]');
        if (!event.defaultPrevented) {
            form.setAttribute('data-spsync-submitted', '1');
        }
        if (button && !event.defaultPrevented) {
            setTimeout(function () {
                button.disabled = true;
                if (button.getAttribute('data-spsync-busy')) {
                    button.textContent = button.getAttribute('data-spsync-busy');
                }
            }, 0);
        }
    });

    // Coming back with the browser's Back button restores the page as it was left: re-arm its forms.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('[data-spsync-submitted]').forEach(function (form) {
                form.removeAttribute('data-spsync-submitted');
                form.querySelectorAll('[type="submit"]').forEach(function (button) { button.disabled = false; });
            });
        }
    });

    // Remember unsaved edits so auto-refresh never throws them away.
    document.querySelectorAll('.spsync form').forEach(function (form) {
        form.addEventListener('input', function () { form.classList.add('is-dirty'); });
    });

    // Show/hide a secret field.
    document.querySelectorAll('[data-spsync-toggle-secret]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-spsync-toggle-secret'));
            var hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            button.textContent = hidden ? 'Hide' : 'Show';
        });
    });

    // Copy to clipboard with inline feedback.
    document.querySelectorAll('[data-spsync-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-spsync-copy'));
            var label = button.textContent;
            var done = function () {
                button.textContent = 'Copied ✓';
                setTimeout(function () { button.textContent = label; }, 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(done);
            } else {
                var type = input.type;
                input.type = 'text';
                input.select();
                document.execCommand('copy');
                input.type = type;
                done();
            }
        });
    });

    // Schedule form: show only the relevant fields and a plain-language summary.
    var schedule = document.querySelector('[data-spsync-schedule]');
    if (schedule) {
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var value = function (name) { var el = schedule.querySelector('[name="' + name + '"]'); return el ? el.value : ''; };
        var updateSchedule = function () {
            var automatic = schedule.querySelector('[name="backup_method"]:checked').value === 'automatic';
            var fields = schedule.querySelector('[data-spsync-automatic]');
            var frequency = value('backup_frequency');
            // Disabled fields are not submitted, so switching to manual keeps the saved schedule.
            fields.disabled = !automatic;
            fields.hidden = !automatic;
            fields.querySelectorAll('[data-show-for]').forEach(function (row) {
                row.hidden = row.getAttribute('data-show-for') !== frequency;
            });
            var when = {
                daily: 'every day',
                weekly: 'every ' + days[parseInt(value('backup_day_of_week'), 10) || 0],
                monthly: 'on day ' + value('backup_day_of_month') + ' of every month',
                once: 'once, on ' + (value('backup_specific_date') || 'the chosen date')
            }[frequency];
            var keep = schedule.querySelector('[name="retention_limit"]');
            var keepText = keep.value === '0' ? 'never deletes old backups' : 'keeps the last ' + keep.value;
            schedule.querySelector('[data-spsync-summary]').textContent = automatic
                ? 'Backs up ' + when + ' at ' + (value('backup_time') || '02:00') + ' and ' + keepText + '.'
                : '';
        };
        schedule.addEventListener('change', updateSchedule);
        schedule.addEventListener('input', updateSchedule);
        updateSchedule();
    }

    // Backup contents: folder choices only apply to "Only some folders".
    var contents = document.querySelector('[data-spsync-contents]');
    if (contents) {
        var updateContents = function () {
            contents.querySelector('[data-spsync-selected]').disabled = contents.querySelector('[name="backup_scope"]:checked').value === 'full';
        };
        contents.addEventListener('change', updateContents);
        updateContents();
    }

    // Restore upload: drag-and-drop feedback, chosen file name, exactly one upload source.
    var upload = document.getElementById('spsync-upload-restore-form');
    if (upload) {
        var drop = upload.querySelector('.spsync-drop');
        var zip = document.getElementById('spsync-backup-zip');
        var name = upload.querySelector('[data-spsync-filename]');
        ['dragenter', 'dragover'].forEach(function (type) {
            drop.addEventListener(type, function () { drop.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            drop.addEventListener(type, function () { drop.classList.remove('is-dragover'); });
        });
        zip.addEventListener('change', function () {
            var file = zip.files[0];
            drop.classList.toggle('has-file', !!file);
            name.textContent = file ? file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)' : '';
        });
        upload.addEventListener('submit', function (event) {
            var chosen = ['spsync-backup-zip', 'spsync-backup-folder', 'spsync-backup-files'].filter(function (id) {
                return document.getElementById(id).files.length > 0;
            }).length;
            if (chosen !== 1) {
                event.preventDefault();
                event.stopImmediatePropagation();
                var error = document.getElementById('spsync-upload-error');
                error.textContent = chosen === 0 ? 'Choose a backup ZIP first.' : 'Use only one upload option: a ZIP, a folder, or individual files.';
                error.hidden = false;
                return;
            }
            var button = document.getElementById('spsync-upload-restore-button');
            if (chunkedUpload(event, button)) {
                return;
            }
            setTimeout(function () {
                button.disabled = true;
                button.textContent = 'Uploading and checking the backup… keep this tab open';
            }, 0);
        }, true);
    }

    /*
     * Chunked restore upload. A multi-GB backup sent as one request is refused by the web server
     * ("413 Request Entity Too Large") before WordPress even runs, so the file is sent in small
     * slices that the server appends in order. Slices shrink automatically if the server still
     * rejects them, failed slices are retried, and choosing the same file again resumes.
     */
    function chunkedUpload(event, button) {
        var endpoint = upload.getAttribute('data-spsync-chunked');
        if (!endpoint || !window.fetch || !window.FormData || !window.Blob || !Blob.prototype.slice) {
            return false; // classic form post
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        var MIN_CHUNK = 262144;
        var protectedNames = ['.htaccess', 'index.php', 'web.config'];
        var nonce = upload.querySelector('[name="_wpnonce"]').value;
        var box = upload.querySelector('[data-spsync-progress]');
        var bar = box.querySelector('progress');
        var text = box.querySelector('[data-spsync-progress-text]');
        var error = document.getElementById('spsync-upload-error');
        var label = button.textContent;
        var zipFile = document.getElementById('spsync-backup-zip').files[0];
        var folderInput = document.getElementById('spsync-backup-folder');
        var mode = zipFile ? 'zip' : 'files';
        var files = zipFile ? [zipFile] : Array.prototype.filter.call(
            (folderInput.files.length ? folderInput : document.getElementById('spsync-backup-files')).files,
            function (file) { return protectedNames.indexOf(file.name) === -1; }
        );
        var total = files.reduce(function (sum, file) { return sum + file.size; }, 0);
        var sent = {};
        var chunk = 0;
        var cancelled = false;
        var started = Date.now();
        var startBytes = 0;

        var wait = function (ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); };
        var mb = function (bytes) { return (bytes / 1048576).toFixed(bytes < 10485760 ? 1 : 0) + ' MB'; };
        var sentBytes = function () { return Object.keys(sent).reduce(function (sum, name) { return sum + sent[name]; }, 0); };
        var warnOnLeave = function (e) { e.preventDefault(); e.returnValue = ''; };

        function post(fields, blob) {
            var data = new FormData();
            data.append('action', 'spsync_restore_upload');
            data.append('_wpnonce', nonce);
            Object.keys(fields).forEach(function (key) { data.append(key, fields[key]); });
            if (blob) {
                data.append('chunk', blob, 'chunk.bin');
            }
            return fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) {
                return response.text().then(function (body) {
                    var json = null;
                    try { json = JSON.parse(body); } catch (ignore) {}
                    return { status: response.status, json: json };
                });
            }, function () { return { status: 0, json: null }; });
        }
        function message(reply, fallback) {
            return reply.json && reply.json.data && reply.json.data.message ? reply.json.data.message : fallback;
        }
        function showProgress() {
            var bytes = sentBytes();
            var seconds = (Date.now() - started) / 1000;
            var speed = seconds > 2 ? (bytes - startBytes) / seconds : 0;
            var left = speed > 0 ? Math.round((total - bytes) / speed) : 0;
            bar.value = total ? Math.floor(bytes / total * 100) : 0;
            text.textContent = 'Uploading ' + mb(bytes) + ' of ' + mb(total) + ' (' + bar.value + '%)' +
                (speed > 0 ? ' · ' + mb(speed) + '/s · about ' + (left > 90 ? Math.round(left / 60) + ' min' : left + ' s') + ' left' : '');
        }
        async function begin() {
            var reply = await post({
                step: 'begin',
                mode: mode,
                confirm_restore: '1',
                files: JSON.stringify(files.map(function (file) { return { name: file.name, size: file.size }; }))
            });
            if (!reply.json || !reply.json.success) {
                throw new Error(message(reply, 'The upload could not start (HTTP ' + reply.status + ').'));
            }
            return reply.json.data;
        }
        async function sendFile(file) {
            var failures = 0;
            while (sent[file.name] < file.size) {
                if (cancelled) {
                    throw new Error('cancelled');
                }
                var offset = sent[file.name];
                var reply = await post({ step: 'chunk', name: file.name, offset: offset }, file.slice(offset, Math.min(offset + chunk, file.size)));
                if (reply.status === 413) {
                    if (chunk <= MIN_CHUNK) {
                        throw new Error('The web server refuses even small upload pieces (HTTP 413). Ask your host to allow requests of at least 1 MB.');
                    }
                    chunk = Math.max(MIN_CHUNK, Math.floor(chunk / 2));
                    continue;
                }
                if (reply.json && reply.json.success) {
                    sent[file.name] = reply.json.data.received;
                    failures = 0;
                    showProgress();
                    continue;
                }
                var code = reply.json && reply.json.data ? reply.json.data.code : '';
                if (code === 'resync') {
                    sent = (await begin()).received;
                    continue;
                }
                if (reply.json && reply.status >= 400 && reply.status < 500 && !(reply.json.data && reply.json.data.retry)) {
                    throw new Error(message(reply, 'The upload was refused.'));
                }
                // Network error, server error or an incomplete piece: wait and send it again.
                if (++failures > 6) {
                    throw new Error('The connection to the server keeps failing. Check your connection, then choose the same file again to continue where it stopped.');
                }
                text.textContent = 'Connection problem; trying again…';
                await wait(Math.min(30000, 1000 * Math.pow(2, failures)));
            }
        }
        async function finish() {
            bar.removeAttribute('value');
            text.textContent = 'Upload complete. Checking the backup… a large backup can take several minutes.';
            var reply = await post({ step: 'finish' });
            if (reply.json && reply.json.success) {
                return reply.json.data.redirect;
            }
            if (reply.json && reply.status === 400) {
                throw new Error(message(reply, 'The backup could not be verified.'));
            }
            // The reply was lost (for example a proxy timeout) while the server keeps checking: ask for the result.
            for (var tries = 0; tries < 720; tries++) {
                await wait(5000);
                var status = await post({ step: 'status' });
                var state = status.json && status.json.success ? status.json.data : null;
                if (!state || state.phase === 'verifying' || state.phase === 'verified') {
                    continue;
                }
                if (state.redirect) {
                    return state.redirect;
                }
                if (state.phase === 'failed') {
                    throw new Error(state.message || 'The backup could not be verified.');
                }
                return window.location.href;
            }
            throw new Error('The server is taking unusually long to check the backup. Reload this page later to see the result.');
        }
        function reset(failure) {
            window.removeEventListener('beforeunload', warnOnLeave);
            button.disabled = false;
            button.textContent = label;
            box.hidden = true;
            if (failure) {
                error.textContent = failure;
                error.hidden = false;
            }
        }

        box.querySelector('[data-spsync-cancel-upload]').onclick = function () {
            if (window.confirm('Stop the upload and delete what was sent so far?')) {
                cancelled = true;
                post({ step: 'cancel' });
            }
        };
        error.hidden = true;
        button.disabled = true;
        button.textContent = 'Uploading… keep this tab open';
        box.hidden = false;
        bar.value = 0;
        text.textContent = 'Preparing the upload…';
        window.addEventListener('beforeunload', warnOnLeave);

        (async function () {
            var info = await begin();
            chunk = info.chunk_bytes;
            sent = info.received;
            startBytes = sentBytes();
            started = Date.now();
            if (info.resumed && startBytes > 0) {
                text.textContent = 'Continuing the earlier upload from ' + mb(startBytes) + '…';
            }
            for (var i = 0; i < files.length; i++) {
                await sendFile(files[i]);
            }
            var redirect = await finish();
            window.removeEventListener('beforeunload', warnOnLeave);
            window.location.href = redirect;
        }()).catch(function (failure) {
            reset(cancelled ? 'Upload cancelled.' : failure.message);
        });
        return true;
    }
}());
