<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

// NOTE:
// Some sites load FPDF from other plugins/themes. Including it unconditionally triggers:
// "Cannot declare class FPDF, because the name is already in use".
// We load it only if the class is not already defined.

final class ARM_PDF {

    public static function generate(int $post_id): array {
        if (!class_exists('\\FPDF', false)) {
            require_once ARM_PLUGIN_DIR . 'lib/fpdf/fpdf.php';
        }

        $upload = ARM_Utils::upload_dir();
        $basedir = $upload['basedir'];
        $baseurl = $upload['baseurl'];

        $filename = 'recepcion-' . $post_id . '-' . date('Ymd-His') . '.pdf';
        $filepath = trailingslashit($basedir) . $filename;
        $fileurl  = trailingslashit($baseurl) . $filename;

        $data = ARM_Form::get_post_data($post_id);

        $logo_id = absint(get_option(ARM_Settings::OPT_LOGO_ID, 0));
        $logo_path = '';
        if ($logo_id) {
            $p = get_attached_file($logo_id);
            if ($p && file_exists($p)) { $logo_path = $p; }
        }

        // Importante: evitar emojis/símbolos no soportados por FPDF (ISO-8859-1)
        // y mantener un pie limpio para impresión.
        $footer_text = (string) get_option(
            ARM_Settings::OPT_PDF_FOOTER,
            'Talca - Linares - Parral | +56 9 9748 5650'
        );

        $pdf = new ARM_PDF_Doc($post_id, $logo_path, $footer_text);
        $pdf->AliasNbPages();
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        // ===== Secciones =====
        $pdf->Ln(3);
        self::section_cliente($pdf, $data);
        $pdf->Ln(2);
        self::section_equipo($pdf, $data);
        $pdf->Ln(2);
        self::section_detalle($pdf, $data);
        $pdf->Ln(2);
        self::section_estado($pdf, $data);
        // Nota: Las imágenes se envían adjuntas por correo (no se incrustan en el PDF).

        // Save
        $pdf->Output('F', $filepath);

        return [
            'path' => $filepath,
            'url'  => $fileurl,
        ];
    }

    private static function card_title(ARM_PDF_Doc $pdf, string $title): void {
        $pdf->SetFont('Arial','B',11);
        $pdf->SetFillColor(244, 245, 247);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->Cell(0, 7.5, self::enc($title), 0, 1, 'L', true);
        $pdf->SetDrawColor(220,220,220);
        $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
        $pdf->Ln(3);
    }

    private static function row(ARM_PDF_Doc $pdf, string $label, string $value): void {
        $labelW = 48;
        $valueW = (198 - 12 - 12) - $labelW;
        $lineH  = 5.6;

        $y0 = $pdf->GetY();
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->MultiCell($labelW, $lineH, self::enc($label), 0, 'L');

        $y1 = $pdf->GetY();
        $pdf->SetXY(12 + $labelW, $y0);

        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell($valueW, $lineH, self::enc(self::value_or_dash($value)), 0, 'L');
        $y2 = $pdf->GetY();

        $pdf->SetY(max($y1, $y2));
    }

    private static function section_cliente(ARM_PDF_Doc $pdf, array $data): void {
        self::card_title($pdf, 'Cliente');
        self::row($pdf, 'Cliente', (string)($data['arm_cliente'] ?? ''));
        self::row($pdf, 'Correo', (string)($data['arm_correo_cliente'] ?? ''));
        self::row($pdf, 'Fecha recepción', ARM_Utils::fmt_date_for_humans((string)($data['arm_fecha_recepcion'] ?? '')));
    }

    private static function section_equipo(ARM_PDF_Doc $pdf, array $data): void {
        self::card_title($pdf, 'Equipo');
        $tipo = (string)($data['arm_tipo_maquinaria'] ?? '');
        self::row($pdf, 'Tipo', $tipo);

        if ($tipo === 'Tractor') {
            self::row($pdf, 'Tipo tractor', (string)($data['arm_tipo_tractor'] ?? ''));
            self::row($pdf, 'Marca', (string)($data['arm_marca_tractor'] ?? ''));
        } else {
            self::row($pdf, 'Tipo implemento', (string)($data['arm_tipo_implemento'] ?? ''));
            self::row($pdf, 'Marca', (string)($data['arm_marca_implemento'] ?? ''));
        }

        self::row($pdf, 'Modelo', (string)($data['arm_modelo'] ?? ''));
        self::row($pdf, 'Interno', (string)($data['arm_interno'] ?? ''));
        self::row($pdf, 'Serie', (string)($data['arm_serie'] ?? ''));
        self::row($pdf, 'Patente', (string)($data['arm_patente'] ?? ''));
        self::row($pdf, 'Horas', (string)($data['arm_horas'] ?? ''));
    }

    private static function section_detalle(ARM_PDF_Doc $pdf, array $data): void {
        self::card_title($pdf, 'Detalle');

        // Falla (caja)
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0, 6, self::enc('Falla'), 0, 1, 'L');
        $y0 = $pdf->GetY();
        $x = 12; $w = 198 - 12 - 12;
        $pdf->SetDrawColor(215,215,215);
        $pdf->SetFont('Arial','',10);
        $falla = self::value_or_dash((string)($data['arm_falla'] ?? ''));
        $boxH = self::calc_box_height($pdf, $w - 6, 5.2, $falla, 20);
        $pdf->Rect($x, $y0, $w, $boxH);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->MultiCell($w-6, 5.2, self::enc($falla), 0, 'L');
        $pdf->SetY($y0 + $boxH);
        $pdf->Ln(2);

        // Checklist (más visual)
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0, 6, self::enc('Checklist'), 0, 1, 'L');

        $selected = $data['arm_checklist'] ?? [];
        if (!is_array($selected)) { $selected = []; }
        $selected = array_map('strval', $selected);

        $all = [
            'TAPA TDF',
            'VARILLA AC',
            'ANTIVUELCO',
            'ESPEJOS',
            'VIDRIOS',
            'FOCOS',
            'TUERCA RUEDA',
            'NIVEL COMBUSTIBLE',
        ];

        // 2 columnas (más legible)
        $cols = 2;
        $colW = (198 - 12 - 12) / $cols;
        $lineH = 5.6;
        $startX = 12;
        $startY = $pdf->GetY();

        $i = 0;
        foreach ($all as $item) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x = $startX + ($col * $colW);
            $y = $startY + ($row * $lineH);

            $mark = in_array($item, $selected, true) ? 'X' : ' ';
            $pdf->SetXY($x, $y);
            $pdf->SetFont('Arial','',10);
            $pdf->Cell($colW, $lineH, self::enc('[' . $mark . '] ' . $item), 0, 0, 'L');
            $i++;
        }
        $rows = (int) ceil(count($all) / $cols);
        $pdf->SetY($startY + ($rows * $lineH));

        $pdf->Ln(2);

        // Otros (caja)
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0, 6, self::enc('Otros'), 0, 1, 'L');
        $y0 = $pdf->GetY();
        $x = 12;
        $pdf->SetDrawColor(215,215,215);
        $otros = self::value_or_dash((string)($data['arm_otros'] ?? ''));
        $boxH = self::calc_box_height($pdf, $w - 6, 5.2, $otros, 14);
        $pdf->Rect($x, $y0, $w, $boxH);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->SetFont('Arial','',10);
        $pdf->MultiCell($w-6, 5.2, self::enc($otros), 0, 'L');
        $pdf->SetY($y0 + $boxH);
    }

    private static function section_estado(ARM_PDF_Doc $pdf, array $data): void {
        self::card_title($pdf, 'Estado');
        self::row($pdf, 'Llaves', (string)($data['arm_llaves'] ?? ''));
        self::row($pdf, 'Combustible', (string)($data['arm_nivel_combustible'] ?? ''));
        // Observaciones (caja)
        $pdf->Ln(1);
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0, 6, self::enc('Observaciones'), 0, 1, 'L');
        $y0 = $pdf->GetY();
        $x = 12; $w = 198 - 12 - 12;
        $pdf->SetDrawColor(215,215,215);
        $obs = self::value_or_dash((string)($data['arm_observaciones'] ?? ''));
        $boxH = self::calc_box_height($pdf, $w - 6, 5.2, $obs, 16);
        $pdf->Rect($x, $y0, $w, $boxH);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->SetFont('Arial','',10);
        $pdf->MultiCell($w-6, 5.2, self::enc($obs), 0, 'L');
        $pdf->SetY($y0 + $boxH);
    }


    private static function value_or_dash(string $value): string {
        $value = trim($value);
        return $value !== '' ? $value : '—';
    }

    private static function calc_box_height(ARM_PDF_Doc $pdf, float $width, float $lineH, string $text, float $minHeight): float {
        $lines = self::calc_text_lines($pdf, $width, $text);
        $height = ($lines * $lineH) + 4;
        return max($minHeight, $height);
    }

    private static function calc_text_lines(ARM_PDF_Doc $pdf, float $width, string $text): int {
        $text = str_replace("\r", '', $text);
        if ($text === '') {
            return 1;
        }
        $words = explode("\n", $text);
        $lines = 0;
        foreach ($words as $block) {
            $line = '';
            $chunks = preg_split('/\s+/', $block);
            foreach ($chunks as $chunk) {
                $test = $line === '' ? $chunk : $line . ' ' . $chunk;
                if ($pdf->GetStringWidth($test) <= $width) {
                    $line = $test;
                } else {
                    $lines++;
                    $line = $chunk;
                }
            }
            $lines++;
        }
        return max(1, $lines);
    }


    public static function enc(string $s): string {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    }
}

final class ARM_PDF_Doc extends \FPDF {
    private int $post_id;
    private string $logo_path;
    private string $footer_text;

    public function __construct(int $post_id, string $logo_path, string $footer_text) {
        parent::__construct('P', 'mm', 'A4');
        $this->post_id = $post_id;
        $this->logo_path = $logo_path;
        $this->footer_text = $footer_text;
    }

    public function Header(): void {
        // Logo
        if ($this->logo_path !== '' && file_exists($this->logo_path)) {
            $this->Image($this->logo_path, 12, 10, 28);
        }

        // Título
        $this->SetFont('Arial', 'B', 14);
        $this->SetXY(0, 12);
        $this->Cell(0, 7, ARM_PDF::enc('RECEPCIÓN DE MAQUINARIA'), 0, 1, 'C');

        // Folio/fecha (derecha)
        $this->SetFont('Arial', '', 10);
        $this->SetXY(150, 12);
        $this->Cell(48, 6, ARM_PDF::enc('Folio: ') . $this->post_id, 0, 2, 'R');
        $this->Cell(48, 6, ARM_PDF::enc('Emisión: ') . date_i18n('d/m/Y H:i'), 0, 2, 'R');

        $this->Ln(6);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(6);
    }

    public function Footer(): void {
        return;
    }
}
