<?php
require 'inc.php';

if (!empty($_SESSION['usuario'])) {
    header('Location: ' . (es_admin() ? 'admin.php' : 'panel.php'));
    exit;
}

function generar_captcha(): string
{
    $a = random_int(1, 12);
    $b = random_int(1, 12);
    $op = ['+', '-', '*'][random_int(0, 2)];
    if ($op === '-' && $b > $a) [$a, $b] = [$b, $a];
    $_SESSION['captcha_resultado'] = match ($op) {
        '+' => $a + $b,
        '-' => $a - $b,
        '*' => $a * $b,
    };
    return $a . ' ' . ($op === '*' ? '×' : $op) . ' ' . $b . ' = ?';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = post_texto('usuario');
    $clave = post_texto('password');
    $captcha = post_texto('captcha');
    $esperado = $_SESSION['captcha_resultado'] ?? null;
    $cuenta = buscar_usuario($usuario);
    $captchaOk = $esperado !== null
        && filter_var($captcha, FILTER_VALIDATE_INT) !== false
        && (int)$captcha === (int)$esperado;

    if ($cuenta && password_verify($clave, (string)($cuenta['password_hash'] ?? '')) && $captchaOk) {
        unset($_SESSION['captcha_resultado']);
        session_regenerate_id(true);
        $_SESSION['usuario'] = (string)$cuenta['usuario'];
        $_SESSION['rol'] = (string)($cuenta['rol'] ?? 'usuario');
        $_SESSION['user_id'] = (int)($cuenta['id'] ?? 0);
        $_SESSION['reserva_owner'] = bin2hex(random_bytes(16));
        $_SESSION['numeros_rifa'] = [];
        header('Location: ' . ($_SESSION['rol'] === 'admin' ? 'admin.php' : 'panel.php'));
        exit;
    }
    $error = 'Usuario, contraseña o resultado del CAPTCHA incorrecto.';
}

$pregunta = generar_captcha();
encabezado('Iniciar sesión', false);
mostrar_flash();
?>
<?php if ($error): ?><div class="msg error"><?= e($error) ?></div><?php endif; ?>
<p>Inicia sesión para reservar números y consultar los premios de la rifa.</p>
<form method="POST" action="index.php" autocomplete="off">
    <label for="usuario">Usuario</label>
    <input type="text" id="usuario" name="usuario" autocomplete="username" required>

    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <label>CAPTCHA: resuelve la operación</label>
    <div class="captcha"><?= e($pregunta) ?></div>
    <input type="text" name="captcha" inputmode="numeric" placeholder="Tu respuesta" required>

    <button type="submit">Iniciar sesión</button>
</form>
<p class="texto-secundario">¿Todavía no tienes cuenta? <a href="registrarse.php">Regístrate aquí</a>.</p>
<?php pie(); ?>
