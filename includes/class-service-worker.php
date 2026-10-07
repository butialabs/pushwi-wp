<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Serves /pushwi-sw.js without a physical file. Must match sdk/src/pushwi-sw.js byte for
 * byte: the Pushwi dashboard checks it.
 */
class Pushwi_Service_Worker
{
    public const QUERY_VAR = 'pushwi_sw';

    private const REWRITE_REGEX = '^pushwi-sw\.js$';

    private const SHIM = 'self.PUSHWI_API = self.PUSHWI_API || "https://api.pushwi.com";'."\n"
        .'self.PUSHWI_SDK = self.PUSHWI_SDK || self.PUSHWI_CDN || "https://sdk.pushwi.com";'."\n"
        .'importScripts(self.PUSHWI_SDK + "/v1/pushwi-sw-core.js");'."\n";

    public static function register_rewrite_rule(): void
    {
        add_rewrite_tag('%'.self::QUERY_VAR.'%', '([^&]+)');
        add_rewrite_rule(self::REWRITE_REGEX, 'index.php?'.self::QUERY_VAR.'=1', 'top');
    }

    public static function unregister_rewrite_rule(): void
    {
        global $wp_rewrite;

        if ($wp_rewrite instanceof WP_Rewrite) {
            unset($wp_rewrite->extra_rules_top[self::REWRITE_REGEX]);
        }
    }

    public static function using_plain_permalinks(): bool
    {
        return (string) get_option('permalink_structure') === '';
    }

    public static function installed_in_subdirectory(): bool
    {
        $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        return trim($path, '/') !== '';
    }

    public static function root_url(): string
    {
        return home_url('/pushwi-sw.js');
    }

    public static function content(): string
    {
        $prefix = '';

        // Non-production URLs; the shim keeps existing self.PUSHWI_* values.
        if (Pushwi_Snippet::api_url() !== Pushwi_Snippet::API_URL) {
            $prefix .= 'self.PUSHWI_API = '.wp_json_encode(Pushwi_Snippet::api_url()).";\n";
        }

        if (Pushwi_Snippet::sdk_url() !== Pushwi_Snippet::SDK_URL) {
            $prefix .= 'self.PUSHWI_SDK = '.wp_json_encode(Pushwi_Snippet::sdk_url()).";\n";
        }

        return $prefix.self::SHIM;
    }

    // Runs before redirect_canonical can redirect the URL.
    public static function maybe_serve(WP $wp): void
    {
        if (empty($wp->query_vars[self::QUERY_VAR])) {
            return;
        }

        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Service-Worker-Allowed: /');

        echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS; URLs via wp_json_encode.

        exit;
    }
}
