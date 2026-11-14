<?php
require_once __DIR__ . '/factura_render.php';

function factura_documento_guardar(mysqli $db, array $factura, array $lineas, ?array $nota = null): string
{
    $bytes = factura_render($factura, $lineas, $nota);
    $tipo = $nota ? 'nota' : 'factura';
    $id = (int)($nota['id_factura_ecf_nota'] ?? $factura['id_facturas']);
    $fid = (int)$factura['id_facturas'];
    $hash = hash('sha256', $bytes);
    $st = $db->prepare('INSERT INTO factura_documento (tipo,documento_id,factura_id,contenido,sha256) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE contenido=VALUES(contenido),sha256=VALUES(sha256)');
    $st->bind_param('siiss', $tipo, $id, $fid, $bytes, $hash);
    $st->execute();
    $st->close();
    // Cloud SQL es la copia durable; Storage es una réplica privada opcional.
    $bucket = getenv('INVOICE_PDF_BUCKET') ?: '';
    if ($bucket !== '') {
        try { factura_documento_storage($bucket, $tipo . '/' . $id . '/' . $hash . '.pdf', $bytes); }
        catch (Throwable $e) { error_log('VIORA PDF Storage: réplica pendiente; copia disponible en SQL.'); }
    }
    return $bytes;
}

function factura_documento_storage(string $bucket, string $objeto, string $bytes): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $bucket)) throw new RuntimeException('Bucket inválido');
    $ch = curl_init('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'], CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_PROXY => '']);
    $token = json_decode((string)curl_exec($ch), true);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($status !== 200 || empty($token['access_token'])) throw new RuntimeException('Identidad no disponible');
    // El hash forma parte de la ruta: no se sobrescriben documentos anteriores.
    $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($bucket) . '/o?uploadType=media&ifGenerationMatch=0&name=' . rawurlencode($objeto);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $bytes, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/pdf'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!in_array($status, [200, 201, 412], true)) throw new RuntimeException('Réplica no confirmada');
}

function factura_documento_generar(mysqli $db, int $id): string
{
    $st = $db->prepare('SELECT f.*, p.nombres,p.apellidos,p.cedula,p.telefono, ct.numero_contrato,a.numero AS apto,e.nombre AS edificio,fe.encf,fe.estado AS ecf_estado,fe.codigo_seguridad,fe.dgii_url,fe.fecha_emision_ecf,fe.tipo_ecf FROM factura f LEFT JOIN persona p ON p.id_personas=f.persona_id LEFT JOIN contrato ct ON ct.id_contratos=f.contrato_id LEFT JOIN apartamento a ON a.id_apartamentos=ct.apartamento_id LEFT JOIN edificio e ON e.id_edificios=a.edificio_id LEFT JOIN factura_ecf fe ON fe.factura_id=f.id_facturas WHERE f.id_facturas=?');
    $st->bind_param('i', $id); $st->execute();
    $f = $st->get_result()->fetch_assoc(); $st->close();
    if (!$f) throw new RuntimeException('Factura no encontrada.');
    $st = $db->prepare('SELECT concepto,descripcion,periodo,monto FROM factura_detalle WHERE factura_id=? AND activo=1 ORDER BY id_detalles');
    $st->bind_param('i', $id); $st->execute();
    $lineas = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    return factura_documento_guardar($db, $f, $lineas);
}
