<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_Email {

    /**
     * Sends internal/client notifications.
     *
     * IMPORTANT:
     * Some hosts disable PHP's mail() function. If there is no SMTP plugin configuring PHPMailer,
     * calling wp_mail() can fatally error. In that case we skip sending and return a warning.
     */
    public static function send(int $post_id, string $pdf_path, string $pdf_url, array $image_paths = [], int $image_count = 0): array {
        $data = ARM_Form::get_post_data($post_id);

        $internal_raw = (string) get_option(ARM_Settings::OPT_INTERNAL_EMAILS, '');
        $internal_emails = ARM_Utils::normalize_emails($internal_raw);

        $send_to_client = absint(get_option(ARM_Settings::OPT_SEND_TO_CLIENT, 1)) === 1;
        $client_email = ARM_Utils::sanitize_email($data['arm_correo_cliente'] ?? '');

        $from_name = (string) get_option(ARM_Settings::OPT_FROM_NAME, 'Agrocampo');
        $reply_to = (string) get_option(ARM_Settings::OPT_REPLY_TO, '');

        $subject = self::subject($post_id, $data);

        $headers = [];
        if ($reply_to && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $reply_to . '>';
        }

        // Force From name (scope: this send call only)
        $from_name_cb = function() use ($from_name){ return $from_name; };
        add_filter('wp_mail_from_name', $from_name_cb, 99);
        $from_cb = null;
        if ($reply_to && is_email($reply_to)) {
            $from_cb = function() use ($reply_to){ return $reply_to; };
            add_filter('wp_mail_from', $from_cb, 99);
        }

        $last_mail_error = '';
        $mail_failed_cb = function($wp_error) use (&$last_mail_error) {
            if ($wp_error instanceof \WP_Error) {
                $last_mail_error = $wp_error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $mail_failed_cb, 99, 1);

        $attachments = [];
        if ($pdf_path && file_exists($pdf_path)) $attachments[] = $pdf_path;

        // Adjuntar imágenes temporales (se envían por correo, no se incrustan en el PDF)
        if (!empty($image_paths)) {
            $image_paths = array_slice($image_paths, 0, 10);
            foreach ($image_paths as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    $attachments[] = $path;
                }
            }
        }

        // Fail-safe: avoid fatal error when mail() is disabled and no SMTP is configured.
        // Many SMTP plugins hook into phpmailer_init.
        $smtp_hooked = has_action('phpmailer_init') ? true : false;
        if (!function_exists('mail') && !$smtp_hooked) {
            $msg = 'Correo no enviado: el servidor no tiene mail() habilitado y no hay SMTP configurado.';
            update_post_meta($post_id, 'arm_email_status', 'skipped');
            update_post_meta($post_id, 'arm_email_message', $msg);
            // cleanup filters
            remove_filter('wp_mail_from_name', $from_name_cb, 99);
            if ($from_cb) {
                remove_filter('wp_mail_from', $from_cb, 99);
            }
            remove_action('wp_mail_failed', $mail_failed_cb, 99);
            return [
                'status' => 'skipped',
                'message' => $msg,
                'internal_sent' => false,
                'client_sent' => false,
            ];
        }

        // Internal email
        $internal_sent = false;
        $client_sent = false;
        if (!empty($internal_emails)) {
            $body = self::body_internal($post_id, $data, $pdf_url, $image_count);
            $internal_sent = (bool) wp_mail($internal_emails, $subject, $body, $headers, $attachments);
        }

        // Client email
        if ($send_to_client && $client_email && is_email($client_email)) {
            $body = self::body_client($post_id, $data);
            $client_sent = (bool) wp_mail([$client_email], $subject, $body, $headers, $attachments);
        }

        // cleanup filters (do not nuke global filters)
        remove_filter('wp_mail_from_name', $from_name_cb, 99);
        if ($from_cb) {
            remove_filter('wp_mail_from', $from_cb, 99);
        }
        remove_action('wp_mail_failed', $mail_failed_cb, 99);

        $status = ($internal_sent || $client_sent) ? 'sent' : 'failed';
        update_post_meta($post_id, 'arm_email_status', $status);
        $error_message = '';
        if ($status === 'failed') {
            $error_message = $last_mail_error !== '' ? $last_mail_error : 'wp_mail() devolvió false';
        }
        update_post_meta($post_id, 'arm_email_message', $error_message);

        return [
            'status' => $status,
            'message' => $error_message,
            'internal_sent' => $internal_sent,
            'client_sent' => $client_sent,
        ];
    }

    private static function subject(int $post_id, array $data): string {
        $cliente = $data['arm_cliente'] ?? '';
        $tipo = $data['arm_tipo_maquinaria'] ?? '';
        $marca = ($tipo === 'Tractor') ? ($data['arm_marca_tractor'] ?? '') : ($data['arm_marca_implemento'] ?? '');
        $modelo = $data['arm_modelo'] ?? '';
        $parts = array_filter([
            'Recepción #' . $post_id,
            $cliente,
            trim($marca . ' ' . $modelo),
        ]);
        return implode(' - ', $parts);
    }

    private static function body_internal(int $post_id, array $d, string $pdf_url, int $image_count): string {
        $tipo = $d['arm_tipo_maquinaria'] ?? '';
        $marca = ($tipo === 'Tractor') ? ($d['arm_marca_tractor'] ?? '') : ($d['arm_marca_implemento'] ?? '');

        $lines = [];
        $lines[] = "Se registró una recepción de maquinaria.";
        $lines[] = "";
        $lines[] = "Folio: " . $post_id;
        $lines[] = "Fecha recepción: " . ARM_Utils::fmt_date_for_humans((string)($d['arm_fecha_recepcion'] ?? ''));
        $lines[] = "Cliente: " . ($d['arm_cliente'] ?? '');
        $lines[] = "Correo cliente: " . ($d['arm_correo_cliente'] ?? '');
        $lines[] = "Tipo: " . $tipo;
        if ($tipo === 'Tractor') {
            $lines[] = "Tipo tractor: " . ($d['arm_tipo_tractor'] ?? '');
        } else {
            $lines[] = "Tipo implemento: " . ($d['arm_tipo_implemento'] ?? '');
        }
        $lines[] = "Marca: " . $marca;
        $lines[] = "Modelo: " . ($d['arm_modelo'] ?? '');
        $lines[] = "Interno: " . ($d['arm_interno'] ?? '');
        $lines[] = "Serie: " . ($d['arm_serie'] ?? '');
        $lines[] = "Patente: " . ($d['arm_patente'] ?? '');
        $lines[] = "Horas: " . ($d['arm_horas'] ?? '');
        $lines[] = "";
        $lines[] = "Falla:";
        $lines[] = ($d['arm_falla'] ?? '');
        $lines[] = "";
        $lines[] = "Checklist: " . (is_array($d['arm_checklist'] ?? null) ? implode(', ', $d['arm_checklist']) : '');
        $lines[] = "Otros: " . ($d['arm_otros'] ?? '');
        $lines[] = "";
        $lines[] = "Llaves: " . ($d['arm_llaves'] ?? '');
        $lines[] = "Nivel combustible: " . ($d['arm_nivel_combustible'] ?? '');
        $lines[] = "Observaciones: " . ($d['arm_observaciones'] ?? '');
        if ($image_count > 0) {
            $lines[] = "Imágenes adjuntas: " . $image_count;
        }
        if ($pdf_url) {
            $lines[] = "";
            $lines[] = "PDF: " . $pdf_url;
        }
        return implode("\n", $lines);
    }

    private static function body_client(int $post_id, array $d): string {
        $lines = [];
        $lines[] = "Hola" . ($d['arm_cliente'] ? " " . $d['arm_cliente'] : "") . ",";
        $lines[] = "";
        $lines[] = "Adjuntamos el PDF de recepción de maquinaria (Folio #" . $post_id . ").";
        $lines[] = "";
        $lines[] = "Agrocampo";
        return implode("\n", $lines);
    }
}
