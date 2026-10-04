<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    Bridgeframe
 */

// Asegura que solo se ejecute desde WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Permitir controlar desde un filtro si se borra todo o no
if ( apply_filters('bridgeframe_delete_all', true) ) {

    // Opciones registradas por el plugin
    delete_option('bridgeframe_headless_mode');// Checkbox del modo headless
    delete_option('bridgeframe_redirect_url');// URL de redirección si está en modo headless
    delete_option('bridgeframe_token');// Token actual de autenticación
    delete_option('bridgeframe_consumers');
    delete_option('bridgeframe_legacy_token_hash');
    delete_option('bridgeframe_cache_enabled');
    delete_option('bridgeframe_cache_ttl');
    delete_option('bridgeframe_cors_mode');
    delete_option('bridgeframe_cors_origins');

    global $wpdb;

    $transient_pattern = $wpdb->esc_like('_transient_bridgeframe_') . '%';
    $timeout_pattern = $wpdb->esc_like('_transient_timeout_bridgeframe_') . '%';
    $rate_pattern = $wpdb->esc_like('bridgeframe_rate_') . '%';

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $transient_pattern,
            $timeout_pattern,
            $rate_pattern
        )
    );
}
