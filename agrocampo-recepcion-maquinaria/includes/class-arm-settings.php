<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_Settings {

    const OPT_LOGO_ID          = 'arm_logo_id';
    const OPT_INTERNAL_EMAILS  = 'arm_internal_emails';
    const OPT_SEND_TO_CLIENT   = 'arm_send_to_client';
    const OPT_FROM_NAME        = 'arm_from_name';
    const OPT_REPLY_TO         = 'arm_reply_to';
    const OPT_PDF_FOOTER       = 'arm_pdf_footer';

    const OPT_FRONT_SLUG       = 'arm_front_slug';
    const OPT_FORM_FIELDS      = 'arm_form_fields';
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    
        // Flush rewrite rules when the frontend slug changes
        add_action('update_option_' . self::OPT_FRONT_SLUG, function($old, $new){
            if ($old !== $new) { flush_rewrite_rules(); }
        }, 10, 2);
}

    public function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . ARM_CPT::POST_TYPE,
            'Recepción Maquinaria - Ajustes',
            'Recepción Maquinaria',
            'manage_options',
            'arm-settings',
            [$this, 'render']
        );
    }

    public function register_settings(): void {
        register_setting('arm_settings_group', self::OPT_FRONT_SLUG, [
            'type' => 'string',
            'sanitize_callback' => function($v){
                $slug = sanitize_title(is_string($v) ? $v : '');
                return $slug !== '' ? $slug : 'recepcion-maquinaria';
            },
            'default' => 'recepcion-maquinaria',
        ]);

        register_setting('arm_settings_group', self::OPT_FORM_FIELDS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_form_fields'],
            'default' => [],
        ]);

        register_setting('arm_settings_group', self::OPT_LOGO_ID, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);
        register_setting('arm_settings_group', self::OPT_INTERNAL_EMAILS, [
            'type' => 'string',
            'sanitize_callback' => function($v){ return is_string($v) ? trim($v) : ''; },
            'default' => '',
        ]);
        register_setting('arm_settings_group', self::OPT_SEND_TO_CLIENT, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);
        register_setting('arm_settings_group', self::OPT_FROM_NAME, [
            'type' => 'string',
            'sanitize_callback' => function($v){ return is_string($v) ? trim($v) : ''; },
            'default' => 'Agrocampo',
        ]);
        register_setting('arm_settings_group', self::OPT_REPLY_TO, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => '',
        ]);
        register_setting('arm_settings_group', self::OPT_PDF_FOOTER, [
            'type' => 'string',
            // Evitar emojis/símbolos raros en PDF (FPDF usa ISO-8859-1)
            'sanitize_callback' => [$this, 'sanitize_pdf_footer'],
            'default' => 'Talca - Linares - Parral | +56 9 9748 5650',
        ]);
    }

    /**
     * Sanitiza el pie del PDF para que no rompa FPDF (sin emojis, sin caracteres invisibles).
     */
    public function sanitize_pdf_footer($v): string {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') return '';

        // Reemplazos típicos
        $v = str_replace(["\r"], '', $v);
        $v = str_replace(['•'], '-', $v);

        // Quitar la mayoría de emojis/símbolos fuera del rango imprimible.
        // Mantiene letras, números, espacios y puntuación común.
        $v = preg_replace('/[^\p{L}\p{N}\s\-\|\+\.,:;\(\)\/]/u', '', $v);
        return trim((string)$v);
    }

    public function sanitize_form_fields($value): array {
        $schema = ARM_Form::get_configurable_fields_schema();
        $sanitized = [];

        foreach ($schema as $key => $field) {
            $row = is_array($value) && isset($value[$key]) && is_array($value[$key]) ? $value[$key] : [];
            $visible = isset($row['visible']) ? absint($row['visible']) : 1;
            $required = isset($row['required']) ? absint($row['required']) : (!empty($field['default_required']) ? 1 : 0);

            if ($visible !== 1) {
                $visible = 0;
                $required = 0;
            }

            $sanitized[$key] = [
                'visible' => $visible,
                'required' => $required === 1 ? 1 : 0,
            ];
        }

        return $sanitized;
    }

    public function enqueue_admin_assets($hook): void {
        if ($hook !== 'arm_recepcion_page_arm-settings') return;
        wp_enqueue_media();
        wp_enqueue_script(
            'arm-admin',
            ARM_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ARM_PLUGIN_VERSION,
            true
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        $logo_id = absint(get_option(self::OPT_LOGO_ID, 0));
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';

        ?>
        <div class="wrap">
            <h1>Recepción de Maquinaria</h1>
            <form method="post" action="options.php">
                <?php settings_fields('arm_settings_group'); ?>

                <h2>General</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Ruta de acceso (frontend)</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_FRONT_SLUG); ?>" value="<?php echo esc_attr(get_option(self::OPT_FRONT_SLUG, 'recepcion-maquinaria')); ?>" placeholder="recepcion-maquinaria">
                            <p class="description">URL: <code><?php echo esc_html(trailingslashit(home_url('/' . sanitize_title(get_option(self::OPT_FRONT_SLUG, 'recepcion-maquinaria'))))); ?></code></p>
                            <p class="description">Vista standalone (sin theme). Al cambiar el slug se actualizan los permalinks.</p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>Correos</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Correos internos</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT_INTERNAL_EMAILS); ?>" rows="3" class="large-text" placeholder="correo1@dominio.cl, correo2@dominio.cl"><?php echo esc_textarea(get_option(self::OPT_INTERNAL_EMAILS, '')); ?></textarea>
                            <p class="description">Separar por coma, punto y coma o saltos de línea.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Enviar al cliente</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::OPT_SEND_TO_CLIENT); ?>" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_SEND_TO_CLIENT); ?>" value="1" <?php checked(1, absint(get_option(self::OPT_SEND_TO_CLIENT, 1))); ?>>
                                Sí, enviar copia al correo del cliente (si fue ingresado)
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Nombre remitente</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_FROM_NAME); ?>" value="<?php echo esc_attr(get_option(self::OPT_FROM_NAME, 'Agrocampo')); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Reply-To</th>
                        <td>
                            <input type="email" class="regular-text" name="<?php echo esc_attr(self::OPT_REPLY_TO); ?>" value="<?php echo esc_attr(get_option(self::OPT_REPLY_TO, '')); ?>" placeholder="postventa@agrocampo.cl">
                            <p class="description">Si queda vacío, se usa el correo del sitio.</p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>PDF</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Logo (PDF)</th>
                        <td>
                            <input type="hidden" id="arm_logo_id" name="<?php echo esc_attr(self::OPT_LOGO_ID); ?>" value="<?php echo esc_attr($logo_id); ?>">
                            <button type="button" class="button" id="arm_logo_pick">Seleccionar logo</button>
                            <button type="button" class="button" id="arm_logo_clear">Quitar</button>
                            <div style="margin-top:10px;">
                                <img id="arm_logo_preview" src="<?php echo esc_url($logo_url); ?>" style="max-width:260px; height:auto; <?php echo $logo_url ? '' : 'display:none;'; ?>">
                            </div>
                            <p class="description">Se usa en el encabezado del PDF. Recomendado PNG transparente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pie PDF</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT_PDF_FOOTER); ?>" rows="3" class="large-text"><?php echo esc_textarea(get_option(self::OPT_PDF_FOOTER, 'Talca - Linares - Parral | +56 9 9748 5650')); ?></textarea>
                            <p class="description">Se imprime centrado al final del PDF.</p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>Campos del formulario</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Configuración de campos</th>
                        <td>
                            <p class="description">Los campos estructurales siempre se muestran. Aquí puedes definir visibilidad y obligatoriedad del resto de campos.</p>
                            <?php $form_fields = ARM_Form::get_field_settings(); ?>
                            <table class="widefat striped" style="max-width:780px; margin-top:10px;">
                                <thead>
                                    <tr>
                                        <th>Campo</th>
                                        <th>Visible</th>
                                        <th>Obligatorio</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (ARM_Form::get_configurable_fields_schema() as $field_key => $field) :
                                        $state = $form_fields[$field_key] ?? ['visible' => 1, 'required' => 0];
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($field['label']); ?></td>
                                        <td>
                                            <input type="hidden" name="<?php echo esc_attr(self::OPT_FORM_FIELDS . '[' . $field_key . '][visible]'); ?>" value="0">
                                            <label>
                                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_FORM_FIELDS . '[' . $field_key . '][visible]'); ?>" value="1" <?php checked(1, absint($state['visible'] ?? 1)); ?>>
                                                Mostrar
                                            </label>
                                        </td>
                                        <td>
                                            <input type="hidden" name="<?php echo esc_attr(self::OPT_FORM_FIELDS . '[' . $field_key . '][required]'); ?>" value="0">
                                            <label>
                                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_FORM_FIELDS . '[' . $field_key . '][required]'); ?>" value="1" <?php checked(1, absint($state['required'] ?? 0)); ?>>
                                                Requerido
                                            </label>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>Ayuda</h2>
                <p>Inserta el formulario en cualquier página con el shortcode:</p>
                <code>[agrocampo_recepcion_maquinaria]</code>

                <?php submit_button('Guardar cambios'); ?>
            </form>
        </div>
        <?php
    }
}
