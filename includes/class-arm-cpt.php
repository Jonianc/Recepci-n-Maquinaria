<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_CPT {

    const POST_TYPE = 'arm_recepcion';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_meta']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'column_values'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'sortable_columns']);
    }

    public function register_post_type(): void {
        $labels = [
            'name' => 'Recepciones',
            'singular_name' => 'Recepción',
            'add_new' => 'Nueva',
            'add_new_item' => 'Nueva Recepción',
            'edit_item' => 'Editar Recepción',
            'new_item' => 'Nueva Recepción',
            'view_item' => 'Ver Recepción',
            'search_items' => 'Buscar Recepciones',
            'not_found' => 'No hay recepciones',
            'menu_name' => 'Recepciones',
        ];

        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-clipboard',
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function register_meta(): void {
        $keys = ARM_Form::meta_keys();
        foreach ($keys as $k) {
            register_post_meta(self::POST_TYPE, $k, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => function($v){ return is_string($v) ? $v : ''; },
                'auth_callback' => function(){ return current_user_can('edit_posts'); }
            ]);
        }

        register_post_meta(self::POST_TYPE, 'arm_checklist', [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => function($v){ return is_array($v) ? array_values(array_map('sanitize_text_field', $v)) : []; },
            'auth_callback' => function(){ return current_user_can('edit_posts'); }
        ]);

        register_post_meta(self::POST_TYPE, 'arm_imagenes', [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => function($v){ return is_array($v) ? array_values(array_map('absint', $v)) : []; },
            'auth_callback' => function(){ return current_user_can('edit_posts'); }
        ]);

        register_post_meta(self::POST_TYPE, 'arm_pdf_url', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback' => function(){ return current_user_can('edit_posts'); }
        ]);
    }

    public function columns($cols) {
        $new = [];
        $new['cb'] = $cols['cb'];
        $new['title'] = 'Folio';
        $new['arm_fecha'] = 'Fecha';
        $new['arm_cliente'] = 'Cliente';
        $new['arm_tipo'] = 'Tipo';
        $new['arm_marca'] = 'Marca';
        $new['arm_modelo'] = 'Modelo';
        $new['arm_interno'] = 'Interno';
        $new['arm_pdf'] = 'PDF';
        $new['date'] = $cols['date'];
        return $new;
    }

    public function column_values($col, $post_id): void {
        switch ($col) {
            case 'arm_fecha':
                $v = get_post_meta($post_id, 'arm_fecha_recepcion', true);
                echo esc_html(ARM_Utils::fmt_date_for_humans((string)$v));
                break;
            case 'arm_cliente':
                echo esc_html(get_post_meta($post_id, 'arm_cliente', true));
                break;
            case 'arm_tipo':
                echo esc_html(get_post_meta($post_id, 'arm_tipo_maquinaria', true));
                break;
            case 'arm_marca':
                $tipo = get_post_meta($post_id, 'arm_tipo_maquinaria', true);
                $m = ($tipo === 'Tractor') ? get_post_meta($post_id, 'arm_marca_tractor', true) : get_post_meta($post_id, 'arm_marca_implemento', true);
                echo esc_html($m);
                break;
            case 'arm_modelo':
                echo esc_html(get_post_meta($post_id, 'arm_modelo', true));
                break;
            case 'arm_interno':
                echo esc_html(get_post_meta($post_id, 'arm_interno', true));
                break;
            case 'arm_pdf':
                $url = get_post_meta($post_id, 'arm_pdf_url', true);
                if ($url) {
                    echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Descargar</a>';
                } else {
                    echo '—';
                }
                break;
        }
    }

    public function sortable_columns($cols) {
        $cols['arm_fecha'] = 'arm_fecha_recepcion';
        $cols['arm_cliente'] = 'arm_cliente';
        return $cols;
    }
}
