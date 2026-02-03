jQuery(function($){
  var frame;
  $('#arm_logo_pick').on('click', function(e){
    e.preventDefault();
    if(frame){ frame.open(); return; }
    frame = wp.media({
      title: 'Seleccionar logo',
      button: { text: 'Usar este logo' },
      multiple: false
    });
    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      $('#arm_logo_id').val(att.id);
      $('#arm_logo_preview').attr('src', att.url).show();
    });
    frame.open();
  });

  $('#arm_logo_clear').on('click', function(e){
    e.preventDefault();
    $('#arm_logo_id').val('');
    $('#arm_logo_preview').attr('src','').hide();
  });
});
