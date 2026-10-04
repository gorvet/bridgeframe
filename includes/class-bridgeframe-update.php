<?php
defined('ABSPATH') || exit;

class Bridgeframe_Update {

    public static function init() {

        add_filter('pre_set_site_transient_update_plugins', function($transient) {
            if (empty($transient->checked)) return $transient;

            $plugin_slug = BRIDGEFRAME_SLUG; 
            $remote_url  = 'https://repo.gorvet.com/updates/bridgeframe/info.json';
            $local_version = BRIDGEFRAME_VERSION;

            $response = wp_remote_get($remote_url, ['timeout' => 5]);

            if (!is_wp_error($response) && $response['response']['code'] == 200) {
                $info = json_decode(wp_remote_retrieve_body($response));

                if (
                isset($info->version) &&
                version_compare($info->version, $local_version, '>')
                ) {
                    $transient->response[$plugin_slug] = (object)[
    'slug'          => $info->slug,
    'plugin'        => $plugin_slug,
    'new_version'   => $info->version,
    'url'           => $info->homepage ?? '',
    'package'       => $info->download_url,

    // Para compatibilidad en update-core.php
    'tested'        => $info->tested ?? '6.9.4',
    'requires'      => $info->requires ?? '6.9.4',
    'requires_php'  => $info->requires_php ?? '8.1',

    // Para ícono en update-core.php
    'icons' => ['default' => 'https://repo.gorvet.com/updates/bridgeframe/icon.png'],

    // Opcional: nota visible solo si haces clic en "Ver detalles"
    'upgrade_notice' => $info->upgrade_notice ?? 'Actualización recomendada para mejorar estabilidad y seguridad.',
];

                }
            }

            return $transient;
        });

        add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'bridgeframe') {
        return $result;
    }

    $remote_url = 'https://repo.gorvet.com/updates/bridgeframe/info.json';
    $response = wp_remote_get($remote_url, ['timeout' => 5]);

    if (is_wp_error($response) || $response['response']['code'] !== 200) {
        return new WP_Error('error', 'No se pudo obtener información del plugin.');
    }

    $info = json_decode(wp_remote_retrieve_body($response));

    $plugin_info = (object)[
        'name'           => $info->name ?? 'Bridgeframe',
        'slug'           => $info->slug ?? 'bridgeframe',
        'version'        => $info->version,
        'author'         => $info->author ?? 'Juank de Gorvet',
        'requires'       => $info->requires ?? '6.9.4',
        'requires_php'   => $info->requires_php ?? '8.1',
        'tested'         => $info->tested ?? '6.9.4',
        'last_updated'   => $info->last_updated ?? date('Y-m-d'),
        'sections'       => (array)($info->sections ?? ['description' => 'Sin descripción.']),
        'homepage'       => $info->homepage ?? '',
        'download_link'  => $info->download_url,
        
        'banners' => [
            'low'  => 'https://repo.gorvet.com/updates/bridgeframe/icon.png',
            'high' => 'https://repo.gorvet.com/updates/bridgeframe/banner-1544x500.jpg'
        ]
    ];//banner-772x250.jpg 
    return $plugin_info;
}, 10, 3);

       
    }

}
