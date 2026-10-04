<?php
defined('ABSPATH') || exit;

class Bridgeframe_Settings {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_bridgeframe_regenerate_token', [__CLASS__, 'ajax_regenerate_token']);
        add_action('wp_ajax_bridgeframe_clear_cache', [__CLASS__, 'ajax_clear_cache']);
    }

    public static function add_menu() {
        add_options_page(
            'Bridgeframe',
            'Bridgeframe',
            'manage_options',
            'bridgeframe-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('bridgeframe_settings_group', 'bridgeframe_headless_mode', [
            'type'              => 'boolean',
            'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
            'default'           => false,
        ]);

        register_setting('bridgeframe_settings_group', 'bridgeframe_redirect_url', [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_redirect_url'],
            'default'           => '',
        ]);

        register_setting('bridgeframe_settings_group', Bridgeframe_Utils::OPTION_CACHE_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
            'default'           => Bridgeframe_Utils::get_default(Bridgeframe_Utils::OPTION_CACHE_ENABLED),
        ]);

        register_setting('bridgeframe_settings_group', Bridgeframe_Utils::OPTION_CACHE_TTL, [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_cache_ttl'],
            'default'           => Bridgeframe_Utils::get_default(Bridgeframe_Utils::OPTION_CACHE_TTL),
        ]);

        register_setting('bridgeframe_settings_group', Bridgeframe_Utils::OPTION_CORS_MODE, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_cors_mode'],
            'default'           => Bridgeframe_Utils::get_default(Bridgeframe_Utils::OPTION_CORS_MODE),
        ]);

        register_setting('bridgeframe_settings_group', Bridgeframe_Utils::OPTION_CORS_ORIGINS, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_cors_origins'],
            'default'           => Bridgeframe_Utils::get_default(Bridgeframe_Utils::OPTION_CORS_ORIGINS),
        ]);
    }

    public static function sanitize_checkbox($value) {
        return !empty($value);
    }

    public static function sanitize_redirect_url($value) {
        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            return '';
        }

        return esc_url_raw($url, ['http', 'https']);
    }

    public static function sanitize_cache_ttl($value) {
        $ttl = absint($value);

        if ($ttl < 30) {
            return 30;
        }

        return min($ttl, DAY_IN_SECONDS);
    }

    public static function sanitize_cors_mode($value) {
        $mode = sanitize_key($value);
        return in_array($mode, ['disabled', 'open', 'restricted'], true) ? $mode : 'open';
    }

    public static function sanitize_cors_origins($value) {
        $value = is_string($value) ? $value : '';
        $items = preg_split('/[\r\n,]+/', $value);
        $origins = [];

        foreach ($items as $item) {
            $origin = Bridgeframe_Utils::normalize_origin($item);

            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return implode("\n", array_values(array_unique($origins)));
    }

    public static function enqueue_assets($hook_suffix) {
        if ($hook_suffix !== 'settings_page_bridgeframe-settings') {
            return;
        }

        wp_enqueue_script(
            'bridgeframe-admin',
            BRIDGEFRAME_URL . 'assets/admin.js',
            [],
            BRIDGEFRAME_VERSION,
            true
        );

        wp_localize_script('bridgeframe-admin', 'BridgeframeAdmin', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('bridgeframe_nonce'),
            'tokenAction'      => 'bridgeframe_regenerate_token',
            'clearCacheAction' => 'bridgeframe_clear_cache',
            'messages'         => [
                'generating' => 'Generando...',
                'generated'  => 'Generado',
                'clearing'   => 'Limpiando...',
                'cleared'    => 'Caché limpio',
                'error'      => 'Error',
            ],
        ]);
    }

    public static function ajax_regenerate_token() {
        check_ajax_referer('bridgeframe_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'No autorizado.'], 403);
        }

        $new_token = Bridgeframe_Auth::generate_token();
        wp_send_json_success(['token' => $new_token]);
    }

    public static function ajax_clear_cache() {
        check_ajax_referer('bridgeframe_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'No autorizado.'], 403);
        }

        Bridgeframe_API::clear_cache();
        wp_send_json_success(['message' => 'Caché limpio']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $token = Bridgeframe_Auth::get_token();
        $headless = (bool) get_option('bridgeframe_headless_mode', false);
        $redirect_url = get_option('bridgeframe_redirect_url', '');
        $cache_enabled = Bridgeframe_Utils::is_cache_enabled();
        $cache_ttl = Bridgeframe_Utils::get_cache_ttl();
        $cors_mode = Bridgeframe_Utils::get_cors_mode();
        $cors_origins = get_option(Bridgeframe_Utils::OPTION_CORS_ORIGINS, '');
        ?>
        <div class="wrap">
            <h1><strong>Bridgeframe</strong></h1>

            <table class="form-table">
                <tr>
                    <th scope="row">Token actual</th>
                    <td>
                        <code id="bridgeframe-token" style="font-size:16px; padding: 5px; margin-right: 5px; display: inline-block;"><?php echo esc_html($token); ?></code>
                        <button type="button" class="button" id="regenerate-token-btn">Regenerar token</button>
                        <span id="token-status" style="margin-left:10px;"></span>
                        <p class="description">Las aplicaciones externas deben enviar este token para leer contenido.</p>
                    </td>
                </tr>
            </table>

            <hr>

            <form method="post" action="options.php">
                <?php settings_fields('bridgeframe_settings_group'); ?>

                <h2>Modo headless</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Activar modo headless</th>
                        <td>
                            <input type="hidden" name="bridgeframe_headless_mode" value="0">
                            <label>
                                <input type="checkbox" name="bridgeframe_headless_mode" value="1" <?php checked($headless, true); ?>>
                                Bloquear el frontend público de WordPress
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">URL de redirección</th>
                        <td>
                            <input type="url" name="bridgeframe_redirect_url" value="<?php echo esc_attr($redirect_url); ?>" class="regular-text">
                            <p class="description">Si está vacía, WordPress responderá con acceso denegado.</p>
                        </td>
                    </tr>
                </table>

                <h2>API de lectura</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Caché</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(Bridgeframe_Utils::OPTION_CACHE_ENABLED); ?>" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Bridgeframe_Utils::OPTION_CACHE_ENABLED); ?>" value="1" <?php checked($cache_enabled, true); ?>>
                                Guardar respuestas de la API en caché
                            </label>
                            <p>
                                <button type="button" class="button" id="bridgeframe-clear-cache-btn">Limpiar caché</button>
                                <span id="bridgeframe-cache-status" style="margin-left:10px;"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Duración del caché</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr(Bridgeframe_Utils::OPTION_CACHE_TTL); ?>" value="<?php echo esc_attr($cache_ttl); ?>" min="30" max="<?php echo esc_attr(DAY_IN_SECONDS); ?>" step="30" class="small-text">
                            <span>segundos</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Modo de consumo</th>
                        <td>
                            <select name="<?php echo esc_attr(Bridgeframe_Utils::OPTION_CORS_MODE); ?>">
                                <option value="open" <?php selected($cors_mode, 'open'); ?>>Abierto con token</option>
                                <option value="restricted" <?php selected($cors_mode, 'restricted'); ?>>Frontend desde dominios permitidos</option>
                                <option value="disabled" <?php selected($cors_mode, 'disabled'); ?>>Solo backend con token, sin CORS</option>
                            </select>
                            <p class="description">Todos los modos exigen token. CORS solo afecta llamadas directas desde navegador.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dominios permitidos</th>
                        <td>
                            <textarea name="<?php echo esc_attr(Bridgeframe_Utils::OPTION_CORS_ORIGINS); ?>" rows="5" class="large-text code" placeholder="https://app.ejemplo.com"><?php echo esc_textarea($cors_origins); ?></textarea>
                            <p class="description">Uno por línea. Se usan en el modo frontend desde dominios permitidos para enviar CORS solo a esos orígenes.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Guardar cambios'); ?>
            </form>
        </div>
        <?php
    }
}
