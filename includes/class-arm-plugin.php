<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_Plugin {

    private static $instance = null;

    /** @var ARM_Settings */
    public $settings;

    /** @var ARM_CPT */
    public $cpt;

    /** @var ARM_Form */
    public $form;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function boot(): void {
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-utils.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-settings.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-cpt.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-pdf.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-email.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-form.php';

        $this->settings = new ARM_Settings();
        $this->cpt      = new ARM_CPT();
        $this->form     = new ARM_Form();
    }

    public static function activate(): void {
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-cpt.php';
        (new ARM_CPT())->register_post_type();

        // Registrar ruta standalone antes del flush
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-utils.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-settings.php';
        require_once ARM_PLUGIN_DIR . 'includes/class-arm-form.php';
        (new ARM_Form())->register_route();

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
