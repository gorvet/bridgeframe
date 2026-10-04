<?php
/** Ejecutar en una instalación de prueba: php tests/integration.php /ruta/wp-load.php /ruta/gframe-framework */
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit("Uso: php tests/integration.php WP_LOAD [GFRAME_ROOT]\n");
define('WP_USE_THEMES', false);
require $argv[1];
if (!class_exists('Bridgeframe_V2')) exit("Activa Bridgeframe antes de ejecutar.\n");
if (!empty($argv[2])) require rtrim($argv[2], '/\\') . '/src/GFrame/Headless/WordPressClient.php';

$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function request_v2($endpoint, $token = '', $params = [], $method = 'GET', $contract = '2.0') {
    $request = new WP_REST_Request($method, '/bridgeframe/v2/' . $endpoint);
    if ($token !== '') $request->set_header('Authorization', 'Bearer ' . $token);
    if ($contract !== '') $request->set_header('X-BridgeFrame-Contract', $contract);
    foreach ($params as $key => $value) $request->set_param($key, $value);
    $server = rest_get_server();
    $response = apply_filters('rest_post_dispatch', $server->dispatch($request), $server, $request);
    $body = $response->get_data();
    check(isset($body['status'], $body['code'], $body['data'], $body['meta']['request_id']), 'Sobre completo: ' . $endpoint);
    check($body['meta']['contract_version'] === '2.0', 'Versión: ' . $endpoint);
    return $response;
}

$saved = Bridgeframe_Auth::consumers();
$savedLegacyHash = get_option(Bridgeframe_Auth::LEGACY_HASH_KEY, null);
$savedLegacyToken = get_option(Bridgeframe_Auth::OPTION_KEY, null);
$posts = [];
$menu = 0;
$issuedIds = [];
try {
    $migrationToken = bin2hex(random_bytes(32));
    update_option(Bridgeframe_Auth::CONSUMERS_KEY, [], false);
    update_option(Bridgeframe_Auth::OPTION_KEY, $migrationToken, false);
    Bridgeframe_Auth::setup();
    check(get_option(Bridgeframe_Auth::OPTION_KEY, null) === null, 'Migración elimina token en claro');
    check(Bridgeframe_Auth::is_token_valid($migrationToken), 'Migración conserva token de v1');
    check(Bridgeframe_Auth::authenticate_consumer($migrationToken)['scopes'] === ['content.read'], 'Migración concede solo lectura v2');
    Bridgeframe_Auth::revoke_consumer('legacy');
    check(!Bridgeframe_Auth::is_token_valid($migrationToken), 'Revocación migrada también invalida v1');
    update_option(Bridgeframe_Auth::CONSUMERS_KEY, $saved, false);
    if ($savedLegacyHash === null) delete_option(Bridgeframe_Auth::LEGACY_HASH_KEY);
    else update_option(Bridgeframe_Auth::LEGACY_HASH_KEY, $savedLegacyHash, false);
    $read = Bridgeframe_Auth::create_consumer('Prueba pública', ['content.read']);
    $all = Bridgeframe_Auth::create_consumer('Prueba completa', ['content.read', 'private.read', 'comments.write', 'comments.moderate']);
    $expired = Bridgeframe_Auth::create_consumer('Prueba caducada', ['content.read'], time() - 1);
    $issuedIds = [$read['id'], $all['id'], $expired['id']];
    check(strpos(wp_json_encode(Bridgeframe_Auth::consumers()), $read['token']) === false, 'No se almacena la credencial en claro');
    check(!Bridgeframe_Auth::is_token_valid($read['token']), 'Una credencial v2 no puede eludir scopes mediante v1');
    $public = wp_insert_post(['post_title' => 'Bridgeframe integration', 'post_name' => 'bridgeframe-test-' . wp_generate_uuid4(), 'post_content' => '<p>Integración</p>', 'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open'], true);
    check(!is_wp_error($public), 'Crear contenido público');
    $posts[] = $public;
    $private = wp_insert_post(['post_title' => 'Bridgeframe private', 'post_content' => 'Privado', 'post_status' => 'private', 'post_type' => 'post'], true);
    check(!is_wp_error($private), 'Crear contenido privado');
    $posts[] = $private;
    $menu = wp_create_nav_menu('Bridgeframe test ' . wp_generate_uuid4());
    check(!is_wp_error($menu), 'Crear menú');

    check(request_v2('html', '', ['id' => $public])->get_status() === 401, 'Falta Bearer');
    check(request_v2('html', '', ['id' => $public, 'token' => $read['token']])->get_status() === 401, 'Query token rechazado');
    check(request_v2('schema', 'invalid')->get_status() === 401, 'Token inválido');
    check(request_v2('schema', $expired['token'])->get_status() === 401, 'Token caducado');
    check(request_v2('schema', $read['token'], [], 'GET', '1.0')->get_status() === 400, 'Contrato incorrecto');
    foreach (['html' => ['id' => $public], 'list' => ['ids' => (string) $public], 'terms' => ['taxonomy' => 'category'], 'menu' => ['id' => $menu], 'schema' => []] as $endpoint => $params) {
        $result = request_v2($endpoint, $read['token'], $params);
        check($result->get_status() === 200 && $result->get_data()['status'] === 'success', 'Éxito ' . $endpoint);
    }
    $first = request_v2('html', $read['token'], ['id' => $public])->get_data();
    $second = request_v2('html', $read['token'], ['id' => $public])->get_data();
    check($first['data'] === $second['data'] && $first['meta']['request_id'] !== $second['meta']['request_id'], 'La caché conserva datos y genera request_id nuevo');
    check(request_v2('html', $read['token'], ['id' => $private, 'private' => 'true'])->get_status() === 403, 'Lectura privada exige scope');
    check(request_v2('html', $all['token'], ['id' => $private, 'private' => 'true'])->get_status() === 200, 'Lectura privada autorizada');
    check(request_v2('html', $read['token'], ['id' => $private])->get_status() === 403, 'Privado no expuesto con ID');
    check(request_v2('html', $read['token'], ['id' => $public, 'type' => 'invalid-type'])->get_data()['code'] === 'invalid_content_type', 'Código de tipo inválido');
    check(request_v2('terms', $read['token'], ['taxonomy' => 'invalid-taxonomy'])->get_data()['code'] === 'invalid_taxonomy', 'Código de taxonomía inválida');
    check(request_v2('html', $read['token'], ['slug' => 'absent-' . wp_generate_uuid4()])->get_data()['code'] === 'content_not_found', 'Código no encontrado');
    check(request_v2('comments', $read['token'], ['post_id' => $public])->get_status() === 403, 'Consulta de moderación protegida');
    check(request_v2('comments', $read['token'], [], 'POST')->get_status() === 403, 'Escritura protegida');
    check(request_v2('comments/1/moderate', $read['token'], [], 'POST')->get_status() === 403, 'Moderación protegida');
    $comment = request_v2('comments', $all['token'], ['post_id' => $public, 'author_name' => 'Prueba', 'author_email' => 'test@example.org', 'content' => 'Comentario de integración'], 'POST');
    check($comment->get_status() === 201, 'Crear comentario autorizado: ' . wp_json_encode($comment->get_data()));
    check(request_v2('comments', $all['token'], ['post_id' => $public])->get_status() === 200, 'Consulta autorizada');
    $commentId = $comment->get_data()['data']['comment_id'];
    check(request_v2('comments/' . $commentId . '/moderate', $all['token'], ['action' => 'approve'], 'POST')->get_status() === 200, 'Moderación autorizada');

    if (class_exists('GFrame\\Headless\\WordPressClient')) {
        $client = new GFrame\Headless\WordPressClient('https://cms.example.test', $read['token'], function ($args) use ($read) {
            check(in_array('X-BridgeFrame-Contract: 2.0', $args['headers'], true), 'Cabecera GFrame');
            check($args['verify_peer'] && $args['verify_host'], 'TLS GFrame');
            $endpoint = basename(parse_url($args['url'], PHP_URL_PATH));
            $result = request_v2($endpoint, $read['token'], $args['query']);
            return ['ok' => $result->get_status() < 400, 'status' => $result->get_status(), 'json' => $result->get_data()];
        });
        foreach ([$client->contentById($public), $client->content(get_post($public)->post_name), $client->contents(['ids' => (string) $public]), $client->terms(), $client->menu(['id' => $menu]), $client->schema()] as $result) {
            check($result['status'] === 'success', 'Cliente real GFrame');
        }
        check($client->contentById($private, 'post', true)['code'] === 'wordpress_unauthorized', 'GFrame conserva fallo de autorización');
    }
    $rotated = Bridgeframe_Auth::rotate_consumer($read['id']);
    check(request_v2('schema', $read['token'])->get_status() === 401, 'Rotación invalida token previo');
    check(request_v2('schema', $rotated['token'])->get_status() === 200, 'Token rotado funciona');
    global $wpdb;
    $key = 'bridgeframe_rate_' . (int) floor(time() / 60) . '_' . md5($read['id']);
    $wpdb->update($wpdb->options, ['option_value' => '120'], ['option_name' => $key]);
    check(request_v2('schema', $rotated['token'])->get_status() === 429, 'Límite de consumo');
    Bridgeframe_Auth::revoke_consumer($read['id']);
    check(request_v2('schema', $rotated['token'])->get_status() === 401, 'Revocación');
    echo "OK: {$checks} comprobaciones con WordPress real" . (class_exists('GFrame\\Headless\\WordPressClient') ? ' y cliente GFrame' : '') . ".\n";
} finally {
    foreach ($posts as $id) wp_delete_post($id, true);
    if ($menu && !is_wp_error($menu)) wp_delete_nav_menu($menu);
    update_option(Bridgeframe_Auth::CONSUMERS_KEY, $saved, false);
    if ($savedLegacyHash === null) delete_option(Bridgeframe_Auth::LEGACY_HASH_KEY);
    else update_option(Bridgeframe_Auth::LEGACY_HASH_KEY, $savedLegacyHash, false);
    if ($savedLegacyToken === null) delete_option(Bridgeframe_Auth::OPTION_KEY);
    else update_option(Bridgeframe_Auth::OPTION_KEY, $savedLegacyToken, false);
    global $wpdb;
    foreach ($issuedIds as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('bridgeframe_rate_') . '%' . $wpdb->esc_like('_' . md5($id))));
    }
}
