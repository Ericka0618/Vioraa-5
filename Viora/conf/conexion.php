<?php

$conexion = new mysqli("localhost", "root", "", "viora");

if ($conexion->connect_errno) {
    die("Error de conexión: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");

?>