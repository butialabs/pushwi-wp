<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings → Pushwi page.
 */
class Pushwi_Settings
{
    public const OPTION_GROUP = 'pushwi_settings';

    public const PAGE_SLUG = 'pushwi';

    public const OPTION_PUBLIC_ID = 'pushwi_public_id';

    public const OPTION_API_KEY = 'pushwi_api_key';

    public const OPTION_POST_TYPES = 'pushwi_post_types';

    public const OPTION_DEFAULT_ACTION = 'pushwi_default_action';

    public const OPTION_TAG_CATEGORIES = 'pushwi_tag_categories';

    public const OPTION_TAG_POST_TAGS = 'pushwi_tag_post_tags';

    public const ACTIONS = ['none', 'send', 'draft'];

    public const AUDIENCES = ['all', 'terms'];

    public static function get_public_id(): string
    {
        return (string) get_option(self::OPTION_PUBLIC_ID, '');
    }

    public static function get_api_key(): string
    {
        return (string) get_option(self::OPTION_API_KEY, '');
    }

    /**
     * @return string[]
     */
    public static function get_post_types(): array
    {
        $types = get_option(self::OPTION_POST_TYPES, ['post']);

        return is_array($types) ? array_values(array_filter($types, 'post_type_exists')) : ['post'];
    }

    public static function get_default_action(): string
    {
        $action = (string) get_option(self::OPTION_DEFAULT_ACTION, 'none');

        return in_array($action, self::ACTIONS, true) ? $action : 'none';
    }

    public static function tag_categories(): bool
    {
        return (bool) get_option(self::OPTION_TAG_CATEGORIES, true);
    }

    public static function tag_post_tags(): bool
    {
        return (bool) get_option(self::OPTION_TAG_POST_TAGS, false);
    }

    public static function settings_url(): string
    {
        return admin_url('options-general.php?page='.self::PAGE_SLUG);
    }

    public static function register_menu(): void
    {
        add_options_page(
            __('Pushwi', 'pushwi'),
            __('Pushwi', 'pushwi'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public static function action_links(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::settings_url()),
            esc_html__('Settings', 'pushwi')
        ));

        return $links;
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_PUBLIC_ID, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_public_id'],
            'default' => '',
        ]);

        register_setting(self::OPTION_GROUP, self::OPTION_TAG_CATEGORIES, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting(self::OPTION_GROUP, self::OPTION_TAG_POST_TAGS, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        register_setting(self::OPTION_GROUP, self::OPTION_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_api_key'],
            'default' => '',
        ]);

        register_setting(self::OPTION_GROUP, self::OPTION_POST_TYPES, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_post_types'],
            'default' => ['post'],
        ]);

        register_setting(self::OPTION_GROUP, self::OPTION_DEFAULT_ACTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_default_action'],
            'default' => 'none',
        ]);

        add_settings_section(
            'pushwi_install',
            __('Site installation', 'pushwi'),
            [self::class, 'render_install_section'],
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_PUBLIC_ID,
            __('Site public ID', 'pushwi'),
            [self::class, 'render_public_id_field'],
            self::PAGE_SLUG,
            'pushwi_install',
            ['label_for' => self::OPTION_PUBLIC_ID]
        );

        add_settings_field(
            'pushwi_additional_data',
            __('Additional data', 'pushwi'),
            [self::class, 'render_additional_data_field'],
            self::PAGE_SLUG,
            'pushwi_install'
        );

        add_settings_section(
            'pushwi_publishing',
            __('Publishing from the editor', 'pushwi'),
            [self::class, 'render_publishing_section'],
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_API_KEY,
            __('Secret API key', 'pushwi'),
            [self::class, 'render_api_key_field'],
            self::PAGE_SLUG,
            'pushwi_publishing',
            ['label_for' => self::OPTION_API_KEY]
        );

        add_settings_field(
            self::OPTION_POST_TYPES,
            __('Post types', 'pushwi'),
            [self::class, 'render_post_types_field'],
            self::PAGE_SLUG,
            'pushwi_publishing'
        );

        add_settings_field(
            self::OPTION_DEFAULT_ACTION,
            __('Default action for new posts', 'pushwi'),
            [self::class, 'render_default_action_field'],
            self::PAGE_SLUG,
            'pushwi_publishing',
            ['label_for' => self::OPTION_DEFAULT_ACTION]
        );
    }

    /**
     * Invalid values keep the previous one.
     *
     * @param mixed $value
     */
    public static function sanitize_public_id($value): string
    {
        if (! is_string($value)) {
            return self::get_public_id();
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (! preg_match('/^pub_[A-Za-z0-9]{8,64}$/', $value)) {
            add_settings_error(
                self::OPTION_PUBLIC_ID,
                'pushwi_invalid_public_id',
                __('Invalid public ID. Copy the exact value shown in your Pushwi dashboard under Widget → Snippet (it starts with "pub_").', 'pushwi')
            );

            return self::get_public_id();
        }

        return $value;
    }

    /**
     * Blank keeps the saved key; it is never echoed back.
     *
     * @param mixed $value
     */
    public static function sanitize_api_key($value): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php.
        if (! empty($_POST['pushwi_api_key_remove'])) {
            return '';
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return self::get_api_key();
        }

        if (! preg_match('/^sk_live_[0-9]+\|[A-Za-z0-9]{20,128}$/', $value)) {
            add_settings_error(
                self::OPTION_API_KEY,
                'pushwi_invalid_api_key',
                __('Invalid secret API key. Create one in your Pushwi dashboard under Developer → API keys (it starts with "sk_live_").', 'pushwi')
            );

            return self::get_api_key();
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function sanitize_post_types($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $value = array_map('sanitize_key', $value);

        return array_values(array_intersect($value, array_keys(self::available_post_types())));
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_default_action($value): string
    {
        return is_string($value) && in_array($value, self::ACTIONS, true) ? $value : 'none';
    }

    /**
     * @return array<string, string> name => label
     */
    public static function available_post_types(): array
    {
        $types = [];

        foreach (get_post_types(['public' => true, 'show_ui' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }

            $types[$type->name] = $type->labels->singular_name;
        }

        return $types;
    }

    public static function render_install_section(): void
    {
        printf(
            '<p>%s</p>',
            esc_html__('Loads the Pushwi widget on every page and serves the required service worker at /pushwi-sw.js.', 'pushwi')
        );
    }

    public static function render_publishing_section(): void
    {
        printf(
            '<p>%s</p>',
            esc_html__('Optional. With a secret API key, a Pushwi box appears in the post editor to send the post as a push campaign or save it as a draft in Pushwi.', 'pushwi')
        );
    }

    public static function render_public_id_field(): void
    {
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="pub_..." autocomplete="off" spellcheck="false" />',
            esc_attr(self::OPTION_PUBLIC_ID),
            esc_attr(self::get_public_id())
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__('Find it in your Pushwi dashboard under Widget → Snippet. This value is public: it is already visible in your site HTML.', 'pushwi')
        );
    }

    public static function render_additional_data_field(): void
    {
        $fields = [
            self::OPTION_TAG_CATEGORIES => [self::tag_categories(), __('Categories', 'pushwi')],
            self::OPTION_TAG_POST_TAGS => [self::tag_post_tags(), __('Tags', 'pushwi')],
        ];

        printf('<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__('Additional data', 'pushwi'));

        foreach ($fields as $name => [$is_checked, $label]) {
            printf(
                '<label style="display:block"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
                esc_attr($name),
                checked($is_checked, true, false),
                esc_html($label)
            );
        }

        echo '</fieldset>';

        printf(
            '<p class="description">%s</p>',
            esc_html__('Required to target campaigns at "readers interested in this post\'s categories and tags".', 'pushwi')
        );
    }

    public static function render_api_key_field(): void
    {
        $key = self::get_api_key();

        printf(
            '<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" placeholder="%2$s" autocomplete="new-password" spellcheck="false" />',
            esc_attr(self::OPTION_API_KEY),
            esc_attr($key !== '' ? self::mask_key($key) : 'sk_live_...')
        );

        if ($key !== '') {
            printf(
                '<p><label><input type="checkbox" name="pushwi_api_key_remove" value="1" /> %s</label></p>',
                esc_html__('Remove the saved key', 'pushwi')
            );
        }

        printf(
            '<p class="description">%s</p>',
            esc_html__('Create one in your Pushwi dashboard under Developer → API keys. It is stored in this site\'s database and only sent to api.pushwi.com. Leave blank to keep the current key.', 'pushwi')
        );
    }

    public static function render_post_types_field(): void
    {
        $selected = self::get_post_types();

        echo '<fieldset>';

        foreach (self::available_post_types() as $name => $label) {
            printf(
                '<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_POST_TYPES),
                esc_attr($name),
                checked(in_array($name, $selected, true), true, false),
                esc_html($label)
            );
        }

        echo '</fieldset>';
    }

    public static function render_default_action_field(): void
    {
        self::render_select(self::OPTION_DEFAULT_ACTION, self::get_default_action(), self::action_labels());
    }

    /**
     * @return array<string, string>
     */
    public static function action_labels(): array
    {
        return [
            'none' => __('Don\'t send', 'pushwi'),
            'send' => __('Send notification when published', 'pushwi'),
            'draft' => __('Create a Pushwi draft when published', 'pushwi'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function audience_labels(): array
    {
        return [
            'all' => __('All subscribers', 'pushwi'),
            'terms' => __('Subscribers interested in the post\'s categories and tags', 'pushwi'),
        ];
    }

    /**
     * @param array<string, string> $options
     */
    private static function render_select(string $name, string $current, array $options): void
    {
        printf('<select id="%1$s" name="%1$s">', esc_attr($name));

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    private static function mask_key(string $key): string
    {
        return 'sk_live_••••••••'.substr($key, -4);
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pushwi', 'pushwi'); ?></h1>
            <?php // No settings_errors(): options-head.php already prints them. ?>
            <?php self::render_connection_notice(); ?>
            <?php self::render_plain_permalinks_notice(); ?>
            <?php self::render_subdirectory_notice(); ?>
            <form method="post" action="options.php">
                <?php
                    settings_fields(self::OPTION_GROUP);
                    do_settings_sections(self::PAGE_SLUG);
                    submit_button();
                ?>
            </form>
            <?php if (Pushwi_Api::is_configured()) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pushwi_test_connection" />
                    <?php wp_nonce_field('pushwi_test_connection'); ?>
                    <?php submit_button(__('Test connection', 'pushwi'), 'secondary', 'submit', false); ?>
                </form>
            <?php } ?>
        </div>
        <?php
    }

    public static function handle_test_connection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'pushwi'), 403);
        }

        check_admin_referer('pushwi_test_connection');

        $result = Pushwi_Api::ping();

        set_transient(
            'pushwi_connection_'.get_current_user_id(),
            is_wp_error($result) ? $result->get_error_message() : 'ok',
            MINUTE_IN_SECONDS
        );

        wp_safe_redirect(self::settings_url());
        exit;
    }

    private static function render_connection_notice(): void
    {
        $key = 'pushwi_connection_'.get_current_user_id();
        $result = get_transient($key);

        if ($result === false) {
            return;
        }

        delete_transient($key);

        if ($result === 'ok') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__('Connected to Pushwi. The secret API key is valid.', 'pushwi')
            );

            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Pushwi connection failed:', 'pushwi'),
            esc_html((string) $result)
        );
    }

    // With plain permalinks the rewrite rule is never used.
    private static function render_plain_permalinks_notice(): void
    {
        if (! Pushwi_Service_Worker::using_plain_permalinks()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Warning:', 'pushwi'),
            esc_html__('your site uses "Plain" permalinks, which prevents Pushwi from serving the service worker (/pushwi-sw.js). The subscription widget will not work until this is changed.', 'pushwi'),
            esc_url(admin_url('options-permalink.php')),
            esc_html__('Choose another structure in Settings → Permalinks', 'pushwi')
        );
    }

    // The service worker must live at the domain root.
    private static function render_subdirectory_notice(): void
    {
        if (! Pushwi_Service_Worker::installed_in_subdirectory()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <code>%s</code></p></div>',
            esc_html__('Warning:', 'pushwi'),
            esc_html__('WordPress is installed in a subdirectory. Browsers require the service worker at the root of your domain, so copy the file below to your web server root:', 'pushwi'),
            esc_html(Pushwi_Service_Worker::root_url())
        );
    }
}
