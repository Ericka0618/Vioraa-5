

<?php
session_start();

require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';

if (empty($_SESSION['logged_in'])) {
    header('Location: ../login/login.php');
    exit;
}

$nombre = $_SESSION['nombre_usuario'] ?? 'Administrador';
$menuActivo = 'facturacion';

// Protección básica contra XSS al mostrar información.
function escapar($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Facturación | VIORA</title>

<!-- Estilos generales de VIORA -->
<link rel="stylesheet"
      href="../estilos/styleP.css?v=<?= time() ?>">

<style>

:root {
    --verde: #0F382B;
    --verde-hover: #195540;
    --fondo: #f5f6f5;
    --borde: #e1e7e3;
    --texto: #24352d;
}

/* Estilos del módulo de facturación */
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--fondo);
    font-family: Arial, sans-serif;
    color: var(--texto);
}

.contenido-factura {
    flex: 1;
    min-width: 0;
    padding: 30px;
}

.encabezado-factura {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.encabezado-factura h1 {
    font-size: 26px;
    color: var(--verde);
    margin: 0;
}

.encabezado-factura p {
    color: #777;
    font-size: 14px;
    margin: 8px 0 0;
}

.tarjeta-factura {
    background: white;
    border: 1px solid var(--borde);
    border-radius: 12px;
    margin-bottom: 20px;
    overflow: hidden;
}

.titulo-tarjeta {
    background: #f7faf8;
    padding: 17px 22px;
    border-bottom: 1px solid var(--borde);
    color: var(--verde);
    font-weight: bold;
}

.cuerpo-tarjeta {
    padding: 22px;
}

.grid-factura {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.grupo {
    margin-bottom: 15px;
    min-width: 0;
}

.grupo label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 8px;
}

.grupo input,
.grupo select,
.grupo textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d8e0da;
    border-radius: 7px;
    font-size: 14px;
    background: white;
    color: var(--texto);
}

.grupo input:focus,
.grupo select:focus,
.grupo textarea:focus {
    outline: none;
    border-color: var(--verde);
}

.grupo input[readonly] {
    background: #f7f9f7;
    cursor: default;
}

.grupo select:disabled {
    background: #f7f9f7;
    color: #888;
}

.ayuda {
    font-size: 12px;
    color: #777;
    margin-top: 6px;
}

.tabla-contenedor {
    overflow-x: auto;
}

.tabla-factura {
    width: 100%;
    border-collapse: collapse;
    min-width: 560px;
}

.tabla-factura th {
    background: #f5f8f6;
    color: var(--verde);
    text-align: left;
    padding: 14px;
    font-size: 13px;
}

.tabla-factura td {
    padding: 15px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}

.tabla-factura input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--verde);
    cursor: pointer;
}

.tabla-factura input[type="checkbox"]:disabled {
    cursor: not-allowed;
}

.resumen {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
}

.resumen-total {
    background: #f3f8f5;
    border-radius: 10px;
    padding: 20px;
    min-width: 280px;
}

.resumen-total h2 {
    margin: 10px 0 0;
    color: var(--verde);
    font-size: 25px;
}

.acciones {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 20px;
    margin-bottom: 30px;
}

.btn {
    padding: 12px 22px;
    border: none;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: background .2s;
}

.btn-verde {
    background: var(--verde);
    color: white;
}

.btn-verde:hover:not(:disabled) {
    background: var(--verde-hover);
}

.btn-gris {
    background: #e9eeeb;
    color: #34443b;
}

.btn-gris:hover {
    background: #dce5df;
}

.btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.mensaje {
    margin-bottom: 15px;
    padding: 12px 15px;
    border-radius: 7px;
    display: none;
    font-size: 14px;
}

.mensaje.exito {
    display: block;
    background: #e4f4e9;
    color: #176336;
}

.mensaje.error {
    display: block;
    background: #fbe8e8;
    color: #a12424;
}

.mensaje.info {
    display: block;
    background: #eaf2fa;
    color: #245783;
}

.estado-propiedad {
    display: inline-block;
    padding: 7px 12px;
    border-radius: 20px;
    background: #e4f4e9;
    color: #176336;
    font-size: 12px;
    font-weight: bold;
}

@media(max-width: 768px) {
    .grid-factura {
        grid-template-columns: 1fr;
    }

    .contenido-factura {
        padding: 15px;
    }

    .encabezado-factura {
        flex-direction: column;
        align-items: flex-start;
    }

    .resumen-total {
        width: 100%;
        min-width: 0;
    }

    .acciones {
        flex-direction: column;
    }

    .acciones .btn {
        width: 100%;
    }
}
</style>
</head>

<body>

<div class="diseño">

<?php
// Menú lateral de VIORA.
include __DIR__ . '/menu_lateral.php';
?>

<main class="contenido-factura">

    <!-- ENCABEZADO -->
    <div class="encabezado-factura">
        <div>
            <h1>Facturación</h1>
            <p>
                Administración de alquileres
                y mantenimiento
            </p>
        </div>

        <button
            type="button"
            class="btn btn-gris"
            onclick="location.reload()">
            + Nueva factura
        </button>
    </div>

    <!-- MENSAJES -->
    <div
        id="mensaje"
        class="mensaje"
        role="status"
        aria-live="polite">
    </div>

    <!-- DATOS DE FACTURA -->
    <div class="tarjeta-factura">

        <div class="titulo-tarjeta">
            Datos de la factura
        </div>

        <div class="cuerpo-tarjeta">

            <div class="grid-factura">

                <!-- CLIENTE -->
                <div class="grupo">
                    <label for="cliente">
                        Cliente *
                    </label>

                    <select id="cliente">
                        <option value="">
                            Cargando clientes...
                        </option>
                    </select>

                    <div class="ayuda">
                        Seleccione el cliente para cargar
                        automáticamente su propiedad.
                    </div>
                </div>

                <!-- FECHA -->
                <div class="grupo">
                    <label for="fecha">
                        Fecha de factura
                    </label>

                    <input
                        type="date"
                        id="fecha"
                        value="<?= date('Y-m-d') ?>"
                        readonly>
                </div>

                <!-- PROPIEDAD AUTOMÁTICA -->
                <div class="grupo">
                    <label for="propiedad">
                        Propiedad del cliente
                    </label>

                    <input
                        type="text"
                        id="propiedad"
                        placeholder="Se cargará automáticamente"
                        readonly>

                    <div class="ayuda">
                        VIORA buscará la propiedad
                        asociada al contrato del cliente.
                    </div>
                </div>

                <!-- PERÍODO -->
                <div class="grupo">
                    <label for="periodo">
                        Período de facturación
                    </label>

                    <input
                        type="month"
                        id="periodo"
                        value="<?= date('Y-m') ?>">
                </div>

            </div>

            <!-- VARIAS PROPIEDADES -->
            <div
                class="grupo"
                id="grupoVariasPropiedades"
                hidden>

                <label for="selectorPropiedades">
                    Seleccionar propiedad a facturar
                </label>

                <select id="selectorPropiedades">
                    <option value="">
                        Seleccionar propiedad...
                    </option>
                </select>

                <div class="ayuda">
                    Este cliente tiene más de una propiedad.
                </div>
            </div>

        </div>
    </div>

    <!-- INFORMACIÓN DE PROPIEDAD -->
    <div class="tarjeta-factura">

        <div class="titulo-tarjeta">
            Información de la propiedad
        </div>

        <div class="cuerpo-tarjeta">

            <div class="grid-factura">

                <div class="grupo">
                    <label for="condominio">
                        Condominio
                    </label>
                    <input id="condominio" readonly>
                </div>

                <div class="grupo">
                    <label for="edificio">
                        Edificio
                    </label>
                    <input id="edificio" readonly>
                </div>

                <div class="grupo">
                    <label for="apartamento">
                        Apartamento
                    </label>
                    <input id="apartamento" readonly>
                </div>

                <div class="grupo">
                    <label for="numero_contrato">
                        Número de contrato
                    </label>
                    <input id="numero_contrato" readonly>
                </div>

            </div>

            <div id="estadoPropiedad" hidden>
                <span class="estado-propiedad">
                    Contrato activo
                </span>
            </div>

        </div>
    </div>

    <!-- CONCEPTOS -->
    <div class="tarjeta-factura">

        <div class="titulo-tarjeta">
            Conceptos de facturación
        </div>

        <div class="cuerpo-tarjeta">

            <div class="tabla-contenedor">

                <table class="tabla-factura">
                    <thead>
                        <tr>
                            <th>Seleccionar</th>
                            <th>Concepto</th>
                            <th>Período</th>
                            <th>Monto</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    id="checkAlquiler"
                                    aria-label="Facturar alquiler"
                                    disabled>
                            </td>

                            <td>Alquiler mensual</td>

                            <td class="periodo-texto">
                                -
                            </td>

                            <td id="montoAlquiler">
                                RD$ 0.00
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    id="checkMantenimiento"
                                    aria-label="Facturar mantenimiento"
                                    disabled>
                            </td>

                            <td>Mantenimiento</td>

                            <td class="periodo-texto">
                                -
                            </td>

                            <td id="montoMantenimiento">
                                RD$ 0.00
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div>

            <div class="resumen">
                <div class="resumen-total">
                    <strong>
                        Total de la factura (incluye ITBIS 18%)
                    </strong>

                    <h2 id="totalFactura">
                        RD$ 0.00
                    </h2>
                </div>
            </div>

        </div>
    </div>

    <!-- COMPRADOR FISCAL (E31) -->
    <div class="tarjeta-factura">

        <div class="titulo-tarjeta">
            Comprador fiscal (opcional)
        </div>

        <div class="cuerpo-tarjeta">
            <div class="grupo">
                <label for="rnc_comprador">RNC o cédula del comprador</label>
                <input
                    type="text"
                    id="rnc_comprador"
                    maxlength="13"
                    inputmode="numeric"
                    placeholder="Ej. 101234567 o 00112345678">
            </div>
            <div class="grupo">
                <label for="razon_social_comprador">Razón social</label>
                <input
                    type="text"
                    id="razon_social_comprador"
                    maxlength="150"
                    placeholder="Nombre fiscal del comprador">
            </div>
            <small>
                Si se indica el RNC, la factura se emitirá como
                E31 (Crédito Fiscal) y la razón social es obligatoria.
            </small>
        </div>

    </div>

    <!-- OBSERVACIONES -->
    <div class="tarjeta-factura">

        <div class="titulo-tarjeta">
            Observaciones
        </div>

        <div class="cuerpo-tarjeta">
            <div class="grupo">
                <textarea
                    id="observaciones"
                    rows="3"
                    placeholder="Observaciones adicionales..."></textarea>
            </div>
        </div>

    </div>

    <!-- BOTONES -->
    <div class="acciones">

        <button
            type="button"
            class="btn btn-gris"
            onclick="location.reload()">
            Cancelar
        </button>

        <button
            type="button"
            class="btn btn-verde"
            id="btnGuardar"
            disabled>
            Generar factura
        </button>

    </div>

</main>
</div>

<script src="facturacion.js?v=<?= time() ?>"></script>

</body>
</html>
