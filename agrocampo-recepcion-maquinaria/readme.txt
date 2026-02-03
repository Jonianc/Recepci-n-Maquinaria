=== Agrocampo - Recepción de Maquinaria ===
Contributors: agrocampo
Tags: pdf, fpdf, formulario, recepcion, maquinaria
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.6

== Descripción ==
Formulario de recepción de maquinaria (Agrocampo) que genera PDF (FPDF) y lo envía por correo.

== Uso ==
1) Instalar y activar el plugin.
2) Ir a Ajustes > Recepción Maquinaria y configurar logo/correos y la ruta del formulario.
3) Abrir la ruta standalone configurada (por defecto: /recepcion-maquinaria/).

== Notas ==
- Los envíos se guardan como CPT "Recepciones" en el admin.
- El PDF se guarda en /wp-content/uploads/agrocampo-recepcion-maquinaria/
- Si tu hosting tiene mail() deshabilitado, configura SMTP (ej. WP Mail SMTP).
- El plugin carga FPDF solo si no existe ya en el sitio (evita conflictos).
- Se omiten binarios del tutorial de FPDF (fuentes/imágenes) para compatibilidad con repositorios que no aceptan archivos binarios.
