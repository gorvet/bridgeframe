<?php
defined('ABSPATH') || exit;

class Bridgeframe_Auth {
    const OPTION_KEY = 'bridgeframe_token';
    const CONSUMERS_KEY = 'bridgeframe_consumers';
    const LEGACY_HASH_KEY = 'bridgeframe_legacy_token_hash';

    public static function is_token_valid($token) {
        $saved = (string) get_option(self::LEGACY_HASH_KEY, '');
        $token = is_string($token) ? trim($token) : '';

        if ($saved === '' || $token === '') {
            return false;
        }

        return hash_equals($saved, hash('sha256', $token));
    }

    public static function generate_token() {
        delete_option(self::LEGACY_HASH_KEY);
        $consumers = self::consumers();
        foreach ($consumers as $id => $consumer) {
            if ($consumer['name'] === 'Principal' || $id === 'legacy') {
                $consumers[$id]['revoked'] = true;
            }
        }
        update_option(self::CONSUMERS_KEY, $consumers, false);
        return self::create_consumer('Principal', ['content.read'], time() + 365 * DAY_IN_SECONDS)['token'];
    }

    public static function get_token() {
        return 'Credenciales almacenadas mediante hash. Genera una para copiarla.';
    }

    public static function setup() {
        $legacy = get_option(self::OPTION_KEY, '');
        if (is_string($legacy) && $legacy !== '') {
            $consumers = self::consumers();
            if (!isset($consumers['legacy'])) {
                $consumers['legacy'] = [
                    'id' => 'legacy', 'name' => 'Migrado de v1', 'hash' => hash('sha256', $legacy),
                    'scopes' => ['content.read'], 'expires_at' => 0, 'revoked' => false,
                ];
                update_option(self::CONSUMERS_KEY, $consumers, false);
                update_option(self::LEGACY_HASH_KEY, hash('sha256', $legacy), false);
            }
            delete_option(self::OPTION_KEY);
        }
    }

    public static function consumers() {
        $consumers = get_option(self::CONSUMERS_KEY, []);
        return is_array($consumers) ? $consumers : [];
    }

    public static function create_consumer($name, array $scopes, $expires_at = 0) {
        $allowed = ['content.read', 'private.read', 'comments.write', 'comments.moderate'];
        $scopes = array_values(array_intersect($allowed, $scopes));
        $token = bin2hex(random_bytes(32));
        $id = wp_generate_uuid4();
        $consumer = ['id' => $id, 'name' => sanitize_text_field($name), 'hash' => hash('sha256', $token),
            'scopes' => $scopes, 'expires_at' => max(0, (int) $expires_at), 'revoked' => false];
        $consumers = self::consumers();
        $consumers[$id] = $consumer;
        update_option(self::CONSUMERS_KEY, $consumers, false);
        return ['id' => $id, 'token' => $token];
    }

    public static function revoke_consumer($id) {
        $consumers = self::consumers();
        if (!isset($consumers[$id])) return false;
        $consumers[$id]['revoked'] = true;
        update_option(self::CONSUMERS_KEY, $consumers, false);
        if ($id === 'legacy') delete_option(self::LEGACY_HASH_KEY);
        return true;
    }

    public static function rotate_consumer($id) {
        $consumers = self::consumers();
        if (!isset($consumers[$id]) || $consumers[$id]['revoked']) return false;
        $token = bin2hex(random_bytes(32));
        $consumers[$id]['hash'] = hash('sha256', $token);
        update_option(self::CONSUMERS_KEY, $consumers, false);
        if ($id === 'legacy') delete_option(self::LEGACY_HASH_KEY);
        return ['id' => $id, 'token' => $token];
    }

    public static function authenticate_consumer($token) {
        if (!is_string($token) || $token === '') return false;
        $hash = hash('sha256', $token);
        foreach (self::consumers() as $consumer) {
            if (!$consumer['revoked'] && (!$consumer['expires_at'] || $consumer['expires_at'] > time())
                && hash_equals($consumer['hash'], $hash)) return $consumer;
        }
        return false;
    }
}
