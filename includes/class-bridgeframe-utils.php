<?php
defined('ABSPATH') || exit;

class Bridgeframe_Utils {
    const OPTION_CACHE_ENABLED = 'bridgeframe_cache_enabled';
    const OPTION_CACHE_TTL = 'bridgeframe_cache_ttl';
    const OPTION_CORS_MODE = 'bridgeframe_cors_mode';
    const OPTION_CORS_ORIGINS = 'bridgeframe_cors_origins';

    public static function defaults() {
        return [
            self::OPTION_CACHE_ENABLED => true,
            self::OPTION_CACHE_TTL     => 300,
            self::OPTION_CORS_MODE     => 'open',
            self::OPTION_CORS_ORIGINS  => '',
        ];
    }

    public static function get_default($key) {
        $defaults = self::defaults();
        return array_key_exists($key, $defaults) ? $defaults[$key] : null;
    }

    public static function is_cache_enabled() {
        return (bool) get_option(self::OPTION_CACHE_ENABLED, self::get_default(self::OPTION_CACHE_ENABLED));
    }

    public static function get_cache_ttl() {
        $ttl = absint(get_option(self::OPTION_CACHE_TTL, self::get_default(self::OPTION_CACHE_TTL)));

        if ($ttl < 30) {
            return 30;
        }

        return min($ttl, DAY_IN_SECONDS);
    }

    public static function get_cors_mode() {
        $mode = sanitize_key(get_option(self::OPTION_CORS_MODE, self::get_default(self::OPTION_CORS_MODE)));
        return in_array($mode, ['disabled', 'open', 'restricted'], true) ? $mode : 'open';
    }

    public static function normalize_origin($origin) {
        $origin = is_string($origin) ? trim($origin) : '';

        if ($origin === '') {
            return '';
        }

        $parts = wp_parse_url($origin);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower($parts['host']);
        $port = !empty($parts['port']) ? ':' . absint($parts['port']) : '';

        return $scheme . '://' . $host . $port;
    }

    public static function get_allowed_origins() {
        $raw = get_option(self::OPTION_CORS_ORIGINS, self::get_default(self::OPTION_CORS_ORIGINS));
        $raw = is_string($raw) ? $raw : '';
        $items = preg_split('/[\r\n,]+/', $raw);
        $origins = [];

        foreach ($items as $item) {
            $origin = self::normalize_origin($item);

            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }
}
