<?php
/**
 * Plugin Name: Miyamoto Jobs Importer
 * Description: Imports the Miyamoto jobs RSS feed into a JetEngine custom post type on a six-hour schedule.
 * Version: 1.2.0
 * Author: Miyamoto International
 * License: GPL-2.0-or-later
 * Text Domain: miyamoto-jobs-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Miyamoto_Jobs_Importer
{
    private const OPTION_NAME = 'miyamoto_jobs_importer_options';
    private const LAST_RESULT_OPTION = 'miyamoto_jobs_importer_last_result';
    private const NOTICE_TRANSIENT = 'miyamoto_jobs_importer_admin_notice';
    private const CRON_HOOK = 'miyamoto_jobs_importer_cron';
    private const SCHEDULE = 'miyamoto_jobs_every_six_hours';
    private const DEFAULT_FEED_URL = 'https://relec.github.io/miyamoto-jobs-rss/jobs.xml';
    private const UKG_NAMESPACE_URL = 'https://relec.github.io/miyamoto-jobs-rss/ukg';

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'add_cron_schedule']);
        add_action(self::CRON_HOOK, [self::class, 'run_import']);
        add_action('admin_menu', [self::class, 'add_settings_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_post_miyamoto_jobs_import_now', [self::class, 'handle_manual_import']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_shortcode('miyamoto_job_description', [self::class, 'render_description_shortcode']);
    }

    public static function activate(): void
    {
        add_option(self::OPTION_NAME, self::default_options());
        self::schedule_import();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function add_cron_schedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 hours', 'miyamoto-jobs-importer'),
            ];
        }

        return $schedules;
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            __('Miyamoto Jobs Importer', 'miyamoto-jobs-importer'),
            __('Miyamoto Jobs Importer', 'miyamoto-jobs-importer'),
            'manage_options',
            'miyamoto-jobs-importer',
            [self::class, 'render_settings_page']
        );
    }

    public static function enqueue_assets(): void
    {
        wp_enqueue_style(
            'miyamoto-jobs-importer',
            plugin_dir_url(__FILE__) . 'assets/miyamoto-jobs-importer.css',
            [],
            '1.2.0'
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            'miyamoto_jobs_importer',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_options'],
                'default' => self::default_options(),
            ]
        );
    }

    public static function sanitize_options($input): array
    {
        $defaults = self::default_options();
        $input = is_array($input) ? $input : [];

        $post_status = sanitize_key($input['post_status'] ?? $defaults['post_status']);
        if (!in_array($post_status, ['publish', 'draft', 'private'], true)) {
            $post_status = $defaults['post_status'];
        }

        $feed_url = esc_url_raw($input['feed_url'] ?? $defaults['feed_url']);
        $post_type = sanitize_key($input['post_type'] ?? $defaults['post_type']);

        return [
            'feed_url' => $feed_url ?: $defaults['feed_url'],
            'post_type' => $post_type ?: $defaults['post_type'],
            'post_status' => $post_status,
        ];
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::schedule_import();

        $options = self::get_options();
        $last_result = get_option(self::LAST_RESULT_OPTION);
        $notice = get_transient(self::NOTICE_TRANSIENT);

        if ($notice) {
            delete_transient(self::NOTICE_TRANSIENT);
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Miyamoto Jobs Importer', 'miyamoto-jobs-importer'); ?></h1>
            <p>
                <?php esc_html_e('Imports the jobs RSS feed into your JetEngine custom post type and keeps imported jobs current on a six-hour WP-Cron schedule.', 'miyamoto-jobs-importer'); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('miyamoto_jobs_importer'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="miyamoto-feed-url"><?php esc_html_e('RSS feed URL', 'miyamoto-jobs-importer'); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                class="regular-text"
                                id="miyamoto-feed-url"
                                name="<?php echo esc_attr(self::OPTION_NAME); ?>[feed_url]"
                                value="<?php echo esc_attr($options['feed_url']); ?>"
                                required
                            />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="miyamoto-post-type"><?php esc_html_e('JetEngine post type slug', 'miyamoto-jobs-importer'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="miyamoto-post-type"
                                name="<?php echo esc_attr(self::OPTION_NAME); ?>[post_type]"
                                value="<?php echo esc_attr($options['post_type']); ?>"
                                required
                            />
                            <p class="description">
                                <?php esc_html_e('Use the post type slug from JetEngine. Example: jobs, job, careers, or job_listing.', 'miyamoto-jobs-importer'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="miyamoto-post-status"><?php esc_html_e('Active imported job status', 'miyamoto-jobs-importer'); ?></label>
                        </th>
                        <td>
                            <select id="miyamoto-post-status" name="<?php echo esc_attr(self::OPTION_NAME); ?>[post_status]">
                                <option value="publish" <?php selected($options['post_status'], 'publish'); ?>><?php esc_html_e('Publish', 'miyamoto-jobs-importer'); ?></option>
                                <option value="draft" <?php selected($options['post_status'], 'draft'); ?>><?php esc_html_e('Draft', 'miyamoto-jobs-importer'); ?></option>
                                <option value="private" <?php selected($options['post_status'], 'private'); ?>><?php esc_html_e('Private', 'miyamoto-jobs-importer'); ?></option>
                            </select>
                        </td>
                    </tr>

                </table>

                <?php submit_button(__('Save Settings', 'miyamoto-jobs-importer')); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Manual Import', 'miyamoto-jobs-importer'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('miyamoto_jobs_import_now'); ?>
                <input type="hidden" name="action" value="miyamoto_jobs_import_now" />
                <?php submit_button(__('Run import now', 'miyamoto-jobs-importer'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('Status', 'miyamoto-jobs-importer'); ?></h2>
            <p>
                <?php echo esc_html(self::next_run_message()); ?>
            </p>
            <p>
                <?php esc_html_e('Sync mode: after the feed is fetched successfully, this plugin deletes posts previously created by this importer and recreates the current feed items.', 'miyamoto-jobs-importer'); ?>
            </p>
            <?php if (is_array($last_result)) : ?>
                <p>
                    <strong><?php esc_html_e('Last run:', 'miyamoto-jobs-importer'); ?></strong>
                    <?php echo esc_html(self::format_last_result($last_result)); ?>
                </p>
            <?php endif; ?>

            <h2><?php esc_html_e('Meta Field Mapping', 'miyamoto-jobs-importer'); ?></h2>
            <table class="widefat striped" style="max-width: 720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('RSS field', 'miyamoto-jobs-importer'); ?></th>
                        <th><?php esc_html_e('JetEngine meta key', 'miyamoto-jobs-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>title</td><td><code>title</code></td></tr>
                    <tr><td>link</td><td><code>link</code></td></tr>
                    <tr><td>category</td><td><code>category</code></td></tr>
                    <tr><td>pubDate</td><td><code>pubDate</code></td></tr>
                    <tr><td>jobLocationType</td><td><code>jobLocationType</code></td></tr>
                    <tr><td>description</td><td><code>_description</code></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('Description Formatting', 'miyamoto-jobs-importer'); ?></h2>
            <p>
                <?php esc_html_e('For the existing JetEngine Dynamic Field that outputs _description, add this CSS class to the field or wrapper:', 'miyamoto-jobs-importer'); ?>
                <code>miyamoto-job-description</code>
            </p>
            <p>
                <?php esc_html_e('Or replace the description field with this shortcode in the listing template:', 'miyamoto-jobs-importer'); ?>
                <code>[miyamoto_job_description]</code>
            </p>
        </div>
        <?php
    }

    public static function render_description_shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'id' => 0,
                'class' => '',
            ],
            $atts,
            'miyamoto_job_description'
        );

        $post_id = absint($atts['id']) ?: get_the_ID();
        if (!$post_id) {
            return '';
        }

        $description = (string) get_post_meta($post_id, '_description', true);
        if ($description === '') {
            return '';
        }

        $classes = self::sanitize_class_list('miyamoto-job-description ' . $atts['class']);

        return sprintf(
            '<div class="%1$s">%2$s</div>',
            esc_attr($classes),
            self::format_description_html($description)
        );
    }

    public static function handle_manual_import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run this import.', 'miyamoto-jobs-importer'));
        }

        check_admin_referer('miyamoto_jobs_import_now');

        $result = self::run_import();
        if (is_wp_error($result)) {
            set_transient(
                self::NOTICE_TRANSIENT,
                [
                    'type' => 'error',
                    'message' => $result->get_error_message(),
                ],
                MINUTE_IN_SECONDS
            );
        } else {
            set_transient(
                self::NOTICE_TRANSIENT,
                [
                    'type' => 'success',
                    'message' => sprintf(
                        'Import complete: %d deleted, %d created, %d skipped.',
                        $result['deleted'],
                        $result['created'],
                        $result['skipped']
                    ),
                ],
                MINUTE_IN_SECONDS
            );
        }

        wp_safe_redirect(admin_url('options-general.php?page=miyamoto-jobs-importer'));
        exit;
    }

    public static function run_import()
    {
        $options = self::get_options();
        $post_type = $options['post_type'];

        if (!post_type_exists($post_type)) {
            $error = new WP_Error(
                'miyamoto_jobs_invalid_post_type',
                sprintf('The configured post type "%s" does not exist. Check the JetEngine post type slug.', $post_type)
            );
            self::record_last_result($error);
            error_log('[Miyamoto Jobs Importer] ' . $error->get_error_message());

            return $error;
        }

        require_once ABSPATH . WPINC . '/feed.php';

        add_filter('wp_feed_cache_transient_lifetime', [self::class, 'feed_cache_lifetime']);
        $feed = fetch_feed($options['feed_url']);
        remove_filter('wp_feed_cache_transient_lifetime', [self::class, 'feed_cache_lifetime']);

        if (is_wp_error($feed)) {
            self::record_last_result($feed);
            error_log('[Miyamoto Jobs Importer] Feed fetch failed: ' . $feed->get_error_message());

            return $feed;
        }

        $max_items = $feed->get_item_quantity(0);
        $items = $max_items > 0 ? $feed->get_items(0, $max_items) : [];
        $result = [
            'created' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'total_feed_items' => count($items),
        ];
        $jobs = [];

        foreach ($items as $item) {
            $job = self::rss_item_to_job($item);
            if (!$job['guid'] || !$job['title'] || !$job['link']) {
                $result['skipped']++;
                continue;
            }

            $jobs[] = $job;
        }

        if (count($items) > 0 && count($jobs) === 0) {
            $error = new WP_Error(
                'miyamoto_jobs_no_valid_items',
                'The feed was fetched, but no valid jobs could be parsed. Existing imported jobs were left unchanged.'
            );
            self::record_last_result($error);
            error_log('[Miyamoto Jobs Importer] ' . $error->get_error_message());

            return $error;
        }

        $result['deleted'] = self::delete_imported_posts($post_type);

        foreach ($jobs as $job) {
            $post_id = self::insert_job_post($job, $post_type, $options['post_status']);
            if (is_wp_error($post_id)) {
                $result['skipped']++;
                error_log('[Miyamoto Jobs Importer] Job skipped: ' . $post_id->get_error_message());
                continue;
            }

            $result['created']++;
        }

        self::record_last_result($result);

        return $result;
    }

    private static function default_options(): array
    {
        return [
            'feed_url' => self::DEFAULT_FEED_URL,
            'post_type' => 'jobs',
            'post_status' => 'publish',
        ];
    }

    public static function feed_cache_lifetime(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    private static function get_options(): array
    {
        return wp_parse_args(get_option(self::OPTION_NAME, []), self::default_options());
    }

    private static function sanitize_class_list(string $classes): string
    {
        $class_names = preg_split('/\s+/', trim($classes));
        $class_names = array_filter(array_map('sanitize_html_class', $class_names));

        return implode(' ', array_unique($class_names));
    }

    private static function format_description_html(string $description): string
    {
        $description = self::normalize_description_breaks($description);
        if ($description === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $description);
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $html[] = self::format_description_paragraph($paragraph);
        }

        return implode('', $html);
    }

    private static function normalize_description_breaks(string $description): string
    {
        $description = html_entity_decode(
            wp_strip_all_tags($description, true),
            ENT_QUOTES | ENT_HTML5,
            get_bloginfo('charset') ?: 'UTF-8'
        );
        $description = preg_replace("/\r\n|\r/", "\n", $description);
        $description = preg_replace("/[ \t]+/", ' ', $description);
        $description = trim($description);

        if (strpos($description, "\n\n") === false) {
            $description = preg_replace('/\s+(Posted:\s+)/', "\n\n" . '$1', $description, 1);
            $description = preg_replace(
                '/(Posted:\s+[A-Za-z]+ \d{1,2}, \d{4})\s+/',
                '$1' . "\n\n",
                $description,
                1
            );
        }

        return trim((string) $description);
    }

    private static function format_description_paragraph(string $paragraph): string
    {
        if (preg_match('/^(Location|Posted):\s*(.+)$/', $paragraph, $matches)) {
            return sprintf(
                '<p class="miyamoto-job-description__meta"><strong>%1$s:</strong> %2$s</p>',
                esc_html($matches[1]),
                esc_html($matches[2])
            );
        }

        return sprintf(
            '<p>%s</p>',
            nl2br(esc_html($paragraph), false)
        );
    }

    private static function schedule_import(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK);
        }
    }

    private static function rss_item_to_job(SimplePie_Item $item): array
    {
        $category_labels = [];
        foreach ((array) $item->get_categories() as $category) {
            if ($category && $category->get_label()) {
                $category_labels[] = sanitize_text_field($category->get_label());
            }
        }

        $description = html_entity_decode(
            wp_strip_all_tags((string) $item->get_description(), true),
            ENT_QUOTES | ENT_HTML5,
            get_bloginfo('charset') ?: 'UTF-8'
        );

        $timestamp = $item->get_date('U') ? (int) $item->get_date('U') : null;

        return [
            'guid' => sanitize_text_field((string) $item->get_id(false)),
            'title' => sanitize_text_field((string) $item->get_title()),
            'link' => esc_url_raw((string) $item->get_link()),
            'category' => implode(', ', array_unique($category_labels)),
            'pubDate' => $timestamp ? wp_date('Y-m-d\TH:i', $timestamp) : '',
            'jobLocationType' => self::get_item_tag_value($item, self::UKG_NAMESPACE_URL, 'jobLocationType'),
            'description' => sanitize_textarea_field($description),
            'timestamp' => $timestamp,
        ];
    }

    private static function get_item_tag_value(SimplePie_Item $item, string $namespace, string $tag): string
    {
        $tags = $item->get_item_tags($namespace, $tag);
        if (!is_array($tags) || empty($tags[0]['data'])) {
            return '';
        }

        return sanitize_text_field(
            html_entity_decode(
                (string) $tags[0]['data'],
                ENT_QUOTES | ENT_HTML5,
                get_bloginfo('charset') ?: 'UTF-8'
            )
        );
    }

    private static function insert_job_post(array $job, string $post_type, string $post_status)
    {
        $post_data = [
            'post_type' => $post_type,
            'post_title' => $job['title'],
            'post_content' => $job['description'],
            'post_excerpt' => wp_trim_words($job['description'], 35),
            'post_status' => $post_status,
        ];

        if ($job['timestamp']) {
            $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $job['timestamp']);
            $post_data['post_date'] = get_date_from_gmt($post_data['post_date_gmt']);
        }

        $post_data['post_name'] = sanitize_title($job['title'] . '-' . substr(md5($job['guid']), 0, 8));
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, 'title', $job['title']);
        update_post_meta($post_id, 'link', $job['link']);
        update_post_meta($post_id, 'category', $job['category']);
        update_post_meta($post_id, 'pubDate', $job['pubDate']);
        update_post_meta($post_id, 'jobLocationType', $job['jobLocationType']);
        update_post_meta($post_id, '_description', $job['description']);
        update_post_meta($post_id, '_miyamoto_job_guid', $job['guid']);
        update_post_meta($post_id, '_miyamoto_job_imported', '1');
        update_post_meta($post_id, '_miyamoto_job_last_seen', current_time('mysql'));

        return $post_id;
    }

    private static function delete_imported_posts(string $post_type): int
    {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => '_miyamoto_job_imported',
            'meta_value' => '1',
            'no_found_rows' => true,
        ]);

        $deleted = 0;
        foreach ($posts as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private static function record_last_result($result): void
    {
        if (is_wp_error($result)) {
            update_option(self::LAST_RESULT_OPTION, [
                'time' => current_time('mysql'),
                'success' => false,
                'message' => $result->get_error_message(),
            ], false);

            return;
        }

        update_option(self::LAST_RESULT_OPTION, [
            'time' => current_time('mysql'),
            'success' => true,
            'created' => (int) $result['created'],
            'deleted' => (int) $result['deleted'],
            'skipped' => (int) $result['skipped'],
            'total_feed_items' => (int) $result['total_feed_items'],
        ], false);
    }

    private static function next_run_message(): string
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if (!$timestamp) {
            return 'Scheduled import is not currently registered. Save settings or reactivate the plugin to register it.';
        }

        return sprintf(
            'Next scheduled import: %s',
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
        );
    }

    private static function format_last_result(array $last_result): string
    {
        if (empty($last_result['success'])) {
            return sprintf('%s - failed: %s', $last_result['time'] ?? 'Unknown time', $last_result['message'] ?? 'Unknown error');
        }

        return sprintf(
            '%s - %d feed items, %d deleted, %d created, %d skipped.',
            $last_result['time'] ?? 'Unknown time',
            $last_result['total_feed_items'] ?? 0,
            $last_result['deleted'] ?? 0,
            $last_result['created'] ?? 0,
            $last_result['skipped'] ?? 0
        );
    }
}

Miyamoto_Jobs_Importer::init();

register_activation_hook(__FILE__, ['Miyamoto_Jobs_Importer', 'activate']);
register_deactivation_hook(__FILE__, ['Miyamoto_Jobs_Importer', 'deactivate']);
