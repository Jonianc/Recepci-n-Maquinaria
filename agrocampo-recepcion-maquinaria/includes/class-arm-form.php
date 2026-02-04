<?php
namespace Agrocampo\RecepcionMaquinaria;

if (!defined('ABSPATH')) { exit; }

final class ARM_Form {

    const QUERY_VAR = 'arm_recepcion_maquinaria';

    /**
     * Lista de metakeys (strings) usadas por el formulario.
     * Se usa para registrar meta (register_post_meta) en el CPT.
     */
    public static function meta_keys(): array {
        return [
            'arm_tipo_maquinaria',
            'arm_tipo_tractor',
            'arm_tipo_implemento',
            'arm_fecha_recepcion',
            'arm_cliente',
            'arm_correo_cliente',
            'arm_marca_tractor',
            'arm_marca_implemento',
            'arm_modelo',
            'arm_interno',
            'arm_serie',
            'arm_patente',
            'arm_horas',
            'arm_falla',
            'arm_otros',
            'arm_llaves',
            'arm_nivel_combustible',
            'arm_observaciones',
        ];
    }

    /**
     * Devuelve un array con los datos guardados en el CPT (post_meta).
     * Se usa para generar el PDF y correos sin depender de $_POST.
     */
    public static function get_post_data(int $post_id): array {
        $data = [];

        foreach (self::meta_keys() as $k) {
            $data[$k] = (string) get_post_meta($post_id, $k, true);
        }

        $check = get_post_meta($post_id, 'arm_checklist', true);
        if (!is_array($check)) {
            $check = [];
        }
        $data['arm_checklist'] = array_values(array_filter(array_map('strval', $check)));

        $imgs = get_post_meta($post_id, 'arm_imagenes', true);
        if (!is_array($imgs)) {
            $imgs = [];
        }
        $data['arm_imagenes'] = array_values(array_map('intval', $imgs));

        $data['post_id'] = $post_id;
        $data['post_title'] = (string) get_the_title($post_id);

        return $data;
    }


    public function __construct() {
        // Standalone frontend (sin theme) por ruta configurable
        add_action('init', [$this, 'register_route']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_standalone'], 0);

        // Handler de envío
        add_action('init', [$this, 'handle_post']);
        add_action('arm_send_email_async', [$this, 'send_email_async'], 10, 1);
    }

    public function add_query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function register_route(): void {
        $slug = $this->get_front_slug();
        // Slug seguro: solo a-z 0-9 y guiones
        add_rewrite_rule('^' . $slug . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    private function get_front_slug(): string {
        $slug = (string) get_option(ARM_Settings::OPT_FRONT_SLUG, 'recepcion-maquinaria');
        $slug = sanitize_title($slug);
        return $slug ?: 'recepcion-maquinaria';
    }

    private function frontend_url(): string {
        return trailingslashit(home_url('/' . $this->get_front_slug()));
    }

    public function maybe_render_standalone(): void {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return;
        }

        // Respuesta standalone: sin theme
        status_header(200);
        nocache_headers();

        $msg  = $this->build_message_from_query();
        $body = $this->render_form($msg);

        echo $this->render_standalone_doc($body);
        exit;
    }

    private function build_message_from_query(): string {
        $out = '';

        if (!empty($_GET['arm_ok'])) {
            $id = (int) $_GET['arm_ok'];
            $pdf_url = $id ? (string) get_post_meta($id, 'arm_pdf_url', true) : '';
            $mail_status = $id ? (string) get_post_meta($id, 'arm_email_status', true) : '';

            $mail_txt = '—';
            if ($mail_status === 'sent') {
                $mail_txt = 'Enviado';
            } elseif ($mail_status === 'queued') {
                $mail_txt = 'En cola de envío';
            } elseif ($mail_status === 'skipped') {
                $mail_txt = 'Omitido (sin SMTP)';
            } elseif ($mail_status === 'failed') {
                $mail_msg = $id ? (string) get_post_meta($id, 'arm_email_message', true) : '';
                $mail_txt = $mail_msg ? ('Falló: ' . $mail_msg) : 'Falló';
            }

            $out .= '<div class="arm-notice arm-notice--success">'
                . '<strong>Recepción guardada' . ($id ? ' (ID: ' . (int) $id . ')' : '') . '.</strong>'
                . '<div style="margin-top:8px;font-size:13px;">'
                . '<div><strong>PDF:</strong> ' . ($pdf_url ? '<a href="' . esc_url($pdf_url) . '" target="_blank" rel="noopener">Abrir PDF</a>' : '—') . '</div>'
                . '<div><strong>Correo:</strong> ' . esc_html($mail_txt) . '</div>'
                . '</div>'
                . '</div>';
        }

        if (!empty($_GET['arm_warn'])) {
            $warn = sanitize_text_field(wp_unslash($_GET['arm_warn']));
            $out .= $this->notice('warning', $warn);
        }

        if (!empty($_GET['arm_err'])) {
            $err = rawurldecode((string) $_GET['arm_err']);
            $err = sanitize_text_field(wp_unslash($err));
            $out .= $this->notice('error', $err);
        }

        return $out;
    }

    private function notice(string $type, string $text): string {
        $type = in_array($type, ['success','warning','error'], true) ? $type : 'warning';
        return '<div class="arm-notice arm-notice--' . esc_attr($type) . '">' . esc_html($text) . '</div>';
    }

    private function render_standalone_doc(string $body): string {
        // HTML completo, sin theme.
        $title = 'Recepción de Maquinaria - Agrocampo';

        $logo_id  = absint(get_option(ARM_Settings::OPT_LOGO_ID, 0));
        $logo_url = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';

        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
    <style>
        :root{
            --bg:#f6f7f7;
            --card:#fff;
            --border:#dcdcde;
            --text:#1d2327;
            --muted:#50575e;
            --primary:#0a7d3b;
            --primary2:#0a6c34;
            --danger:#d63638;
            --warn:#dba617;
            --ok:#00a32a;
            --shadow:0 1px 2px rgba(0,0,0,.05);
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);}
        a{color:var(--primary);text-decoration:none}
        a:hover{text-decoration:underline}

        .arm-page{max-width:860px;margin:16px auto 120px;padding:0 14px;}
        .arm-card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);padding:16px;}
        .arm-header{display:flex;gap:12px;align-items:center;margin-bottom:12px;}
        .arm-header img{max-height:44px;width:auto;display:block}
        .arm-header h1{font-size:18px;line-height:1.2;margin:0;}
        .arm-sub{color:var(--muted);font-size:12px;margin-top:2px;}

        /* Stepper */
        .arm-stepper{display:flex;gap:8px;margin:10px 0 14px;}
        .arm-stepper__item{flex:1;display:flex;align-items:center;gap:8px;padding:10px 10px;border:1px solid var(--border);border-radius:12px;background:#fff;color:var(--muted);font-size:13px;}
        .arm-stepper__dot{width:26px;height:26px;border-radius:999px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-weight:700;}
        .arm-stepper__item.is-active{border-color:rgba(10,125,59,.35);color:var(--text);}
        .arm-stepper__item.is-active .arm-stepper__dot{background:rgba(10,125,59,.1);border-color:rgba(10,125,59,.35);}
        .arm-stepper__item.is-done{border-color:rgba(0,163,42,.35);}
        .arm-stepper__item.is-done .arm-stepper__dot{background:rgba(0,163,42,.1);border-color:rgba(0,163,42,.35);}

        .arm-step{display:none;}
        .arm-step.is-active{display:block;}
        .arm-step h2{font-size:15px;margin:0 0 8px;}
        .arm-help{color:var(--muted);font-size:12px;margin:-6px 0 10px;}

        /* Fields */
        .arm-grid{display:grid;grid-template-columns:1fr;gap:12px;}
        @media(min-width:760px){.arm-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
        .arm-field label{display:block;font-size:13px;margin-bottom:6px;color:var(--muted);font-weight:600;}
        .arm-required{color:var(--danger);font-weight:700;margin-left:4px;}
        .arm-hint{font-size:12px;color:var(--muted);margin-top:6px;}
        .arm-input, .arm-textarea{width:100%;padding:12px;border:1px solid var(--border);border-radius:12px;font-size:16px;background:#fff;transition:border-color .15s, box-shadow .15s;line-height:1.2;}
        .arm-input[type="date"]{text-align:left;min-height:46px;}
        .arm-input:focus, .arm-textarea:focus{border-color:rgba(10,125,59,.5);box-shadow:0 0 0 3px rgba(10,125,59,.12);outline:none;}
        .arm-textarea{resize:vertical;min-height:88px;}
        .arm-inline{display:flex;flex-wrap:wrap;gap:10px;}
        .arm-chip{
            position:relative;display:inline-flex;align-items:center;gap:8px;
            padding:10px 12px;border:1px solid var(--border);border-radius:999px;
            background:#fff;color:var(--text);font-size:14px;user-select:none;cursor:pointer;
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        .arm-chip input{position:absolute;opacity:0;pointer-events:none;}
        .arm-chip:focus-within{outline:2px solid rgba(10,125,59,.35);outline-offset:2px;}
        .arm-chip.is-on{border-color:rgba(10,125,59,.45);background:rgba(10,125,59,.08);}

        .arm-section{margin-top:14px;padding-top:14px;border-top:1px solid var(--border);}
        .arm-section.is-soft{background:#fafafb;border:1px solid var(--border);border-radius:14px;padding:12px 12px 4px;}
        .arm-section h3{margin:0 0 10px;font-size:14px;}

        /* Checklist */
        .arm-check-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;}
        .arm-mini{border:1px solid var(--border);background:#fff;border-radius:12px;padding:8px 10px;font-weight:600;font-size:13px;}
        .arm-mini:active{transform:translateY(1px)}
        .arm-checkgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
        @media(max-width:520px){.arm-checkgrid{grid-template-columns:1fr;}}

        /* Images */
        .arm-file{border:1px dashed var(--border);border-radius:14px;padding:12px;background:#fff;}
        .arm-preview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px;}
        @media(max-width:520px){.arm-preview{grid-template-columns:repeat(2,minmax(0,1fr));}}
        .arm-thumb{position:relative;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff;}
        .arm-thumb img{width:100%;height:110px;object-fit:cover;display:block;}
        .arm-thumb button{position:absolute;top:6px;right:6px;border:0;border-radius:999px;padding:6px 8px;background:rgba(0,0,0,.65);color:#fff;font-weight:700;}

        /* Summary */
        .arm-summary{border:1px solid var(--border);border-radius:14px;padding:12px;background:#fff;}
        .arm-summary h4{margin:0 0 8px;font-size:14px;}
        .arm-summary dl{margin:0;display:grid;grid-template-columns:110px 1fr;gap:6px 10px;font-size:13px;}
        .arm-summary dt{color:var(--muted);}
        .arm-summary dd{margin:0;}

        /* Notices */
        .arm-notice{margin:10px 0 12px;padding:10px 12px;border-radius:12px;font-size:14px;border:1px solid var(--border);background:#fff;}
        .arm-notice--success{border-color:rgba(0,163,42,.35);background:#f0fff4;}
        .arm-notice--warning{border-color:rgba(219,166,23,.45);background:#fff9e6;}
        .arm-notice--error{border-color:rgba(214,54,56,.45);background:#fff0f0;}

        /* Sticky footer */
        .arm-footer{
            position:fixed;left:0;right:0;bottom:0;z-index:999;
            background:rgba(246,247,247,.92);backdrop-filter:saturate(180%) blur(10px);
            border-top:1px solid var(--border);
            padding:10px 12px;
        }
        .arm-footer__inner{max-width:860px;margin:0 auto;display:flex;gap:10px;align-items:center;}
        .arm-spacer{flex:1;}
        .arm-btn{border:1px solid var(--border);background:#fff;color:var(--text);border-radius:14px;padding:12px 14px;font-weight:800;font-size:15px;min-height:46px;transition:transform .12s, box-shadow .12s, border-color .12s;}
        .arm-btn:focus-visible{outline:2px solid rgba(10,125,59,.35);outline-offset:2px;}
        .arm-btn:hover{border-color:rgba(10,125,59,.45);box-shadow:0 2px 6px rgba(0,0,0,.06);}
        .arm-btn--primary{background:var(--primary);border-color:var(--primary);color:#fff;}
        .arm-btn--primary:active{background:var(--primary2);}
        .arm-btn:disabled{opacity:.55;}
        .arm-hidden{display:none!important;}
    </style>
</head>
<body>
<div class="arm-page">
    <div class="arm-card">
        <div class="arm-header">
            <?php if (!empty($logo_url)) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Agrocampo">
            <?php endif; ?>
            <div>
                <h1>Recepción de Maquinaria</h1>
                <div class="arm-sub">Uso interno • pensado para móvil</div>
            </div>
        </div>

        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
</div>

<script>
(function(){
  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  var form = $('#arm-form');
  if(!form) return;

  var step = 1;
  var steps = $all('.arm-step');
  var stepper = $all('.arm-stepper__item');
  var btnPrev = $('[data-action="prev"]');
  var btnNext = $('[data-action="next"]');
  var btnSend = $('[data-action="submit"]');
  var stepLabel = $('#arm_step_label');
  var imagesInput = $('#arm_images');
  var preview = $('#arm_preview');
  var storedFiles = [];

  function setToday(){
    var d = $('#arm_fecha_recepcion');
    if(d && !d.value){
      var now = new Date();
      var mm = String(now.getMonth()+1).padStart(2,'0');
      var dd = String(now.getDate()).padStart(2,'0');
      d.value = now.getFullYear() + '-' + mm + '-' + dd;
    }
  }

  function toggleByTipo(){
    var tipo = ( $('input[name="arm_tipo_maquinaria"]:checked') || {} ).value || '';
    $all('.arm-only-tractor').forEach(function(el){ el.style.display = (tipo==='Tractor') ? '' : 'none'; });
    $all('.arm-only-implemento').forEach(function(el){ el.style.display = (tipo==='Implemento') ? '' : 'none'; });

    // Required dinámico
    $all('input[name="arm_tipo_tractor"]').forEach(function(i){ i.required = (tipo==='Tractor'); });
    $all('input[name="arm_tipo_implemento"]').forEach(function(i){ i.required = (tipo==='Implemento'); });
    $all('input[name="arm_marca_tractor"]').forEach(function(i){ i.required = (tipo==='Tractor'); });
    $all('input[name="arm_marca_implemento"]').forEach(function(i){ i.required = (tipo==='Implemento'); });
  }

  function updateChipStates(){
    $all('.arm-chip').forEach(function(chip){
      var input = $('input', chip);
      if(!input) return;
      chip.classList.toggle('is-on', !!input.checked);
    });
  }

  function showStep(n){
    step = Math.max(1, Math.min(3, n));
    steps.forEach(function(s){ s.classList.toggle('is-active', s.getAttribute('data-step') == String(step)); });
    stepper.forEach(function(it){
      var i = parseInt(it.getAttribute('data-step'),10);
      it.classList.toggle('is-active', i===step);
      it.classList.toggle('is-done', i<step);
    });

    if(btnPrev) btnPrev.classList.toggle('arm-hidden', step===1);
    if(btnNext) btnNext.classList.toggle('arm-hidden', step===3);
    if(btnSend) btnSend.classList.toggle('arm-hidden', step!==3);
    if(stepLabel) stepLabel.textContent = 'Paso ' + step + ' de 3';
    window.scrollTo({top:0, behavior:'smooth'});
    refreshSummary();
  }

  function isVisible(el){
    if(!el) return false;
    if(el.closest('.arm-only-tractor') && el.closest('.arm-only-tractor').style.display === 'none') return false;
    if(el.closest('.arm-only-implemento') && el.closest('.arm-only-implemento').style.display === 'none') return false;
    return el.offsetParent !== null;
  }

  function validateStep(n){
    var container = $('.arm-step[data-step="'+n+'"]');
    if(!container) return true;
    var fields = $all('input, textarea, select', container).filter(isVisible);
    // For radios: ensure groups validate
    for(var i=0;i<fields.length;i++){
      var f = fields[i];
      if(!f.checkValidity()){
        try{ f.reportValidity(); }catch(e){}
        return false;
      }
    }
    return true;
  }

  function upperPatente(){
    var p = $('#arm_patente');
    if(!p) return;
    p.value = (p.value||'').toUpperCase().replace(/\s+/g,'');
  }

  function trimAll(){
    $all('input[type="text"], input[type="email"], textarea', form).forEach(function(i){
      if(typeof i.value === 'string') i.value = i.value.trim();
    });
  }

  function refreshSummary(){
    var box = $('#arm_summary');
    if(!box) return;
    function val(id){ var el = $('#'+id); return el ? (el.value||'').trim() : ''; }
    var tipo = ( $('input[name="arm_tipo_maquinaria"]:checked') || {} ).value || '';
    var subtipo = '';
    if(tipo==='Tractor') subtipo = ( $('input[name="arm_tipo_tractor"]:checked') || {} ).value || '';
    if(tipo==='Implemento') subtipo = ( $('input[name="arm_tipo_implemento"]:checked') || {} ).value || '';
    var marca = '';
    if(tipo==='Tractor') marca = ( $('input[name="arm_marca_tractor"]:checked') || {} ).value || '';
    if(tipo==='Implemento') marca = ( $('input[name="arm_marca_implemento"]:checked') || {} ).value || '';

    $('#s_cliente').textContent = val('arm_cliente') || '—';
    $('#s_fecha').textContent = val('arm_fecha_recepcion') || '—';
    $('#s_equipo').textContent = (tipo ? (tipo + (subtipo?(' • '+subtipo):'')) : '—');
    $('#s_marca').textContent = marca || '—';
    $('#s_modelo').textContent = val('arm_modelo') || '—';
    $('#s_serie').textContent = val('arm_serie') || '—';
    $('#s_patente').textContent = val('arm_patente') || '—';
    $('#s_horas').textContent = val('arm_horas') || '—';
  }

  function bytesToMB(b){ return Math.round((b/1024/1024)*10)/10; }

  function fileKey(file){
    return [file.name, file.size, file.lastModified].join('|');
  }

  function optimizeImage(file){
    return new Promise(function(resolve){
      if(!file || !file.type || file.type.indexOf('image/') !== 0){
        resolve(file);
        return;
      }
      var maxDim = 1200;
      var maxSize = 900 * 1024;
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function(){
        var w = img.width;
        var h = img.height;
        var maxSide = Math.max(w, h);
        var scale = Math.min(1, maxDim / maxSide);
        if(scale >= 1 && file.size <= maxSize){
          URL.revokeObjectURL(url);
          resolve(file);
          return;
        }
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(w * scale);
        canvas.height = Math.round(h * scale);
        var ctx = canvas.getContext('2d');
        if(!ctx){
          URL.revokeObjectURL(url);
          resolve(file);
          return;
        }
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function(blob){
          URL.revokeObjectURL(url);
          if(!blob){
            resolve(file);
            return;
          }
          var optimized = new File([blob], file.name, { type: blob.type || file.type, lastModified: file.lastModified });
          resolve(optimized);
        }, 'image/jpeg', 0.8);
      };
      img.onerror = function(){
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
  }

  function syncInputFiles(){
    if(!imagesInput) return;
    var dt = new DataTransfer();
    storedFiles.forEach(function(f){ dt.items.add(f); });
    imagesInput.files = dt.files;
  }

  function updateImagePreview(){
    if(!imagesInput || !preview) return;
    preview.innerHTML = '';
    var hint = $('#arm_img_hint');
    var files = storedFiles.slice();
    var max = 10;
    if(files.length > max){
      files = files.slice(0,max);
      storedFiles = files.slice();
      syncInputFiles();
    }
    var total = files.reduce(function(a,f){ return a + (f.size||0); }, 0);
    if(hint){
      hint.textContent = files.length ? ('Adjuntas: ' + files.length + '/' + max + ' • Total aprox: ' + bytesToMB(total) + ' MB') : '';
    }
    files.forEach(function(file, idx){
      var url = URL.createObjectURL(file);
      var wrap = document.createElement('div');
      wrap.className = 'arm-thumb';
      wrap.innerHTML = '<img alt="" src="'+url+'"><button type="button" aria-label="Quitar">×</button>';
      $('button', wrap).addEventListener('click', function(){
        storedFiles.splice(idx,1);
        syncInputFiles();
        updateImagePreview();
      });
      preview.appendChild(wrap);
    });
  }

  function mergeSelectedFiles(){
    if(!imagesInput) return;
    var incoming = Array.prototype.slice.call(imagesInput.files || []);
    if(!incoming.length) return;
    var seen = {};
    storedFiles.forEach(function(f){ seen[fileKey(f)] = true; });
    var chain = Promise.resolve();
    incoming.forEach(function(f){
      chain = chain.then(function(){
        var key = fileKey(f);
        if(seen[key]){
          return null;
        }
        seen[key] = true;
        return optimizeImage(f).then(function(optFile){
          storedFiles.push(optFile || f);
        });
      });
    });
    chain.then(function(){
      syncInputFiles();
      updateImagePreview();
    });
  }

  // Events
  $all('input[name="arm_tipo_maquinaria"]').forEach(function(r){ r.addEventListener('change', function(){ toggleByTipo(); refreshSummary(); }); });
  $all('input[name="arm_tipo_tractor"], input[name="arm_tipo_implemento"], input[name="arm_marca_tractor"], input[name="arm_marca_implemento"]').forEach(function(r){ r.addEventListener('change', refreshSummary); });

  $all('#arm_cliente,#arm_fecha_recepcion,#arm_modelo,#arm_serie,#arm_patente,#arm_horas').forEach(function(el){
    el.addEventListener('input', refreshSummary);
    el.addEventListener('blur', refreshSummary);
  });
  var patente = $('#arm_patente');
  if(patente){ patente.addEventListener('input', function(){ patente.value = patente.value.toUpperCase(); }); patente.addEventListener('blur', upperPatente); }

  if(imagesInput){ imagesInput.addEventListener('change', mergeSelectedFiles); }

  // Checklist chips
  $all('.arm-chip input').forEach(function(i){
    i.addEventListener('change', updateChipStates);
    i.addEventListener('change', refreshSummary);
  });
  var btnAll = $('[data-action="check-all"]');
  var btnNone = $('[data-action="check-none"]');
  if(btnAll){ btnAll.addEventListener('click', function(){ $all('.arm-chip input[type="checkbox"]').forEach(function(i){ i.checked = true; }); updateChipStates(); }); }
  if(btnNone){ btnNone.addEventListener('click', function(){ $all('.arm-chip input[type="checkbox"]').forEach(function(i){ i.checked = false; }); updateChipStates(); }); }

  if(btnPrev){ btnPrev.addEventListener('click', function(){ showStep(step-1); }); }
  if(btnNext){ btnNext.addEventListener('click', function(){
    toggleByTipo();
    trimAll();
    if(validateStep(step)) showStep(step+1);
  }); }

  form.addEventListener('submit', function(e){
    toggleByTipo();
    trimAll();
    upperPatente();
    syncInputFiles();
    if(!validateStep(1) || !validateStep(2) || !validateStep(3)){
      e.preventDefault();
      // Lleva a primer paso inválido
      if(!validateStep(1)) showStep(1);
      else if(!validateStep(2)) showStep(2);
      else showStep(3);
      return;
    }
    if(btnSend){
      btnSend.disabled = true;
      btnSend.textContent = 'Enviando…';
    }
  });

  // Init
  setToday();
  toggleByTipo();
  updateChipStates();
  refreshSummary();
  showStep(1);
})();
</script>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    private function render_form(string $msg = ''): string {
        ob_start();
        ?>
        <div class="arm-wrap">
            <?php echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <p class="arm-hint">Campos obligatorios marcados con <span class="arm-required">*</span>.</p>

            <form id="arm-form" class="arm-form" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('arm_submit', 'arm_nonce'); ?>
                <input type="hidden" name="arm_form_submitted" value="1">

                <div class="arm-stepper" aria-label="Progreso">
                    <div class="arm-stepper__item is-active" data-step="1"><span class="arm-stepper__dot">1</span> Cliente</div>
                    <div class="arm-stepper__item" data-step="2"><span class="arm-stepper__dot">2</span> Equipo</div>
                    <div class="arm-stepper__item" data-step="3"><span class="arm-stepper__dot">3</span> Detalle</div>
                </div>

                <!-- Paso 1 -->
                <section class="arm-step is-active" data-step="1">
                    <h2>Cliente</h2>
                    <p class="arm-help">Completa los datos básicos del cliente y la fecha de recepción.</p>
                    <div class="arm-grid">
                        <div class="arm-field">
                            <label for="arm_fecha_recepcion"><strong>Fecha de recepción</strong><span class="arm-required">*</span></label>
                            <input id="arm_fecha_recepcion" class="arm-input" type="date" name="arm_fecha_recepcion" required>
                        </div>
                        <div class="arm-field">
                            <label for="arm_cliente"><strong>Cliente</strong><span class="arm-required">*</span></label>
                            <input id="arm_cliente" class="arm-input" type="text" name="arm_cliente" required autocomplete="off" autocapitalize="words">
                        </div>
                        <div class="arm-field">
                            <label for="arm_correo_cliente"><strong>Correo cliente</strong></label>
                            <input id="arm_correo_cliente" class="arm-input" type="email" name="arm_correo_cliente" placeholder="cliente@dominio.cl" inputmode="email" autocomplete="off">
                        </div>
                    </div>
                </section>

                <!-- Paso 2 -->
                <section class="arm-step" data-step="2">
                    <h2>Equipo</h2>
                    <p class="arm-help">Selecciona tipo y marca para mostrar los campos correspondientes.</p>

                    <div class="arm-grid">
                        <div class="arm-field">
                            <label><strong>Tipo de maquinaria</strong><span class="arm-required">*</span></label>
                            <div class="arm-inline">
                                <label class="arm-chip"><input type="radio" name="arm_tipo_maquinaria" value="Tractor" required> Tractor</label>
                                <label class="arm-chip"><input type="radio" name="arm_tipo_maquinaria" value="Implemento" required> Implemento</label>
                            </div>
                        </div>

                        <div class="arm-field arm-only-tractor" style="display:none;">
                            <label><strong>Tipo de tractor</strong><span class="arm-required">*</span></label>
                            <div class="arm-inline">
                                <label class="arm-chip"><input type="radio" name="arm_tipo_tractor" value="Frutero"> Frutero</label>
                                <label class="arm-chip"><input type="radio" name="arm_tipo_tractor" value="Agrícola"> Agrícola</label>
                                <label class="arm-chip"><input type="radio" name="arm_tipo_tractor" value="Frances"> Francés</label>
                            </div>
                        </div>

                        <div class="arm-field arm-only-implemento" style="display:none;">
                            <label><strong>Tipo de implemento</strong><span class="arm-required">*</span></label>
                            <div class="arm-inline">
                                <label class="arm-chip"><input type="radio" name="arm_tipo_implemento" value="Segadora"> Segadora</label>
                                <label class="arm-chip"><input type="radio" name="arm_tipo_implemento" value="Trituradora"> Trituradora</label>
                                <label class="arm-chip"><input type="radio" name="arm_tipo_implemento" value="Pulverizador"> Pulverizador</label>
                            </div>
                        </div>

                        <div class="arm-field arm-only-tractor" style="display:none;">
                            <label><strong>Marca (tractor)</strong><span class="arm-required">*</span></label>
                            <div class="arm-inline arm-wrap-line">
                                <label class="arm-chip"><input type="radio" name="arm_marca_tractor" value="Massey Ferguson"> Massey Ferguson</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_tractor" value="Valtra"> Valtra</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_tractor" value="Farmtrac"> Farmtrac</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_tractor" value="Lovol"> Lovol</label>
                            </div>
                        </div>

                        <div class="arm-field arm-only-implemento" style="display:none;">
                            <label><strong>Marca (implemento)</strong><span class="arm-required">*</span></label>
                            <div class="arm-inline arm-wrap-line">
                                <label class="arm-chip"><input type="radio" name="arm_marca_implemento" value="Jacto"> Jacto</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_implemento" value="Kverneland"> Kverneland</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_implemento" value="Orsi"> Orsi</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_implemento" value="Piccin"> Piccin</label>
                                <label class="arm-chip"><input type="radio" name="arm_marca_implemento" value="Irtec"> Irtec</label>
                            </div>
                        </div>

                        <div class="arm-field">
                            <label for="arm_modelo"><strong>Modelo</strong></label>
                            <input id="arm_modelo" class="arm-input" type="text" name="arm_modelo" autocomplete="off" autocapitalize="characters">
                        </div>

                        <div class="arm-field">
                            <label for="arm_interno"><strong>Interno</strong></label>
                            <input id="arm_interno" class="arm-input" type="text" name="arm_interno" autocomplete="off" autocapitalize="characters">
                        </div>

                        <div class="arm-field">
                            <label for="arm_serie"><strong>Serie</strong></label>
                            <input id="arm_serie" class="arm-input" type="text" name="arm_serie" autocomplete="off" autocapitalize="characters">
                        </div>

                        <div class="arm-field">
                            <label for="arm_patente"><strong>Patente</strong></label>
                            <input id="arm_patente" class="arm-input" type="text" name="arm_patente" autocomplete="off" autocapitalize="characters" placeholder="ABCD12">
                            <div class="arm-hint">Se guarda en mayúsculas automáticamente.</div>
                        </div>

                        <div class="arm-field">
                            <label for="arm_horas"><strong>Horas</strong></label>
                            <input id="arm_horas" class="arm-input" type="number" name="arm_horas" inputmode="numeric" min="0" step="1" placeholder="0">
                        </div>
                    </div>
                </section>

                <!-- Paso 3 -->
                <section class="arm-step" data-step="3">
                    <h2>Detalle</h2>
                    <p class="arm-help">Describe la falla, marca el checklist y agrega observaciones relevantes.</p>

                    <div class="arm-section is-soft">
                        <h3>Falla</h3>
                        <div class="arm-field">
                            <textarea class="arm-textarea" name="arm_falla" rows="3" placeholder="Síntoma + cuándo ocurre + cualquier detalle útil"></textarea>
                        </div>
                    </div>

                    <div class="arm-section is-soft">
                        <h3>Checklist</h3>
                        <div class="arm-inline" style="margin-bottom:10px;">
                            <button type="button" class="arm-btn" data-action="check-all">Marcar todo</button>
                            <button type="button" class="arm-btn" data-action="check-none">Limpiar</button>
                        </div>
                        <div class="arm-checkgrid">
                            <?php
                            $checks = [
                                'TAPA TDF',
                                'VARILLA AC',
                                'ANTIVUELCO',
                                'ESPEJOS',
                                'VIDRIOS',
                                'FOCOS',
                                'TUERCA RUEDA',
                                'NIVEL COMBUSTIBLE',
                            ];
                            foreach ($checks as $c): ?>
                                <label class="arm-chip"><input type="checkbox" name="arm_checklist[]" value="<?php echo esc_attr($c); ?>"> <?php echo esc_html($c); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="arm-section is-soft">
                        <h3>Otros</h3>
                        <div class="arm-field">
                            <textarea class="arm-textarea" name="arm_otros" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="arm-section is-soft">
                        <h3>Estado</h3>

                        <div class="arm-grid">
                            <div class="arm-field">
                                <label><strong>Llaves</strong></label>
                                <div class="arm-inline">
                                    <label class="arm-chip"><input type="radio" name="arm_llaves" value="SI"> SI</label>
                                    <label class="arm-chip"><input type="radio" name="arm_llaves" value="NO"> NO</label>
                                </div>
                            </div>

                            <div class="arm-field">
                                <label><strong>Nivel de combustible</strong></label>
                                <div class="arm-inline">
                                    <label class="arm-chip"><input type="radio" name="arm_nivel_combustible" value="Mínimo"> Mínimo</label>
                                    <label class="arm-chip"><input type="radio" name="arm_nivel_combustible" value="Medio"> Medio</label>
                                    <label class="arm-chip"><input type="radio" name="arm_nivel_combustible" value="Lleno"> Lleno</label>
                                </div>
                            </div>
                        </div>

                        <div class="arm-field">
                            <label><strong>Observaciones</strong></label>
                            <textarea class="arm-textarea" name="arm_observaciones" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="arm-section is-soft">
                        <h3>Imágenes (recomendado 3 • máx. 10)</h3>
                        <div class="arm-file">
                            <div class="arm-field">
                                <input id="arm_images" class="arm-input" type="file" name="arm_imagenes[]" accept="image/*" multiple>
                                <div class="arm-sub" style="margin-top:6px;">Sugerencia: 1 general + 1 placa/serie + 1 falla. Máx. 10 fotos.</div>
                                <div class="arm-sub">Si una imagen es muy pesada, se ajusta el tamaño máximo sin afectar la nitidez.</div>
                                <div id="arm_img_hint" class="arm-sub" style="margin-top:4px;"></div>
                            </div>
                            <div id="arm_preview" class="arm-preview"></div>
                        </div>
                    </div>

                    <div class="arm-section is-soft">
                        <div id="arm_summary" class="arm-summary">
                            <h4>Resumen</h4>
                            <dl>
                                <dt>Cliente</dt><dd id="s_cliente">—</dd>
                                <dt>Fecha</dt><dd id="s_fecha">—</dd>
                                <dt>Equipo</dt><dd id="s_equipo">—</dd>
                                <dt>Marca</dt><dd id="s_marca">—</dd>
                                <dt>Modelo</dt><dd id="s_modelo">—</dd>
                                <dt>Serie</dt><dd id="s_serie">—</dd>
                                <dt>Patente</dt><dd id="s_patente">—</dd>
                                <dt>Horas</dt><dd id="s_horas">—</dd>
                            </dl>
                        </div>
                    </div>
                </section>
            </form>

            <div class="arm-footer" role="navigation" aria-label="Navegación formulario">
                <div class="arm-footer__inner">
                    <button type="button" class="arm-btn" data-action="prev">Atrás</button>
                    <div id="arm_step_label" class="arm-sub">Paso 1 de 3</div>
                    <div class="arm-spacer"></div>
                    <button type="button" class="arm-btn arm-btn--primary" data-action="next">Siguiente</button>
                    <button type="submit" form="arm-form" class="arm-btn arm-btn--primary arm-hidden" data-action="submit">Enviar</button>
                </div>
            </div>
        </div>
	        <?php
        return (string) ob_get_clean();
    }

    public function handle_post(): void {

        if (empty($_POST['arm_form_submitted'])) return;

        if (!isset($_POST['arm_nonce']) || !wp_verify_nonce($_POST['arm_nonce'], 'arm_submit')) {
            $this->redirect_err('Nonce inválido');
        }

        $tipo = ARM_Utils::sanitize_text($_POST['arm_tipo_maquinaria'] ?? '');
        if (!in_array($tipo, ['Tractor','Implemento'], true)) {
            $this->redirect_err('Tipo inválido');
        }

        $meta = [];
        $meta['arm_tipo_maquinaria'] = $tipo;
        $meta['arm_tipo_tractor'] = ($tipo === 'Tractor') ? ARM_Utils::sanitize_text($_POST['arm_tipo_tractor'] ?? '') : '';
        $meta['arm_tipo_implemento'] = ($tipo === 'Implemento') ? ARM_Utils::sanitize_text($_POST['arm_tipo_implemento'] ?? '') : '';
        $meta['arm_fecha_recepcion'] = ARM_Utils::sanitize_date($_POST['arm_fecha_recepcion'] ?? '');
        $meta['arm_cliente'] = ARM_Utils::sanitize_text($_POST['arm_cliente'] ?? '');
        $meta['arm_correo_cliente'] = ARM_Utils::sanitize_email($_POST['arm_correo_cliente'] ?? '');
        $meta['arm_marca_tractor'] = ($tipo === 'Tractor') ? ARM_Utils::sanitize_text($_POST['arm_marca_tractor'] ?? '') : '';
        $meta['arm_marca_implemento'] = ($tipo === 'Implemento') ? ARM_Utils::sanitize_text($_POST['arm_marca_implemento'] ?? '') : '';
        $meta['arm_modelo'] = ARM_Utils::sanitize_text($_POST['arm_modelo'] ?? '');
        $meta['arm_interno'] = ARM_Utils::sanitize_text($_POST['arm_interno'] ?? '');
        $meta['arm_serie'] = ARM_Utils::sanitize_text($_POST['arm_serie'] ?? '');
        $meta['arm_patente'] = ARM_Utils::sanitize_text($_POST['arm_patente'] ?? '');
        $meta['arm_horas'] = ARM_Utils::sanitize_text($_POST['arm_horas'] ?? '');
        $meta['arm_falla'] = ARM_Utils::sanitize_textarea($_POST['arm_falla'] ?? '');
        $meta['arm_otros'] = ARM_Utils::sanitize_textarea($_POST['arm_otros'] ?? '');
        $meta['arm_llaves'] = ARM_Utils::sanitize_text($_POST['arm_llaves'] ?? '');
        $meta['arm_nivel_combustible'] = ARM_Utils::sanitize_text($_POST['arm_nivel_combustible'] ?? '');
        $meta['arm_observaciones'] = ARM_Utils::sanitize_textarea($_POST['arm_observaciones'] ?? '');

        $checklist = $_POST['arm_checklist'] ?? [];
        if (!is_array($checklist)) $checklist = [];
        $checklist = array_values(array_unique(array_map('sanitize_text_field', $checklist)));

        // Insert post
        $title = 'Recepción #' . date_i18n('Ymd-His') . ' - ' . $meta['arm_cliente'];
        $post_id = wp_insert_post([
            'post_type' => ARM_CPT::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);

        if (is_wp_error($post_id)) {
            $this->redirect_err('No se pudo guardar');
        }

        foreach ($meta as $k => $v) {
            update_post_meta($post_id, $k, $v);
        }
        update_post_meta($post_id, 'arm_checklist', $checklist);

        // Upload images (máx. 10) solo para adjuntar en correo
        $image_paths = [];
        $image_count = 0;
        $image_bytes = 0;
        $image_limit = 10 * 1024 * 1024;

        // Nuevo input múltiple: arm_imagenes[]
        if (!empty($_FILES['arm_imagenes']) && is_array($_FILES['arm_imagenes']['name'] ?? null)) {
            $names = (array) $_FILES['arm_imagenes']['name'];
            $types = (array) $_FILES['arm_imagenes']['type'];
            $tmps  = (array) $_FILES['arm_imagenes']['tmp_name'];
            $errs  = (array) $_FILES['arm_imagenes']['error'];
            $sizes = (array) $_FILES['arm_imagenes']['size'];

            $max = min(10, count($names));
            for ($i = 0; $i < $max; $i++) {
                if (empty($names[$i])) {
                    continue;
                }
                $file = [
                    'name'     => $names[$i],
                    'type'     => $types[$i] ?? '',
                    'tmp_name' => $tmps[$i] ?? '',
                    'error'    => $errs[$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $sizes[$i] ?? 0,
                ];
                $path = $this->handle_image_upload_temp($file);
                if ($path) {
                    $size = (int) @filesize($path);
                    if ($size > 0 && ($image_bytes + $size) <= $image_limit) {
                        $image_paths[] = $path;
                        $image_count++;
                        $image_bytes += $size;
                    } else {
                        $this->cleanup_temp_files([$path]);
                    }
                }
            }
        } else {
            // Compat: inputs individuales (si existieran)
            $image_fields = ['arm_imagen_1','arm_imagen_2','arm_imagen_3'];
            foreach ($image_fields as $f) {
                if (empty($_FILES[$f]) || empty($_FILES[$f]['name'])) continue;
                $path = $this->handle_image_upload_temp($_FILES[$f]);
                if ($path) {
                    $size = (int) @filesize($path);
                    if ($size > 0 && ($image_bytes + $size) <= $image_limit) {
                        $image_paths[] = $path;
                        $image_count++;
                        $image_bytes += $size;
                    } else {
                        $this->cleanup_temp_files([$path]);
                    }
                }
            }
        }

        // Generate PDF
        $pdf = ARM_PDF::generate((int)$post_id);
        update_post_meta($post_id, 'arm_pdf_url', $pdf['url']);
        update_post_meta($post_id, 'arm_pdf_path', $pdf['path']);
        update_post_meta($post_id, 'arm_email_image_paths', $image_paths);
        update_post_meta($post_id, 'arm_email_image_count', $image_count);

        // Email (async)
        $queued = wp_schedule_single_event(time() + 5, 'arm_send_email_async', [(int) $post_id]);
        if ($queued) {
            update_post_meta($post_id, 'arm_email_status', 'queued');
        } else {
            $mail_res = ARM_Email::send((int)$post_id, $pdf['path'], $pdf['url'], $image_paths, $image_count);
            update_post_meta($post_id, 'arm_email_status', (string) ($mail_res['status'] ?? 'unknown'));
            $this->cleanup_temp_files($image_paths);
        }

        // Redirect success
        $url = add_query_arg('arm_ok', (string) $post_id, $this->frontend_url());
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Guarda una imagen temporal para adjuntar en correo (sin crear adjunto en WP).
     */
    private function handle_image_upload_temp(array $file): string {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return '';
        }

        $overrides = ['test_form' => false];
        $uploaded = wp_handle_upload($file, $overrides);

        if (isset($uploaded['error'])) {
            return '';
        }

        return (string) ($uploaded['file'] ?? '');
    }

    private function cleanup_temp_files(array $paths): void {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function send_email_async(int $post_id): void {
        $pdf_path = (string) get_post_meta($post_id, 'arm_pdf_path', true);
        $pdf_url = (string) get_post_meta($post_id, 'arm_pdf_url', true);
        $image_paths = get_post_meta($post_id, 'arm_email_image_paths', true);
        $image_count = (int) get_post_meta($post_id, 'arm_email_image_count', true);
        if (!is_array($image_paths)) {
            $image_paths = [];
        }

        $mail_res = ARM_Email::send((int)$post_id, $pdf_path, $pdf_url, $image_paths, $image_count);
        update_post_meta($post_id, 'arm_email_status', (string) ($mail_res['status'] ?? 'unknown'));
        $this->cleanup_temp_files($image_paths);
        delete_post_meta($post_id, 'arm_email_image_paths');
        delete_post_meta($post_id, 'arm_email_image_count');
    }

    private function redirect_err(string $msg): void {
        $url = add_query_arg('arm_err', rawurlencode($msg), wp_get_referer() ?: $this->frontend_url());
        wp_safe_redirect($url);
        exit;
    }
}
