<?php
/**
 * Plugin Name: Agrocampo - Recepción de Maquinaria
 * Description: Formulario de recepción de maquinaria con PDF (FPDF) y envío por correo.
 * Version: 1.1.6
 * Author: Agrocampo / Rocket Solutions
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: agrocampo-recepcion-maquinaria
 */

if (!defined('ABSPATH')) { exit; }

define('ARM_PLUGIN_VERSION', '1.1.6');
define('ARM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ARM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ARM_PLUGIN_DIR . 'includes/class-arm-plugin.php';

// Activation / Deactivation
register_activation_hook(__FILE__, ['\\Agrocampo\\RecepcionMaquinaria\\ARM_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['\\Agrocampo\\RecepcionMaquinaria\\ARM_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    \Agrocampo\RecepcionMaquinaria\ARM_Plugin::instance();
});
