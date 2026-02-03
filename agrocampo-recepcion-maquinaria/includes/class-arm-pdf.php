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

        // ===== Resumen (arriba) =====
        $pdf->Ln(2);
        self::summary_box($pdf, $data);

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

    private static function summary_box(ARM_PDF_Doc $pdf, array $data): void {
        $x = 12;
        $w = 198 - 12 - 12;
        $y0 = $pdf->GetY();

        $pdf->SetDrawColor(215,215,215);
        $pdf->SetLineWidth(0.2);
        $pdf->Rect($x, $y0, $w, 22);

        $pdf->SetXY($x + 3, $y0 + 3);
        $pdf->SetFont('Arial','B',11);
        $pdf->Cell(0, 6, self::enc('Resumen'), 0, 1, 'L');

        $tipo = (string)($data['arm_tipo_maquinaria'] ?? '');
        $marca = '';
        if ($tipo === 'Tractor') {
            $marca = (string)($data['arm_marca_tractor'] ?? '');
        } else {
            $marca = (string)($data['arm_marca_implemento'] ?? '');
        }
        $modelo = (string)($data['arm_modelo'] ?? '');
        $serie  = (string)($data['arm_serie'] ?? '');
        $patente= (string)($data['arm_patente'] ?? '');
        $horas  = (string)($data['arm_horas'] ?? '');
        $llaves = (string)($data['arm_llaves'] ?? '');
        $comb   = (string)($data['arm_nivel_combustible'] ?? '');

        $pdf->SetFont('Arial','',10);
        $pdf->SetX($x + 3);
        $pdf->Cell(0, 5.5, self::enc(trim($tipo . ' — ' . $marca . ' ' . $modelo)), 0, 1, 'L');

        $line = [];
        if ($serie !== '')  { $line[] = 'Serie: ' . $serie; }
        if ($patente !== ''){ $line[] = 'Patente: ' . $patente; }
        if ($horas !== '')  { $line[] = 'Horas: ' . $horas; }
        $pdf->SetX($x + 3);
        $pdf->MultiCell($w - 6, 5.2, self::enc(implode('   |   ', $line)), 0, 'L');

        $line2 = [];
        if ($llaves !== '') { $line2[] = 'Llaves: ' . $llaves; }
        if ($comb !== '')   { $line2[] = 'Combustible: ' . $comb; }
        if (!empty($line2)) {
            $pdf->SetX($x + 3);
            $pdf->MultiCell($w - 6, 5.2, self::enc(implode('   |   ', $line2)), 0, 'L');
        }

        $pdf->SetY($y0 + 22);
    }

    private static function card_title(ARM_PDF_Doc $pdf, string $title): void {
        $pdf->SetFont('Arial','B',11);
        $pdf->Cell(0, 7, self::enc($title), 0, 1, 'L');
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
        $pdf->MultiCell($valueW, $lineH, self::enc($value), 0, 'L');
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
        $pdf->Rect($x, $y0, $w, 22);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->SetFont('Arial','',10);
        $pdf->MultiCell($w-6, 5.2, self::enc((string)($data['arm_falla'] ?? '')), 0, 'L');
        $pdf->SetY($y0 + 22);
        $pdf->Ln(2);

        // Checklist (más visual)
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(0, 6, self::enc('Checklist'), 0, 1, 'L');

        $selected = $data['arm_checklist'] ?? [];
        if (!is_array($selected)) { $selected = []; }
        $selected = array_map('strval', $selected);

        $all = [
            'TAPA TDF','VARILLA AC','ANTIVUELCO','BARRA TIRO','GANCHO TIRO','TAPA BATERIA',
            'EMB. REMOTO','PUERTAS','EXTINTOR','CUÑAS','GOTEO','LUCES','ETC.'
        ];

        // 3 columnas (mostrar todos; marca compatible: X)
        $cols = 3;
        $colW = (198 - 12 - 12) / $cols;
        $lineH = 5.2;
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
        $pdf->SetDrawColor(215,215,215);
        $pdf->Rect($x, $y0, $w, 16);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->SetFont('Arial','',10);
        $pdf->MultiCell($w-6, 5.2, self::enc((string)($data['arm_otros'] ?? '')), 0, 'L');
        $pdf->SetY($y0 + 16);
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
        $pdf->Rect($x, $y0, $w, 18);
        $pdf->SetXY($x+3, $y0+2);
        $pdf->SetFont('Arial','',10);
        $pdf->MultiCell($w-6, 5.2, self::enc((string)($data['arm_observaciones'] ?? '')), 0, 'L');
        $pdf->SetY($y0 + 18);
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
        $this->SetY(-18);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(70,70,70);

        $footer = trim((string)$this->footer_text);
        if ($footer !== '') {
            $this->MultiCell(0, 4.2, ARM_PDF::enc($footer), 0, 'C');
        }

        $this->SetY(-12);
        $this->SetFont('Arial','',8);
        $this->Cell(0, 4, ARM_PDF::enc('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}
