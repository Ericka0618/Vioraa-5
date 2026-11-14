<?php
// PDF de una factura o de una nota de crédito (solo administrador).
// Uso: factura_pdf.php?id=X (factura) o factura_pdf.php?nota=X (nota E34).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/ecf_service.php';
require_once __DIR__ . '/pdf_simple.php';
require_once __DIR__ . '/qr_simple.php';

if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
$idFactura = (int)($_GET['id'] ?? 0);
$idNota = (int)($_GET['nota'] ?? 0);

try {
    require_once __DIR__ . '/factura_acceso.php';
    $identidad = factura_identidad($conexion);
    $nota = null;
    if ($idNota > 0) {
        $st = $conexion->prepare('SELECT * FROM factura_ecf_nota WHERE id_factura_ecf_nota = ? LIMIT 1');
        $st->bind_param('i', $idNota);
        $st->execute();
        $nota = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$nota) {
            http_response_code(404); exit('Nota de crédito no encontrada.');
        }
        $idFactura = (int)$nota['factura_id'];
    }

    if ($idFactura <= 0) {
        http_response_code(400); exit('Factura no válida.');
    }

    $st = $conexion->prepare("SELECT f.*, p.nombres, p.apellidos, p.cedula, p.telefono,
                                     ct.numero_contrato, a.numero AS apto, e.nombre AS edificio,
                                     fe.encf, fe.estado AS ecf_estado, fe.codigo_seguridad, fe.dgii_url,
                                     fe.fecha_emision_ecf, fe.tipo_ecf
                              FROM factura f
                              LEFT JOIN persona p ON p.id_personas = f.persona_id
                              LEFT JOIN contrato ct ON ct.id_contratos = f.contrato_id
                              LEFT JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id
                              LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                              LEFT JOIN factura_ecf fe ON fe.factura_id = f.id_facturas
                              WHERE f.id_facturas = ? LIMIT 1");
    $st->bind_param('i', $idFactura);
    $st->execute();
    $factura = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$factura || (!$identidad['admin'] && (int)$factura['persona_id'] !== $identidad['persona_id'])) {
        http_response_code(404); exit('Factura no encontrada.');
    }

    $st = $conexion->prepare("SELECT concepto, descripcion, periodo, monto FROM factura_detalle
                              WHERE factura_id = ? AND activo = 1 ORDER BY id_detalles");
    $st->bind_param('i', $idFactura);
    $st->execute();
    $lineas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

} catch (Throwable $error) {
    error_log('factura_pdf: ' . $error->getMessage());
    http_response_code(500); exit('No se pudo generar el PDF.');
}

require_once __DIR__ . '/factura_documento.php';
try { $contenido = factura_documento_guardar($conexion, $factura, $lineas, $nota); }
catch (Throwable $e) { error_log('factura_documento: ' . $e->getMessage()); http_response_code(503); exit('Documento temporalmente no disponible.'); }
$ecf = ['encf' => $nota['encf'] ?? ''];
$nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($nota ? $ecf['encf'] : $factura['numero_documento']));

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . ($nota ? 'NotaCredito-' : 'Factura-') . $nombreArchivo . '.pdf"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $contenido;
