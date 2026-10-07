<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post editor box. Sending on publish:
 * - Classic editor, cron, WP-CLI: dispatched in wp_after_insert_post.
 * - Block editor: REST publishes first and meta boxes are saved in a second request
 *   (meta-box-loader), which dispatches. A cron event covers REST clients without meta boxes.
 */
class Pushwi_Metabox
{
    public const NONCE_ACTION = 'pushwi_metabox';

    public const NONCE_FIELD = 'pushwi_metabox_nonce';

    public const META_PENDING = '_pushwi_pending_publish';

    public const CRON_HOOK = 'pushwi_dispatch_pending';

    private const PENDING_DELAY = 2 * MINUTE_IN_SECONDS;

    public static function register_hooks(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('wp_after_insert_post', [self::class, 'after_insert'], 10, 4);
        add_action(self::CRON_HOOK, [self::class, 'dispatch_pending']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_notices', [self::class, 'render_notice']);
        add_action('wp_ajax_pushwi_dispatch', [self::class, 'ajax_dispatch']);
        add_action('wp_ajax_pushwi_status', [self::class, 'ajax_status']);
    }

    public static function is_enabled_for(string $post_type): bool
    {
        return in_array($post_type, Pushwi_Settings::get_post_types(), true);
    }

    public static function add(string $post_type): void
    {
        if (! self::is_enabled_for($post_type)) {
            return;
        }

        add_meta_box('pushwi', __('Pushwi notification', 'pushwi'), [self::class, 'render'], $post_type, 'side', 'high');
    }

    public static function render(WP_Post $post): void
    {
        if (! Pushwi_Api::is_configured()) {
            echo '<p>'.esc_html__('Add your site public ID and secret API key to send posts as push notifications.', 'pushwi').'</p>';

            if (current_user_can('manage_options')) {
                printf('<p><a href="%s">%s</a></p>', esc_url(Pushwi_Settings::settings_url()), esc_html__('Open Pushwi settings', 'pushwi'));
            }

            return;
        }

        $can_publish = self::can_publish($post);
        $published = $post->post_status === 'publish';
        $action = Pushwi_Campaign::action($post);
        $audience = Pushwi_Campaign::audience($post);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="pushwi-box" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <?php if (! $published) { ?>
                <fieldset class="pushwi-field" <?php disabled(! $can_publish); ?>>
                    <legend class="screen-reader-text"><?php esc_html_e('When the post is published', 'pushwi'); ?></legend>
                    <?php foreach (Pushwi_Settings::action_labels() as $value => $label) { ?>
                        <label class="pushwi-radio">
                            <input type="radio" name="pushwi_action" value="<?php echo esc_attr($value); ?>" <?php checked($action, $value); ?> />
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php } ?>
                </fieldset>
            <?php } ?>

            <p class="pushwi-field">
                <label for="pushwi_title"><?php esc_html_e('Title', 'pushwi'); ?></label>
                <input type="text" id="pushwi_title" name="pushwi_title" class="widefat" maxlength="255"
                    value="<?php echo esc_attr((string) get_post_meta($post->ID, Pushwi_Campaign::META_TITLE, true)); ?>"
                    placeholder="<?php esc_attr_e('Post title', 'pushwi'); ?>" />
            </p>

            <p class="pushwi-field">
                <label for="pushwi_body"><?php esc_html_e('Message', 'pushwi'); ?></label>
                <textarea id="pushwi_body" name="pushwi_body" class="widefat" rows="3" maxlength="500"
                    placeholder="<?php esc_attr_e('Post excerpt', 'pushwi'); ?>"><?php echo esc_textarea((string) get_post_meta($post->ID, Pushwi_Campaign::META_BODY, true)); ?></textarea>
            </p>

            <p class="pushwi-field">
                <label for="pushwi_audience"><?php esc_html_e('Audience', 'pushwi'); ?></label>
                <select id="pushwi_audience" name="pushwi_audience" class="widefat">
                    <?php foreach (Pushwi_Settings::audience_labels() as $value => $label) { ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($audience, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php } ?>
                </select>
            </p>

            <?php if ($published && $can_publish) { ?>
                <p class="pushwi-actions">
                    <button type="button" class="button button-primary" data-pushwi-dispatch="send"><?php esc_html_e('Send now', 'pushwi'); ?></button>
                    <button type="button" class="button" data-pushwi-dispatch="draft"><?php esc_html_e('Create draft', 'pushwi'); ?></button>
                    <span class="spinner"></span>
                </p>
            <?php } ?>

            <div class="pushwi-status" aria-live="polite"><?php echo self::status_html($post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html(). ?></div>
        </div>
        <?php
    }

    public static function status_html(WP_Post $post): string
    {
        $html = '';
        $campaign_id = (int) get_post_meta($post->ID, Pushwi_Campaign::META_CAMPAIGN_ID, true);
        $error = (string) get_post_meta($post->ID, Pushwi_Campaign::META_LAST_ERROR, true);

        if ($campaign_id > 0) {
            $status = (string) get_post_meta($post->ID, Pushwi_Campaign::META_CAMPAIGN_STATUS, true);
            $sent_at = (int) get_post_meta($post->ID, Pushwi_Campaign::META_SENT_AT, true);

            $html .= sprintf(
                '<p class="pushwi-status-ok">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
                esc_html(sprintf(
                    /* translators: 1: campaign status label, 2: date and time. */
                    __('%1$s on %2$s.', 'pushwi'),
                    self::status_label($status),
                    $sent_at > 0 ? wp_date(get_option('date_format').' '.get_option('time_format'), $sent_at) : '—'
                )),
                esc_url(Pushwi_Campaign::dashboard_url($campaign_id)),
                /* translators: %d: Pushwi campaign ID. */
                esc_html(sprintf(__('View campaign #%d', 'pushwi'), $campaign_id))
            );
        }

        if ($error !== '') {
            $html .= sprintf('<p class="pushwi-status-error">%s</p>', esc_html($error));
        }

        return $html;
    }

    private static function status_label(string $status): string
    {
        switch ($status) {
            case 'draft':
                return __('Draft created in Pushwi', 'pushwi');
            case 'queued':
            case 'queuing':
            case 'sending':
                return __('Notification queued for sending', 'pushwi');
            case 'sent':
                return __('Notification sent', 'pushwi');
            case 'failed':
                return __('Notification failed', 'pushwi');
            default:
                return __('Campaign created in Pushwi', 'pushwi');
        }
    }

    /**
     * @param int     $post_id
     * @param WP_Post $post
     */
    public static function save($post_id, $post): void
    {
        if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || ! $post instanceof WP_Post) {
            return;
        }

        if (! self::is_enabled_for($post->post_type) || ! current_user_can('edit_post', $post_id)) {
            return;
        }

        self::save_fields($post, wp_unslash($_POST));

        // Block editor meta box request right after a REST publish.
        if (get_post_meta($post_id, self::META_PENDING, true)) {
            delete_post_meta($post_id, self::META_PENDING);
            wp_clear_scheduled_hook(self::CRON_HOOK, [$post_id]);
            self::maybe_dispatch($post);
        }
    }

    /**
     * @param array<string, mixed> $input Unslashed.
     */
    private static function save_fields(WP_Post $post, array $input): void
    {
        if (isset($input['pushwi_action']) && self::can_publish($post)) {
            $action = sanitize_key((string) $input['pushwi_action']);

            if (in_array($action, Pushwi_Settings::ACTIONS, true)) {
                update_post_meta($post->ID, Pushwi_Campaign::META_ACTION, $action);
            }
        }

        if (isset($input['pushwi_audience'])) {
            $audience = sanitize_key((string) $input['pushwi_audience']);

            if (in_array($audience, Pushwi_Settings::AUDIENCES, true)) {
                update_post_meta($post->ID, Pushwi_Campaign::META_AUDIENCE, $audience);
            }
        }

        foreach (['pushwi_title' => Pushwi_Campaign::META_TITLE, 'pushwi_body' => Pushwi_Campaign::META_BODY] as $field => $meta) {
            if (! isset($input[$field])) {
                continue;
            }

            $value = $field === 'pushwi_body'
                ? sanitize_textarea_field((string) $input[$field])
                : sanitize_text_field((string) $input[$field]);

            if ($value === '') {
                delete_post_meta($post->ID, $meta);
            } else {
                update_post_meta($post->ID, $meta, $value);
            }
        }
    }

    /**
     * @param int          $post_id
     * @param WP_Post      $post
     * @param bool         $update
     * @param WP_Post|null $post_before
     */
    public static function after_insert($post_id, $post, $update, $post_before): void
    {
        if (! $post instanceof WP_Post || wp_is_post_revision($post_id) || ! self::is_enabled_for($post->post_type)) {
            return;
        }

        $just_published = $post->post_status === 'publish'
            && (! $post_before instanceof WP_Post || $post_before->post_status !== 'publish');

        if (! $just_published) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            update_post_meta($post_id, self::META_PENDING, time());
            wp_schedule_single_event(time() + self::PENDING_DELAY, self::CRON_HOOK, [$post_id]);

            return;
        }

        self::maybe_dispatch($post);
    }

    /**
     * @param int $post_id
     */
    public static function dispatch_pending($post_id): void
    {
        $post = get_post((int) $post_id);

        if (! $post instanceof WP_Post || ! get_post_meta($post->ID, self::META_PENDING, true)) {
            return;
        }

        delete_post_meta($post->ID, self::META_PENDING);

        if ($post->post_status === 'publish') {
            self::maybe_dispatch($post);
        }
    }

    // Once per post; after that only the manual button sends again.
    private static function maybe_dispatch(WP_Post $post): void
    {
        $action = Pushwi_Campaign::action($post);

        if ($action === 'none' || (int) get_post_meta($post->ID, Pushwi_Campaign::META_CAMPAIGN_ID, true) > 0) {
            return;
        }

        $result = Pushwi_Campaign::dispatch($post, $action);

        // In the block editor the meta box JS shows the notice.
        if (is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! isset($_GET['meta-box-loader'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::flash($result);
        }
    }

    public static function ajax_dispatch(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post = get_post(isset($_POST['post_id']) ? absint($_POST['post_id']) : 0);
        $action = isset($_POST['dispatch']) ? sanitize_key(wp_unslash($_POST['dispatch'])) : '';

        if (! $post instanceof WP_Post || ! self::is_enabled_for($post->post_type) || ! current_user_can('edit_post', $post->ID) || ! self::can_publish($post)) {
            wp_send_json_error(['message' => __('You are not allowed to send this post.', 'pushwi')], 403);
        }

        if ($post->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Publish the post before sending it.', 'pushwi')], 400);
        }

        self::save_fields($post, wp_unslash($_POST));

        $result = Pushwi_Campaign::dispatch($post, $action);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'html' => self::status_html($post),
            ]);
        }

        wp_send_json_success([
            'message' => $action === 'draft'
                ? __('Draft created in Pushwi.', 'pushwi')
                : __('Notification queued for sending.', 'pushwi'),
            'html' => self::status_html($post),
        ]);
    }

    public static function ajax_status(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post = get_post(isset($_GET['post_id']) ? absint($_GET['post_id']) : 0);

        if (! $post instanceof WP_Post || ! current_user_can('edit_post', $post->ID)) {
            wp_send_json_error(null, 403);
        }

        wp_send_json_success([
            'html' => self::status_html($post),
            'sent_at' => (int) get_post_meta($post->ID, Pushwi_Campaign::META_SENT_AT, true),
            'error' => (string) get_post_meta($post->ID, Pushwi_Campaign::META_LAST_ERROR, true),
            'status' => (string) get_post_meta($post->ID, Pushwi_Campaign::META_CAMPAIGN_STATUS, true),
            'published' => $post->post_status === 'publish',
        ]);
    }

    public static function enqueue(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen || ! self::is_enabled_for((string) $screen->post_type) || ! Pushwi_Api::is_configured()) {
            return;
        }

        wp_enqueue_style('pushwi-metabox', PUSHWI_PLUGIN_URL.'assets/css/metabox.css', [], PUSHWI_PLUGIN_VERSION);
        wp_enqueue_script('pushwi-metabox', PUSHWI_PLUGIN_URL.'assets/js/metabox.js', [], PUSHWI_PLUGIN_VERSION, ['in_footer' => true]);
        wp_localize_script('pushwi-metabox', 'pushwiMetabox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => [
                'confirmResend' => __('A Pushwi campaign was already created for this post. Create another one?', 'pushwi'),
                'confirmSend' => __('Send this post as a push notification now?', 'pushwi'),
                'sent' => __('Pushwi notification queued for sending.', 'pushwi'),
                'draft' => __('Pushwi draft created.', 'pushwi'),
                'failed' => __('Pushwi:', 'pushwi'),
                'requestFailed' => __('Could not contact the server. Try again.', 'pushwi'),
            ],
        ]);
    }

    /**
     * @param array{id:int,status:string,type:string}|WP_Error $result
     */
    private static function flash($result): void
    {
        set_transient('pushwi_notice_'.get_current_user_id(), is_wp_error($result)
            ? ['type' => 'error', 'message' => __('Pushwi:', 'pushwi').' '.$result->get_error_message()]
            : ['type' => 'success', 'message' => $result['status'] === 'draft'
                ? __('Pushwi draft created.', 'pushwi')
                : __('Pushwi notification queued for sending.', 'pushwi')], MINUTE_IN_SECONDS);
    }

    public static function render_notice(): void
    {
        $key = 'pushwi_notice_'.get_current_user_id();
        $notice = get_transient($key);

        if (! is_array($notice)) {
            return;
        }

        delete_transient($key);

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type'] === 'error' ? 'error' : 'success'),
            esc_html((string) $notice['message'])
        );
    }

    private static function can_publish(WP_Post $post): bool
    {
        $type = get_post_type_object($post->post_type);

        return $type !== null && current_user_can($type->cap->publish_posts);
    }
}
