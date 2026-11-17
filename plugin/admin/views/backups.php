<?php
/**
 * Backups: make one now, download a copy, see and restore the backups on SharePoint.
 *
 * @var array $backups StatusSummary::recent_backups()
 */
use SPSync\Admin\SettingsPage;
use SPSync\Admin\StatusSummary;
use SPSync\Backup\DeviceBackup;

if (!defined('ABSPATH')) {
    exit;
}

$configured = StatusSummary::sharepoint_configured();
$busy_reason = $configured ? \SPSync\Helpers\BackupLock::busy_reason() : null;
$device_job_id = get_user_meta(get_current_user_id(), 'spsync_device_backup', true);
$device_job = DeviceBackup::get($device_job_id, get_current_user_id());
$device_expired = $device_job && $device_job['expires_at'] <= time();
$contents = \SPSync\Backup\BackupSelection::get();
$contents_label = $contents['mode'] === 'full'
    ? 'the entire website and database'
    : (($contents['directories'] ? implode(', ', $contents['directories']) : 'no folders') . ($contents['database'] ? ' + database' : ''));
?>
<div class="spsync-columns">
    <section class="spsync-card" aria-labelledby="spsync-now-title">
        <h2 id="spsync-now-title" class="spsync-card__title">Back up to SharePoint</h2>
        <p>Saves <?php echo esc_html($contents_label); ?> to your SharePoint folder. Uploading continues in the background.</p>
        <?php if ($configured && $busy_reason !== null) : ?>
            <p><button type="button" class="button button-primary button-large" disabled>Backup in progress…</button></p>
            <p class="spsync-note spsync-note--active"><?php echo esc_html($busy_reason); ?></p>
        <?php elseif ($configured) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="spsync_run_manual_backup" />
                <?php wp_nonce_field('spsync_manual_backup_action'); ?>
                <button type="submit" class="button button-primary button-large" data-spsync-busy="Starting the backup…">Back up now</button>
            </form>
        <?php else : ?>
            <p class="spsync-note">Connect SharePoint first. <a href="<?php echo esc_url(SettingsPage::url('settings', ['section' => 'sharepoint'])); ?>">Open SharePoint settings</a></p>
        <?php endif; ?>
        <p class="spsync-muted spsync-small">Change what is included under <a href="<?php echo esc_url(SettingsPage::url('settings', ['section' => 'contents'])); ?>">Settings › Backup Contents</a>.</p>
    </section>

    <section class="spsync-card" id="download" aria-labelledby="spsync-download-title">
        <h2 id="spsync-download-title" class="spsync-card__title">Download a copy to your computer</h2>
        <p>Creates a ZIP you can keep on your own computer. No SharePoint account is needed.</p>

        <?php if ($device_job && !$device_expired && in_array($device_job['status'], ['queued', 'running'], true)) : ?>
            <p class="spsync-note spsync-note--active"><span class="spsync-spinner" aria-hidden="true"></span> Preparing your download… This page refreshes by itself.</p>
            <script>setTimeout(function () { window.location.reload(); }, 10000);</script>
        <?php elseif ($device_job && !$device_expired && $device_job['status'] === 'ready') : ?>
            <?php $download_url = wp_nonce_url(add_query_arg(['action' => 'spsync_download_backup', 'backup_job' => $device_job_id], admin_url('admin-post.php')), 'spsync_download_backup_' . $device_job_id); ?>
            <div class="spsync-note spsync-note--ok">
                <p><strong>Your download is ready</strong> · <?php echo esc_html($device_job['filename'] . ' · ' . StatusSummary::size($device_job['filesize'])); ?></p>
                <p class="spsync-small">Available until <?php echo esc_html(StatusSummary::when($device_job['expires_at'])); ?>.</p>
            </div>
            <p><a class="button button-primary button-large" href="<?php echo esc_url($download_url); ?>">Download ZIP to My Device</a></p>
        <?php elseif ($device_job && !$device_expired && $device_job['status'] === 'failed') : ?>
            <p class="spsync-note spsync-note--critical"><strong>The download could not be prepared.</strong> <?php echo esc_html($device_job['error']); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="spsync-inline">
            <input type="hidden" name="action" value="spsync_create_device_backup" />
            <?php wp_nonce_field('spsync_create_device_backup'); ?>
            <button type="submit" class="button<?php echo $device_job && !$device_expired && $device_job['status'] === 'ready' ? '' : ' button-primary button-large'; ?>">
                <?php echo $device_job && !$device_expired ? 'Create a new download' : 'Create Backup for My Device'; ?>
            </button>
        </form>
        <?php if ($device_job && $device_job['status'] !== 'running') : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="spsync-inline">
                <input type="hidden" name="action" value="spsync_discard_device_backup" />
                <input type="hidden" name="backup_job" value="<?php echo esc_attr($device_job_id); ?>" />
                <?php wp_nonce_field('spsync_discard_device_backup'); ?>
                <button type="submit" class="button-link spsync-link-muted">Remove the copy from the server</button>
            </form>
        <?php endif; ?>
        <p class="spsync-muted spsync-small">Downloads are kept on the server for 24 hours. To restore one later, upload the ZIP on the <a href="<?php echo esc_url(SettingsPage::url('restore')); ?>">Restore</a> screen.</p>
    </section>
</div>

<section class="spsync-card" aria-labelledby="spsync-list-title">
    <div class="spsync-card__header">
        <h2 id="spsync-list-title" class="spsync-card__title">Backups on SharePoint</h2>
        <span class="spsync-muted"><?php echo esc_html('Folder: ' . \SPSync\Storage\RemoteInventory::site_folder_name()); ?></span>
    </div>
    <?php if (!$backups) : ?>
        <p class="spsync-empty">No backups yet. <?php echo $configured ? 'Click “Back up now” to create the first one.' : 'Connect SharePoint to start.'; ?></p>
    <?php else : ?>
        <div class="spsync-table-wrap">
            <table class="spsync-table">
                <thead>
                    <tr>
                        <th scope="col">Taken</th>
                        <th scope="col">Size</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="screen-reader-text">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup) : ?>
                        <?php [$level, $label] = StatusSummary::backup_pill($backup['state']); ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html(StatusSummary::when($backup['time'])); ?></strong>
                                <span class="spsync-muted spsync-small spsync-block"><?php echo esc_html(StatusSummary::relative($backup['time'])); ?></span>
                            </td>
                            <td><?php echo esc_html(StatusSummary::size($backup['size'])); ?></td>
                            <td>
                                <span class="spsync-pill spsync-pill--<?php echo esc_attr($level); ?>"><?php echo esc_html($label); ?></span>
                                <?php if ($backup['state'] === 'uploading') : ?>
                                    <span class="spsync-muted spsync-small spsync-block"><?php echo esc_html($backup['uploaded'] . ' of ' . $backup['files'] . ' files'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="spsync-table__actions">
                                <?php if ($backup['state'] === 'completed') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                          data-spsync-confirm="<?php echo esc_attr('Restore the backup from ' . StatusSummary::when($backup['time']) . '?' . "\n\n" . 'This replaces the website’s files and database. A safety snapshot is taken first and used automatically if anything goes wrong.'); ?>">
                                        <input type="hidden" name="action" value="spsync_restore_remote" />
                                        <input type="hidden" name="backup_id" value="<?php echo esc_attr($backup['id']); ?>" />
                                        <input type="hidden" name="confirm_restore" value="" data-spsync-confirmed />
                                        <?php wp_nonce_field('spsync_restore_remote'); ?>
                                        <button type="submit" class="button">Restore</button>
                                    </form>
                                <?php elseif (in_array($backup['state'], ['uploading', 'failed'], true)) : ?>
                                    <?php $cancel_label = $backup['state'] === 'uploading' ? 'Cancel' : 'Remove'; ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                          data-spsync-confirm="<?php echo esc_attr($cancel_label . ' the unfinished backup from ' . StatusSummary::when($backup['time']) . '?' . "\n\n" . 'Its files are deleted from this server and from SharePoint. Your website is not changed.'); ?>">
                                        <input type="hidden" name="action" value="spsync_cancel_backup" />
                                        <input type="hidden" name="backup_id" value="<?php echo esc_attr($backup['id']); ?>" />
                                        <?php wp_nonce_field('spsync_cancel_backup'); ?>
                                        <button type="submit" class="button spsync-button-danger" data-spsync-busy="Cancelling…"><?php echo esc_html($cancel_label); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
