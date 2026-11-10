
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
$menuActivo = 'usuarios';

function esc($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function responder($datos) {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// PETICIONES AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // CONSULTAR USUARIO
        if ($action === 'get') {

            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            $stmt = $conexion->prepare(
                'SELECT id_usuarios, nombre, usuario,
                        id_rol, persona_id, activo
                 FROM usuario
                 WHERE id_usuarios = ?
                 LIMIT 1'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                throw new Exception('Usuario no encontrado.');
            }

            responder([
                'ok' => true,
                'data' => $fila
            ]);
        }

        // ELIMINAR USUARIO
        if ($action === 'delete') {

            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            // Evitar que el usuario elimine su propia cuenta
            if (
                isset($_SESSION['id_usuarios']) &&
                $id === (int)$_SESSION['id_usuarios']
            ) {
                throw new Exception(
                    'No puedes eliminar tu propia cuenta.'
                );
            }

            $stmt = $conexion->prepare(
                'DELETE FROM usuario WHERE id_usuarios = ?'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception('Usuario no encontrado.');
            }

            responder([
                'ok' => true,
                'msg' => 'Usuario eliminado correctamente.'
            ]);
        }

        if (!in_array($action, ['create', 'update'], true)) {
            throw new Exception('Acción no válida.');
        }

        // RECIBIR DATOS
        $nombreUsuario = trim($_POST['nombre'] ?? '');
        $loginUsuario = trim($_POST['usuario'] ?? '');
        $clave = (string)($_POST['clave'] ?? '');

        $idRol = filter_var(
            $_POST['id_rol'] ?? null,
            FILTER_VALIDATE_INT
        );

        $personaId = filter_var(
            $_POST['persona_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $activo = filter_var(
            $_POST['activo'] ?? '1',
            FILTER_VALIDATE_INT
        );

        // VALIDACIONES
        if ($nombreUsuario === '' || mb_strlen($nombreUsuario) > 100) {
            throw new Exception(
                'El nombre es obligatorio y debe tener máximo 100 caracteres.'
            );
        }

        if ($loginUsuario === '' || mb_strlen($loginUsuario) > 50) {
            throw new Exception(
                'El nombre de usuario es obligatorio y debe tener máximo 50 caracteres.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $loginUsuario)) {
            throw new Exception(
                'El usuario solo puede contener letras, números, puntos, guiones y guiones bajos.'
            );
        }

        if ($action === 'create' && $clave === '') {
            throw new Exception('La contraseña es obligatoria.');
        }

        if ($clave !== '' && strlen($clave) < 8) {
            throw new Exception(
                'La contraseña debe tener al menos 8 caracteres.'
            );
        }

        if ($clave !== '' && strlen($clave) > 72) {
            throw new Exception(
                'La contraseña debe tener máximo 72 bytes.'
            );
        }

        if (!$idRol || $idRol <= 0) {
            throw new Exception('Selecciona un rol válido.');
        }

        if (!$personaId || $personaId <= 0) {
            throw new Exception('Selecciona una persona válida.');
        }

        if (!in_array($activo, [0, 1], true)) {
            throw new Exception('Estado inválido.');
        }

        // VERIFICAR ROL
        $check = $conexion->prepare(
            'SELECT id_roles
             FROM role
             WHERE id_roles = ? AND activo = 1
             LIMIT 1'
        );

        $check->bind_param('i', $idRol);
        $check->execute();

        if (!$check->get_result()->fetch_assoc()) {
            throw new Exception(
                'El rol seleccionado no existe o está inactivo.'
            );
        }

        // VERIFICAR PERSONA
        $check = $conexion->prepare(
            'SELECT id_personas
             FROM persona
             WHERE id_personas = ?
             LIMIT 1'
        );

        $check->bind_param('i', $personaId);
        $check->execute();

        if (!$check->get_result()->fetch_assoc()) {
            throw new Exception(
                'La persona seleccionada no existe.'
            );
        }

        // EVITAR USUARIOS DUPLICADOS
        $idExcluir = $action === 'update' ? $id : 0;

        $check = $conexion->prepare(
            'SELECT id_usuarios
             FROM usuario
             WHERE usuario = ? AND id_usuarios <> ?
             LIMIT 1'
        );

        $check->bind_param('si', $loginUsuario, $idExcluir);
        $check->execute();

        if ($check->get_result()->fetch_assoc()) {
            throw new Exception(
                'Ese nombre de usuario ya está registrado.'
            );
        }

        // REGISTRAR USUARIO
        if ($action === 'create') {

            $claveHash = password_hash($clave, PASSWORD_DEFAULT);

            $stmt = $conexion->prepare(
                'INSERT INTO usuario
                 (nombre, usuario, clave, id_rol, persona_id, activo)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->bind_param(
                'sssiii',
                $nombreUsuario,
                $loginUsuario,
                $claveHash,
                $idRol,
                $personaId,
                $activo
            );

        } else {

            // ACTUALIZAR USUARIO
            if ($id <= 0) {
                throw new Exception('ID inválido.');
            }

            // Contraseña opcional al editar
            if ($clave !== '') {

                $claveHash = password_hash(
                    $clave,
                    PASSWORD_DEFAULT
                );

                $stmt = $conexion->prepare(
                    'UPDATE usuario
                     SET nombre = ?, usuario = ?, clave = ?,
                         id_rol = ?, persona_id = ?, activo = ?
                     WHERE id_usuarios = ?'
                );

                $stmt->bind_param(
                    'sssiiii',
                    $nombreUsuario,
                    $loginUsuario,
                    $claveHash,
                    $idRol,
                    $personaId,
                    $activo,
                    $id
                );

            } else {

                $stmt = $conexion->prepare(
                    'UPDATE usuario
                     SET nombre = ?, usuario = ?,
                         id_rol = ?, persona_id = ?, activo = ?
                     WHERE id_usuarios = ?'
                );

                $stmt->bind_param(
                    'ssiiii',
                    $nombreUsuario,
                    $loginUsuario,
                    $idRol,
                    $personaId,
                    $activo,
                    $id
                );
            }
        }

        $stmt->execute();

        responder([
            'ok' => true,
            'msg' => $action === 'create'
                ? 'Usuario registrado correctamente.'
                : 'Usuario actualizado correctamente.'
        ]);

    } catch (Throwable $e) {

        $mensaje = $e instanceof mysqli_sql_exception
            ? 'No se pudo completar la operación. Verifica los datos y las relaciones con otros registros.'
            : $e->getMessage();

        responder([
            'ok' => false,
            'error' => $mensaje
        ]);
    }
}

// CONSULTAR ROLES ACTIVOS
$roles = $conexion->query(
    'SELECT id_roles, nombre
     FROM role
     WHERE activo = 1
     ORDER BY nombre ASC'
);

// CONSULTAR PERSONAS
// Se utiliza id_personas para no depender de otras columnas
// que todavía no conocemos de la tabla persona.
$personas = $conexion->query(
    'SELECT id_personas
     FROM persona
     ORDER BY id_personas ASC'
);

// LISTADO DE USUARIOS
$result = $conexion->query(
    'SELECT u.id_usuarios,
            u.nombre,
            u.usuario,
            u.id_rol,
            u.persona_id,
            u.activo,
            u.fecha_registro,
            r.nombre AS nombre_rol
     FROM usuario u
     INNER JOIN role r ON r.id_roles = u.id_rol
     ORDER BY u.id_usuarios ASC'
);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Configuración de Usuarios - VIORA</title>

    <link rel="stylesheet"
          href="../estilos/styleP.css?v=6">
</head>

<body>

<div class="diseño">

    <?php
    require_once __DIR__ . '/../mantenimientos/menu_lateral.php';
    ?>

    <main class="contenido">

        <header class="contenido__superior">

            <h1 class="titulo">
                Configuración de Usuarios
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
                    Lista de Usuarios
                </h2>

                <button type="button"
                        id="btnNuevo"
                        class="btn-primario">
                    + Añadir Usuario
                </button>

            </div>

            <div class="modulo__cuerpo tabla-contenedor">

                <table class="tabla">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Persona ID</th>
                            <th>Estado</th>
                            <th>Fecha de registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result && $result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?= (int)$row['id_usuarios'] ?>
                                </td>

                                <td>
                                    <?= esc($row['nombre']) ?>
                                </td>

                                <td>
                                    <?= esc($row['usuario']) ?>
                                </td>

                                <td>
                                    <?= esc($row['nombre_rol']) ?>
                                </td>

                                <td>
                                    <?= (int)$row['persona_id'] ?>
                                </td>

                                <td>

                                    <span class="badge <?= $row['activo'] ? 'badge--activo' : 'badge--inactivo' ?>">

                                        <?= $row['activo'] ? 'Activo' : 'Inactivo' ?>

                                    </span>

                                </td>

                                <td>
                                    <?= esc($row['fecha_registro']) ?>
                                </td>

                                <td class="tabla__acciones">

                                    <button type="button"
                                            class="btn-icono btn-icono--editar btn-edit"
                                            data-id="<?= (int)$row['id_usuarios'] ?>"
                                            title="Editar usuario"
                                            aria-label="Editar usuario">
                                        ✎
                                    </button>

                                    <button type="button"
                                            class="btn-icono btn-icono--eliminar btn-delete"
                                            data-id="<?= (int)$row['id_usuarios'] ?>"
                                            title="Eliminar usuario"
                                            aria-label="Eliminar usuario">
                                        🗑️
                                    </button>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="tabla__vacio">
                                No hay usuarios registrados.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<!-- MODAL REGISTRAR / EDITAR USUARIO -->

<div class="modal-backdrop" id="dialog">

    <div class="modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="dlgTitle">

        <div class="modal__header">

            <h3 class="modal__title" id="dlgTitle">
                Nuevo usuario
            </h3>

            <button type="button"
                    class="btn-icono"
                    id="btnClose"
                    aria-label="Cerrar">
                ✖
            </button>

        </div>

        <form id="frmUsuario">

            <div class="modal__body">

                <input type="hidden"
                       name="id"
                       id="fldId">

                <div class="formulario-modal">

                    <div class="campo">

                        <label for="fldNombre">
                            Nombre completo *
                        </label>

                        <input type="text"
                               id="fldNombre"
                               name="nombre"
                               maxlength="100"
                               placeholder="Nombre del usuario"
                               required>

                    </div>

                    <div class="campo">

                        <label for="fldUsuario">
                            Nombre de usuario *
                        </label>

                        <input type="text"
                               id="fldUsuario"
                               name="usuario"
                               maxlength="50"
                               placeholder="Ej. administrador"
                               autocomplete="off"
                               required>

                    </div>

                    <div class="campo">

                        <label for="fldClave">
                            Contraseña *
                        </label>

                        <input type="password"
                               id="fldClave"
                               name="clave"
                               minlength="8"
                               autocomplete="new-password"
                               placeholder="Mínimo 8 caracteres">

                        <small id="claveAyuda"
                               style="color:#777;font-size:12px;">
                            Obligatoria para nuevos usuarios.
                        </small>

                    </div>

                    <div class="campo">

                        <label for="fldRol">
                            Rol del usuario *
                        </label>

                        <select id="fldRol"
                                name="id_rol"
                                required>

                            <option value="">
                                Selecciona un rol
                            </option>

                            <?php while ($r = $roles->fetch_assoc()): ?>

                                <option value="<?= (int)$r['id_roles'] ?>">
                                    <?= esc($r['nombre']) ?>
                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="campo">

                        <label for="fldPersona">
                            Persona asociada *
                        </label>

                        <select id="fldPersona"
                                name="persona_id"
                                required>

                            <option value="">
                                Selecciona una persona
                            </option>

                            <?php while ($p = $personas->fetch_assoc()): ?>

                                <option value="<?= (int)$p['id_personas'] ?>">
                                    Persona #<?= (int)$p['id_personas'] ?>
                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="campo">

                        <label for="fldActivo">
                            Estado del usuario
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
const frm = document.getElementById('frmUsuario');
const btnSave = document.getElementById('btnSave');
const msgError = document.getElementById('msgError');

const fldId = document.getElementById('fldId');
const fldNombre = document.getElementById('fldNombre');
const fldUsuario = document.getElementById('fldUsuario');
const fldClave = document.getElementById('fldClave');
const fldRol = document.getElementById('fldRol');
const fldPersona = document.getElementById('fldPersona');
const fldActivo = document.getElementById('fldActivo');

function mostrarError(mensaje) {
    msgError.textContent = mensaje;
    msgError.style.display = 'block';
}

// ABRIR MODAL
function abrirModal(modo, data = {}) {

    frm.reset();

    msgError.style.display = 'none';
    msgError.textContent = '';

    const creando = modo === 'create';

    fldId.value = creando ? '' : data.id_usuarios;

    document.getElementById('dlgTitle').textContent =
        creando ? 'Nuevo usuario' : 'Editar usuario';

    fldNombre.value = data.nombre ?? '';
    fldUsuario.value = data.usuario ?? '';
    fldClave.value = '';
    fldRol.value = data.id_rol ?? '';
    fldPersona.value = data.persona_id ?? '';
    fldActivo.value = String(data.activo ?? 1);

    fldClave.required = creando;

    document.getElementById('claveAyuda').textContent =
        creando
            ? 'Obligatoria para nuevos usuarios.'
            : 'Deja este campo vacío para conservar la contraseña actual.';

    dlg.classList.add('is-open');
    fldNombre.focus();
}

function cerrarModal() {
    dlg.classList.remove('is-open');
}

// PETICIONES AJAX
async function enviar(fd) {

    const response = await fetch('usuarios.php', {
        method: 'POST',
        body: fd
    });

    if (!response.ok) {
        throw new Error('Error HTTP ' + response.status);
    }

    return response.json();
}

// NUEVO USUARIO
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

// EDITAR USUARIO
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
                alert(datos.error || 'Error al cargar el usuario.');
            }

        } catch (e) {
            alert('No se pudieron cargar los datos del usuario.');
        }

    });

});

// ELIMINAR USUARIO
document.querySelectorAll('.btn-delete').forEach(btn => {

    btn.addEventListener('click', async () => {

        if (!confirm('¿Seguro que deseas eliminar este usuario?')) {
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
                alert(datos.error || 'No se pudo eliminar el usuario.');
            }

        } catch (e) {
            alert('Error de conexión al eliminar el usuario.');
        }

    });

});

// GUARDAR USUARIO
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
                datos.error || 'Error al guardar el usuario.'
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
