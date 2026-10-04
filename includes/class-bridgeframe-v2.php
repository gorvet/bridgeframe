<?php
defined('ABSPATH') || exit;

/** API versionada. Reutiliza las consultas, nunca la autorización heredada. */
class Bridgeframe_V2 {
    const CONTRACT = '2.0';
    private static $contexts;

    public static function register_routes() {
        $routes = [
            '/html' => ['GET' => 'get_html'],
            '/list' => ['GET' => 'get_list'],
            '/terms' => ['GET' => 'get_terms_list'],
            '/menu' => ['GET' => 'get_menu'],
            '/schema' => ['GET' => 'get_schema'],
            '/comments' => ['GET' => 'get_comments', 'POST' => 'create_comment'],
            '/comments/(?P<id>\d+)/moderate' => ['POST' => 'moderate_comment'],
        ];
        foreach ($routes as $route => $methods) {
            $definitions = [];
            foreach ($methods as $method => $callback) {
                $definitions[] = [
                    'methods' => $method,
                    'callback' => ['Bridgeframe_API', $callback],
                    'permission_callback' => [__CLASS__, 'authorize'],
                ];
            }
            register_rest_route('bridgeframe/v2', $route, $definitions);
        }
    }

    private static function context($request) {
        if (!self::$contexts) self::$contexts = new SplObjectStorage();
        if (!self::$contexts->contains($request)) {
            self::$contexts[$request] = ['request_id' => wp_generate_uuid4()];
        }
        return self::$contexts[$request];
    }

    private static function error($code, $message, $status) {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    public static function authorize($request) {
        $context = self::context($request);
        if (isset($context['authorized'])) return true;
        $contract = trim((string) $request->get_header('x-bridgeframe-contract'));
        if ($contract !== '' && $contract !== self::CONTRACT) {
            return self::error('contract_mismatch', 'Versión de contrato no compatible.', 400);
        }
        $authorization = (string) $request->get_header('authorization');
        if ($authorization === '') {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return self::error('invalid_token', 'Se requiere una credencial Bearer válida.', 401);
        }
        $consumer = Bridgeframe_Auth::authenticate_consumer($matches[1]);
        if (!$consumer) return self::error('invalid_token', 'Credencial inválida, revocada o caducada.', 401);
        $context['consumer_id'] = $consumer['id'];
        self::$contexts[$request] = $context;
        $route = $request->get_route();
        $scopes = ['content.read'];
        if (strpos($route, '/comments') !== false) {
            $scopes = [strpos($route, '/moderate') !== false || $request->get_method() === 'GET'
                ? 'comments.moderate' : 'comments.write'];
        }
        $private = $request->get_param('private');
        if (is_scalar($private) && filter_var($private, FILTER_VALIDATE_BOOLEAN)) $scopes[] = 'private.read';
        foreach ($scopes as $scope) {
            if (!in_array($scope, $consumer['scopes'], true)) {
                return self::error('insufficient_scope', 'La credencial no permite esta operación.', 403);
            }
        }
        // add_option es una inserción única: el incremento es atómico entre procesos.
        $window = (int) floor(time() / 60);
        $key = 'bridgeframe_rate_' . $window . '_' . md5($consumer['id']);
        add_option($key, 0, '', false);
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", $key
        ));
        $raw_count = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        if (!$updated || $raw_count === null) {
            return self::error('internal_error', 'No se pudo comprobar el límite de consumo.', 503);
        }
        $count = (int) $raw_count;
        if ($count > 120) {
            return self::error('rate_limited', 'Límite de solicitudes alcanzado.', 429);
        }
        $context['authorized'] = true;
        $context['consumer_id'] = $consumer['id'];
        self::$contexts[$request] = $context;
        // Eliminar ventanas anteriores sin borrar contadores de la ventana actual.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
            $wpdb->esc_like('bridgeframe_rate_') . '%', 'bridgeframe_rate_' . ($window - 1) . '_'
        ));
        return true;
    }

    private static function audit($consumer, $requestId, $route, $code) {
        $event = [
            'consumer_id' => $consumer, 'request_id' => $requestId,
            'route' => $route, 'code' => $code, 'timestamp' => time(),
        ];
        error_log('[Bridgeframe] ' . wp_json_encode($event));
        do_action('bridgeframe_audit', $event);
    }

    public static function envelope($response, $server, $request) {
        if (strpos($request->get_route(), '/bridgeframe/v2/') !== 0) return $response;
        $response = rest_ensure_response($response);
        $context = self::context($request);
        $body = (array) $response->get_data();
        $status = $response->get_status();
        $route = $request->get_route();
        $codes = ['html' => 'content_loaded', 'list' => 'content_listed', 'terms' => 'terms_loaded',
            'menu' => 'menu_loaded', 'schema' => 'schema_loaded', 'comments' => 'comments_loaded'];
        $endpoint = basename($route);
        if ($status >= 400) {
            $code = isset($body['code']) ? $body['code'] : 'invalid_request';
            if (!isset($body['code'])) {
                if ($status === 404) $code = $endpoint === 'menu' ? 'menu_not_found' : (strpos($route, '/comments') !== false ? 'comment_not_found' : 'content_not_found');
                elseif ($status === 403) $code = 'insufficient_scope';
                elseif ($status >= 500) $code = 'internal_error';
                elseif (($body['error'] ?? '') === 'Tipo de contenido inválido') $code = 'invalid_content_type';
                elseif (($body['error'] ?? '') === 'Taxonomía inválida') $code = 'invalid_taxonomy';
            }
            $data = ['message' => $status >= 500 ? 'No se pudo completar la solicitud.' : ($body['message'] ?? $body['error'] ?? 'Solicitud inválida.')];
        } else {
            $code = $codes[$endpoint] ?? 'comment_moderated';
            if ($endpoint === 'comments' && $request->get_method() === 'POST') $code = 'comment_created';
            // No eliminar el status de negocio (p. ej., el estado de comentarios).
            if (($body['status'] ?? '') === 'success') unset($body['status']);
            $data = $body;
        }
        $response->set_data([
            'status' => $status >= 400 ? 'error' : 'success', 'code' => $code, 'data' => $data,
            'meta' => ['contract_version' => self::CONTRACT, 'request_id' => $context['request_id']],
        ]);
        if ($status === 429) $response->header('Retry-After', (string) (60 - time() % 60));
        $response->header('Cache-Control', 'no-store');
        self::audit($context['consumer_id'] ?? '', $context['request_id'], $route, $code);
        return $response;
    }
}
