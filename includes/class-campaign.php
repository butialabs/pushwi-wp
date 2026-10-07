<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns a post into a Pushwi campaign and stores the result in post meta.
 */
class Pushwi_Campaign
{
    public const META_ACTION = '_pushwi_action';

    public const META_TITLE = '_pushwi_title';

    public const META_BODY = '_pushwi_body';

    public const META_AUDIENCE = '_pushwi_audience';

    public const META_CAMPAIGN_ID = '_pushwi_campaign_id';

    public const META_CAMPAIGN_STATUS = '_pushwi_campaign_status';

    public const META_SENT_AT = '_pushwi_sent_at';

    public const META_LAST_ERROR = '_pushwi_last_error';

    public const DASHBOARD_URL = 'https://app.pushwi.com';

    // API validation limits.
    private const MAX_TITLE = 255;

    private const MAX_BODY = 500;

    private const MAX_URL = 255;

    private const MAX_MEDIA_URL = 2048;

    private const LOCK_TTL = 60;

    /**
     * @param string $action "send" or "draft".
     * @return array{id:int,status:string,type:string}|WP_Error
     */
    public static function dispatch(WP_Post $post, string $action)
    {
        if (! in_array($action, ['send', 'draft'], true)) {
            return new WP_Error('pushwi_invalid_action', __('Invalid Pushwi action.', 'pushwi'));
        }

        if (! Pushwi_Api::is_configured()) {
            return self::fail($post, new WP_Error('pushwi_not_configured', __('Pushwi is not configured. Add the public ID and secret API key in Settings → Pushwi.', 'pushwi')));
        }

        if (! self::acquire_lock($post->ID)) {
            return new WP_Error('pushwi_locked', __('This post is already being sent to Pushwi.', 'pushwi'));
        }

        try {
            $payload = self::build_payload($post, $action);

            if (is_wp_error($payload)) {
                return self::fail($post, $payload);
            }

            $result = Pushwi_Api::create_campaign($payload);

            // Cached segment deleted in the dashboard: recreate once.
            if (is_wp_error($result) && isset($payload['segment_id']) && self::is_segment_error($result)) {
                Pushwi_Segments::forget((int) $payload['segment_id']);
                $payload = self::build_payload($post, $action);

                if (is_wp_error($payload)) {
                    return self::fail($post, $payload);
                }

                $result = Pushwi_Api::create_campaign($payload);
            }

            if (is_wp_error($result)) {
                return self::fail($post, $result);
            }

            update_post_meta($post->ID, self::META_CAMPAIGN_ID, $result['id']);
            update_post_meta($post->ID, self::META_CAMPAIGN_STATUS, $result['status']);
            update_post_meta($post->ID, self::META_SENT_AT, time());
            delete_post_meta($post->ID, self::META_LAST_ERROR);

            /**
             * @param array{id:int,status:string,type:string} $result
             * @param WP_Post                                 $post
             * @param array<string, mixed>                    $payload
             */
            do_action('pushwi_campaign_created', $result, $post, $payload);

            return $result;
        } finally {
            self::release_lock($post->ID);
        }
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function build_payload(WP_Post $post, string $action)
    {
        $content = [
            'title' => self::title($post),
            'body' => self::body($post),
            'url' => self::url($post),
        ];

        $image = get_the_post_thumbnail_url($post, 'large');

        if (is_string($image) && self::is_media_url($image)) {
            $content['image_url'] = $image;
        }

        $icon = get_site_icon_url(192);

        if (self::is_media_url($icon)) {
            $content['icon_url'] = $icon;
        }

        $payload = [
            'action' => $action,
            'content' => $content,
        ];

        if (self::audience($post) === 'terms') {
            $segment = Pushwi_Segments::resolve_for_post($post);

            if (is_wp_error($segment)) {
                return $segment;
            }

            // Never fall back to everyone.
            if ($segment === null) {
                return new WP_Error('pushwi_no_terms', __('The post has no categories or tags to target. Add some, or choose "All subscribers".', 'pushwi'));
            }

            $payload['segment_id'] = $segment;
        }

        /**
         * Pushwi preference category key (respects subscriber opt-outs). Empty by default.
         *
         * @param string  $category
         * @param WP_Post $post
         */
        $category = (string) apply_filters('pushwi_campaign_category', '', $post);

        if ($category !== '') {
            $payload['category'] = self::truncate($category, 255);
        }

        /**
         * @param array<string, mixed> $payload
         * @param WP_Post              $post
         * @param string               $action "send" or "draft".
         */
        return (array) apply_filters('pushwi_campaign_payload', $payload, $post, $action);
    }

    public static function action(WP_Post $post): string
    {
        $action = (string) get_post_meta($post->ID, self::META_ACTION, true);

        return in_array($action, Pushwi_Settings::ACTIONS, true) ? $action : Pushwi_Settings::get_default_action();
    }

    public static function audience(WP_Post $post): string
    {
        $audience = (string) get_post_meta($post->ID, self::META_AUDIENCE, true);

        return in_array($audience, Pushwi_Settings::AUDIENCES, true) ? $audience : 'all';
    }

    public static function title(WP_Post $post): string
    {
        $title = (string) get_post_meta($post->ID, self::META_TITLE, true);

        if ($title === '') {
            $title = self::plain_text(get_the_title($post));
        }

        return self::truncate($title !== '' ? $title : (string) get_bloginfo('name'), self::MAX_TITLE);
    }

    public static function body(WP_Post $post): string
    {
        $body = (string) get_post_meta($post->ID, self::META_BODY, true);

        if ($body === '') {
            $body = has_excerpt($post)
                ? self::plain_text($post->post_excerpt)
                : self::plain_text(wp_trim_words(excerpt_remove_blocks(strip_shortcodes($post->post_content)), 30, '…'));
        }

        return self::truncate($body !== '' ? $body : (string) get_bloginfo('description'), self::MAX_BODY) ?: self::title($post);
    }

    // Long permalinks fall back to the shortlink (API limit: 255 chars).
    public static function url(WP_Post $post): string
    {
        $url = (string) get_permalink($post);

        if (strlen($url) > self::MAX_URL) {
            $url = (string) wp_get_shortlink($post->ID, 'post', false);
        }

        if ($url === '' || strlen($url) > self::MAX_URL) {
            $url = add_query_arg('p', $post->ID, home_url('/'));
        }

        return $url;
    }

    public static function dashboard_url(int $campaign_id): string
    {
        $base = untrailingslashit((string) apply_filters('pushwi_dashboard_url', self::DASHBOARD_URL));

        return $base.'/campaigns/'.$campaign_id;
    }

    private static function plain_text(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function truncate(string $text, int $max): string
    {
        if (! function_exists('mb_strlen')) {
            return strlen($text) > $max ? rtrim(substr($text, 0, $max - 3)).'...' : $text;
        }

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private static function is_media_url(string $url): bool
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        return strlen($url) <= self::MAX_MEDIA_URL && in_array($scheme, ['http', 'https'], true);
    }

    private static function is_segment_error(WP_Error $error): bool
    {
        $data = $error->get_error_data();

        return is_array($data) && ($data['status'] ?? 0) === 422 && isset($data['errors']['segment_id']);
    }

    private static function fail(WP_Post $post, WP_Error $error): WP_Error
    {
        update_post_meta($post->ID, self::META_LAST_ERROR, $error->get_error_message());

        return $error;
    }

    // add_option() fails on an existing row, so the lock is atomic.
    private static function acquire_lock(int $post_id): bool
    {
        $name = 'pushwi_lock_'.$post_id;

        if (add_option($name, time(), '', false)) {
            return true;
        }

        $since = (int) get_option($name, 0);

        if ($since > 0 && $since < time() - self::LOCK_TTL) {
            delete_option($name);

            return add_option($name, time(), '', false);
        }

        return false;
    }

    private static function release_lock(int $post_id): void
    {
        delete_option('pushwi_lock_'.$post_id);
    }
}
