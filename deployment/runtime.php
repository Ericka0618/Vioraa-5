<?php
// Cloud Run terminates TLS before Apache.
if (getenv('K_SERVICE')) {
    $_SERVER['HTTPS'] = 'on';
    ini_set('session.cookie_secure', '1');
}

require_once '/etc/viora/mysql-session.php';

// Explicit sentinel for an integration awaiting a user-provided secret.
if (getenv('GEMINI_API_KEY') === 'UNCONFIGURED') putenv('GEMINI_API_KEY=');
