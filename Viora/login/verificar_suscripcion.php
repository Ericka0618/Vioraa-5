<?php
// login/verificar_suscripcion.php

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();

header('Content-Type: application/json; charset=utf-8');

// ========================================
// 1. RESPUESTAS JSON
// ========================================

function responder($codigo, $mensaje, $success = false)
{
    http_response_code($codigo);

    echo json_encode([
        'success' => $success,
        'message' => $mensaje
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ========================================
// 2. VALIDAR SOLICITUD
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, 'Método no permitido.');
}

$idUsuario = (int) (
    $_SESSION['registro_pendiente_usuario'] ?? 0
);

if ($idUsuario <= 0) {
    responder(401, 'Tu sesión ha expirado.');
}

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($datos)) {
    responder(400, 'Los datos recibidos no son válidos.');
}

$subscriptionId = $datos['subscriptionID'] ?? '';

if (
    !is_string($subscriptionId) ||
    !preg_match('/^I-[A-Z0-9]+$/i', $subscriptionId)
) {
    responder(400, 'Identificador de suscripción inválido.');
}

// ========================================
// 3. CONFIGURACIÓN
// ========================================

$planEsperado = 'P-2GW66466MT116720LNLIDS2A';

$rutaConexion = __DIR__ . '/../conf/conexion.php';

$rutaConfig = 'C:/xampp/paypal_private/paypal_config.php';

if (!is_file($rutaConexion)) {
    responder(500, 'No se encontró la conexión de MySQL.');
}

if (!is_file($rutaConfig)) {
    responder(
        500,
        'No se encontró la configuración privada de PayPal.'
    );
}

// ========================================
// 4. FUNCIONES DE PAYPAL
// ========================================

function peticionPayPal(
    $url,
    $clientId,
    $clientSecret,
    $token = null
) {
    $curl = curl_init($url);

    $headers = [
        'Accept: application/json'
    ];

    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ];

    if ($token === null) {
        $opciones[CURLOPT_POST] = true;
        $opciones[CURLOPT_USERPWD] =
            $clientId . ':' . $clientSecret;

        $opciones[CURLOPT_POSTFIELDS] =
            'grant_type=client_credentials';

        $headers[] =
            'Content-Type: application/x-www-form-urlencoded';
    } else {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $opciones[CURLOPT_HTTPHEADER] = $headers;

    curl_setopt_array($curl, $opciones);

    $respuesta = curl_exec($curl);

    if ($respuesta === false) {
        $error = curl_error($curl);
        curl_close($curl);

        throw new Exception(
            'Error de conexión con PayPal: ' . $error
        );
    }

    $codigo = curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    $contenido = json_decode(
        $respuesta,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if ($codigo < 200 || $codigo >= 300) {
        throw new Exception(
            'PayPal respondió con HTTP ' . $codigo
        );
    }

    return $contenido;
}

// ========================================
// 5. VERIFICAR SUSCRIPCIÓN
// ========================================

$conexion = null;
$transaccionIniciada = false;

try {

    require $rutaConexion;

    $config = require $rutaConfig;

    if (
        !is_array($config) ||
        empty($config['client_id']) ||
        empty($config['client_secret'])
    ) {
        throw new Exception(
            'Configuración privada de PayPal incompleta.'
        );
    }

    if (
        !isset($conexion) ||
        !($conexion instanceof mysqli)
    ) {
        throw new Exception(
            'La conexión de MySQL no es válida.'
        );
    }

    $apiUrl = rtrim(
        $config['api_url'] ??
        'https://api-m.sandbox.paypal.com',
        '/'
    );

    // Solo permitir el entorno Sandbox.
    if (
        $apiUrl !==
        'https://api-m.sandbox.paypal.com'
    ) {
        throw new Exception(
            'La URL de PayPal Sandbox no es válida.'
        );
    }

    // Obtener token OAuth.
    $autenticacion = peticionPayPal(
        $apiUrl . '/v1/oauth2/token',
        $config['client_id'],
        $config['client_secret']
    );

    $token = $autenticacion['access_token'] ?? null;

    if (!$token) {
        throw new Exception(
            'No se recibió el token de PayPal.'
        );
    }

    // Consultar la suscripción en PayPal.
    $suscripcion = peticionPayPal(
        $apiUrl .
        '/v1/billing/subscriptions/' .
        rawurlencode($subscriptionId),
        $config['client_id'],
        $config['client_secret'],
        $token
    );

    // Comprobar identificador.
    if (
        ($suscripcion['id'] ?? '') !==
        $subscriptionId
    ) {
        responder(
            400,
            'La suscripción no coincide con la solicitud.'
        );
    }

    // Comprobar plan.
    if (
        ($suscripcion['plan_id'] ?? '') !==
        $planEsperado
    ) {
        responder(
            400,
            'La suscripción no pertenece al plan VIORA.'
        );
    }

    // Comprobar estado.
    $estadoPayPal = $suscripcion['status'] ?? '';

    if ($estadoPayPal !== 'ACTIVE') {
        responder(
            409,
            'La suscripción todavía no está activa en PayPal.'
        );
    }

    // ========================================
    // 6. COMPROBAR PRIMER PAGO
    // ========================================

    $ultimoPago =
        $suscripcion['billing_info']['last_payment']
        ?? null;

    $montoPago =
        $ultimoPago['amount']['value'] ?? null;

    $monedaPago =
        $ultimoPago['amount']['currency_code'] ?? null;

    $fechaPago =
        $ultimoPago['time'] ?? null;

    $pagoConfirmado =
        !empty($fechaPago) &&
        $monedaPago === 'USD' &&
        is_numeric($montoPago) &&
        (float) $montoPago >= 10.00;

    // Fechas de PayPal.
    $fechaInicio = null;
    $fechaProximoPago = null;

    if (!empty($suscripcion['start_time'])) {
        $fechaInicio = gmdate(
            'Y-m-d H:i:s',
            strtotime($suscripcion['start_time'])
        );
    }

    if (
        !empty(
            $suscripcion['billing_info']['next_billing_time']
        )
    ) {
        $fechaProximoPago = gmdate(
            'Y-m-d H:i:s',
            strtotime(
                $suscripcion['billing_info']['next_billing_time']
            )
        );
    }

    // ========================================
    // 7. GUARDAR EN MYSQL
    // ========================================

    $conexion->begin_transaction();
    $transaccionIniciada = true;

    // Comprobar usuario.
    $consulta = $conexion->prepare(
        "SELECT id_usuarios
         FROM usuario
         WHERE id_usuarios = ?
         FOR UPDATE"
    );

    $consulta->bind_param('i', $idUsuario);
    $consulta->execute();
    $consulta->store_result();

    if ($consulta->num_rows !== 1) {
        $consulta->close();

        throw new Exception(
            'El usuario no existe en la base de datos.'
        );
    }

    $consulta->close();

    // Evitar reutilizar una suscripción ajena.
    $consulta = $conexion->prepare(
        "SELECT usuario_id
         FROM suscripcion
         WHERE paypal_subscription_id = ?
         LIMIT 1"
    );

    $consulta->bind_param('s', $subscriptionId);
    $consulta->execute();

    $consulta->bind_result($propietario);

    if ($consulta->fetch()) {
        if ((int) $propietario !== $idUsuario) {
            $consulta->close();

            $conexion->rollback();
            $transaccionIniciada = false;

            responder(
                409,
                'Esta suscripción pertenece a otra cuenta.'
            );
        }
    }

    $consulta->close();

    $estadoLocal = $pagoConfirmado
        ? 'Activo'
        : 'Pendiente de pago';

    // Registrar o actualizar la suscripción.
    $consulta = $conexion->prepare(
        "INSERT INTO suscripcion
        (
            usuario_id,
            paypal_subscription_id,
            paypal_plan_id,
            monto,
            moneda,
            estado,
            fecha_inicio,
            fecha_proximo_pago
        )
        VALUES (?, ?, ?, 10.00, 'USD', ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            paypal_plan_id = VALUES(paypal_plan_id),
            estado = VALUES(estado),
            fecha_inicio = VALUES(fecha_inicio),
            fecha_proximo_pago = VALUES(fecha_proximo_pago)"
    );

    $consulta->bind_param(
        'isssss',
        $idUsuario,
        $subscriptionId,
        $planEsperado,
        $estadoLocal,
        $fechaInicio,
        $fechaProximoPago
    );

    $consulta->execute();
    $consulta->close();

    // ========================================
    // 8. ACTIVAR CUENTA SI HAY PAGO
    // ========================================

    if ($pagoConfirmado) {

        $consulta = $conexion->prepare(
            "UPDATE usuario
             SET activo = 1,
                 estado_cuenta = 'Activo'
             WHERE id_usuarios = ?"
        );

        $consulta->bind_param('i', $idUsuario);
        $consulta->execute();
        $consulta->close();
    }

    $conexion->commit();
    $transaccionIniciada = false;

    // ========================================
    // 9. RESPUESTA FINAL
    // ========================================

    if ($pagoConfirmado) {

        unset(
            $_SESSION['registro_pendiente_usuario']
        );

        responder(
            200,
            'Suscripción verificada. Tu cuenta VIORA está activa.',
            true
        );
    }

    responder(
        202,
        'Tu suscripción está activa en PayPal. Estamos esperando la confirmación del primer pago.'
    );

} catch (Throwable $e) {

    if (
        $transaccionIniciada &&
        $conexion instanceof mysqli
    ) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    error_log(
        '[VIORA PayPal] ' . $e->getMessage()
    );

    responder(
        500,
        'Ocurrió un error al verificar PayPal. Revisa el registro de errores de PHP.'
    );
}
