<?php
session_start();

if (is_readable('/etc/viora/bootstrap.php')) {
    require_once '/etc/viora/bootstrap.php';
}
require_once __DIR__ . '/../conf/auditoria.php';
if (($_SESSION['logged_in'] ?? false) === true) {
    $id = auditoria_contexto()[0];
    auditoria_evento('Autenticación', 'Cierre de sesión', 'Sesión cerrada', true, $id ? [['usuario', (string)$id]] : []);
}

session_unset();

session_destroy();

header("Location: login.php");
exit;
