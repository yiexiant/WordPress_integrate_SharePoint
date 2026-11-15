<?php
/**
 * Settings, split into small sections with a side menu.
 *
 * @var string $section One of SettingsPage::SECTIONS
 */
use SPSync\Admin\SettingsPage;
use SPSync\Admin\StatusSummary;
use SPSync\Helpers\Settings;
use SPSync\Storage\RemoteInventory;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="spsync-settings">
    <nav class="spsync-subnav" aria-label="Settings sections">
        <?php foreach (SettingsPage::SECTIONS as $key => $label) : ?>
            <a href="<?php echo esc_url(SettingsPage::url('settings', ['section' => $key])); ?>" class="spsync-subnav__item<?php echo $section === $key ? ' is-active' : ''; ?>" <?php echo $section === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="spsync-settings__panel">

<?php if ($section === 'schedule') : ?>
    <?php
    $frequency = Settings::get('backup_frequency', 'manual');
    $automatic = $frequency !== 'manual';
    $retention = Settings::get('retention_limit', '30');
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    ?>
    <form method="post" action="" class="spsync-card" data-spsync-schedule>
        <?php wp_nonce_field('spsync_save_settings_action'); ?>
        <h2 class="spsync-card__title">When should backups run?</h2>

        <div class="spsync-choices" role="radiogroup" aria-label="Backup method">
            <label class="spsync-choice">
                <input type="radio" name="backup_method" value="automatic" <?php checked($automatic); ?> />
                <span class="spsync-choice__body">
                    <strong>Automatically, to SharePoint</strong> <span class="spsync-pill spsync-pill--ok">Recommended</span>
                    <span class="spsync-muted">Runs on a schedule, even when nobody is logged in.</span>
                </span>
            </label>
            <label class="spsync-choice">
                <input type="radio" name="backup_method" value="manual" <?php checked($frequency, 'manual'); ?> />
                <span class="spsync-choice__body">
                    <strong>Only when I start one</strong>
                    <span class="spsync-muted">Use “Back up now” or download a copy yourself.</span>
                </span>
            </label>
        </div>

        <fieldset class="spsync-fields" data-spsync-automatic>
            <legend class="screen-reader-text">Schedule</legend>
            <div class="spsync-field">
                <label for="backup_frequency">How often</label>
                <select name="backup_frequency" id="backup_frequency">
                    <option value="daily" <?php selected($frequency, 'daily'); ?>>Every day</option>
                    <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Every week</option>
                    <option value="monthly" <?php selected($frequency, 'monthly'); ?>>Every month</option>
                    <option value="once" <?php selected($frequency, 'once'); ?>>Just once</option>
                </select>
            </div>
            <div class="spsync-field" data-show-for="weekly">
                <label for="backup_day_of_week">On</label>
                <select name="backup_day_of_week" id="backup_day_of_week">
                    <?php foreach ($days as $index => $day) : ?>
                        <option value="<?php echo esc_attr((string) $index); ?>" <?php selected(Settings::get('backup_day_of_week', '0'), (string) $index); ?>><?php echo esc_html($day); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spsync-field" data-show-for="monthly">
                <label for="backup_day_of_month">On day</label>
                <select name="backup_day_of_month" id="backup_day_of_month">
                    <?php for ($day = 1; $day <= 28; $day++) : ?>
                        <option value="<?php echo esc_attr((string) $day); ?>" <?php selected((int) Settings::get('backup_day_of_month', '1'), $day); ?>><?php echo esc_html((string) $day); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="spsync-field" data-show-for="once">
                <label for="backup_specific_date">On date</label>
                <input type="date" name="backup_specific_date" id="backup_specific_date" value="<?php echo esc_attr(Settings::get('backup_specific_date', '')); ?>" min="<?php echo esc_attr(wp_date('Y-m-d')); ?>" />
            </div>
            <div class="spsync-field">
                <label for="backup_time">At</label>
                <input type="time" name="backup_time" id="backup_time" value="<?php echo esc_attr(Settings::get('backup_time', '02:00')); ?>" />
            </div>
            <div class="spsync-field">
                <label for="retention_limit">Keep</label>
                <select name="retention_limit" id="retention_limit">
                    <?php foreach (['5' => 'the last 5 backups', '10' => 'the last 10 backups', '30' => 'the last 30 backups', '50' => 'the last 50 backups', '100' => 'the last 100 backups', '0' => 'every backup (never delete)'] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($retention, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="spsync-summary" data-spsync-summary aria-live="polite"></p>
            <p class="spsync-muted spsync-small">Times use the website’s timezone (<?php echo esc_html(function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC'); ?>). Older backups beyond the limit are deleted from SharePoint after each successful backup.</p>
        </fieldset>

        <p class="spsync-actions"><button type="submit" name="spsync_save_settings" value="1" class="button button-primary">Save schedule</button></p>
    </form>
<?php endif; ?>

<?php if ($section === 'contents') : ?>
    <?php $selection = \SPSync\Backup\BackupSelection::get(); $directories = \SPSync\Backup\BackupSelection::directories(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="spsync-card" data-spsync-contents>
        <input type="hidden" name="action" value="spsync_save_contents" />
        <?php wp_nonce_field('spsync_save_contents'); ?>
        <h2 class="spsync-card__title">Backup Contents</h2>
        <p>Choose what every backup includes: scheduled ones, “Back up now”, downloads and backups started from the central dashboard.</p>

        <div class="spsync-choices">
            <label class="spsync-choice">
                <input type="radio" name="backup_scope" value="full" <?php checked($selection['mode'], 'full'); ?> />
                <span class="spsync-choice__body">
                    <strong>The whole website</strong> <span class="spsync-pill spsync-pill--ok">Recommended</span>
                    <span class="spsync-muted">WordPress, themes, plugins, uploads and the database. Caches and logs are skipped.</span>
                </span>
            </label>
            <label class="spsync-choice">
                <input type="radio" name="backup_scope" value="selected" <?php checked($selection['mode'], 'selected'); ?> />
                <span class="spsync-choice__body">
                    <strong>Only some folders</strong>
                    <span class="spsync-muted">Pick folders inside wp-content, with or without the database.</span>
                </span>
            </label>
        </div>

        <fieldset class="spsync-fields" data-spsync-selected>
            <legend>Folders in wp-content</legend>
            <?php if (!$directories) : ?>
                <p class="spsync-muted">No readable folders were found inside wp-content.</p>
            <?php else : ?>
                <div class="spsync-chips">
                    <?php foreach ($directories as $name => $path) : ?>
                        <label class="spsync-chip"><input type="checkbox" name="backup_directories[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $selection['directories'], true)); ?> /> <?php echo esc_html($name); ?></label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <label class="spsync-check"><input type="checkbox" name="backup_include_database" value="1" <?php checked(Settings::get('backup_include_database', '0'), '1'); ?> /> Include the database (posts, pages, users and settings)</label>
        </fieldset>

        <p class="spsync-actions"><button type="submit" class="button button-primary">Save Backup Contents</button></p>
    </form>
<?php endif; ?>

<?php if ($section === 'sharepoint') : ?>
    <?php
    $connection = StatusSummary::connection();
    $has_secret = Settings::get('client_secret') !== '';
    ?>
    <?php if ($connection) : ?>
        <div class="spsync-note spsync-note--<?php echo $connection['success'] ? 'ok' : 'critical'; ?>">
            <p><strong><?php echo $connection['success'] ? 'Connected to SharePoint.' : 'The last connection test failed.'; ?></strong>
                <?php echo esc_html($connection['message']); ?>
                <span class="spsync-muted">(<?php echo esc_html(StatusSummary::relative($connection['time'])); ?>)</span></p>
        </div>
    <?php endif; ?>

    <form method="post" action="" class="spsync-card">
        <?php wp_nonce_field('spsync_save_settings_action'); ?>
        <h2 class="spsync-card__title">Microsoft 365 sign-in</h2>
        <p>These come from an app registration in Microsoft Entra ID. <a href="#spsync-help">Where do I find them?</a></p>
        <div class="spsync-grid-2">
            <div class="spsync-field">
                <label for="tenant_id">Tenant ID</label>
                <input name="tenant_id" type="text" id="tenant_id" value="<?php echo esc_attr(Settings::get('tenant_id')); ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" spellcheck="false" />
            </div>
            <div class="spsync-field">
                <label for="client_id">Client ID</label>
                <input name="client_id" type="text" id="client_id" value="<?php echo esc_attr(Settings::get('client_id')); ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" spellcheck="false" />
            </div>
        </div>
        <div class="spsync-field">
            <label for="client_secret">Client Secret</label>
            <div class="spsync-input-group">
                <input name="client_secret" type="password" id="client_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($has_secret ? 'Saved. Leave empty to keep it' : 'Paste the secret value'); ?>" />
                <button type="button" class="button" data-spsync-toggle-secret="client_secret">Show</button>
            </div>
            <label class="spsync-check"><input name="encrypt_secret" type="checkbox" id="encrypt_secret" value="1" <?php checked(Settings::get('encrypt_secret', '1'), '1'); ?> /> Store the secret encrypted (recommended)</label>
        </div>

        <h2 class="spsync-card__title spsync-card__title--spaced">Where backups are saved</h2>
        <div class="spsync-field">
            <label for="site_url">SharePoint site address</label>
            <input name="site_url" type="url" id="site_url" value="<?php echo esc_attr(Settings::get('site_url')); ?>" placeholder="https://contoso.sharepoint.com/sites/WebsiteBackups" />
        </div>
        <div class="spsync-grid-2">
            <div class="spsync-field">
                <label for="document_library">Document library</label>
                <input name="document_library" type="text" id="document_library" value="<?php echo esc_attr(Settings::get('document_library')); ?>" placeholder="Documents" />
            </div>
            <div class="spsync-field">
                <label for="root_folder">Main backup folder</label>
                <input name="root_folder" type="text" id="root_folder" value="<?php echo esc_attr(Settings::get('root_folder')); ?>" placeholder="WordPress Backups" />
            </div>
        </div>
        <div class="spsync-field">
            <label for="site_folder">This website’s folder</label>
            <input name="site_folder" type="text" id="site_folder" value="<?php echo esc_attr(RemoteInventory::site_folder_name()); ?>" spellcheck="false" />
            <p class="spsync-muted spsync-small">Each website needs its own folder inside the main folder, so websites never see or delete each other’s backups. Set it before the first backup; renaming it later hides older backups.</p>
        </div>
        <p class="spsync-path"><span class="spsync-muted">Backups go to:</span> <code><?php echo esc_html(trim((Settings::get('document_library') ?: 'Documents') . ' / ' . (Settings::get('root_folder') ?: 'WordPress Backups') . ' / ' . RemoteInventory::site_folder_name())); ?></code></p>

        <p class="spsync-actions"><button type="submit" name="spsync_save_settings" value="1" class="button button-primary">Save SharePoint settings</button></p>
    </form>

    <section class="spsync-card">
        <h2 class="spsync-card__title">Test the connection</h2>
        <p>Signs in to Microsoft, finds the site and library, and creates the backup folders if they are missing.</p>
        <?php if (StatusSummary::sharepoint_configured()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="spsync_test_connection" />
                <?php wp_nonce_field('spsync_test_conn_action'); ?>
                <button type="submit" class="button" data-spsync-busy="Testing…">Test connection</button>
            </form>
        <?php else : ?>
            <p class="spsync-note">Fill in and save the Tenant ID, Client ID, Client Secret and site address first.</p>
        <?php endif; ?>
    </section>

    <details class="spsync-card spsync-details" id="spsync-help">
        <summary>Where do I find these values?</summary>
        <ol>
            <li>Sign in to <strong>entra.microsoft.com</strong> → <strong>App registrations</strong> → <strong>New registration</strong> (single tenant).</li>
            <li>On the app’s <strong>Overview</strong>, copy the <strong>Directory (tenant) ID</strong> and <strong>Application (client) ID</strong>.</li>
            <li><strong>Certificates &amp; secrets → New client secret</strong>: copy the secret’s <strong>Value</strong> (not its ID). Note the expiry date.</li>
            <li><strong>API permissions → Microsoft Graph → Application → Sites.ReadWrite.All</strong>, then <strong>Grant admin consent</strong>.</li>
            <li>The site address is the SharePoint site’s URL, e.g. <code>https://contoso.sharepoint.com/sites/WebsiteBackups</code>. The library is usually <strong>Documents</strong>.</li>
        </ol>
        <p class="spsync-muted spsync-small">Full instructions, including a least-privilege option, are in the plugin’s DEPLOYMENT.md.</p>
    </details>
<?php endif; ?>

<?php if ($section === 'dashboard') : ?>
    <section class="spsync-card">
        <h2 class="spsync-card__title">Central Dashboard</h2>
        <p>Monitor many websites from one place. In the central dashboard, click <strong>Add website</strong> and enter this website’s address and the key below.</p>
        <div class="spsync-field">
            <label for="spsync-api-key-input">Secret API key</label>
            <div class="spsync-input-group">
                <input type="password" id="spsync-api-key-input" value="<?php echo esc_attr(Settings::get('api_key')); ?>" readonly class="code" />
                <button type="button" class="button" data-spsync-toggle-secret="spsync-api-key-input">Show</button>
                <button type="button" class="button button-primary" data-spsync-copy="spsync-api-key-input">Copy key</button>
            </div>
            <p class="spsync-muted spsync-small">Treat it like a password: anyone with this key can start backups and restores on this website.</p>
        </div>
        <div class="spsync-field">
            <label for="spsync-site-address">Website address</label>
            <input type="text" id="spsync-site-address" value="<?php echo esc_attr(home_url()); ?>" readonly class="code" />
        </div>
    </section>
<?php endif; ?>

    </div>
</div>
