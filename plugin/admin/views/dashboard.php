<?php
/**
 * Dashboard: is this website protected, and what should I do next?
 *
 * @var array $overall  ['level','title','text'] from StatusSummary::overall()
 * @var array $backups  StatusSummary::recent_backups()
 */
use SPSync\Admin\SettingsPage;
use SPSync\Admin\StatusSummary;

if (!defined('ABSPATH')) {
    exit;
}

$steps = StatusSummary::setup_steps($backups);
$remaining = array_filter($steps, function ($step) { return !$step['done']; });
$last = StatusSummary::last_successful($backups);
$schedule = StatusSummary::schedule();
$connection = StatusSummary::connection();
$configured = StatusSummary::sharepoint_configured();
?>
<section class="spsync-hero spsync-hero--<?php echo esc_attr($overall['level']); ?>">
    <div class="spsync-hero__body">
        <h2 class="spsync-hero__title"><?php echo esc_html($overall['title']); ?></h2>
        <p class="spsync-hero__text"><?php echo esc_html($overall['text']); ?></p>
    </div>
    <div class="spsync-hero__actions">
        <?php if ($configured && \SPSync\Helpers\BackupLock::busy_reason() !== null) : ?>
            <button type="button" class="button button-primary button-hero" disabled title="<?php echo esc_attr(\SPSync\Helpers\BackupLock::busy_reason()); ?>">Backup in progress…</button>
        <?php elseif ($configured) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="spsync_run_manual_backup" />
                <?php wp_nonce_field('spsync_manual_backup_action'); ?>
                <button type="submit" class="button button-primary button-hero" data-spsync-busy="Starting the backup…">Back up now</button>
            </form>
        <?php else : ?>
            <a class="button button-primary button-hero" href="<?php echo esc_url(SettingsPage::url('settings', ['section' => 'sharepoint'])); ?>">Connect SharePoint</a>
        <?php endif; ?>
        <a class="button button-hero" href="<?php echo esc_url(SettingsPage::url('backups') . '#download'); ?>">Download a copy</a>
    </div>
</section>

<?php if ($remaining) : ?>
    <section class="spsync-card" aria-labelledby="spsync-setup-title">
        <div class="spsync-card__header">
            <h2 id="spsync-setup-title" class="spsync-card__title">Get set up</h2>
            <span class="spsync-muted"><?php echo esc_html((count($steps) - count($remaining)) . ' of ' . count($steps) . ' done'); ?></span>
        </div>
        <ol class="spsync-steps">
            <?php foreach ($steps as $index => $step) : ?>
                <li class="spsync-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
                    <span class="spsync-step__marker" aria-hidden="true"><?php echo $step['done'] ? '✓' : esc_html((string) ($index + 1)); ?></span>
                    <div class="spsync-step__body">
                        <strong><?php echo esc_html($step['title']); ?></strong>
                        <span class="spsync-muted"><?php echo esc_html($step['done'] ? 'Done' : $step['text']); ?></span>
                    </div>
                    <?php if (!$step['done']) : ?>
                        <a class="button" href="<?php echo esc_url($step['url']); ?>"><?php echo esc_html($step['cta']); ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<div class="spsync-stats">
    <div class="spsync-stat">
        <span class="spsync-stat__label">Last successful backup</span>
        <strong class="spsync-stat__value"><?php echo esc_html($last ? StatusSummary::relative($last['time']) : 'None yet'); ?></strong>
        <span class="spsync-muted"><?php echo esc_html($last ? StatusSummary::when($last['time']) . ' · ' . StatusSummary::size($last['size']) : 'Run a backup to create the first one.'); ?></span>
    </div>
    <div class="spsync-stat">
        <span class="spsync-stat__label">Next automatic backup</span>
        <strong class="spsync-stat__value"><?php echo esc_html($schedule['next'] ? StatusSummary::relative($schedule['next']) : 'Not scheduled'); ?></strong>
        <span class="spsync-muted"><?php echo esc_html($schedule['label']); ?></span>
    </div>
    <div class="spsync-stat">
        <span class="spsync-stat__label">SharePoint</span>
        <strong class="spsync-stat__value">
            <?php
            if (!$configured) {
                echo 'Not connected';
            } elseif (!$connection) {
                echo 'Not tested yet';
            } else {
                echo $connection['success'] ? 'Connected' : 'Connection failed';
            }
            ?>
        </strong>
        <span class="spsync-muted">
            <?php echo esc_html($connection ? 'Checked ' . StatusSummary::relative($connection['time']) : 'Folder: ' . \SPSync\Storage\RemoteInventory::site_folder_name()); ?>
        </span>
    </div>
</div>

<section class="spsync-card" aria-labelledby="spsync-latest-title">
    <div class="spsync-card__header">
        <h2 id="spsync-latest-title" class="spsync-card__title">Latest backups</h2>
        <a href="<?php echo esc_url(SettingsPage::url('backups')); ?>">View all backups →</a>
    </div>
    <?php if (!$backups) : ?>
        <p class="spsync-empty">No backups on SharePoint yet.</p>
    <?php else : ?>
        <ul class="spsync-list">
            <?php foreach (array_slice($backups, 0, 5) as $backup) : ?>
                <?php [$level, $label] = StatusSummary::backup_pill($backup['state']); ?>
                <li class="spsync-list__item">
                    <span><?php echo esc_html(StatusSummary::when($backup['time'])); ?></span>
                    <span class="spsync-muted"><?php echo esc_html(StatusSummary::size($backup['size'])); ?></span>
                    <span class="spsync-pill spsync-pill--<?php echo esc_attr($level); ?>"><?php echo esc_html($label); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
