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
$menuActivo = 'edificios';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'get') {
            if ($id <= 0) throw new Exception('ID inválido.');
            $stmt = $conexion->prepare('SELECT id_edificios, condominio_id, nombre, cantidad_pisos, estado, activo FROM edificio WHERE id_edificios = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            if (!$fila) throw new Exception('Edificio no encontrado.');
            echo json_encode(['ok' => true, 'data' => $fila]); 
            exit;
        }

        if ($action === 'delete') {
            if ($id <= 0) throw new Exception('ID inválido.');
            $stmt = $conexion->prepare('DELETE FROM edificio WHERE id_edificios = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            echo json_encode(['ok' => true, 'msg' => 'Edificio eliminado correctamente.']); 
            exit;
        }

        if (!in_array($action, ['create', 'update'], true)) throw new Exception('Acción no válida.');

        $condominioId = filter_var($_POST['condominio_id'] ?? null, FILTER_VALIDATE_INT);
        $nombreEdificio = trim($_POST['nombre'] ?? '');
        $pisosTexto = trim((string)($_POST['cantidad_pisos'] ?? ''));
        $pisos = $pisosTexto === '' ? null : filter_var($pisosTexto, FILTER_VALIDATE_INT);
        $estado = trim($_POST['estado'] ?? 'Activo');
        $activo = filter_var($_POST['activo'] ?? '1', FILTER_VALIDATE_INT);

        if (!$condominioId || $condominioId <= 0) throw new Exception('Selecciona un condominio válido.');
        if ($nombreEdificio === '' || mb_strlen($nombreEdificio) > 100) throw new Exception('El nombre es obligatorio y debe tener máximo 100 caracteres.');
        if ($pisosTexto !== '' && ($pisos === false || $pisos <= 0)) throw new Exception('La cantidad de pisos debe ser un número entero mayor que cero.');
        if (!in_array($estado, ['Activo', 'En mantenimiento', 'Inactivo'], true)) throw new Exception('Estado inválido.');
        if (!in_array($activo, [0, 1], true)) throw new Exception('Disponibilidad inválida.');

        $check = $conexion->prepare('SELECT id_condominios FROM condominio WHERE id_condominios = ? LIMIT 1');
        $check->bind_param('i', $condominioId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) throw new Exception('El condominio seleccionado no existe.');

        if ($action === 'create') {
            $stmt = $conexion->prepare('INSERT INTO edificio (condominio_id, nombre, cantidad_pisos, estado, activo) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('isisi', $condominioId, $nombreEdificio, $pisos, $estado, $activo);
        } else {
            if ($id <= 0) throw new Exception('ID inválido.');
            $stmt = $conexion->prepare('UPDATE edificio SET condominio_id = ?, nombre = ?, cantidad_pisos = ?, estado = ?, activo = ? WHERE id_edificios = ?');
            $stmt->bind_param('isisii', $condominioId, $nombreEdificio, $pisos, $estado, $activo, $id);
        }

        $stmt->execute();
        echo json_encode(['ok' => true, 'msg' => $action === 'create' ? 'Edificio registrado correctamente.' : 'Edificio actualizado correctamente.']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e instanceof mysqli_sql_exception ? 'No se pudo completar la operación. Comprueba las relaciones con otros registros.' : $e->getMessage()]);
    }
    exit;
}

$condominios = $conexion->query('SELECT id_condominios, nombre FROM condominio ORDER BY nombre ASC');
$result = $conexion->query('SELECT e.id_edificios, e.condominio_id, e.nombre, e.cantidad_pisos, e.estado, e.activo, c.nombre AS nombre_condominio FROM edificio e INNER JOIN condominio c ON c.id_condominios = e.condominio_id ORDER BY e.id_edificios ASC');

function esc($valor) { 
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimiento de Edificios - VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=6">
</head>
<body>
    <div class="diseño">
        <?php include 'menu_lateral.php'; ?>
        <main class="contenido">
            <header class="contenido__superior">
                <h1 class="titulo">Mantenimiento de Edificios</h1>
                <div class="filtros" role="group" aria-label="Usuario actual">
                    <span class="filtro filtro--activo"><?= esc($nombre) ?></span>
                    <span class="filtro"><?= esc($usuario) ?></span>
                    <a href="../login/logout.php" class="filtro" style="text-decoration:none;">Cerrar sesión</a>
                </div>
            </header>

            <section class="modulo">
                <div class="modulo__header">
                    <h2 class="modulo__titulo">Lista de Edificios</h2>
                    <button type="button" id="btnNuevo" class="btn-primario">+ Añadir Edificio</button>
                </div>
                <div class="modulo__cuerpo tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Condominio</th>
                                <th>Cantidad de pisos</th>
                                <th>Estado</th>
                                <th>Activo</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= (int)$row['id_edificios'] ?></td>
                                        <td><?= esc($row['nombre']) ?></td>
                                        <td><?= esc($row['nombre_condominio']) ?></td>
                                        <td><?= $row['cantidad_pisos'] === null ? '—' : (int)$row['cantidad_pisos'] ?></td>
                                        <td><?= esc($row['estado']) ?></td>
                                        <td>
                                            <span class="badge <?= $row['activo'] ? 'badge--activo' : 'badge--inactivo' ?>">
                                                <?= $row['activo'] ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td class="tabla__acciones">
                                            <button type="button" class="btn-icono btn-icono--editar btn-edit" data-id="<?= (int)$row['id_edificios'] ?>" title="Editar" aria-label="Editar edificio">✎</button>
                                            <button type="button" class="btn-icono btn-icono--eliminar btn-delete" data-id="<?= (int)$row['id_edificios'] ?>" title="Eliminar" aria-label="Eliminar edificio">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="tabla__vacio">No hay edificios registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="modal-backdrop" id="dialog">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dlgTitle">
            <div class="modal__header">
                <h3 class="modal__title" id="dlgTitle">Nuevo edificio</h3>
                <button type="button" class="btn-icono" id="btnClose" aria-label="Cerrar">✖</button>
            </div>
            <form id="frmEdificio">
                <div class="modal__body">
                    <input type="hidden" name="id" id="fldId">
                    <div class="formulario-modal">
                        <div class="campo">
                            <label for="fldCondominio">Condominio *</label>
                            <select id="fldCondominio" name="condominio_id" required>
                                <option value="">Selecciona un condominio</option>
                                <?php while ($c = $condominios->fetch_assoc()): ?>
                                    <option value="<?= (int)$c['id_condominios'] ?>"><?= esc($c['nombre']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="fldNombre">Nombre del edificio *</label>
                            <input type="text" id="fldNombre" name="nombre" maxlength="100" required>
                        </div>
                        <div class="campo">
                            <label for="fldPisos">Cantidad de pisos</label>
                            <input type="number" id="fldPisos" name="cantidad_pisos" min="1" step="1" placeholder="Ej. 5">
                        </div>
                        <div class="campo">
                            <label for="fldEstado">Estado del edificio</label>
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
                    </div>
                    <p id="msgError" class="mensaje-error" style="display:none;"></p>
                </div>
                <div class="modal__footer">
                    <button type="button" class="btn-secundario" id="btnCancel">Cancelar</button>
                    <button type="submit" class="btn-primario" id="btnSave">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const dlg = document.getElementById('dialog');
        const frm = document.getElementById('frmEdificio');
        const btnSave = document.getElementById('btnSave');
        const msgError = document.getElementById('msgError');
        const fldId = document.getElementById('fldId');
        const fldCondominio = document.getElementById('fldCondominio');
        const fldNombre = document.getElementById('fldNombre');
        const fldPisos = document.getElementById('fldPisos');
        const fldEstado = document.getElementById('fldEstado');
        const fldActivo = document.getElementById('fldActivo');

        function mostrarError(mensaje) { 
            msgError.textContent = mensaje; 
            msgError.style.display = 'block'; 
        }

        function abrirModal(modo, data = {}) {
            frm.reset(); 
            msgError.style.display = 'none'; 
            msgError.textContent = '';
            fldId.value = modo === 'create' ? '' : data.id_edificios;
            document.getElementById('dlgTitle').textContent = modo === 'create' ? 'Nuevo edificio' : 'Editar edificio';
            fldCondominio.value = data.condominio_id ?? '';
            fldNombre.value = data.nombre ?? '';
            fldPisos.value = data.cantidad_pisos ?? '';
            fldEstado.value = data.estado ?? 'Activo';
            fldActivo.value = String(data.activo ?? 1);
            dlg.classList.add('is-open'); 
            fldCondominio.focus();
        }

        function cerrarModal() { 
            dlg.classList.remove('is-open'); 
        }

        async function enviar(fd) {
            const response = await fetch('Edificio.php', { method: 'POST', body: fd });
            if (!response.ok) throw new Error('Error HTTP ' + response.status);
            return response.json();
        }

        document.getElementById('btnNuevo').addEventListener('click', () => abrirModal('create'));
        document.getElementById('btnClose').addEventListener('click', cerrarModal);
        document.getElementById('btnCancel').addEventListener('click', cerrarModal);
        dlg.addEventListener('click', e => { if (e.target === dlg) cerrarModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });

        document.querySelectorAll('.btn-edit').forEach(btn => btn.addEventListener('click', async () => {
            const fd = new FormData(); 
            fd.append('ajax', '1'); 
            fd.append('action', 'get'); 
            fd.append('id', btn.dataset.id);
            try { 
                const datos = await enviar(fd); 
                if (datos.ok) abrirModal('edit', datos.data); 
                else alert(datos.error || 'Error al cargar el edificio.'); 
            } catch (e) { 
                alert('No se pudieron cargar los datos del edificio.'); 
            }
        }));

        document.querySelectorAll('.btn-delete').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('¿Seguro que deseas eliminar este edificio?')) return;
            const fd = new FormData(); 
            fd.append('ajax', '1'); 
            fd.append('action', 'delete'); 
            fd.append('id', btn.dataset.id);
            try { 
                const datos = await enviar(fd); 
                if (datos.ok) location.reload(); 
                else alert(datos.error || 'No se pudo eliminar el edificio.'); 
            } catch (e) { 
                alert('Error de conexión al eliminar el edificio.'); 
            }
        }));

        frm.addEventListener('submit', async e => {
            e.preventDefault(); 
            if (!frm.reportValidity()) return;
            const fd = new FormData(frm); 
            fd.append('ajax', '1'); 
            fd.append('action', fldId.value ? 'update' : 'create');
            btnSave.disabled = true; 
            btnSave.textContent = 'Guardando...'; 
            msgError.style.display = 'none';
            try { 
                const datos = await enviar(fd); 
                if (datos.ok) location.reload(); 
                else mostrarError(datos.error || 'Error al guardar el edificio.'); 
            } catch (e) { 
                mostrarError('No se pudo completar la operación. Verifica la conexión.'); 
            } finally { 
                btnSave.disabled = false; 
                btnSave.textContent = 'Guardar'; 
            }
        });
    </script>
</body>
</html>