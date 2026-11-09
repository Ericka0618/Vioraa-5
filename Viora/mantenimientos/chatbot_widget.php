<?php
// mantenimientos/chatbot_widget.php
// Burbuja flotante del asistente IA. Se incluye desde menu_lateral.php,
// por lo que aparece en todas las páginas con sesión iniciada.
?>
<link rel="stylesheet" href="../estilos/chatbot.css?v=2">

<div class="cb" id="cb-widget" data-api="../mantenimientos/chatbot_api.php"
     <?php if (!empty($esCliente)): ?>data-saludo="¡Hola! Soy el asistente de VIORA y puedo ayudarle a encontrar su propiedad ideal. Cuénteme qué busca (por ejemplo: «2 habitaciones», «más de 1 baño» o «alquiler hasta RD$ 20,000») o escriba «recomiéndame una propiedad» y le hago unas preguntas. También puedo agendarle una visita o registrar su solicitud para rentar o comprar."<?php endif; ?>>
  <section class="cb__ventana" id="cb-ventana" aria-label="Asistente IA de VIORA" hidden>
    <header class="cb__cabecera">
      <div>
        <strong>Asistente VIORA</strong>
        <span>Gemini Flash-Lite</span>
      </div>
      <div class="cb__acciones">
        <button type="button" id="cb-limpiar" title="Nueva conversación" aria-label="Nueva conversación">↺</button>
        <button type="button" id="cb-cerrar" title="Cerrar" aria-label="Cerrar">✕</button>
      </div>
    </header>

    <div class="cb__mensajes" id="cb-mensajes" aria-live="polite"></div>

    <form class="cb__formulario" id="cb-formulario" autocomplete="off">
      <textarea id="cb-entrada" rows="1" maxlength="2000"
                placeholder="Escriba su pregunta…" aria-label="Mensaje"></textarea>
      <button type="submit" id="cb-enviar" aria-label="Enviar">➤</button>
    </form>
  </section>

  <button type="button" class="cb__burbuja" id="cb-abrir" aria-label="Abrir asistente IA" title="Asistente IA">
    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true">
      <path d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
    </svg>
  </button>
</div>

<script src="../mantenimientos/chatbot.js?v=3" defer></script>
