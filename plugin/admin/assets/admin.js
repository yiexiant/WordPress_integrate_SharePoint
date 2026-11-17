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
            setTimeout(function () {
                button.disabled = true;
                button.textContent = 'Uploading and checking the backup… keep this tab open';
            }, 0);
        }, true);
    }
}());
