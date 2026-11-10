<?php
session_start();
require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';

// Proteger la página
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}

$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';
$usuario = $_SESSION['usuario'] ?? '';
$menuActivo = 'condominios';
if (empty($_SESSION['csrf_condominio'])) { $_SESSION['csrf_condominio'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_condominio'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403); exit('Formulario no valido.');
}

/* =========================================
   ENDPOINT AJAX: GET, CREATE Y UPDATE
========================================= */
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {

        // Obtener condominio para editar
        if ($action === 'get') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $conexion->prepare("
                SELECT id_condominios, nombre, descripcion,
                       direccion, ciudad, provincia, estado, activo
                FROM condominio
                WHERE id_condominios = ?
                LIMIT 1
            ");

            $stmt->bind_param("i", $id);
            $stmt->execute();

            $resultado = $stmt->get_result();
            $fila = $resultado->fetch_assoc();

            if (!$fila) {
                throw new Exception('Condominio no encontrado.');
            }

            echo json_encode([
                'ok' => true,
                'data' => $fila
            ]);
            exit;
        }

        // Registrar o actualizar
        if (in_array($action, ['create', 'update'], true)) {

            $id = (int)($_POST['id'] ?? 0);
            $nombreCondominio = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $provincia = trim($_POST['provincia'] ?? '');
            $estado = trim($_POST['estado'] ?? 'Activo');
            $activo = isset($_POST['activo'])
                ? (int)$_POST['activo'] : 1;

            // Validaciones
            if ($nombreCondominio === '' || $direccion === '') {
                throw new Exception(
                    'El nombre y la dirección son obligatorios.'
                );
            }

            if (mb_strlen($nombreCondominio) > 100) {
                throw new Exception(
                    'El nombre no puede superar los 100 caracteres.'
                );
            }

            if (mb_strlen($direccion) > 255) {
                throw new Exception(
                    'La dirección no puede superar los 255 caracteres.'
                );
            }

            if (!in_array($estado, [
                'Activo',
                'En mantenimiento',
                'Inactivo'
            ], true)) {
                throw new Exception('El estado seleccionado no es válido.');
            }

            if (!in_array($activo, [0, 1], true)) {
                throw new Exception('El valor de activo no es válido.');
            }

            if ($action === 'create') {

                $stmt = $conexion->prepare("
                    INSERT INTO condominio
                    (nombre, descripcion, direccion, ciudad,
                     provincia, estado, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "ssssssi",
                    $nombreCondominio,
                    $descripcion,
                    $direccion,
                    $ciudad,
                    $provincia,
                    $estado,
                    $activo
                );

                $stmt->execute();

                echo json_encode([
                    'ok' => true,
                    'msg' => 'Condominio registrado correctamente.'
                ]);
                exit;
            }

            if ($action === 'update') {

                if ($id <= 0) {
                    throw new Exception('ID inválido.');
                }

                $stmt = $conexion->prepare("
                    UPDATE condominio
                    SET nombre = ?,
                        descripcion = ?,
                        direccion = ?,
                        ciudad = ?,
                        provincia = ?,
                        estado = ?,
                        activo = ?
                    WHERE id_condominios = ?
                ");

                $stmt->bind_param(
                    "ssssssii",
                    $nombreCondominio,
                    $descripcion,
                    $direccion,
                    $ciudad,
                    $provincia,
                    $estado,
                    $activo,
                    $id
                );

                $stmt->execute();

                echo json_encode([
                    'ok' => true,
                    'msg' => 'Condominio actualizado correctamente.'
                ]);
                exit;
            }
        }

        echo json_encode([
            'ok' => false,
            'error' => 'Acción no válida.'
        ]);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => $e instanceof mysqli_sql_exception
                ? 'No se pudo completar la operación. Verifica los datos y las relaciones.'
                : $e->getMessage()
        ]);
        exit;
    }
}

/* =========================================
   ELIMINAR CONDOMINIO
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'])) {

    $idEliminar = (int)$_POST['eliminar'];

    if ($idEliminar <= 0) {
        echo "<script>
            alert('ID de condominio inválido.');
            location='condominio.php';
        </script>";
        exit;
    }

    try {
        $stmt = $conexion->prepare("
            DELETE FROM condominio
            WHERE id_condominios = ?
        ");

        $stmt->bind_param("i", $idEliminar);
        $stmt->execute();

        echo "<script>
            alert('Condominio eliminado correctamente.');
            location='condominio.php';
        </script>";
        exit;

    } catch (Throwable $e) {
        echo "<script>
            alert('No se pudo eliminar el condominio. Puede tener registros relacionados.');
            location='condominio.php';
        </script>";
        exit;
    }
}

/* =========================================
   CONSULTAR CONDOMINIOS
========================================= */
$sql = "
    SELECT id_condominios, nombre, descripcion,
           direccion, ciudad, provincia, estado, activo
    FROM condominio
    ORDER BY id_condominios ASC
";

$result = $conexion->query($sql);

if (!$result) {
    die('Error en la consulta: ' . $conexion->error);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mantenimiento de Condominios - Eriad System</title>

    <link rel="stylesheet" href="../estilos/styleP.css?v=6">

</head>

<body>

<div class="diseño">

    <?php include 'menu_lateral.php'; ?>

    <main class="contenido">

        <header class="contenido__superior">

            <h1 class="titulo">Mantenimiento de Condominios</h1>

            <div class="filtros" role="group" aria-label="Usuario actual">
                <span class="filtro filtro--activo">
                    <?= htmlspecialchars($nombre) ?>
                </span>

                <span class="filtro">
                    <?= htmlspecialchars($usuario) ?>
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
                <h2 class="modulo__titulo">Lista de Condominios</h2>

                <button type="button"
                        id="btnNuevo"
                        class="btn-primario">
                    + Añadir Condominio
                </button>
            </div>

            <div class="modulo__cuerpo tabla-contenedor">

                <table class="tabla">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                            <th>Ciudad</th>
                            <th>Provincia</th>
                            <th>Estado</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result && $result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>
                                <td>
                                    <?= (int)$row['id_condominios'] ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['nombre'] ?? '') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['direccion'] ?? '') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['ciudad'] ?? '') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['provincia'] ?? '') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['estado'] ?? '') ?>
                                </td>

                                <td>
                                    <?php if ($row['activo']): ?>
                                        <span class="badge badge--activo">
                                            Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge--inactivo">
                                            Inactivo
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="tabla__acciones">

                                    <button type="button"
                                            class="btn-icono btn-icono--editar btn-edit"
                                            data-id="<?= (int)$row['id_condominios'] ?>"
                                            title="Editar"
                                            aria-label="Editar condominio">
                                        ✎
                                    </button>

                                    <form method="post" action="condominio.php" style="display:inline" onsubmit="return confirm('¿Seguro que deseas eliminar este condominio?');"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_condominio'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="eliminar" value="<?= (int)$row['id_condominios'] ?>"><button type="submit"
                                       class="btn-icono btn-icono--eliminar"
                                       title="Eliminar"
                                       aria-label="Eliminar condominio"
                                       >
                                        🗑️
                                    </button></form>

                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="tabla__vacio">
                                No hay condominios registrados.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>
                </table>

            </div>
        </section>

    </main>
</div>

<!-- =====================================
     MODAL PARA REGISTRAR Y EDITAR
===================================== -->
<div class="modal-backdrop" id="dialog">

    <div class="modal" role="dialog" aria-modal="true"
         aria-labelledby="dlgTitle">

        <div class="modal__header">
            <h3 class="modal__title" id="dlgTitle">
                Nuevo condominio
            </h3>

            <button type="button"
                    class="btn-icono"
                    id="btnClose"
                    aria-label="Cerrar">
                ✖
            </button>
        </div>

        <form id="frmCondominio"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_condominio'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="modal__body">

                <input type="hidden" name="id" id="fldId">

                <div class="formulario-modal">

                    <div class="campo">
                        <label for="fldNombre">Nombre *</label>
                        <input type="text"
                               id="fldNombre"
                               name="nombre"
                               maxlength="100"
                               required>
                    </div>

                    <div class="campo">
                        <label for="fldDireccion">Dirección *</label>
                        <input type="text"
                               id="fldDireccion"
                               name="direccion"
                               maxlength="255"
                               required>
                    </div>

                    <div class="campo">
                        <label for="fldCiudad">Ciudad</label>
                        <input type="text"
                               id="fldCiudad"
                               name="ciudad"
                               maxlength="100">
                    </div>

                    <div class="campo">
                        <label for="fldProvincia">Provincia</label>
                        <input type="text"
                               id="fldProvincia"
                               name="provincia"
                               maxlength="100">
                    </div>

                    <div class="campo">
                        <label for="fldEstado">Estado del condominio</label>
                        <select id="fldEstado" name="estado">
                            <option value="Activo">Activo</option>
                            <option value="En mantenimiento">En mantenimiento</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="fldActivo">Disponible en el sistema</label>
                        <select id="fldActivo" name="activo">
                            <option value="1">Sí, activo</option>
                            <option value="0">No, inactivo</option>
                        </select>
                    </div>

                    <div class="campo campo-completo">
                        <label for="fldDescripcion">Descripción</label>
                        <textarea id="fldDescripcion"
                                  name="descripcion"
                                  placeholder="Detalles del condominio (opcional)"></textarea>
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
const btnNuevo = document.getElementById('btnNuevo');
const btnClose = document.getElementById('btnClose');
const btnCancel = document.getElementById('btnCancel');
const frm = document.getElementById('frmCondominio');
const btnSave = document.getElementById('btnSave');
const msgError = document.getElementById('msgError');
const title = document.getElementById('dlgTitle');

const fldId = document.getElementById('fldId');
const fldNombre = document.getElementById('fldNombre');
const fldDescripcion = document.getElementById('fldDescripcion');
const fldDireccion = document.getElementById('fldDireccion');
const fldCiudad = document.getElementById('fldCiudad');
const fldProvincia = document.getElementById('fldProvincia');
const fldEstado = document.getElementById('fldEstado');
const fldActivo = document.getElementById('fldActivo');

function mostrarError(mensaje) {
    msgError.textContent = mensaje;
    msgError.style.display = 'block';
}

function abrirModal(modo, data = {}) {
    frm.reset();

    msgError.textContent = '';
    msgError.style.display = 'none';

    if (modo === 'create') {
        title.textContent = 'Nuevo condominio';
        fldId.value = '';
        fldEstado.value = 'Activo';
        fldActivo.value = '1';
    } else {
        title.textContent = 'Editar condominio';

        fldId.value = data.id_condominios;
        fldNombre.value = data.nombre || '';
        fldDescripcion.value = data.descripcion || '';
        fldDireccion.value = data.direccion || '';
        fldCiudad.value = data.ciudad || '';
        fldProvincia.value = data.provincia || '';
        fldEstado.value = data.estado || 'Activo';
        fldActivo.value = String(data.activo ?? '1');
    }

    dlg.classList.add('is-open');
    fldNombre.focus();
}

function cerrarModal() {
    dlg.classList.remove('is-open');
}

btnNuevo.addEventListener('click', () => {
    abrirModal('create');
});

btnClose.addEventListener('click', cerrarModal);
btnCancel.addEventListener('click', cerrarModal);

dlg.addEventListener('click', e => {
    if (e.target === dlg) {
        cerrarModal();
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModal();
    }
});

// Cargar los datos del condominio seleccionado
document.querySelectorAll('.btn-edit').forEach(btn => {

    btn.addEventListener('click', async () => {

        const fd = new FormData();
        fd.append('csrf', <?= json_encode($_SESSION['csrf_condominio']) ?>);
        fd.append('ajax', '1');
        fd.append('action', 'get');
        fd.append('id', btn.dataset.id);

        try {
            const respuesta = await fetch('condominio.php', {
                method: 'POST',
                body: fd
            });

            const datos = await respuesta.json();

            if (datos.ok) {
                abrirModal('edit', datos.data);
            } else {
                alert(datos.error || 'Error al cargar el condominio.');
            }

        } catch (error) {
            alert('No se pudieron cargar los datos del condominio.');
        }
    });
});

// Registrar o actualizar condominio
frm.addEventListener('submit', async e => {

    e.preventDefault();

    if (!frm.reportValidity()) {
        return;
    }

    if (!fldNombre.value.trim() || !fldDireccion.value.trim()) {
        mostrarError('El nombre y la dirección son obligatorios.');
        return;
    }

    const esEdicion = fldId.value !== '';
    const fd = new FormData(frm);

    fd.append('ajax', '1');
    fd.append('action', esEdicion ? 'update' : 'create');

    btnSave.disabled = true;
    btnSave.textContent = 'Guardando...';

    try {
        const respuesta = await fetch('condominio.php', {
            method: 'POST',
            body: fd
        });

        const datos = await respuesta.json();

        if (datos.ok) {
            location.reload();
        } else {
            mostrarError(datos.error || 'Error al guardar el condominio.');
        }

    } catch (error) {
        mostrarError('No se pudo completar la operación. Verifica la conexión.');
    } finally {
        btnSave.disabled = false;
        btnSave.textContent = 'Guardar';
    }
});
</script>

</body>
</html>
