<?php
/**
 * Plugin Name: Bridgeframe
 * Plugin URI: https://github.com/gorvet/bridgeframe
 * Description: Convierte WordPress en un backend headless con salida HTML limpia, ideal para trabajar el front desde frameworks externos.
 * Version: 2.1.0
 * Author: Juank de Gorvet
 * Author URI: https://api.whatsapp.com/send/?phone=5353779424
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bridgeframe
 * Domain Path: /languages
 * Requires at least: 6.9.4
 * Tested up to: 6.9.4
 * Requires PHP: 8.1
 * 
 * @package Bridgeframe
 */



defined('ABSPATH') || exit;

define('BRIDGEFRAME_VERSION', '2.1.0');
define('BRIDGEFRAME_DIR', plugin_dir_path(__FILE__));
define('BRIDGEFRAME_URL', plugin_dir_url(__FILE__));
define('BRIDGEFRAME_SLUG', plugin_basename(__FILE__));


// Cargar traducciones
add_action('plugins_loaded', function () {
    load_plugin_textdomain('bridgeframe', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-auth.php';
require_once BRIDGEFRAME_DIR . 'includes/class-bridgeframe-loader.php';

register_activation_hook(__FILE__, ['Bridgeframe_Auth', 'setup']);

add_action('plugins_loaded', ['Bridgeframe_Loader', 'init']);
