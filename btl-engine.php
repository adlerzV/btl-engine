<?php

/**
 * Plugin Name: BTL Engine
 * Version: 1.3.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce, wp-graphql
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/core/bootstrap.php';

register_activation_hook(__FILE__, function () {
    BTL_Migrations::run_schema_upgrade();
    BTL_Rate_Sync::activate();
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        foreach ([
            'btl_sync_exchange_rates',
            'btl_batch_step',
            'btl_batch_watchdog',
            'btl_revalidate_flush',
            'btl_batch_job',
            'btl_product_chunk_job',
            'btl_cleanup_job',
            'btl_cdkey_cleanup_orphans',
        ] as $hook) {
            as_unschedule_all_actions($hook, null, 'btl');
        }
    }

    delete_option('btl_batch_lock_v2');
    delete_option('btl_revalidate_lock_v2');
    delete_option('btl_rate_sync_last_health_check');
    delete_transient('btl_batch_pending_request');
    wp_clear_scheduled_hook('btl_cdkey_cleanup_orphans');
});
