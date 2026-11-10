
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
$menuActivo = 'roles';

function esc($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// PETICIONES AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // CONSULTAR ROL
        if ($action === 'get') {
            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'SELECT id_roles, nombre, descripcion, activo
                 FROM role
                 WHERE id_roles = ?
                 LIMIT 1'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                throw new Exception('Rol no encontrado.');
            }

            echo json_encode([
                'ok' => true,
                'data' => $fila
            ]);
            exit;
        }

        // ELIMINAR ROL
        if ($action === 'delete') {
            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'DELETE FROM role WHERE id_roles = ?'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception('Rol no encontrado.');
            }

            echo json_encode([
                'ok' => true,
                'msg' => 'Rol eliminado correctamente.'
            ]);
            exit;
        }

        if (!in_array($action, ['create', 'update'], true)) {
            throw new Exception('Acción no válida.');
        }

        // RECIBIR DATOS
        $nombreRol = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $activo = filter_var(
            $_POST['activo'] ?? '1',
            FILTER_VALIDATE_INT
        );

        // VALIDACIONES
        if ($nombreRol === '' || mb_strlen($nombreRol) > 60) {
            throw new Exception(
                'El nombre es obligatorio y debe tener máximo 60 caracteres.'
            );
        }

        if (mb_strlen($descripcion) > 255) {
            throw new Exception(
                'La descripción debe tener máximo 255 caracteres.'
            );
        }

        if (!in_array($activo, [0, 1], true)) {
            throw new Exception('Estado inválido.');
        }

        // VERIFICAR NOMBRE DUPLICADO
        $check = $conexion->prepare(
            'SELECT id_roles
             FROM role
             WHERE nombre = ? AND id_roles <> ?
             LIMIT 1'
        );

        $idExcluir = $action === 'update' ? $id : 0;
        $check->bind_param('si', $nombreRol, $idExcluir);
        $check->execute();

        if ($check->get_result()->fetch_assoc()) {
            throw new Exception(
                'Ya existe un rol registrado con ese nombre.'
            );
        }

        // REGISTRAR ROL
        if ($action === 'create') {
            $stmt = $conexion->prepare(
                'INSERT INTO role (nombre, descripcion, activo)
                 VALUES (?, ?, ?)'
            );

            $stmt->bind_param(
                'ssi',
                $nombreRol,
                $descripcion,
                $activo
            );

        } else {
            // ACTUALIZAR ROL
            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'UPDATE role
                 SET nombre = ?, descripcion = ?, activo = ?
                 WHERE id_roles = ?'
            );

            $stmt->bind_param(
                'ssii',
                $nombreRol,
                $descripcion,
                $activo,
                $id
            );
        }

        $stmt->execute();

        echo json_encode([
            'ok' => true,
            'msg' => $action === 'create'
                ? 'Rol registrado correctamente.'
                : 'Rol actualizado correctamente.'
        ]);

    } catch (Throwable $e) {
        $mensaje = $e instanceof mysqli_sql_exception
            ? 'No se pudo completar la operación. Verifica si el rol está relacionado con otros registros.'
            : $e->getMessage();

        echo json_encode([
            'ok' => false,
            'error' => $mensaje
        ]);
    }

    exit;
}

// LISTADO DE ROLES
$result = $conexion->query(
    'SELECT id_roles, nombre, descripcion, activo
     FROM role
     ORDER BY id_roles ASC'
);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Configuración de Roles - VIORA</title>

    <link rel="stylesheet" href="../estilos/styleP.css?v=6">
</head>

<body>

<div class="diseño">

    <?php include '../mantenimientos/menu_lateral.php'; ?>

    <main class="contenido">

        <header class="contenido__superior">

            <h1 class="titulo">Configuración de Roles</h1>

            <div class="filtros" role="group" aria-label="Usuario actual">

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
                    Lista de Roles
                </h2>

                <button type="button"
                        id="btnNuevo"
                        class="btn-primario">
                    + Añadir Rol
                </button>

            </div>

            <div class="modulo__cuerpo tabla-contenedor">

                <table class="tabla">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre del Rol</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result && $result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?= (int)$row['id_roles'] ?>
                                </td>

                                <td>
                                    <?= esc($row['nombre']) ?>
                                </td>

                                <td>
                                    <?= esc($row['descripcion'] ?: 'Sin descripción') ?>
                                </td>

                                <td>
                                    <span class="badge <?= $row['activo'] ? 'badge--activo' : 'badge--inactivo' ?>">

                                        <?= $row['activo'] ? 'Activo' : 'Inactivo' ?>

                                    </span>
                                </td>

                                <td class="tabla__acciones">

                                    <button type="button"
                                            class="btn-icono btn-icono--editar btn-edit"
                                            data-id="<?= (int)$row['id_roles'] ?>"
                                            title="Editar rol"
                                            aria-label="Editar rol">
                                        ✎
                                    </button>

                                    <button type="button"
                                            class="btn-icono btn-icono--eliminar btn-delete"
                                            data-id="<?= (int)$row['id_roles'] ?>"
                                            title="Eliminar rol"
                                            aria-label="Eliminar rol">
                                        🗑️
                                    </button>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="tabla__vacio">
                                No hay roles registrados.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<!-- MODAL REGISTRAR / EDITAR ROL -->

<div class="modal-backdrop" id="dialog">

    <div class="modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="dlgTitle">

        <div class="modal__header">

            <h3 class="modal__title" id="dlgTitle">
                Nuevo rol
            </h3>

            <button type="button"
                    class="btn-icono"
                    id="btnClose"
                    aria-label="Cerrar">
                ✖
            </button>

        </div>

        <form id="frmRol">

            <div class="modal__body">

                <input type="hidden" name="id" id="fldId">

                <div class="formulario-modal">

                    <div class="campo">

                        <label for="fldNombre">
                            Nombre del rol *
                        </label>

                        <input type="text"
                               id="fldNombre"
                               name="nombre"
                               maxlength="60"
                               placeholder="Ej. Administrador"
                               required>

                    </div>

                    <div class="campo">

                        <label for="fldDescripcion">
                            Descripción
                        </label>

                        <textarea id="fldDescripcion"
                                  name="descripcion"
                                  maxlength="255"
                                  rows="3"
                                  placeholder="Describe la función del rol"></textarea>

                    </div>

                    <div class="campo">

                        <label for="fldActivo">
                            Estado del rol
                        </label>

                        <select id="fldActivo" name="activo">

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
const frm = document.getElementById('frmRol');
const btnSave = document.getElementById('btnSave');
const msgError = document.getElementById('msgError');

const fldId = document.getElementById('fldId');
const fldNombre = document.getElementById('fldNombre');
const fldDescripcion = document.getElementById('fldDescripcion');
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
        : data.id_roles;

    document.getElementById('dlgTitle').textContent =
        modo === 'create'
            ? 'Nuevo rol'
            : 'Editar rol';

    fldNombre.value = data.nombre ?? '';
    fldDescripcion.value = data.descripcion ?? '';
    fldActivo.value = String(data.activo ?? 1);

    dlg.classList.add('is-open');

    fldNombre.focus();
}

// CERRAR MODAL
function cerrarModal() {
    dlg.classList.remove('is-open');
}

// PETICIONES AJAX
async function enviar(fd) {

    const response = await fetch('Roles.php', {
        method: 'POST',
        body: fd
    });

    if (!response.ok) {
        throw new Error('Error HTTP ' + response.status);
    }

    return response.json();
}

// BOTÓN NUEVO
document.getElementById('btnNuevo')
    .addEventListener('click', () => abrirModal('create'));

// CERRAR
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

// EDITAR ROL
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
                alert(datos.error || 'Error al cargar el rol.');
            }

        } catch (e) {
            alert('No se pudieron cargar los datos del rol.');
        }

    });

});

// ELIMINAR ROL
document.querySelectorAll('.btn-delete').forEach(btn => {

    btn.addEventListener('click', async () => {

        if (!confirm('¿Seguro que deseas eliminar este rol?')) {
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
                alert(datos.error || 'No se pudo eliminar el rol.');
            }

        } catch (e) {
            alert('Error de conexión al eliminar el rol.');
        }

    });

});

// GUARDAR ROL
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
                datos.error || 'Error al guardar el rol.'
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
