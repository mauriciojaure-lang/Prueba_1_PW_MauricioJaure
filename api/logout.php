<?php
// GET /api/logout.php
require __DIR__ . '/helpers.php';
exigir_metodo('GET');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $c = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
}
session_destroy();
responder(200, ['ok' => true, 'mensaje' => 'Sesión cerrada.']);
