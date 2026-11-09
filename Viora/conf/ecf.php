<?php

// La API Key se lee de la variable de entorno ECF_API_KEY.
// No la guarde en este archivo.
return [
    'base_url' => 'https://ecf-platform-backend-50801509587.us-central1.run.app',
    'api_key' => getenv('ECF_API_KEY') ?: '',
    'itbis_porcentaje' => 18,
    'emisor' => [
        'RNCEmisor' => '132907401',
        'RazonSocialEmisor' => 'UTESA',
        'NombreComercial' => 'UTESA',
        'DireccionEmisor' => 'Santiago',
        'TelefonoEmisor' => '829-282-7556',
        'CorreoEmisor' => 'utesa@utesa.edu.com',
    ],
];

?>
