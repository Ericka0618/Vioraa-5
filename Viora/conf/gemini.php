<?php

// La API Key se lee de la variable de entorno GEMINI_API_KEY.
// Para desarrollo local, crea un archivo .env con: GEMINI_API_KEY=tu_clave_aqui
return [
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model' => 'gemini-flash-lite-latest',
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout' => 30,
    'max_caracteres_mensaje' => 2000,
    'max_mensajes_historial' => 20,
];

?>
