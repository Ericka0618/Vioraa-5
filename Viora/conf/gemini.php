<?php

// La API Key se lee de la variable de entorno GEMINI_API_KEY.
// Produccion: /etc/viora/environment.json. No guardar claves en el repositorio.
return [
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model' => getenv('GEMINI_MODEL') ?: 'gemini-flash-lite-latest',
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout' => 30,
    'max_caracteres_mensaje' => 2000,
    'max_mensajes_historial' => 20,
];

?>
