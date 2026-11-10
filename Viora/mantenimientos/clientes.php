
<?php
session_start();
require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}

$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';
$usuario = $_SESSION['usuario'] ?? '';
$menuActivo = 'clientes';

function esc($valor) {
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function responder($datos) {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// PETICIONES AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['ajax'] ?? '') === '1') {

    header('Content-Type: application/json; charset=utf-8');

    try {

        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // CONSULTAR CLIENTE
        if ($action === 'get') {

            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'SELECT id_personas, nombres, apellidos,
                        cedula, correo, telefono, direccion,
                        activo, fecha_registro
                 FROM persona
                 WHERE id_personas = ?
                 LIMIT 1'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                throw new Exception('Cliente no encontrado.');
            }

            responder([
                'ok' => true,
                'data' => $fila
            ]);
        }

        // ELIMINAR CLIENTE
        if ($action === 'delete') {

            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'DELETE FROM persona WHERE id_personas = ?'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception('Cliente no encontrado.');
            }

            responder([
                'ok' => true,
                'msg' => 'Cliente eliminado correctamente.'
            ]);
        }

        if (!in_array($action, ['create', 'update'], true)) {
            throw new Exception('Acción no válida.');
        }

        // RECIBIR DATOS
        $nombres = trim($_POST['nombres'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');

        $activo = filter_var(
            $_POST['activo'] ?? '1',
            FILTER_VALIDATE_INT
        );

        // VALIDACIONES
        if ($nombres === '' || mb_strlen($nombres) > 100) {
            throw new Exception(
                'Los nombres son obligatorios y deben tener máximo 100 caracteres.'
            );
        }

        if ($apellidos === '' || mb_strlen($apellidos) > 100) {
            throw new Exception(
                'Los apellidos son obligatorios y deben tener máximo 100 caracteres.'
            );
        }

        if (mb_strlen($cedula) > 20) {
            throw new Exception(
                'La cédula debe tener máximo 20 caracteres.'
            );
        }

        if ($correo !== '' &&
            !filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            throw new Exception(
                'Introduce un correo electrónico válido.'
            );
        }

        if (mb_strlen($correo) > 120) {
            throw new Exception(
                'El correo debe tener máximo 120 caracteres.'
            );
        }

        if (mb_strlen($telefono) > 40) {
            throw new Exception(
                'El teléfono debe tener máximo 40 caracteres.'
            );
        }

        if (mb_strlen($direccion) > 255) {
            throw new Exception(
                'La dirección debe tener máximo 255 caracteres.'
            );
        }

        if (!in_array($activo, [0, 1], true)) {
            throw new Exception('Estado inválido.');
        }

        // VALIDAR CÉDULA DUPLICADA
        if ($cedula !== '') {

            $idExcluir = $action === 'update' ? $id : 0;

            $check = $conexion->prepare(
                'SELECT id_personas
                 FROM persona
                 WHERE cedula = ?
                 AND id_personas <> ?
                 LIMIT 1'
            );

            $check->bind_param(
                'si',
                $cedula,
                $idExcluir
            );

            $check->execute();

            if ($check->get_result()->fetch_assoc()) {
                throw new Exception(
                    'Ya existe un cliente registrado con esa cédula.'
                );
            }
        }

        // CAMPOS OPCIONALES
        $cedula = $cedula !== '' ? $cedula : null;
        $correo = $correo !== '' ? $correo : null;
        $telefono = $telefono !== '' ? $telefono : null;
        $direccion = $direccion !== '' ? $direccion : null;

        // REGISTRAR CLIENTE
        if ($action === 'create') {

            $stmt = $conexion->prepare(
                'INSERT INTO persona
                 (nombres, apellidos, cedula, correo,
                  telefono, direccion, activo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->bind_param(
                'ssssssi',
                $nombres,
                $apellidos,
                $cedula,
                $correo,
                $telefono,
                $direccion,
                $activo
            );

        } else {

            // ACTUALIZAR CLIENTE
            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'UPDATE persona
                 SET nombres = ?,
                     apellidos = ?,
                     cedula = ?,
                     correo = ?,
                     telefono = ?,
                     direccion = ?,
                     activo = ?
                 WHERE id_personas = ?'
            );

            $stmt->bind_param(
                'ssssssii',
                $nombres,
                $apellidos,
                $cedula,
                $correo,
                $telefono,
                $direccion,
                $activo,
                $id
            );
        }

        $stmt->execute();

        responder([
            'ok' => true,
            'msg' => $action === 'create'
                ? 'Cliente registrado correctamente.'
                : 'Cliente actualizado correctamente.'
        ]);

    } catch (Throwable $e) {

        $mensaje = $e instanceof mysqli_sql_exception
            ? 'No se pudo completar la operación. Comprueba si el cliente está relacionado con otros registros.'
            : $e->getMessage();

        responder([
            'ok' => false,
            'error' => $mensaje
        ]);
    }
}

// LISTADO DE CLIENTES
$result = $conexion->query(
    'SELECT id_personas, nombres, apellidos,
            cedula, correo, telefono, direccion,
            fecha_registro, activo
     FROM persona
     ORDER BY id_personas ASC'
);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Mantenimiento de Clientes - VIORA</title>

    <link rel="stylesheet"
          href="../estilos/styleP.css?v=6">
</head>

<body>

<div class="diseño">

    <?php
    require_once __DIR__ . '/menu_lateral.php';
    ?>

    <main class="contenido">

        <header class="contenido__superior">

            <h1 class="titulo">
                Mantenimiento de Clientes
            </h1>

            <div class="filtros"
                 role="group"
                 aria-label="Usuario actual">

                <span class="filtro filtro--activo">
                    <?= esc($nombre) ?>
                </span>

                <span class="filtro">
                    <?= esc($usuario) ?>
                </span>

                <a href="../login/logout.php"
                   class="filtro"
                   style="text-decoration:none;">
                    Cerrar sesión
                </a>

            </div>

        </header>

        <section class="modulo">

            <div class="modulo__header">

                <h2 class="modulo__titulo">
                    Lista de Clientes
                </h2>

                <button type="button"
                        id="btnNuevo"
                        class="btn-primario">
                    + Añadir Cliente
                </button>

            </div>

            <div class="modulo__cuerpo tabla-contenedor">

                <table class="tabla">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre completo</th>
                            <th>Cédula</th>
                            <th>Correo</th>
                            <th>Teléfono</th>
                            <th>Dirección</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result && $result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?= (int)$row['id_personas'] ?>
                                </td>

                                <td>
                                    <?= esc(
                                        $row['nombres'] . ' ' .
                                        $row['apellidos']
                                    ) ?>
                                </td>

                                <td>
                                    <?= esc($row['cedula'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= esc($row['correo'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= esc($row['telefono'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= esc($row['direccion'] ?: '—') ?>
                                </td>

                                <td>

                                    <span class="badge <?= $row['activo'] ? 'badge--activo' : 'badge--inactivo' ?>">

                                        <?= $row['activo'] ? 'Activo' : 'Inactivo' ?>

                                    </span>

                                </td>

                                <td class="tabla__acciones">

                                    <button type="button"
                                            class="btn-icono btn-icono--editar btn-edit"
                                            data-id="<?= (int)$row['id_personas'] ?>"
                                            title="Editar cliente"
                                            aria-label="Editar cliente">
                                        ✎
                                    </button>

                                    <button type="button"
                                            class="btn-icono btn-icono--eliminar btn-delete"
                                            data-id="<?= (int)$row['id_personas'] ?>"
                                            title="Eliminar cliente"
                                            aria-label="Eliminar cliente">
                                        🗑️
                                    </button>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="tabla__vacio">
                                No hay clientes registrados.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<!-- MODAL REGISTRAR / EDITAR CLIENTE -->

<div class="modal-backdrop" id="dialog">

    <div class="modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="dlgTitle">

        <div class="modal__header">

            <h3 class="modal__title" id="dlgTitle">
                Nuevo cliente
            </h3>

            <button type="button"
                    class="btn-icono"
                    id="btnClose"
                    aria-label="Cerrar">
                ✖
            </button>

        </div>

        <form id="frmCliente">

            <div class="modal__body">

                <input type="hidden"
                       name="id"
                       id="fldId">

                <div class="formulario-modal">

                    <div class="campo">

                        <label for="fldNombres">
                            Nombres *
                        </label>

                        <input type="text"
                               id="fldNombres"
                               name="nombres"
                               maxlength="100"
                               placeholder="Nombres del cliente"
                               required>

                    </div>

                    <div class="campo">

                        <label for="fldApellidos">
                            Apellidos *
                        </label>

                        <input type="text"
                               id="fldApellidos"
                               name="apellidos"
                               maxlength="100"
                               placeholder="Apellidos del cliente"
                               required>

                    </div>

                    <div class="campo">

                        <label for="fldCedula">
                            Cédula
                        </label>

                        <input type="text"
                               id="fldCedula"
                               name="cedula"
                               maxlength="20"
                               placeholder="Ej. 001-1234567-8">

                    </div>

                    <div class="campo">

                        <label for="fldCorreo">
                            Correo electrónico
                        </label>

                        <input type="email"
                               id="fldCorreo"
                               name="correo"
                               maxlength="120"
                               placeholder="cliente@correo.com">

                    </div>

                    <div class="campo">

                        <label for="fldTelefono">
                            Teléfono
                        </label>

                        <input type="tel"
                               id="fldTelefono"
                               name="telefono"
                               maxlength="40"
                               placeholder="Ej. 809-555-1234">

                    </div>

                    <div class="campo">

                        <label for="fldDireccion">
                            Dirección
                        </label>

                        <textarea id="fldDireccion"
                                  name="direccion"
                                  maxlength="255"
                                  rows="2"
                                  placeholder="Dirección del cliente"></textarea>

                    </div>

                    <div class="campo">

                        <label for="fldActivo">
                            Estado del cliente
                        </label>

                        <select id="fldActivo"
                                name="activo">

                            <option value="1">
                                Activo
                            </option>

                            <option value="0">
                                Inactivo
                            </option>

                        </select>

                    </div>

                </div>

                <p id="msgError"
                   class="mensaje-error"
                   style="display:none;"></p>

            </div>

            <div class="modal__footer">

                <button type="button"
                        class="btn-secundario"
                        id="btnCancel">
                    Cancelar
                </button>

                <button type="submit"
                        class="btn-primario"
                        id="btnSave">
                    Guardar
                </button>

            </div>

        </form>

    </div>

</div>

<script>

const dlg = document.getElementById('dialog');
const frm = document.getElementById('frmCliente');
const btnSave = document.getElementById('btnSave');
const msgError = document.getElementById('msgError');

const fldId = document.getElementById('fldId');
const fldNombres = document.getElementById('fldNombres');
const fldApellidos = document.getElementById('fldApellidos');
const fldCedula = document.getElementById('fldCedula');
const fldCorreo = document.getElementById('fldCorreo');
const fldTelefono = document.getElementById('fldTelefono');
const fldDireccion = document.getElementById('fldDireccion');
const fldActivo = document.getElementById('fldActivo');

// MOSTRAR ERRORES
function mostrarError(mensaje) {
    msgError.textContent = mensaje;
    msgError.style.display = 'block';
}

// ABRIR MODAL
function abrirModal(modo, data = {}) {

    frm.reset();

    msgError.style.display = 'none';
    msgError.textContent = '';

    fldId.value = modo === 'create'
        ? ''
        : data.id_personas;

    document.getElementById('dlgTitle').textContent =
        modo === 'create'
            ? 'Nuevo cliente'
            : 'Editar cliente';

    fldNombres.value = data.nombres ?? '';
    fldApellidos.value = data.apellidos ?? '';
    fldCedula.value = data.cedula ?? '';
    fldCorreo.value = data.correo ?? '';
    fldTelefono.value = data.telefono ?? '';
    fldDireccion.value = data.direccion ?? '';
    fldActivo.value = String(data.activo ?? 1);

    dlg.classList.add('is-open');

    fldNombres.focus();
}

// CERRAR MODAL
function cerrarModal() {
    dlg.classList.remove('is-open');
}

// PETICIONES AJAX
async function enviar(fd) {

    const response = await fetch('clientes.php', {
        method: 'POST',
        body: fd
    });

    if (!response.ok) {
        throw new Error('Error HTTP ' + response.status);
    }

    return response.json();
}

// NUEVO CLIENTE
document.getElementById('btnNuevo')
    .addEventListener('click', () => abrirModal('create'));

// CERRAR MODAL
document.getElementById('btnClose')
    .addEventListener('click', cerrarModal);

document.getElementById('btnCancel')
    .addEventListener('click', cerrarModal);

dlg.addEventListener('click', e => {
    if (e.target === dlg) cerrarModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModal();
});

// EDITAR CLIENTE
document.querySelectorAll('.btn-edit').forEach(btn => {

    btn.addEventListener('click', async () => {

        const fd = new FormData();

        fd.append('ajax', '1');
        fd.append('action', 'get');
        fd.append('id', btn.dataset.id);

        try {

            const datos = await enviar(fd);

            if (datos.ok) {
                abrirModal('edit', datos.data);
            } else {
                alert(datos.error || 'Error al cargar el cliente.');
            }

        } catch (e) {
            alert('No se pudieron cargar los datos del cliente.');
        }

    });

});

// ELIMINAR CLIENTE
document.querySelectorAll('.btn-delete').forEach(btn => {

    btn.addEventListener('click', async () => {

        if (!confirm('¿Seguro que deseas eliminar este cliente?')) {
            return;
        }

        const fd = new FormData();

        fd.append('ajax', '1');
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);

        try {

            const datos = await enviar(fd);

            if (datos.ok) {
                location.reload();
            } else {
                alert(datos.error || 'No se pudo eliminar el cliente.');
            }

        } catch (e) {
            alert('Error de conexión al eliminar el cliente.');
        }

    });

});

// GUARDAR CLIENTE
frm.addEventListener('submit', async e => {

    e.preventDefault();

    if (!frm.reportValidity()) return;

    const fd = new FormData(frm);

    fd.append('ajax', '1');

    fd.append(
        'action',
        fldId.value ? 'update' : 'create'
    );

    btnSave.disabled = true;
    btnSave.textContent = 'Guardando...';
    msgError.style.display = 'none';

    try {

        const datos = await enviar(fd);

        if (datos.ok) {
            location.reload();
        } else {
            mostrarError(
                datos.error || 'Error al guardar el cliente.'
            );
        }

    } catch (e) {

        mostrarError(
            'No se pudo completar la operación. Verifica la conexión.'
        );

    } finally {

        btnSave.disabled = false;
        btnSave.textContent = 'Guardar';

    }

});

</script>

</body>
</html>
