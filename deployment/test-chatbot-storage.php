<?php
// Real SQL tests, fixed isolated destination. Never uses production environment variables.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', 'viora_integrations_qa', 13308);
$db->set_charset('utf8mb4');
require __DIR__ . '/../Viora/mantenimientos/chatbot_historial.php';
require __DIR__ . '/../Viora/mantenimientos/chatbot_herramientas.php';
$checks = [];
function check(string $name, bool $value): void {
    global $checks;
    $checks[] = ['test'=>$name,'passed'=>$value];
    echo ($value?'PASS ':'FAIL ') . $name . PHP_EOL;
    if (!$value) throw new RuntimeException($name);
}
function denied(string $name, callable $action): void {
    try { $action(); } catch (Throwable $e) { check($name,true); return; }
    check($name,false);
}
try {
    $_SESSION=[];
    $owner=5; $other=6;
    $id=chatbot_conversacion($db,$owner);
    chatbot_guardar_turno($db,$id,'QA chatbot isolation','Respuesta de prueba de almacenamiento, no Gemini.',$owner);
    $history=chatbot_historial($db,$id,2,$owner);
    check('Owner retrieves both persisted messages',count($history)===2 && $history[0]['texto']==='QA chatbot isolation');
    check('Different user cannot read a known conversation ID',chatbot_historial($db,$id,100,$other)===[]);
    $count=(int)$db->query("SELECT COUNT(*) FROM mensaje_ia WHERE conversacion_id=$id")->fetch_row()[0];
    denied('Different user cannot append to known conversation',fn()=>chatbot_guardar_turno($db,$id,'forged','forged',$other));
    check('Denied append leaves record count unchanged',(int)$db->query("SELECT COUNT(*) FROM mensaje_ia WHERE conversacion_id=$id")->fetch_row()[0]===$count);
    $_SESSION=['chatbot_conversacion'=>$id];
    check('Session with another owner ID cannot reuse it',chatbot_conversacion($db,$other)!==$id);
    $_SESSION=[];
    check('Owner recovers conversation after session reset',chatbot_conversacion($db,$owner)===$id);
    $db->query("UPDATE conversacion_ia SET estado='Cerrada' WHERE id_conversaciones=$id");
    denied('Closed conversation cannot receive new turns',fn()=>chatbot_guardar_turno($db,$id,'closed','closed',$owner));
    check('Closing a conversation preserves its messages',(int)$db->query("SELECT COUNT(*) FROM mensaje_ia WHERE conversacion_id=$id")->fetch_row()[0]===$count);
    $new=chatbot_conversacion($db,$owner);
    check('New conversation has an independent empty history',$new!==$id && chatbot_historial($db,$new,100,$owner)===[]);
    $result=chatbot_ejecutar_cliente($db,'facturas',[],null);
    check('Client cannot execute administrative tool',isset($result['error']));
    check('Client action requires server confirmation',isset(chatbot_accion_previa(1,['apartamento_id'=>1,'confirmado'=>false])['error']));
    check('Client with no authorized person cannot write',isset(chatbot_accion_previa(null,['apartamento_id'=>1,'confirmado'=>true])['error']));
} finally {
    file_put_contents(__DIR__.'/chatbot-storage-results.json',json_encode($checks,JSON_PRETTY_PRINT));
}
