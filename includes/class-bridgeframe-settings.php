<?php
defined('ABSPATH') || exit;

class Bridgeframe_Settings {
    private const SCOPE_LABELS = [
        'content.read'      => 'Leer contenido público',
        'private.read'      => 'Incluir contenido privado',
        'comments.write'    => 'Crear comentarios',
        'comments.moderate' => 'Consultar y moderar comentarios',
    ];

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

        wp_enqueue_style('bridgeframe-admin', BRIDGEFRAME_URL . 'assets/admin.css', [], BRIDGEFRAME_VERSION);
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
                'generated'  => 'Token generado. Cópialo ahora.',
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
        ob_start();
        self::render_credential_rows();
        wp_send_json_success(['token' => $new_token, 'credentialRows' => ob_get_clean()]);
    }

    public static function ajax_clear_cache() {
        check_ajax_referer('bridgeframe_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'No autorizado.'], 403);
        }

        Bridgeframe_API::clear_cache();
        wp_send_json_success(['message' => 'Caché limpio']);
    }

    private static function render_credential_rows() {
        $consumers = Bridgeframe_Auth::consumers();
        if (!$consumers) {
            echo '<tr><td colspan="5">Todavía no hay credenciales registradas.</td></tr>';
            return;
        }

        foreach ($consumers as $consumer) {
            $permissions = array_map(static function ($scope) {
                return self::SCOPE_LABELS[$scope] ?? $scope;
            }, $consumer['scopes']);
            $expiry = $consumer['expires_at'];
            $status = 'Activa';
            if ($consumer['revoked']) {
                $status = 'Revocada';
            } elseif ($expiry && $expiry <= time()) {
                $status = 'Caducada';
            }
            ?>
            <tr>
                <th scope="row"><?php echo esc_html($consumer['name']); ?></th>
                <td><?php echo esc_html($permissions ? implode(', ', $permissions) : 'Sin permisos'); ?></td>
                <td>
                    <?php echo esc_html($expiry ? wp_date('d/m/Y H:i', $expiry) : ($consumer['id'] === 'legacy' ? 'Sin caducidad (migración de v1)' : 'Sin caducidad')); ?>
                </td>
                <td><?php echo esc_html($status); ?></td>
                <td>
                    <?php if (!$consumer['revoked']): ?>
                        <form method="post" class="bridgeframe-credential-actions">
                            <?php wp_nonce_field('bridgeframe_consumers', '_wpnonce', false); ?>
                            <input type="hidden" name="consumer_id" value="<?php echo esc_attr($consumer['id']); ?>">
                            <button class="button" name="bridgeframe_consumer_action" value="rotate" aria-label="<?php echo esc_attr('Reemplazar token de ' . $consumer['name']); ?>">Reemplazar token</button>
                            <button class="button" name="bridgeframe_consumer_action" value="revoke" aria-label="<?php echo esc_attr('Revocar token de ' . $consumer['name']); ?>">Revocar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $issued = false;
        $creation_failed = false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['bridgeframe_consumer_action'])) {
            check_admin_referer('bridgeframe_consumers');
            $action = sanitize_key(wp_unslash($_POST['bridgeframe_consumer_action']));
            $id = sanitize_text_field(wp_unslash($_POST['consumer_id'] ?? ''));
            if ($action === 'create') {
                $name = sanitize_text_field(wp_unslash($_POST['consumer_name'] ?? ''));
                $scopes = isset($_POST['scopes']) && is_array($_POST['scopes']) ? array_map('sanitize_key', wp_unslash($_POST['scopes'])) : [];
                $days = min(3650, max(1, absint($_POST['expires_days'] ?? 365)));
                if ($name !== '' && $scopes) {
                    $issued = Bridgeframe_Auth::create_consumer($name, $scopes, time() + $days * DAY_IN_SECONDS);
                }
                $creation_failed = !$issued;
            } elseif ($action === 'rotate') {
                $issued = Bridgeframe_Auth::rotate_consumer($id);
            } elseif ($action === 'revoke') {
                Bridgeframe_Auth::revoke_consumer($id);
            }
        }

        $headless = (bool) get_option('bridgeframe_headless_mode', false);
        $redirect_url = get_option('bridgeframe_redirect_url', '');
        $cache_enabled = Bridgeframe_Utils::is_cache_enabled();
        $cache_ttl = Bridgeframe_Utils::get_cache_ttl();
        $cors_mode = Bridgeframe_Utils::get_cors_mode();
        $cors_origins = get_option(Bridgeframe_Utils::OPTION_CORS_ORIGINS, '');
        ?>
        <div class="wrap">
            <h1><strong>Bridgeframe</strong></h1>

            <section class="bridgeframe-credentials" aria-labelledby="bridgeframe-credentials-heading">
                <h2 id="bridgeframe-credentials-heading">Credenciales de la API</h2>
                <p>Envía el token en la cabecera <code>Authorization: Bearer &lt;token&gt;</code>. Cópialo al generarlo. Después queda oculto.</p>

                <?php if ($issued): ?>
                    <div class="notice notice-success inline">
                        <p>Copia ahora el token <code class="bridgeframe-token"><?php echo esc_html($issued['token']); ?></code></p>
                    </div>
                <?php endif; ?>

                <div class="bridgeframe-primary">
                    <h3>Credencial principal</h3>
                    <p>Da acceso de solo lectura al contenido público y vence al cabo de un año.</p>
                    <div class="bridgeframe-credential-actions">
                        <button type="button" class="button" id="regenerate-token-btn" aria-describedby="bridgeframe-regenerate-note">Regenerar token principal</button>
                        <span id="token-status" role="status"></span>
                    </div>
                    <code id="bridgeframe-token" class="bridgeframe-token" hidden></code>
                    <p class="description" id="bridgeframe-regenerate-note">Al regenerarlo, se invalidan el token principal anterior y el token migrado de v1.</p>
                </div>

                <h3 id="bridgeframe-registered-heading">Credenciales registradas</h3>
                <p>Crea una credencial por integración cuando necesites darle permisos o caducidad propios.</p>
                <details class="bridgeframe-create-credential"<?php if ($creation_failed) echo ' open'; ?>>
                    <summary>Crear credencial</summary>
                    <form method="post">
                        <?php wp_nonce_field('bridgeframe_consumers'); ?>
                        <input type="hidden" name="bridgeframe_consumer_action" value="create">
                        <?php if ($creation_failed): ?>
                            <div class="notice notice-error inline"><p>Indica un nombre y selecciona al menos un permiso.</p></div>
                        <?php endif; ?>
                        <p><label for="consumer-name">Nombre de la integración</label><br><input id="consumer-name" name="consumer_name" class="regular-text" required maxlength="100"></p>
                        <fieldset>
                            <legend>Permisos</legend>
                            <?php foreach (self::SCOPE_LABELS as $scope => $label): ?>
                                <label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>" <?php checked($scope, 'content.read'); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p><label for="expires-days">Caducidad en días</label> <input id="expires-days" type="number" name="expires_days" min="1" max="3650" value="365" required></p>
                        <?php submit_button('Generar token', 'primary', 'submit', false); ?>
                    </form>
                </details>

                <div class="bridgeframe-credential-table" role="region" aria-labelledby="bridgeframe-registered-heading" tabindex="0">
                    <table class="widefat striped" aria-labelledby="bridgeframe-registered-heading">
                        <thead><tr><th scope="col">Nombre</th><th scope="col">Permisos</th><th scope="col">Caducidad</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                        <tbody id="bridgeframe-credential-rows">
                            <?php self::render_credential_rows(); ?>
                        </tbody>
                    </table>
                </div>
            </section>

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
