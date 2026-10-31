<?php
// POST /api/login.php (usuario, password). Solo las cuentas con rol admin pueden usar la API de administración.
require __DIR__ . '/helpers.php';
exigir_metodo('POST');

$in = entrada();
$usuario = texto($in, 'usuario');
$clave = texto($in, 'password');
if ($usuario === '' || $clave === '') {
    responder(400, ['ok' => false, 'mensaje' => 'Debe enviar usuario y password.']);
}

$cuenta = buscar_cuenta_app($usuario);
if ($cuenta && password_verify($clave, (string)($cuenta['password_hash'] ?? ''))) {
    if (($cuenta['rol'] ?? 'usuario') !== 'admin') {
        responder(403, ['ok' => false, 'mensaje' => 'La API de administración solo está disponible para el administrador.']);
    }
    session_regenerate_id(true);
    $_SESSION['api_usuario'] = (string)$cuenta['usuario'];
    $_SESSION['api_rol'] = 'admin';
    responder(200, ['ok' => true, 'mensaje' => 'Sesión administrativa iniciada.', 'usuario' => $cuenta['usuario'], 'rol' => 'admin']);
}

responder(401, ['ok' => false, 'mensaje' => 'Credenciales incorrectas.']);
