<?php
function ecf_validar_peticion(): void
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
        http_response_code(413);
        throw new InvalidArgumentException('La solicitud fiscal excede el tamaño permitido.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('Use POST para esta operación.');
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['ecf_csrf']) || !hash_equals($_SESSION['ecf_csrf'], $token)) {
        http_response_code(403);
        throw new InvalidArgumentException('La sesión del formulario venció. Recargue la página.');
    }
}

// Validaciones compartidas; no asigna secuencias fiscales.
function ecf_centavos($valor): int
{
    $texto = (string)$valor;
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $texto)) {
        throw new InvalidArgumentException('Importe inválido: use hasta dos decimales positivos.');
    }
    $partes = explode('.', $texto);
    $centavos = (int)$partes[0] * 100 + (int)str_pad($partes[1] ?? '', 2, '0');
    if ($centavos <= 0) throw new InvalidArgumentException('El importe debe ser mayor que cero.');
    return $centavos;
}

function ecf_url_segura(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
}

function ecf_validar_configuracion(array $cfg, string $tipo): void
{
    if (($cfg['ambiente'] ?? '') !== 'pruebas' || empty($cfg['contrato_validado']) || empty($cfg['emisor_confirmado'])) {
        throw new InvalidArgumentException('Confirme el contrato, el ambiente de pruebas y los datos del emisor antes de enviar.');
    }
    if (!in_array($tipo, ['E31', 'E32', 'E34'], true) || !in_array($tipo, $cfg['tipos_autorizados'] ?? [], true)) {
        throw new InvalidArgumentException('Tipo fiscal no confirmado como autorizado para este emisor.');
    }
    if (!ecf_url_segura($cfg['base_url']) || empty($cfg['api_key']) || preg_match('/[\r\n]/', $cfg['api_key'])) {
        throw new InvalidArgumentException('Configure HTTPS y una credencial fiscal válida.');
    }
    $e = $cfg['emisor'];
    if (!preg_match('/^(?:\d{9}|\d{11})$/D', $e['RNCEmisor'] ?? '')) throw new InvalidArgumentException('Configure la identificación del emisor confirmado.');
    foreach (['RazonSocialEmisor', 'DireccionEmisor', 'TelefonoEmisor', 'CorreoEmisor'] as $campo) {
        if (trim($e[$campo] ?? '') === '') throw new InvalidArgumentException('Complete los datos fiscales del emisor.');
    }
    if (!filter_var($e['CorreoEmisor'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo del emisor inválido.');
}

function ecf_validar_nota(array $nota, array $lineas, ?array $comprador): void
{
    if (!preg_match('/^E3[12]\d{10}$/D', $nota['encf_modificado'] ?? '')) throw new InvalidArgumentException('La nota requiere el e-NCF original E31 o E32.');
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $nota['fecha_encf_modificado'] ?? '');
    if (!$fecha || $fecha->format('Y-m-d') !== $nota['fecha_encf_modificado'] || $fecha > ecf_ahora()) {
        throw new InvalidArgumentException('Fecha del documento original inválida.');
    }
    if (!in_array((int)($nota['codigo_modificacion'] ?? 0), [1, 3], true)) throw new InvalidArgumentException('Código de modificación no permitido.');
    if (!$lineas) throw new InvalidArgumentException('La nota necesita conceptos.');
    foreach ($lineas as $linea) {
        if (!is_int($linea['centavos'] ?? null) || $linea['centavos'] <= 0 || trim($linea['descripcion'] ?? '') === '') {
            throw new InvalidArgumentException('Concepto de nota inválido.');
        }
    }
    if ($comprador !== null && (!ecf_rnc_normalizar($comprador['RNCComprador'] ?? null) || trim($comprador['RazonSocialComprador'] ?? '') === '')) {
        throw new InvalidArgumentException('Comprador fiscal incompleto.');
    }
}

function ecf_auditar_inicio(mysqli $db, int $facturaId, ?int $notaId, array $payload): int
{
    $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $q = $db->prepare('INSERT INTO ecf_envio (factura_id,nota_id,payload_sha256,estado) VALUES (?,?,?,\'ENVIANDO\')');
    $q->bind_param('iis', $facturaId, $notaId, $hash); $q->execute();
    return (int)$db->insert_id;
}

function ecf_auditar_respuesta(mysqli $db, int $id, array $respuesta, string $clave): void
{
    // Respuesta privada en BD; nunca se devuelve íntegra al navegador.
    $limpiar = function ($valor, string $campo = '') use (&$limpiar, $clave) {
        if (preg_match('/key|token|secret|authorization|password/i', $campo)) return '[REDACTADO]';
        if (is_array($valor)) { foreach ($valor as $k => $v) $valor[$k] = $limpiar($v, (string)$k); return $valor; }
        return is_string($valor) && $clave !== '' ? str_replace($clave, '[REDACTADO]', $valor) : $valor;
    };
    // Un cuerpo no JSON puede incluir páginas de diagnóstico privadas; conservar solo hash y longitud.
    $body = $respuesta['datos'] ?: ['cuerpo_sha256' => hash('sha256', $respuesta['raw']), 'bytes' => strlen($respuesta['raw'])];
    $json = json_encode($limpiar($body), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 1048576) $json = json_encode(['respuesta_sha256' => hash('sha256', $json), 'excede_limite' => true]);
    $estado = ecf_normalizar_respuesta($respuesta)['estado']; $http = $respuesta['codigo'];
    $q = $db->prepare('UPDATE ecf_envio SET http_codigo=?,respuesta_json=?,estado=?,respondido_en=NOW() WHERE id_envio=?');
    $q->bind_param('issi', $http, $json, $estado, $id); $q->execute();
}
