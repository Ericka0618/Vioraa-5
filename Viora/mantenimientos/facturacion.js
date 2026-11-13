
/*
==================================================
 VIORA - SISTEMA DE FACTURACIÓN
 Administración de condominios
==================================================
*/

'use strict';

// ================================================
// 1. VARIABLES GENERALES
// ================================================

const $ = (id) => document.getElementById(id);

let propiedades = [];
let propiedadActual = null;
let cargando = false;
let guardando = false;
let solicitudPropiedades = 0;

// Formato de moneda dominicana
const dinero = (valor) => {
    return new Intl.NumberFormat('es-DO', {
        style: 'currency',
        currency: 'DOP',
        minimumFractionDigits: 2
    }).format(Number(valor) || 0);
};

// ================================================
// 2. MOSTRAR MENSAJES
// ================================================

function mensaje(texto, tipo = 'error') {
    const caja = $('mensaje');

    if (!caja) return;

    caja.textContent = texto;
    caja.className = 'mensaje ' + tipo;
}

function limpiarMensaje() {
    const caja = $('mensaje');

    if (!caja) return;

    caja.textContent = '';
    caja.className = 'mensaje';
}

// ================================================
// 3. CONSULTAS AL SERVIDOR
// ================================================

async function obtenerJSON(url, opciones = {}) {

    const respuesta = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...opciones
    });

    const texto = await respuesta.text();

    let datos;

    try {
        datos = JSON.parse(texto);
    } catch (error) {
        throw new Error('No se pudo leer la respuesta del servidor. Recargue la página e intente nuevamente.');
    }

    if (!datos || typeof datos !== 'object' || Array.isArray(datos)) {
        throw new Error('El servidor devolvió una respuesta inesperada. Intente nuevamente.');
    }

    if (!respuesta.ok || !datos.success) {
        throw new Error(
            datos.message ||
            'Error del servidor: ' + respuesta.status
        );
    }

    return datos;
}

// ================================================
// 4. CARGAR CLIENTES AUTOMÁTICAMENTE
// ================================================

async function cargarClientes() {

    const select = $('cliente');

    if (!select) {
        console.error(
            'No existe el elemento con id cliente'
        );
        return;
    }

    select.innerHTML =
        '<option value="">Cargando clientes...</option>';

    select.disabled = true;

    limpiarMensaje();

    try {

        const datos = await obtenerJSON(
            'buscar_clientes_factura.php'
        );

        select.innerHTML =
            '<option value="">Seleccionar cliente...</option>';

        const clientes = Array.isArray(datos.clientes)
            ? datos.clientes
            : [];

        if (clientes.length === 0) {

            mensaje(
                'No se encontraron clientes con ' +
                'contratos de alquiler activos.',
                'info'
            );

            select.innerHTML =
                '<option value="">No hay clientes disponibles</option>';

            return;
        }

        clientes.forEach(cliente => {

            const opcion = document.createElement('option');

            opcion.value = cliente.id_personas;

            opcion.textContent =
                cliente.nombre +
                (
                    cliente.cedula
                        ? ' - ' + cliente.cedula
                        : ''
                );

            select.appendChild(opcion);
        });

        console.log(
            'VIORA: clientes cargados correctamente:',
            clientes.length
        );

    } catch (error) {

        console.error(
            'VIORA: error al cargar clientes:',
            error
        );

        select.innerHTML =
            '<option value="">Error al cargar clientes</option>';

        mensaje(
            'No se pudieron cargar los clientes. ' +
            error.message
        );

    } finally {
        select.disabled = false;
    }
}

// ================================================
// 5. LIMPIAR INFORMACIÓN DE LA PROPIEDAD
// ================================================

function limpiarDatosPropiedad() {

    propiedadActual = null;

    const campos = [
        'propiedad',
        'condominio',
        'edificio',
        'apartamento',
        'numero_contrato'
    ];

    campos.forEach(id => {
        if ($(id)) {
            $(id).value = '';
        }
    });

    if ($('estadoPropiedad')) {
        $('estadoPropiedad').hidden = true;
    }

    if ($('montoAlquiler')) {
        $('montoAlquiler').textContent = dinero(0);
    }

    if ($('montoMantenimiento')) {
        $('montoMantenimiento').textContent = dinero(0);
    }

    ['checkAlquiler', 'checkMantenimiento'].forEach(id => {
        if ($(id)) {
            $(id).checked = false;
            $(id).disabled = true;
        }
    });

    actualizarTotal();
}

function limpiarPropiedad() {

    limpiarDatosPropiedad();

    propiedades = [];

    if ($('grupoVariasPropiedades')) {
        $('grupoVariasPropiedades').hidden = true;
    }

    if ($('selectorPropiedades')) {
        $('selectorPropiedades').innerHTML =
            '<option value="">Seleccionar propiedad...</option>';
    }
}

// ================================================
// 6. CARGAR PROPIEDADES DEL CLIENTE
// ================================================

async function cargarPropiedades() {

    const numeroSolicitud = ++solicitudPropiedades;

    limpiarPropiedad();
    limpiarMensaje();

    const clienteId = $('cliente').value;

    if (!clienteId) {
        return;
    }

    cargando = true;
    actualizarTotal();

    try {

        const datos = await obtenerJSON(
            'obtener_propiedades_cliente.php?persona_id=' +
            encodeURIComponent(clienteId)
        );

        // Evitar mostrar resultados de un cliente
        // seleccionado anteriormente.
        if (numeroSolicitud !== solicitudPropiedades) {
            return;
        }

        propiedades = Array.isArray(datos.propiedades)
            ? datos.propiedades
            : [];

        if (propiedades.length === 0) {

            mensaje(
                'Este cliente no tiene propiedades ' +
                'con contratos de alquiler activos.',
                'info'
            );

            return;
        }

        // Si el cliente tiene una sola propiedad,
        // cargarla automáticamente.
        if (propiedades.length === 1) {

            propiedadActual = propiedades[0];

            seleccionarPropiedad();

            console.log(
                'Propiedad cargada automáticamente:',
                propiedadActual
            );

            return;
        }

        // Si tiene varias propiedades,
        // mostrar el selector correspondiente.
        const selector = $('selectorPropiedades');

        selector.innerHTML =
            '<option value="">Seleccionar propiedad...</option>';

        propiedades.forEach(p => {

            const texto =
                p.condominio +
                ' - ' +
                p.edificio +
                ' - Apartamento ' +
                p.apartamento;

            const opcion = new Option(
                texto,
                p.id_contratos
            );

            selector.add(opcion);
        });

        $('grupoVariasPropiedades').hidden = false;

        mensaje(
            'Este cliente tiene varias propiedades. ' +
            'Seleccione cuál desea facturar.',
            'info'
        );

    } catch (error) {

        if (numeroSolicitud !== solicitudPropiedades) {
            return;
        }

        console.error(
            'Error al cargar propiedades:',
            error
        );

        mensaje(error.message);

    } finally {

        if (numeroSolicitud === solicitudPropiedades) {
            cargando = false;
            actualizarTotal();
        }
    }
}

// ================================================
// 7. SELECCIONAR Y MOSTRAR PROPIEDAD
// ================================================

function seleccionarPropiedad() {

    if (!propiedadActual) {
        limpiarDatosPropiedad();
        return;
    }

    limpiarMensaje();

    const p = propiedadActual;

    // Propiedad principal
    $('propiedad').value =
        p.condominio +
        ' - ' +
        p.edificio +
        ' - Apartamento ' +
        p.apartamento;

    // Información de la propiedad
    $('condominio').value = p.condominio || '';
    $('edificio').value = p.edificio || '';
    $('apartamento').value = p.apartamento || '';

    $('numero_contrato').value =
        p.numero_contrato || '';

    // Estado del contrato
    if ($('estadoPropiedad')) {
        $('estadoPropiedad').hidden = false;
    }

    // Obtener montos del contrato
    const alquiler = Number(p.monto_alquiler) || 0;

    const mantenimiento =
        Number(p.monto_mantenimiento) || 0;

    // Mostrar montos
    $('montoAlquiler').textContent =
        dinero(alquiler);

    $('montoMantenimiento').textContent =
        dinero(mantenimiento);

    // Habilitar conceptos con monto válido
    $('checkAlquiler').disabled =
        alquiler <= 0;

    $('checkMantenimiento').disabled =
        mantenimiento <= 0;

    // Seleccionar automáticamente
    $('checkAlquiler').checked =
        alquiler > 0;

    $('checkMantenimiento').checked =
        mantenimiento > 0;

    actualizarTotal();

    console.log(
        'VIORA: propiedad seleccionada:',
        p.numero_contrato
    );
}

// ================================================
// 8. SELECCIÓN DE VARIAS PROPIEDADES
// ================================================

function cambiarPropiedad() {

    const contratoId =
        $('selectorPropiedades').value;

    if (!contratoId) {
        limpiarDatosPropiedad();
        return;
    }

    propiedadActual = propiedades.find(
        p => String(p.id_contratos) === String(contratoId)
    ) || null;

    seleccionarPropiedad();
}

// ================================================
// 9. ACTUALIZAR PERÍODO
// ================================================

function actualizarPeriodo() {

    const periodo = $('periodo').value;

    document.querySelectorAll('.periodo-texto')
        .forEach(elemento => {
            elemento.textContent = periodo || '-';
        });

    actualizarTotal();
}

// ================================================
// 10. CALCULAR TOTAL AUTOMÁTICAMENTE
// ================================================

function actualizarTotal() {

    // ITBIS 18% por concepto en centavos, igual que el servidor (ecf_calcular_totales)
    let totalCentavos = 0;

    if (propiedadActual) {

        const alquiler =
            Number(propiedadActual.monto_alquiler) || 0;

        const mantenimiento =
            Number(propiedadActual.monto_mantenimiento) || 0;

        const conceptos = [];

        if ($('checkAlquiler').checked) {
            conceptos.push(alquiler);
        }

        if ($('checkMantenimiento').checked) {
            conceptos.push(mantenimiento);
        }

        conceptos.forEach((monto) => {
            const centavos = Math.round(monto * 100);
            const itbis = Math.round(centavos * 18 / 100);
            totalCentavos += centavos + itbis;
        });
    }

    $('totalFactura').textContent = dinero(totalCentavos / 100);

    const periodoValido =
        /^\d{4}-(0[1-9]|1[0-2])$/.test(
            $('periodo').value
        );

    $('btnGuardar').disabled =
        !propiedadActual ||
        !periodoValido ||
        totalCentavos <= 0 ||
        cargando ||
        guardando;
}

// ================================================
// 11. GUARDAR FACTURA
// ================================================

async function guardarFactura() {

    if (guardando) return;

    if (!propiedadActual) {
        mensaje('Primero seleccione un cliente y propiedad.');
        return;
    }

    const contratoId = propiedadActual.id_contratos;

    const periodo = $('periodo').value;
    const fecha = $('fecha').value;

    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodo)) {
        mensaje('Seleccione un período válido.');
        return;
    }

    const conceptos = [];

    if ($('checkAlquiler').checked) {
        conceptos.push('Alquiler');
    }

    if ($('checkMantenimiento').checked) {
        conceptos.push('Mantenimiento');
    }

    if (conceptos.length === 0) {
        mensaje('Seleccione al menos un concepto.');
        return;
    }

    const datosFactura = {
        contrato_id: contratoId,
        periodo: periodo,
        fecha: fecha,
        conceptos: conceptos,
        observaciones: $('observaciones').value.trim(),
        rnc_comprador: $('rnc_comprador').value.trim(),
        razon_social_comprador: $('razon_social_comprador').value.trim()
    };

    const confirmar = confirm(
        '¿Desea generar la factura del período ' +
        periodo +
        '?\n\nConceptos: ' +
        conceptos.join(', ')
    );

    if (!confirmar) return;

    // Se abre antes del fetch para que el navegador no bloquee el popup.
    const ventanaPdf = window.open('about:blank', '_blank');

    guardando = true;
    actualizarTotal();
    limpiarMensaje();

    try {

        const resultado = await obtenerJSON(
            'guardar_factura.php',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="ecf-csrf"]')?.content || ''
                },
                body: JSON.stringify(datosFactura)
            }
        );

        mensaje(
            'Factura ' +
            resultado.numero_documento +
            ' generada correctamente. Total: ' +
            dinero(resultado.total),
            'exito'
        );

        abrirPdfFactura(resultado.id_facturas, ventanaPdf);

        console.log(
            'Factura generada:',
            resultado
        );

        // Evitar repetir el guardado
        limpiarPropiedad();

        $('cliente').value = '';
        $('observaciones').value = '';
        $('rnc_comprador').value = '';
        $('razon_social_comprador').value = '';

    } catch (error) {

        console.error(
            'Error al guardar factura:',
            error
        );

        if (ventanaPdf) ventanaPdf.close();

        mensaje(
            'No se pudo generar la factura. ' +
            error.message
        );

    } finally {

        guardando = false;
        actualizarTotal();
    }
}

// Carga el PDF de la factura recién generada en la ventana abierta al hacer clic.
function abrirPdfFactura(idFactura, ventana) {
    const url = 'factura_pdf.php?id=' + encodeURIComponent(idFactura);

    if (ventana && !ventana.closed) {
        ventana.location.href = url;
        return;
    }

    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.target = '_blank';
    enlace.textContent = ' Ver PDF de la factura';

    $('mensaje').appendChild(enlace);
}

// ================================================
// 12. INICIALIZAR FACTURACIÓN
// ================================================

document.addEventListener('DOMContentLoaded', () => {

    console.log(
        'VIORA: módulo de facturación iniciado'
    );

    // Cargar clientes desde MySQL
    cargarClientes();

    // Mostrar período actual
    actualizarPeriodo();

    // Al seleccionar cliente, cargar su propiedad
    $('cliente').addEventListener(
        'change',
        cargarPropiedades
    );

    // Si tiene varias propiedades
    $('selectorPropiedades').addEventListener(
        'change',
        cambiarPropiedad
    );

    // Cambio de período
    $('periodo').addEventListener(
        'change',
        actualizarPeriodo
    );

    // Seleccionar alquiler
    $('checkAlquiler').addEventListener(
        'change',
        actualizarTotal
    );

    // Seleccionar mantenimiento
    $('checkMantenimiento').addEventListener(
        'change',
        actualizarTotal
    );

    // Guardar factura
    $('btnGuardar').addEventListener(
        'click',
        guardarFactura
    );

    // Estado inicial
    limpiarPropiedad();
});
