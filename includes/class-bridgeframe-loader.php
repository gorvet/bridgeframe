<?php
defined('ABSPATH') || exit;

class Bridgeframe_Loader {
    public static function init() {
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-utils.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-auth.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-content.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-api.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-headless.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-settings.php';
        require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-update.php';

        Bridgeframe_Auth::setup();

        // Iniciar API
        add_action('rest_api_init', ['Bridgeframe_API', 'register_routes']);
        add_filter('rest_pre_serve_request', ['Bridgeframe_API', 'send_cors_headers'], 20, 4);
        add_action('save_post', ['Bridgeframe_API', 'clear_cache']);
        add_action('deleted_post', ['Bridgeframe_API', 'clear_cache']);
        add_action('created_term', ['Bridgeframe_API', 'clear_cache']);
        add_action('edited_term', ['Bridgeframe_API', 'clear_cache']);
        add_action('delete_term', ['Bridgeframe_API', 'clear_cache']);
        add_action('acf/save_post', ['Bridgeframe_API', 'clear_cache']);
        add_action('update_option_' . Bridgeframe_Utils::OPTION_CACHE_ENABLED, ['Bridgeframe_API', 'clear_cache']);
        add_action('update_option_' . Bridgeframe_Utils::OPTION_CACHE_TTL, ['Bridgeframe_API', 'clear_cache']);

        // Iniciar ajustes de admin
        if (is_admin()) {
            Bridgeframe_Settings::init();
        }

        Bridgeframe_Headless::init();
        Bridgeframe_Update::init();
    }
}
