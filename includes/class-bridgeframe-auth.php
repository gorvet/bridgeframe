<?php
defined('ABSPATH') || exit;

class Bridgeframe_Auth {
    const OPTION_KEY = 'bridgeframe_token';

    public static function is_token_valid($token) {
        $saved = self::get_token();
        $token = is_string($token) ? trim($token) : '';

        if ($saved === '' || $token === '') {
            return false;
        }

        return hash_equals($saved, $token);
    }

    public static function generate_token() {
        $new_token = wp_generate_password(40, false, false);
        update_option(self::OPTION_KEY, $new_token, false);
        return $new_token;
    }

    public static function get_token() {
        $token = get_option(self::OPTION_KEY, '');
        return is_string($token) ? $token : '';
    }

    public static function setup() {
        if (!get_option(self::OPTION_KEY)) {
            self::generate_token();
        }
    }
}
