<?php
/**
 * Restore: upload a backup and put it back, and see how the last restore went.
 */
use SPSync\Admin\SettingsPage;
use SPSync\Admin\StatusSummary;
use SPSync\Helpers\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$pending_directory = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
$pending = $pending_directory ? \SPSync\Restore\LocalBackup::validate($pending_directory) : null;
$restore_status = Settings::get('restore_status', 'idle');
$restore_error = Settings::get('restore_error');
$running = in_array($restore_status, StatusSummary::ACTIVE_RESTORE, true);
?>
<?php if ($restore_status === 'completed' && Settings::get('restore_job_active')) : ?>
    <div class="spsync-note spsync-note--ok"><p><strong>The last restore finished successfully.</strong> Log in again if you were signed out; restored users and passwords come from the backup.</p></div>
<?php elseif ($restore_status === 'failed') : ?>
    <div class="spsync-note spsync-note--critical">
        <p><strong>The last restore failed, and the website was put back as it was.</strong></p>
        <?php if ($restore_error) : ?><p><?php echo esc_html($restore_error); ?></p><?php endif; ?>
        <?php if (Settings::get('manual_restore_directory')) : ?>
            <p class="spsync-small">Recovery files for your server administrator: <code><?php echo esc_html(Settings::get('manual_restore_directory')); ?></code></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($pending && !is_wp_error($pending)) : ?>
    <section class="spsync-card" aria-labelledby="spsync-pending-title">
        <h2 id="spsync-pending-title" class="spsync-card__title">A checked backup is waiting</h2>
        <p>You uploaded <strong><?php echo esc_html($pending['backup_id']); ?></strong> earlier and it passed all checks, but it was not restored yet. You can restore it now without uploading it again.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="spsync_restore_uploaded" />
            <?php wp_nonce_field('spsync_restore_uploaded'); ?>
            <label class="spsync-check"><input type="checkbox" name="confirm_restore" value="1" required /> I understand this replaces the website’s files and database with this backup.</label>
            <p class="spsync-actions">
                <button type="submit" class="button button-primary">Restore Verified Backup Now</button>
            </p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="spsync_discard_upload" />
            <?php wp_nonce_field('spsync_discard_upload'); ?>
            <button type="submit" class="button-link spsync-link-muted">Discard this upload</button>
        </form>
    </section>
<?php elseif (is_wp_error($pending)) : ?>
    <div class="spsync-note spsync-note--warning"><p><?php echo esc_html($pending->get_error_message()); ?></p></div>
<?php endif; ?>

<section class="spsync-card" aria-labelledby="spsync-upload-title">
    <h2 id="spsync-upload-title" class="spsync-card__title">Restore from a backup file</h2>
    <p>Upload a backup ZIP made by this plugin, for example one you downloaded on the <a href="<?php echo esc_url(SettingsPage::url('backups')); ?>">Backups</a> screen. Everything is checked before anything on the website changes. To restore a backup that is on SharePoint, use the <strong>Restore</strong> button next to it on the <a href="<?php echo esc_url(SettingsPage::url('backups')); ?>">Backups</a> screen.</p>

    <?php if ($running) : ?>
        <p class="spsync-note spsync-note--active"><span class="spsync-spinner" aria-hidden="true"></span> A restore is running. Wait until it finishes.</p>
    <?php endif; ?>

    <form id="spsync-upload-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          data-spsync-chunked="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <input type="hidden" name="action" value="spsync_upload_backup" />
        <?php wp_nonce_field('spsync_upload_backup'); ?>

        <label class="spsync-drop" for="spsync-backup-zip">
            <span class="spsync-drop__title">Choose a backup ZIP</span>
            <span class="spsync-drop__hint">or drag it here · any size: large backups are sent in small pieces</span>
            <span class="spsync-drop__file" data-spsync-filename></span>
            <input id="spsync-backup-zip" type="file" name="backup_zip" accept=".zip,application/zip" />
        </label>

        <details class="spsync-details">
            <summary>Other ways to upload (an unzipped backup folder or its files)</summary>
            <p><label for="spsync-backup-folder"><strong>Backup folder</strong></label><br /><input id="spsync-backup-folder" type="file" name="backup_folder[]" webkitdirectory directory multiple /></p>
            <p><label for="spsync-backup-files"><strong>Individual backup files</strong> (manifest.json, database.sql, backup_part*.zip)</label><br /><input id="spsync-backup-files" type="file" name="backup_files[]" accept=".json,.sql,.zip" multiple /></p>
            <p class="spsync-muted spsync-small">Files are sent in pieces, so the server’s upload limit (<?php echo esc_html(size_format(wp_max_upload_size())); ?>) does not apply. Without JavaScript the limit is <?php echo esc_html(size_format(wp_max_upload_size())); ?> per file and <?php echo esc_html(ini_get('post_max_size')); ?> per upload.</p>
        </details>

        <div class="spsync-warning-box">
            <strong>Before you restore</strong>
            <ul>
                <li>The website’s files and database are replaced with the ones in the backup.</li>
                <li>Users, passwords and settings come from the backup, so you may need to log in again.</li>
                <li>A safety snapshot is taken first and put back automatically if the restore fails.</li>
            </ul>
            <label class="spsync-check"><input type="checkbox" name="confirm_restore" value="1" required /> I understand that restoring replaces this website’s files and database.</label>
        </div>

        <div class="spsync-upload-progress" data-spsync-progress hidden>
            <progress max="100" value="0" aria-label="Upload progress"></progress>
            <p class="spsync-upload-progress__text" data-spsync-progress-text aria-live="polite"></p>
            <p class="spsync-muted spsync-small">Keep this tab open. If the upload is interrupted, choose the same file again: it continues where it stopped.</p>
            <button type="button" class="button-link spsync-link-muted" data-spsync-cancel-upload>Cancel upload</button>
        </div>
        <p id="spsync-upload-error" class="spsync-error" role="alert" hidden></p>
        <p class="spsync-actions">
            <button id="spsync-upload-restore-button" type="submit" class="button button-primary button-large" <?php disabled($running); ?>>Upload, Verify &amp; Restore</button>
        </p>
    </form>
</section>
