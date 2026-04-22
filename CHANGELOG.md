# Changelog

## 1.2.0
- Agrega opción "Otra" en "Marca (implemento)" con campo manual obligatorio cuando aplica.
- Permite configurar "Tipo de implemento" desde Ajustes con textarea (una opción por línea) y fallback por defecto.
- Unifica la lógica JS del formulario en `assets/js/form.js` y la referencia desde la vista standalone.
- Mantiene compatibilidad de resumen, validación, guardado, PDF, correos y listado admin usando los metadatos existentes.

## 1.1.7
- Hace opcional el campo "Correo cliente" en el formulario de recepción.
- Si se ingresa correo de cliente, valida formato y muestra error si es inválido.
- Mantiene envío al cliente solo cuando hay correo válido y la opción está habilitada.

## 1.1.6
- Elimina la navegación por pestañas en Ajustes y muestra General, Correos, PDF y Ayuda en una sola pantalla.
- Mantiene un único botón “Guardar cambios” para todo el formulario.
- Registra el slug frontend como ajuste para evitar sobrescrituras entre secciones.
- Asegura persistencia correcta de "Enviar al cliente" cuando el checkbox está desmarcado.

## 1.1.5
- Corrige la carga de la Librería de Medios en Ajustes bajo Recepciones.

## 1.1.4
- Corrige navegación de pestañas en ajustes bajo Recepciones.

## 1.1.3
- Unifica el menú: la configuración queda bajo Recepciones como submenú.

## 1.1.2
- Ajusta el menú del admin para evitar submenús duplicados.

## 1.1.1
- Reorganiza el admin con pestañas y menú principal para mejorar jerarquía y navegación.
