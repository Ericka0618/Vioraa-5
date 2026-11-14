<?php

// Cloud Run inyecta IMECF_API_KEY desde Secret Manager; alias legado compatible.
// No la guarde en este archivo.
return [
    'base_url' => getenv('ECF_BASE_URL') ?: 'https://ecf-platform-backend-50801509587.us-central1.run.app',
    'api_key' => trim(getenv('IMECF_API_KEY') ?: (getenv('ECF_API_KEY') ?: '')),
    'itbis_porcentaje' => 18,
    'ambiente' => getenv('ECF_AMBIENTE') ?: '',
    'contrato_validado' => getenv('ECF_CONTRATO_VALIDADO') === 'true',
    'emisor_confirmado' => getenv('ECF_EMISOR_CONFIRMADO') === 'true',
    'tipos_autorizados' => array_values(array_filter(array_map('trim', explode(',', getenv('ECF_TIPOS_AUTORIZADOS') ?: '')))),
    'emisor' => [
        'RNCEmisor' => getenv('ECF_EMISOR_RNC') ?: '',
        'RazonSocialEmisor' => getenv('ECF_EMISOR_RAZON_SOCIAL') ?: '',
        'NombreComercial' => getenv('ECF_EMISOR_NOMBRE_COMERCIAL') ?: '',
        'DireccionEmisor' => getenv('ECF_EMISOR_DIRECCION') ?: '',
        'TelefonoEmisor' => getenv('ECF_EMISOR_TELEFONO') ?: '',
        'CorreoEmisor' => getenv('ECF_EMISOR_CORREO') ?: '',
    ],
];

?>
