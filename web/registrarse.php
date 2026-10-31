<?php
require 'inc.php';

if (!empty($_SESSION['usuario'])) {
    header('Location: ' . (es_admin() ? 'admin.php' : 'panel.php'));
    exit;
}

$error = '';
$nombre = '';
$apellido = '';
$ciudad = '';
$usuario = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = post_texto('nombre');
    $apellido = post_texto('apellido');
    $ciudad = post_texto('ciudad');
    $usuario = post_texto('usuario');
    $clave = post_texto('password');
    $confirmacion = post_texto('confirmar_password');

    $perfilValido = static function (string $valor, int $maximo): bool {
        return $valor !== ''
            && preg_match('/\A.{1,' . $maximo . '}\z/us', $valor) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $valor) !== 1;
    };

    if (!$perfilValido($nombre, 60)) {
        $error = 'Ingresa un nombre válido de hasta 60 caracteres.';
    } elseif (!$perfilValido($apellido, 60)) {
        $error = 'Ingresa un apellido válido de hasta 60 caracteres.';
    } elseif (!$perfilValido($ciudad, 80)) {
        $error = 'Ingresa una ciudad válida de hasta 80 caracteres.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{4,24}$/', $usuario)) {
        $error = 'El usuario debe tener entre 4 y 24 caracteres: letras, números, punto, guion o guion bajo.';
    } elseif (strlen($clave) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($clave !== $confirmacion) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $nuevoHash = password_hash($clave, PASSWORD_DEFAULT);
        $actualizacion = modificar_json_bloqueado(ARCHIVO_USUARIOS, function (&$usuarios) use ($nombre, $apellido, $ciudad, $usuario, $nuevoHash) {
            foreach ($usuarios as $existente) {
                if (strtolower((string)($existente['usuario'] ?? '')) === strtolower($usuario)) {
                    return ['ok' => false, 'mensaje' => 'Ese nombre de usuario ya está registrado.'];
                }
            }
            $maxId = 0;
            foreach ($usuarios as $existente) $maxId = max($maxId, (int)($existente['id'] ?? 0));
            $nuevoId = $maxId + 1;
            $usuarios[] = [
                'id' => $nuevoId,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ciudad' => $ciudad,
                'categoria' => 'user', // Categoría automática: una cuenta nueva empieza como user.
                'usuario' => $usuario,
                'password_hash' => $nuevoHash,
                // Nunca se acepta un rol del formulario: toda cuenta pública es usuario normal.
                'rol' => 'usuario',
                'creado_en' => date(DATE_ATOM),
            ];
            return ['ok' => true, 'id' => $nuevoId, 'mensaje' => 'Cuenta creada correctamente. Ya puedes iniciar sesión.'];
        });

        $resultado = $actualizacion['resultado'] ?? null;
        if (empty($actualizacion['guardado'])) {
            $error = 'No se pudo guardar la cuenta. Revisa los permisos de escritura de web/data/.';
        } elseif (empty($resultado['ok'])) {
            $error = $resultado['mensaje'] ?? 'No se pudo crear la cuenta.';
        } else {
            // También crea un registro de participante pendiente en el JSON que consulta la API.
            // Todavía no tiene número: se asigna más adelante y nunca se ocupa uno automáticamente.
            $idUsuarioNuevo = (int)($resultado['id'] ?? 0);
            $directorioBloqueo = dirname(ARCHIVO_BLOQUEO_RIFA);
            if (!is_dir($directorioBloqueo)) {
                @mkdir($directorioBloqueo, 0775, true);
            }
            $bloqueoRifa = @fopen(ARCHIVO_BLOQUEO_RIFA, 'c+');
            $bloqueoObtenido = $bloqueoRifa !== false && flock($bloqueoRifa, LOCK_EX);
            $sincronizacion = ['guardado' => false, 'resultado' => null];

            if ($bloqueoObtenido) {
                $sincronizacion = modificar_json_bloqueado(ARCHIVO_PARTICIPANTES, function (&$lista) use ($idUsuarioNuevo, $nombre, $apellido, $ciudad, $usuario) {
                    foreach ($lista as $existente) {
                        if ((int)($existente['usuario_id'] ?? 0) === $idUsuarioNuevo && $idUsuarioNuevo > 0) {
                            return ['ok' => true, 'mensaje' => 'El participante de esta cuenta ya estaba sincronizado.'];
                        }
                    }
                    $maxIdParticipante = 0;
                    foreach ($lista as $existente) {
                        $maxIdParticipante = max($maxIdParticipante, (int)($existente['id'] ?? 0));
                    }
                    $lista[] = [
                        'id' => $maxIdParticipante + 1,
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'numero_rifa' => null,
                        'ciudad' => $ciudad,
                        'categoria' => 'user',
                        'usuario_id' => $idUsuarioNuevo,
                        'usuario' => $usuario,
                        'rol' => 'usuario',
                        'origen' => 'registro_web',
                        'estado' => 'user',
                        'creado_en' => date(DATE_ATOM),
                    ];
                    return ['ok' => true, 'mensaje' => 'Cuenta sincronizada con participantes.'];
                });
                flock($bloqueoRifa, LOCK_UN);
                fclose($bloqueoRifa);
            }

            if (empty($sincronizacion['guardado']) || empty($sincronizacion['resultado']['ok'])) {
                // Evita dejar una cuenta creada si no fue posible reflejarla en la lista de participantes.
                modificar_json_bloqueado(ARCHIVO_USUARIOS, function (&$lista) use ($idUsuarioNuevo) {
                    $lista = array_values(array_filter($lista, fn($item) => (int)($item['id'] ?? 0) !== $idUsuarioNuevo));
                    return ['ok' => true];
                });
                $error = 'No se pudo sincronizar el registro con la lista de participantes. Revisa los permisos de escritura de api/data/.';
            } else {
                flash('ok', 'Cuenta creada y agregada a participantes. Su categoría inicial es user.');
                header('Location: index.php');
                exit;
            }
        }
    }
}

encabezado('Crear una cuenta', false);
?>
<?php if ($error): ?><div class="msg error"><?= e($error) ?></div><?php endif; ?>
<p>Completa tus datos para crear tu cuenta. El rol se asigna automáticamente y no se puede cambiar desde el registro.</p>
<form method="POST" action="registrarse.php" autocomplete="off">
    <div class="fila">
        <div>
            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($nombre) ?>" maxlength="60" autocomplete="given-name" required>
        </div>
        <div>
            <label for="apellido">Apellido</label>
            <input type="text" id="apellido" name="apellido" value="<?= e($apellido) ?>" maxlength="60" autocomplete="family-name" required>
        </div>
    </div>

    <label for="ciudad">Ciudad</label>
    <input type="text" id="ciudad" name="ciudad" value="<?= e($ciudad) ?>" maxlength="80" autocomplete="address-level2" required>

    <label for="usuario">Nombre de usuario</label>
    <input type="text" id="usuario" name="usuario" value="<?= e($usuario) ?>" minlength="4" maxlength="24" pattern="[A-Za-z0-9_.-]{4,24}" autocomplete="username" required>

    <div class="fila">
        <div>
            <label for="password">Contraseña (mínimo 8 caracteres)</label>
            <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
        </div>
        <div>
            <label for="confirmar_password">Confirmar contraseña</label>
            <input type="password" id="confirmar_password" name="confirmar_password" minlength="8" autocomplete="new-password" required>
        </div>
    </div>

    <label for="rol_visible">Rol de la cuenta</label>
    <input type="text" id="rol_visible" value="Usuario" class="rol-solo-lectura" readonly aria-readonly="true">
    <p class="texto-secundario">Por seguridad, el registro público crea cuentas de usuario normal. El rol lo determina el servidor y no se puede editar aquí.</p>

    <button type="submit">Registrarme</button>
</form>
<p class="texto-secundario"><a href="index.php">Volver al inicio de sesión</a>.</p>
<?php pie(); ?>
