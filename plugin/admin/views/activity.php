<?php
/**
 * Activity: what the plugin did, newest first, in the site's timezone.
 */
use SPSync\Admin\SettingsPage;
use SPSync\Admin\StatusSummary;

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$filters = ['normal' => 'Everything', 'problems' => 'Problems only', 'all' => 'Including technical details'];
$filter = isset($_GET['show']) && isset($filters[$_GET['show']]) ? sanitize_key($_GET['show']) : 'normal';
$table = $wpdb->prefix . 'spsync_logs';
$levels = ['problems' => "('error','warning')", 'normal' => "('error','warning','info')", 'all' => "('error','warning','info','debug')"][$filter];

$logs = [];
if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
    $logs = $wpdb->get_results("SELECT * FROM $table WHERE level IN $levels ORDER BY id DESC LIMIT 100", ARRAY_A);
}
$pill = ['error' => ['critical', 'Error'], 'warning' => ['warning', 'Warning'], 'info' => ['neutral', 'Info'], 'debug' => ['neutral', 'Debug']];
?>
<section class="spsync-card" aria-labelledby="spsync-activity-title">
    <div class="spsync-card__header">
        <h2 id="spsync-activity-title" class="spsync-card__title">Activity</h2>
        <div class="spsync-segmented" role="group" aria-label="Filter activity">
            <?php foreach ($filters as $key => $label) : ?>
                <a href="<?php echo esc_url(SettingsPage::url('activity', ['show' => $key])); ?>" class="spsync-segmented__item<?php echo $filter === $key ? ' is-active' : ''; ?>" <?php echo $filter === $key ? 'aria-current="true"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$logs) : ?>
        <p class="spsync-empty"><?php echo $filter === 'problems' ? 'No problems recorded. 🎉' : 'Nothing has happened yet.'; ?></p>
    <?php else : ?>
        <ul class="spsync-log">
            <?php foreach ($logs as $log) : ?>
                <?php
                $level = strtolower($log['level'] ?? 'info');
                [$pill_level, $pill_label] = $pill[$level] ?? ['neutral', ucfirst($level)];
                $time = !empty($log['created_at']) ? strtotime($log['created_at'] . ' UTC') : 0;
                $context = !empty($log['context']) ? json_decode($log['context'], true) : null;
                ?>
                <li class="spsync-log__item spsync-log__item--<?php echo esc_attr($pill_level); ?>">
                    <span class="spsync-pill spsync-pill--<?php echo esc_attr($pill_level); ?>"><?php echo esc_html($pill_label); ?></span>
                    <div class="spsync-log__body">
                        <p class="spsync-log__message"><?php echo esc_html($log['message']); ?></p>
                        <?php if ($context) : ?>
                            <details class="spsync-log__context">
                                <summary>Technical details</summary>
                                <pre><?php echo esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                    <time class="spsync-log__time" datetime="<?php echo esc_attr($time ? gmdate('c', $time) : ''); ?>" title="<?php echo esc_attr(StatusSummary::when($time)); ?>"><?php echo esc_html(StatusSummary::relative($time)); ?></time>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="spsync-muted spsync-small">Showing the latest 100 entries. Entries older than 30 days are removed automatically.</p>
    <?php endif; ?>
</section>
