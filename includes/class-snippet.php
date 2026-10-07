<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Loads the Pushwi SDK on the front end.
 */
class Pushwi_Snippet
{
    public const HANDLE = 'pushwi-sdk';

    public const SDK_URL = 'https://sdk.pushwi.com';

    public const API_URL = 'https://api.pushwi.com';

    public static function sdk_url(): string
    {
        return untrailingslashit((string) apply_filters('pushwi_sdk_url', self::SDK_URL));
    }

    public static function api_url(): string
    {
        return untrailingslashit((string) apply_filters('pushwi_api_url', self::API_URL));
    }

    public static function enqueue(): void
    {
        if (Pushwi_Settings::get_public_id() === '') {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            self::sdk_url().'/v1/pushwi.js',
            [],
            null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by the CDN path (/v1/).
            ['in_footer' => false, 'strategy' => 'async']
        );

        wp_add_inline_script(self::HANDLE, self::inline_bootstrap(), 'before');
    }

    // Command queue stub plus the current post interest tags.
    private static function inline_bootstrap(): string
    {
        $js = 'window.pushwi=window.pushwi||function(){(pushwi.q=pushwi.q||[]).push(arguments);};';

        foreach (self::subscriber_tags() as $tag) {
            $js .= 'pushwi("addTag",'.wp_json_encode($tag).');';
        }

        return $js;
    }

    /**
     * @return string[]
     */
    public static function subscriber_tags(): array
    {
        $tags = [];

        if (is_singular()) {
            $post = get_queried_object();

            if ($post instanceof WP_Post) {
                $tags = Pushwi_Segments::tags_for_post($post);
            }
        }

        /**
         * @param string[] $tags Subscriber tags, e.g. ["category:sports"].
         */
        $tags = (array) apply_filters('pushwi_subscriber_tags', $tags);

        // API limits: 50 tags, 255 chars each.
        $tags = array_filter(array_map('strval', $tags), function (string $tag): bool {
            return $tag !== '' && strlen($tag) <= 255;
        });

        return array_slice(array_values(array_unique($tags)), 0, 50);
    }

    /**
     * Adds data-site (and data-api outside production) to the SDK tag.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function filter_script_attributes(array $attributes): array
    {
        if (($attributes['id'] ?? '') !== self::HANDLE.'-js') {
            return $attributes;
        }

        $attributes['data-site'] = Pushwi_Settings::get_public_id();

        if (self::api_url() !== self::API_URL) {
            $attributes['data-api'] = self::api_url();
        }

        return $attributes;
    }
}
