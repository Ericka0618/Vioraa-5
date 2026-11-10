<?php
// Secrets are supplied by the private production bootstrap, never committed.
return [
    'client_id' => getenv('PAYPAL_CLIENT_ID') ?: '',
    'client_secret' => getenv('PAYPAL_CLIENT_SECRET') ?: '',
    'api_url' => 'https://api-m.sandbox.paypal.com',
];