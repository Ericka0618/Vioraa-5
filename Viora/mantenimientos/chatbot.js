(function () {
  const raiz = document.getElementById('cb-widget');
  if (!raiz) {
    return;
  }

  const apiUrl = raiz.dataset.api;
  const ventana = document.getElementById('cb-ventana');
  const contenedor = document.getElementById('cb-mensajes');
  const formulario = document.getElementById('cb-formulario');
  const entrada = document.getElementById('cb-entrada');
  const botonEnviar = document.getElementById('cb-enviar');
  const botonAbrir = document.getElementById('cb-abrir');
  const botonCerrar = document.getElementById('cb-cerrar');
  const botonLimpiar = document.getElementById('cb-limpiar');

  const CLAVE_ABIERTO = 'viora_chat_abierto';
  const saludoInicial = raiz.dataset.saludo || 'Hola, soy el asistente de VIORA. Puedo explicarle cómo usar el sistema, '
    + 'dónde encontrar cada módulo o resolver dudas sobre la página en que está. ¿En qué puedo ayudarle?';

  let historial = [];
  let ocupado = false;

  function leerAlmacen(clave) {
    try {
      return sessionStorage.getItem(clave);
    } catch (e) {
      return null;
    }
  }

  function escribirAlmacen(clave, valor) {
    try {
      sessionStorage.setItem(clave, valor);
    } catch (e) { /* almacenamiento no disponible */ }
  }

  function guardarHistorial() {
    // El historial pertenece al usuario en MySQL; no conservar copias compartidas en el navegador.
    try { sessionStorage.removeItem('viora_chat_historial'); } catch (e) { /* sin almacenamiento */ }
  }

  function agregarMensaje(rol, texto) {
    const burbuja = document.createElement('div');
    burbuja.className = 'cb__mensaje cb__mensaje--' + (rol === 'user' ? 'usuario' : 'asistente');
    burbuja.textContent = texto;
    contenedor.appendChild(burbuja);
    contenedor.scrollTop = contenedor.scrollHeight;
    return burbuja;
  }

  function pintarHistorial() {
    contenedor.innerHTML = '';
    agregarMensaje('assistant', saludoInicial);
    historial.forEach(function (item) {
      agregarMensaje(item.rol, item.texto);
    });
  }

  function abrir() {
    ventana.hidden = false;
    botonAbrir.classList.add('cb__burbuja--oculta');
    escribirAlmacen(CLAVE_ABIERTO, '1');
    contenedor.scrollTop = contenedor.scrollHeight;
    entrada.focus();
  }

  function cerrar() {
    ventana.hidden = true;
    botonAbrir.classList.remove('cb__burbuja--oculta');
    escribirAlmacen(CLAVE_ABIERTO, '0');
    botonAbrir.focus();
  }

  function establecerCargando(cargando) {
    ocupado = cargando;
    botonEnviar.disabled = cargando;
    entrada.disabled = cargando;
    if (!cargando) {
      entrada.focus();
    }
  }

  async function enviarMensaje(mensaje) {
    agregarMensaje('user', mensaje);
    const burbujaRespuesta = agregarMensaje('assistant', 'Escribiendo…');
    burbujaRespuesta.classList.add('cb__mensaje--pendiente');

    establecerCargando(true);
    try {
      const respuesta = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': raiz.dataset.csrf },
        body: JSON.stringify({
          mensaje: mensaje,
          pagina: { titulo: document.title, ruta: window.location.pathname }
        })
      });
      const datos = await respuesta.json().catch(function () { return {}; });
      if (!respuesta.ok) {
        throw new Error(datos.error || 'No se pudo obtener respuesta del asistente.');
      }
      burbujaRespuesta.textContent = datos.respuesta;
      burbujaRespuesta.classList.remove('cb__mensaje--pendiente');
      historial.push({ rol: 'user', texto: mensaje });
      historial.push({ rol: 'assistant', texto: datos.respuesta });
      guardarHistorial();
    } catch (error) {
      burbujaRespuesta.textContent = error.message;
      burbujaRespuesta.classList.remove('cb__mensaje--pendiente');
      burbujaRespuesta.classList.add('cb__mensaje--error');
    } finally {
      establecerCargando(false);
      contenedor.scrollTop = contenedor.scrollHeight;
    }
  }

  formulario.addEventListener('submit', function (evento) {
    evento.preventDefault();
    const mensaje = entrada.value.trim();
    if (mensaje === '' || ocupado) {
      return;
    }
    entrada.value = '';
    enviarMensaje(mensaje);
  });

  entrada.addEventListener('keydown', function (evento) {
    if (evento.key === 'Enter' && !evento.shiftKey) {
      evento.preventDefault();
      formulario.requestSubmit();
    }
  });

  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape' && !ventana.hidden) {
      cerrar();
    }
  });

  botonAbrir.addEventListener('click', abrir);
  botonCerrar.addEventListener('click', cerrar);
  botonLimpiar.addEventListener('click', async function () {
    if (ocupado) {
      return;
    }
    establecerCargando(true);
    try {
      const respuesta = await fetch(apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': raiz.dataset.csrf}, body: JSON.stringify({accion: 'nueva'})});
      if (!respuesta.ok) throw new Error('No se pudo iniciar una conversación.');
    } catch (e) { agregarMensaje('assistant', e.message); return; }
    finally { establecerCargando(false); }
    historial = [];
    guardarHistorial();
    pintarHistorial();
  });

  establecerCargando(true);
  fetch(apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': raiz.dataset.csrf}, body: JSON.stringify({accion: 'historial'})})
    .then(async function (r) {
      const datos = await r.json().catch(function () { return {}; });
      if (!r.ok) throw new Error(datos.error || 'No se pudo recuperar la conversación.');
      return datos;
    })
    .then(function (datos) { historial = datos.historial || []; guardarHistorial(); pintarHistorial(); })
    .catch(function (e) { historial = []; guardarHistorial(); pintarHistorial(); agregarMensaje('assistant', e.message); })
    .finally(function () { establecerCargando(false); });


  pintarHistorial();
  if (leerAlmacen(CLAVE_ABIERTO) === '1') {
    abrir();
  }
})();
