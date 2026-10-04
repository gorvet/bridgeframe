<?php
defined('ABSPATH') || exit;

class Bridgeframe_Headless {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'apply_headless_restrictions'], 1);
        add_action('init', [__CLASS__, 'cleanup_wp_head']);
    }

    public static function apply_headless_restrictions() {
        if (!get_option('bridgeframe_headless_mode')) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';

        // Permitir API, admin y login
        if (
            is_admin() ||
            wp_doing_ajax() ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            strpos($request_uri, '/wp-login.php') !== false ||
            strpos($request_uri, '/wp-json/') !== false
        ) {
            return;
        }

        $redirect_url = esc_url_raw(get_option('bridgeframe_redirect_url', ''));
        $current_url = esc_url_raw(home_url($request_uri));

        if (!empty($redirect_url) && $redirect_url !== $current_url) {
            wp_redirect($redirect_url, 302);
            exit;
        }


        wp_die(
            esc_html__('Acceso denegado. Este sitio está operando como backend headless.', 'bridgeframe'),
            esc_html__('Acceso denegado', 'bridgeframe'),
            ['response' => 403]
        );
    }

    public static function cleanup_wp_head() {
        if (!get_option('bridgeframe_headless_mode')) {
            return;
        }

        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
    }
}
