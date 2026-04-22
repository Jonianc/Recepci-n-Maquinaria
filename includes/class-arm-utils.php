<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_Utils {

    public static function opt(string $key, $default = '') {
        $val = get_option($key, $default);
        return $val;
    }

    public static function sanitize_text($v): string {
        return sanitize_text_field((string)$v);
    }

    public static function sanitize_textarea($v): string {
        return sanitize_textarea_field((string)$v);
    }

    public static function sanitize_email($v): string {
        return sanitize_email((string)$v);
    }

    public static function sanitize_date($v): string {
        // Expecting YYYY-MM-DD. If other, store raw sanitized.
        $v = self::sanitize_text($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        return $v;
    }

    public static function normalize_emails(string $raw): array {
        $parts = preg_split('/[,\n;]/', $raw);
        $emails = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (!$p) continue;
            $e = sanitize_email($p);
            if ($e && is_email($e)) $emails[] = $e;
        }
        return array_values(array_unique($emails));
    }

    public static function upload_dir(): array {
        $u = wp_upload_dir();
        $subdir = 'agrocampo-recepcion-maquinaria';
        $path = trailingslashit($u['basedir']) . $subdir;
        $url  = trailingslashit($u['baseurl']) . $subdir;

        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }

        return [
            'basedir' => $path,
            'baseurl' => $url,
        ];
    }

    public static function fmt_date_for_humans(string $ymd): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            $ts = strtotime($ymd . ' 12:00:00');
            return date_i18n('d/m/Y', $ts);
        }
        return $ymd;
    }
}
