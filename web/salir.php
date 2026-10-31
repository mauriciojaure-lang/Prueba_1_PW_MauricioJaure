<?php
require 'inc.php';

// Al cerrar sesión se liberan los números de esta sesión para que puedan ser elegidos por otros.
if (!empty($_SESSION['reserva_owner'])) {
    liberar_reservas_propietario((string)$_SESSION['reserva_owner']);
}
$_SESSION = [];                                   // vacía las variables de sesión
if (ini_get('session.use_cookies')) {             // elimina la cookie de sesión
    $c = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
}
session_destroy();                                // destruye la sesión en el servidor

header('Location: index.php');
exit;
