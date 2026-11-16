<?php
/**
 * Servicio ECF Demo - Genera comprobantes de ejemplo para pruebas
 * Cuando se obtengan credenciales reales, cambiar a ecf_service_real.php
 */

function ecf_generar_comprobante_demo($tipo, $datos_factura) {
    /**
     * Genera número de comprobante ficticio para pruebas
     * Formato: E31-2026-000001 (tipo-año-número)
     */
    $año = date('Y');
    $numero = str_pad(rand(1, 9999), 6, '0', STR_PAD_LEFT);

    return [
        'estado' => 'aprobado_demo',
        'encf' => $tipo . '-' . $año . '-' . $numero,
        'ecf_api_id' => 'DEMO_' . uniqid(),
        'fecha_emision' => date('Y-m-d H:i:s'),
        'fecha_envio' => date('Y-m-d H:i:s'),
        'intentos' => 1,
        'tipo_ecf' => $tipo,
        'firma_digital' => hash('sha256', json_encode($datos_factura)),
        'modo' => 'demo_pruebas',
        'mensaje' => 'Este es un comprobante de prueba generado en modo demo. Para producción, se requieren credenciales IMECF reales.'
    ];
}

function ecf_enviar_a_imecf_demo($comprobante_data) {
    /**
     * Simula envío a IMECF
     * En producción, esto haría una llamada real a la API
     */
    return [
        'exitoso' => true,
        'estado' => 'enviado_demo',
        'mensaje' => 'Comprobante generado en ambiente de pruebas',
        'id_respuesta' => 'DEMO_' . uniqid(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function ecf_consultar_estado_demo($ecf_id) {
    /**
     * Consulta estado ficticio del comprobante
     */
    return [
        'encf' => $ecf_id,
        'estado' => 'aprobado',
        'fecha_aprobacion' => date('Y-m-d'),
        'ambiente' => 'pruebas',
        'modo' => 'demo'
    ];
}

function ecf_generar_nota_credito_demo($factura_id, $monto) {
    /**
     * Genera nota de crédito de prueba (E34)
     */
    $año = date('Y');
    $numero = str_pad(rand(1, 9999), 6, '0', STR_PAD_LEFT);

    return [
        'estado' => 'aprobado_demo',
        'encf' => 'E34-' . $año . '-' . $numero,
        'factura_original' => $factura_id,
        'monto' => $monto,
        'tipo_ecf' => 'E34',
        'fecha_emision' => date('Y-m-d H:i:s'),
        'modo' => 'demo_pruebas'
    ];
}
?>
