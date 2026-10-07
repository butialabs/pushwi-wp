<?php

// Campaigns and segments already created in Pushwi are kept.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach ([
    'pushwi_public_id',
    'pushwi_api_key',
    'pushwi_post_types',
    'pushwi_default_action',
    'pushwi_tag_categories',
    'pushwi_tag_post_tags',
    'pushwi_segments',
    'pushwi_inbox_enabled', // 1.0.0
] as $pushwi_option) {
    delete_option($pushwi_option);
}

foreach ([
    '_pushwi_action',
    '_pushwi_title',
    '_pushwi_body',
    '_pushwi_audience',
    '_pushwi_campaign_id',
    '_pushwi_campaign_status',
    '_pushwi_sent_at',
    '_pushwi_last_error',
    '_pushwi_pending_publish',
] as $pushwi_meta_key) {
    delete_post_meta_by_key($pushwi_meta_key);
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of pushwi_lock_* rows.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('pushwi_lock_').'%'));

wp_unschedule_hook('pushwi_dispatch_pending');
