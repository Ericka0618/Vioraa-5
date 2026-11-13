<?php
// Solo fixtures en una instancia local separada; no acepta destinos remotos.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', 'viora_integrations_qa', 13308);
$db->set_charset('utf8mb4');
require __DIR__ . '/../Viora/login/paypal_service.php';
require __DIR__ . '/../Viora/conf/subscription_access.php';
require __DIR__ . '/../Viora/mantenimientos/chatbot_historial.php';
require __DIR__ . '/../Viora/mantenimientos/ecf_service.php';
$_SESSION = [];
$checks = [];
function check(string $name, bool $ok): void {
    global $checks;
    $checks[] = ['test' => $name, 'passed' => $ok];
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) throw new RuntimeException($name);
}
function rejects(string $name, callable $f): void {
    try { $f(); } catch (Throwable $e) { check($name, true); return; }
    check($name, false);
}
// Reutiliza un usuario únicamente en el dump de prueba; no imprime sus datos.
$uid = 5;
$db->query("UPDATE suscripcion SET estado='Pendiente' WHERE usuario_id=$uid AND paypal_subscription_id IS NULL"); // Retirar solo el acceso artificial del fixture de navegador local.
$db->query("UPDATE usuario SET activo=0,estado_cuenta='Pendiente de pago' WHERE id_usuarios=$uid");
$db->query("DELETE FROM suscripcion_pago WHERE paypal_subscription_id='I-QAINTEGRATION'");
$db->query("DELETE FROM suscripcion WHERE paypal_subscription_id='I-QAINTEGRATION'");
putenv('PAYPAL_PLAN_ID=P-QAINTEGRATION');
$plan = ['billing_cycles' => [['tenure_type' => 'REGULAR', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'pricing_scheme' => ['fixed_price' => ['currency_code' => 'USD', 'value' => '10.00']]]]];
$sub = ['id' => 'I-QAINTEGRATION', 'plan_id' => 'P-QAINTEGRATION', 'custom_id' => 'viora-user-' . $uid, 'status' => 'APPROVAL_PENDING', 'start_time' => gmdate('c'), 'billing_info' => ['next_billing_time' => gmdate('c', time()+25*86400)]];
$payments = [];
$api = function ($cfg, $path, $token = null) use (&$plan, &$sub, &$payments) {
    if ($path === '/v1/oauth2/token') return ['access_token' => 'fixture'];
    if (str_contains($path, '/billing/plans/')) return $plan;
    if (str_contains($path, '/transactions?')) return ['transactions' => $payments];
    return $sub;
};
$run = fn() => paypal_sincronizar($db, [], 'I-QAINTEGRATION', $uid, $api);
try {
    $event = ['id' => 'WH-QA', 'event_type' => 'PAYMENT.SALE.COMPLETED'];
    $headers = array_fill_keys(['HTTP_PAYPAL_AUTH_ALGO','HTTP_PAYPAL_CERT_URL','HTTP_PAYPAL_TRANSMISSION_ID','HTTP_PAYPAL_TRANSMISSION_SIG','HTTP_PAYPAL_TRANSMISSION_TIME'], 'fixture');
    $verify = fn($cfg, $path, $token = null, $body = null) => $token === null ? ['access_token'=>'fixture'] : ['verification_status'=>'SUCCESS'];
    check('Webhook verification accepts provider SUCCESS', paypal_webhook_validar([], $event, $headers, 'WH-QA', $verify));
    check('Webhook verification rejects missing signature headers', !paypal_webhook_validar([], $event, [], 'WH-QA', $verify));
    $deny = fn($cfg, $path, $token = null, $body = null) => $token === null ? ['access_token'=>'fixture'] : ['verification_status'=>'FAILURE'];
    check('Forged webhook is rejected', !paypal_webhook_validar([], $event, $headers, 'WH-QA', $deny));
    check('Pending approval cannot activate', !$run()['activo']);
    check('Pending user stays disabled', (int)$db->query("SELECT activo FROM usuario WHERE id_usuarios=$uid")->fetch_row()[0] === 0);
    $sub['status'] = 'ACTIVE';
    check('ACTIVE without transaction cannot activate', !$run()['activo']);
    $payments = [['id' => 'QA-TX-1', 'status' => 'COMPLETED', 'time' => gmdate('c'), 'amount_with_breakdown' => ['gross_amount' => ['value' => '10.00', 'currency_code' => 'USD']]]];
    check('Confirmed monthly USD10 activates', $run()['activo']);
    check('Account enabled and catalog allowed', viora_subscription_access($db, $uid));
    $run();
    check('Duplicate confirmation records one payment', (int)$db->query("SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_transaction_id='QA-TX-1'")->fetch_row()[0] === 1);
    $sub['status'] = 'CANCELLED';
    check('Cancellation revokes access in existing session', !$run()['activo'] && !viora_subscription_access($db, $uid));
    $sub['status'] = 'ACTIVE';
    $sub['billing_info']['next_billing_time'] = gmdate('c', time()-60);
    check('Past due subscription blocked', !$run()['activo'] && !viora_subscription_access($db, $uid));
    $sub['billing_info']['next_billing_time'] = gmdate('c', time()+25*86400);
    $payments[0]['id'] = 'QA-TX-2';
    check('Renewal restores access', $run()['activo'] && viora_subscription_access($db, $uid));
    $payments[0]['amount_with_breakdown']['gross_amount']['currency_code'] = 'DOP';
    check('Wrong currency rejected', !$run()['activo']);
    $payments[0]['amount_with_breakdown']['gross_amount']['currency_code'] = 'USD';
    $payments[0]['amount_with_breakdown']['gross_amount']['value'] = '9.99';
    check('Wrong amount rejected', !$run()['activo']);
    $payments[0]['amount_with_breakdown']['gross_amount']['value'] = '10.00';
    $sub['custom_id'] = 'viora-user-6';
    rejects('Foreign subscription rejected', $run);
    $sub['custom_id'] = 'viora-user-5';
    $plan['billing_cycles'][0]['frequency']['interval_unit'] = 'YEAR';
    rejects('Non monthly plan rejected', $run);
    $plan['billing_cycles'][0]['frequency']['interval_unit'] = 'MONTH';
    $payments[0]['status'] = 'REFUNDED';
    check('Refund does not authorize access', !$run()['activo']);
    check('Refund updates ledger state', $db->query("SELECT estado FROM suscripcion_pago WHERE paypal_transaction_id='QA-TX-2'")->fetch_row()[0] === 'REFUNDED');
    $id = chatbot_conversacion($db, $uid);
    chatbot_guardar_turno($db, $id, 'Pregunta QA', 'Respuesta QA', $uid);
    $hist = chatbot_historial($db, $id, 2, $uid);
    check('Conversation and both messages persist', count($hist)===2 && $hist[0]['rol']==='user' && $hist[1]['texto']==='Respuesta QA');
    unset($_SESSION['chatbot_conversacion']);
    check('Conversation restored after login', chatbot_conversacion($db, $uid)===$id);
    check('Another user cannot reuse conversation', chatbot_conversacion($db, 6)!==$id);
    check('ITBIS by line in integer cents', ecf_calcular_totales([10000,2500],18)===['base'=>12500,'itbis'=>2250,'total'=>14750]);
    check('RNC normalization', ecf_rnc_normalizar('132-90740-1')==='132907401');
    rejects('Invalid fiscal identifier rejected', fn() => ecf_rnc_normalizar('invalid'));
    putenv('ECF_ENABLED=false');
    check('Disabled emission never contacts provider', ecf_enviar_documento(['api_key'=>'fixture'],[])['estado']==='PENDIENTE_CONFIGURACION');
    // Existing accepted fixture must never be sent or overwritten, even if emission is disabled.
    $old = $db->query('SELECT * FROM factura_ecf WHERE factura_id=4')->fetch_assoc();
    $r = ecf_enviar_factura($db,4);
    check('Accepted fiscal document preserved on resend', $r['encf']===$old['encf'] && $db->query('SELECT * FROM factura_ecf WHERE factura_id=4')->fetch_assoc()===$old);
    $db->query("UPDATE factura_ecf SET estado='PENDIENTE_CONCILIACION' WHERE factura_id=1");
    check('Uncertain send cannot be repeated', ecf_enviar_factura($db,1)['estado']==='PENDIENTE_CONCILIACION');
    $db->query("UPDATE factura_ecf SET estado='PENDIENTE_CONFIGURACION' WHERE factura_id=1");
    rejects('Legacy invoice with mismatched ITBIS blocked', fn() => ecf_construir_payload($db,1,ecf_configuracion(),'E32'));
    // Corregir solo el fixture para probar la rama válida; no modifica facturas remotas.
    $db->query('UPDATE factura SET total=24190.00 WHERE id_facturas=1');
    try {
        check('Valid invoice prepares with existing E32 format', ecf_construir_payload($db,1,ecf_configuracion(),'E32')['ECF']['Encabezado']['IdDoc']['TipoeCF']==='32');
    } finally { $db->query('UPDATE factura SET total=20500.00 WHERE id_facturas=1'); }
} finally {
    file_put_contents(__DIR__ . '/integration-test-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
