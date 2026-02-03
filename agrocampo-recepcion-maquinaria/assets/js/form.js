jQuery(function($){
  function toggle(){
    var tipo = $('input[name="arm_tipo_maquinaria"]:checked').val();
    if(tipo === 'Tractor'){
      $('.arm-only-tractor').show().find('input').prop('disabled', false);
      $('.arm-only-implemento').hide().find('input').prop('disabled', true).prop('checked', false);
    } else if(tipo === 'Implemento'){
      $('.arm-only-implemento').show().find('input').prop('disabled', false);
      $('.arm-only-tractor').hide().find('input').prop('disabled', true).prop('checked', false);
    } else {
      $('.arm-only-tractor,.arm-only-implemento').hide().find('input').prop('disabled', true).prop('checked', false);
    }
  }
  $(document).on('change', 'input[name="arm_tipo_maquinaria"]', toggle);
  toggle();
});
