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

    $all('input[name="arm_tipo_tractor"]').forEach(function(i){ i.required = (tipo==='Tractor'); });
    $all('input[name="arm_tipo_implemento"]').forEach(function(i){ i.required = (tipo==='Implemento'); });
    $all('input[name="arm_marca_tractor"]').forEach(function(i){ i.required = (tipo==='Tractor'); });
    $all('input[name="arm_marca_implemento"]').forEach(function(i){ i.required = (tipo==='Implemento'); });

    toggleMarcaImplementoOtra();
  }

  function toggleMarcaImplementoOtra(){
    var tipo = ( $('input[name="arm_tipo_maquinaria"]:checked') || {} ).value || '';
    var marca = ( $('input[name="arm_marca_implemento"]:checked') || {} ).value || '';
    var isOtra = (tipo === 'Implemento' && marca === '__other__');
    var wrap = $('#arm_marca_implemento_otra_wrap');
    var input = $('#arm_marca_implemento_otra');

    if(wrap){
      wrap.style.display = isOtra ? '' : 'none';
    }
    if(input){
      input.required = isOtra;
      if(!isOtra){
        input.value = '';
      }
    }
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
    if(tipo==='Tractor') {
      marca = ( $('input[name="arm_marca_tractor"]:checked') || {} ).value || '';
    }
    if(tipo==='Implemento') {
      var marcaImplemento = ( $('input[name="arm_marca_implemento"]:checked') || {} ).value || '';
      if(marcaImplemento === '__other__') {
        marca = val('arm_marca_implemento_otra');
      } else {
        marca = marcaImplemento;
      }
    }

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
  function fileKey(file){ return [file.name, file.size, file.lastModified].join('|'); }

  function optimizeImage(file){
    return new Promise(function(resolve){
      if(!file || !file.type || file.type.indexOf('image/') !== 0){
        resolve(file);
        return;
      }
      var maxDim = 1000;
      var maxSize = 600 * 1024;
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
        }, 'image/jpeg', 0.72);
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

  $all('input[name="arm_tipo_maquinaria"]').forEach(function(r){
    r.addEventListener('change', function(){ toggleByTipo(); refreshSummary(); });
  });
  $all('input[name="arm_tipo_tractor"], input[name="arm_tipo_implemento"], input[name="arm_marca_tractor"], input[name="arm_marca_implemento"]').forEach(function(r){
    r.addEventListener('change', function(){ toggleMarcaImplementoOtra(); refreshSummary(); });
  });

  $all('#arm_cliente,#arm_fecha_recepcion,#arm_modelo,#arm_serie,#arm_patente,#arm_horas,#arm_marca_implemento_otra').forEach(function(el){
    if(!el) return;
    el.addEventListener('input', refreshSummary);
    el.addEventListener('blur', refreshSummary);
  });

  var patente = $('#arm_patente');
  if(patente){
    patente.addEventListener('input', function(){ patente.value = patente.value.toUpperCase(); });
    patente.addEventListener('blur', upperPatente);
  }

  if(imagesInput){ imagesInput.addEventListener('change', mergeSelectedFiles); }

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

  setToday();
  toggleByTipo();
  toggleMarcaImplementoOtra();
  updateChipStates();
  refreshSummary();
  showStep(1);
})();
