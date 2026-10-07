<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post terms become subscriber tags ("{taxonomy}:{slug}") and campaign segments
 * ("tags has_any"). Segment IDs are cached since the API cannot list them.
 */
class Pushwi_Segments
{
    public const OPTION_CACHE = 'pushwi_segments';

    private const MAX_VALUES = 50;

    /**
     * @return string[] Taxonomies enabled under "Additional data".
     */
    public static function taxonomies(string $post_type): array
    {
        $defaults = [];

        if (Pushwi_Settings::tag_categories()) {
            $defaults[] = 'category';
        }

        if (Pushwi_Settings::tag_post_tags()) {
            $defaults[] = 'post_tag';
        }

        /**
         * @param string[] $taxonomies
         * @param string   $post_type
         */
        $taxonomies = (array) apply_filters('pushwi_tag_taxonomies', $defaults, $post_type);

        return array_values(array_filter($taxonomies, function ($taxonomy) use ($post_type): bool {
            return is_string($taxonomy) && is_object_in_taxonomy($post_type, $taxonomy);
        }));
    }

    /**
     * @return string[] e.g. ["category:sports", "post_tag:world-cup"].
     */
    public static function tags_for_post(WP_Post $post): array
    {
        $tags = [];
        $default_category = (int) get_option('default_category');

        foreach (self::taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);

            if (! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                // Skip "Uncategorized".
                if ($taxonomy === 'category' && $term->term_id === $default_category) {
                    continue;
                }

                $tags[] = self::tag_for_term($term);
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, self::MAX_VALUES);
    }

    public static function tag_for_term(WP_Term $term): string
    {
        return $term->taxonomy.':'.rawurldecode($term->slug);
    }

    /**
     * @param string[] $values
     * @return array<string, mixed>
     */
    public static function definition_for_tags(array $values, WP_Post $post): array
    {
        $definition = [
            'version' => 2,
            'groups' => [
                ['rules' => [['type' => 'tags', 'op' => 'has_any', 'values' => array_values($values)]]],
            ],
        ];

        /**
         * @param array<string, mixed> $definition
         * @param WP_Post              $post
         */
        return (array) apply_filters('pushwi_segment_definition', $definition, $post);
    }

    /**
     * @return int|null|WP_Error Segment ID, or null when the post has no terms.
     */
    public static function resolve_for_post(WP_Post $post)
    {
        $values = self::tags_for_post($post);

        if ($values === []) {
            return null;
        }

        $definition = self::definition_for_tags($values, $post);
        $hash = md5((string) wp_json_encode($definition));

        $cached = self::cached($hash);

        if ($cached !== null) {
            return $cached;
        }

        $segment = Pushwi_Api::create_segment(self::segment_name($post, $values), $definition);

        if (is_wp_error($segment)) {
            return $segment;
        }

        if ($segment['id'] <= 0) {
            return new WP_Error('pushwi_segment_invalid', __('Pushwi did not return a segment ID.', 'pushwi'));
        }

        self::remember($hash, $segment['id']);

        return $segment['id'];
    }

    // For segments deleted in the dashboard.
    public static function forget(int $segment_id): void
    {
        $cache = self::cache();
        $site = Pushwi_Settings::get_public_id();

        if (! isset($cache[$site])) {
            return;
        }

        $cache[$site] = array_filter($cache[$site], function ($id) use ($segment_id): bool {
            return (int) $id !== $segment_id;
        });

        update_option(self::OPTION_CACHE, $cache, false);
    }

    /**
     * @param string[] $values
     */
    private static function segment_name(WP_Post $post, array $values): string
    {
        $labels = [];

        foreach (self::taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);

            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (in_array(self::tag_for_term($term), $values, true)) {
                        $labels[] = $term->name;
                    }
                }
            }
        }

        /* translators: %s: comma-separated list of category and tag names. */
        $name = sprintf(__('WordPress: %s', 'pushwi'), implode(', ', array_unique($labels)));

        return function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
    }

    private static function cached(string $hash): ?int
    {
        $cache = self::cache();
        $id = $cache[Pushwi_Settings::get_public_id()][$hash] ?? null;

        return $id !== null ? (int) $id : null;
    }

    private static function remember(string $hash, int $id): void
    {
        $cache = self::cache();
        $cache[Pushwi_Settings::get_public_id()][$hash] = $id;

        update_option(self::OPTION_CACHE, $cache, false);
    }

    /**
     * @return array<string, array<string, int>> public_id => [hash => segment_id]
     */
    private static function cache(): array
    {
        $cache = get_option(self::OPTION_CACHE, []);

        return is_array($cache) ? $cache : [];
    }
}
