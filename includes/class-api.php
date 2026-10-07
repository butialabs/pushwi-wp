<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pushwi management API client. Only called on explicit editor actions.
 */
class Pushwi_Api
{
    private const TIMEOUT = 15;

    public static function is_configured(): bool
    {
        return Pushwi_Settings::get_public_id() !== '' && Pushwi_Settings::get_api_key() !== '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id:int,status:string,type:string}|WP_Error
     */
    public static function create_campaign(array $payload)
    {
        $response = self::request('POST', '/v1/campaigns', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'id' => (int) ($response['id'] ?? 0),
            'status' => (string) ($response['status'] ?? ''),
            'type' => (string) ($response['type'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{id:int,audience_count:int}|WP_Error
     */
    public static function create_segment(string $name, array $definition)
    {
        $response = self::request('POST', '/v1/segments', [
            'name' => $name,
            'definition' => $definition,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'id' => (int) ($response['id'] ?? 0),
            'audience_count' => (int) ($response['audience_count'] ?? 0),
        ];
    }

    /**
     * The API has no whoami endpoint, so list one subscriber.
     *
     * @return true|WP_Error
     */
    public static function ping()
    {
        $response = self::request('GET', '/v1/subscribers?per_page=1');

        return is_wp_error($response) ? $response : true;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|WP_Error
     */
    public static function request(string $method, string $path, ?array $body = null)
    {
        $key = Pushwi_Settings::get_api_key();

        if ($key === '') {
            return new WP_Error('pushwi_not_configured', __('The Pushwi secret API key is not configured.', 'pushwi'));
        }

        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer '.$key,
                'Accept' => 'application/json',
                'User-Agent' => 'Pushwi-WordPress/'.PUSHWI_PLUGIN_VERSION.'; '.home_url('/'),
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(Pushwi_Snippet::api_url().$path, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'pushwi_http_error',
                /* translators: %s: error message from the HTTP layer. */
                sprintf(__('Could not reach Pushwi: %s', 'pushwi'), $response->get_error_message())
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];

        if ($code >= 200 && $code < 300) {
            return $data;
        }

        return new WP_Error('pushwi_api_'.$code, self::error_message($code, $data), [
            'status' => $code,
            'errors' => $data['errors'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function error_message(int $code, array $data): string
    {
        switch ($code) {
            case 401:
                return __('Pushwi rejected the secret API key (401). Check the key in Settings → Pushwi.', 'pushwi');
            case 402:
            case 403:
                $detail = isset($data['message']) && is_string($data['message']) ? ' '.$data['message'] : '';

                return __('Pushwi refused the request (403). Make sure you are using a secret key (sk_live_) for this site and that your plan allows it.', 'pushwi').$detail;
            case 422:
                $messages = [];

                foreach ((array) ($data['errors'] ?? []) as $field => $errors) {
                    $messages[] = $field.': '.implode(' ', array_map('strval', (array) $errors));
                }

                return __('Pushwi rejected the notification:', 'pushwi').' '.($messages !== [] ? implode('; ', $messages) : (string) ($data['message'] ?? ''));
            case 429:
                return __('Too many requests to Pushwi. Wait a minute and try again.', 'pushwi');
            default:
                /* translators: %d: HTTP status code. */
                return sprintf(__('Unexpected response from Pushwi (HTTP %d).', 'pushwi'), $code);
        }
    }
}
