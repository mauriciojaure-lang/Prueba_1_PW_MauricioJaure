<?php
// Funciones comunes de la aplicación web.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('RIFA_WEB');
    session_start();
}

const PRECIO_NUMERO = 2000;
const MAX_NUMERO = 100;
const ARCHIVO_RESERVAS = __DIR__ . '/data/reservas.json';
const ARCHIVO_USUARIOS = __DIR__ . '/data/usuarios.json';
const ARCHIVO_COMPROBANTES = __DIR__ . '/data/comprobantes.json';
const ARCHIVO_PARTICIPANTES = __DIR__ . '/../api/data/participantes.json';
const ARCHIVO_PREMIOS = __DIR__ . '/../api/data/premios.json';
const ARCHIVO_BLOQUEO_RIFA = __DIR__ . '/../api/data/estado_rifa.lock';
const CARPETA_COMPROBANTES = __DIR__ . '/../private/comprobantes/';

function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function post_texto(string $clave): string
{
    return (isset($_POST[$clave]) && is_string($_POST[$clave])) ? trim($_POST[$clave]) : '';
}

function get_texto(string $clave): string
{
    return (isset($_GET[$clave]) && is_string($_GET[$clave])) ? trim($_GET[$clave]) : '';
}

// Token CSRF para proteger operaciones que modifican información desde formularios.
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function validar_csrf_token(): bool
{
    return isset($_POST['_csrf_token'], $_SESSION['_csrf_token'])
        && is_string($_POST['_csrf_token'])
        && is_string($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $_POST['_csrf_token']);
}

function pesos(int $monto): string
{
    return '$' . number_format($monto, 0, ',', '.');
}

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function mostrar_flash(): void
{
    if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="msg ' . e($f['tipo'] ?? 'ok') . '">' . e($f['mensaje'] ?? '') . '</div>';
    }
}

// Crea cuentas de demostración solo en una instalación nueva.
// Las contraseñas se guardan con password_hash(), nunca como texto plano.
function asegurar_usuarios_iniciales(): void
{
    $directorio = dirname(ARCHIVO_USUARIOS);
    if (!is_dir($directorio)) {
        @mkdir($directorio, 0775, true);
    }
    if (file_exists(ARCHIVO_USUARIOS)) {
        return;
    }

    $usuarios = [
        [
            'id' => 1,
            'nombre' => 'Administrador',
            'apellido' => 'Rifa',
            'ciudad' => 'Valparaíso',
            'categoria' => '',
            'usuario' => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'rol' => 'admin',
            'origen' => 'demo',
            'creado_en' => date(DATE_ATOM),
        ],
        [
            'id' => 2,
            'nombre' => 'Usuario',
            'apellido' => 'UPLA',
            'ciudad' => 'Valparaíso',
            'categoria' => '',
            'usuario' => 'userupla',
            'password_hash' => password_hash('upla123', PASSWORD_DEFAULT),
            'rol' => 'usuario',
            'origen' => 'demo',
            'creado_en' => date(DATE_ATOM),
        ],
    ];

    // Modo x: evita sobrescribir el archivo si dos solicitudes se inicializan al mismo tiempo.
    $fp = @fopen(ARCHIVO_USUARIOS, 'x');
    if ($fp !== false) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

function leer_json_archivo_compartido(string $ruta, int $tipoBloqueo = LOCK_SH): array
{
    if (!file_exists($ruta)) {
        return [];
    }
    $fp = fopen($ruta, 'r');
    if ($fp === false) {
        return [];
    }
    if (!flock($fp, $tipoBloqueo)) {
        fclose($fp);
        return [];
    }
    $contenido = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $datos = json_decode($contenido ?: '[]', true);
    return is_array($datos) ? $datos : [];
}

// Modifica un JSON dentro de un bloqueo exclusivo para evitar escrituras simultáneas.
function modificar_json_bloqueado(string $ruta, callable $operacion): array
{
    $directorio = dirname($ruta);
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        return ['guardado' => false, 'resultado' => null];
    }
    $fp = fopen($ruta, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if (is_resource($fp)) fclose($fp);
        return ['guardado' => false, 'resultado' => null];
    }

    rewind($fp);
    $contenido = stream_get_contents($fp);
    $datos = json_decode($contenido ?: '[]', true);
    if (!is_array($datos)) $datos = [];
    $resultado = $operacion($datos);
    $json = json_encode(array_values($datos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    rewind($fp);
    ftruncate($fp, 0);
    $escrito = fwrite($fp, $json === false ? '[]' : $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return ['guardado' => $escrito !== false, 'resultado' => $resultado];
}



// Las categorías de clientes se calculan a partir del estado de sus comprobantes.
// El rol de seguridad (admin/usuario) sigue siendo independiente de esta categoría.
function calcular_categoria_automatica(int $usuarioId, string $nombreUsuario, ?array $comprobantes = null): string
{
    $comprobantes = $comprobantes ?? leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);
    $tienePendiente = false;
    foreach ($comprobantes as $comprobante) {
        $coincide = ($usuarioId > 0 && (int)($comprobante['usuario_id'] ?? 0) === $usuarioId)
            || ($nombreUsuario !== '' && strcasecmp((string)($comprobante['usuario'] ?? ''), $nombreUsuario) === 0);
        if (!$coincide) continue;
        $estado = strtolower(trim((string)($comprobante['estado'] ?? '')));
        if (in_array($estado, ['aceptado', 'aprobado', 'confirmado'], true)) {
            return 'comprador';
        }
        if (in_array($estado, ['pendiente', 'pendiente de revisión', 'pendiente_revision', 'comprobante_enviado'], true)) {
            $tienePendiente = true;
        }
    }
    return $tienePendiente ? 'cliente' : 'user';
}

function guardar_lista_json_segura(string $ruta, array $lista): bool
{
    $resultado = modificar_json_bloqueado($ruta, function (&$actual) use ($lista) {
        $actual = array_values($lista);
        return true;
    });
    return !empty($resultado['guardado']) && ($resultado['resultado'] ?? false) === true;
}

function sincronizar_categoria_automatica_usuario(int $usuarioId, string $nombreUsuario = ''): string
{
    if ($usuarioId < 1 && $nombreUsuario === '') return 'user';
    $bloqueo = abrir_bloqueo_estado_rifa(true);
    if ($bloqueo === false) return 'user';
    $comprobantes = leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);
    $categoria = calcular_categoria_automatica($usuarioId, $nombreUsuario, $comprobantes);

    modificar_json_bloqueado(ARCHIVO_USUARIOS, function (&$usuarios) use ($usuarioId, $nombreUsuario, $categoria) {
        foreach ($usuarios as &$usuario) {
            $coincide = ($usuarioId > 0 && (int)($usuario['id'] ?? 0) === $usuarioId)
                || ($nombreUsuario !== '' && strcasecmp((string)($usuario['usuario'] ?? ''), $nombreUsuario) === 0);
            if ($coincide && ($usuario['rol'] ?? 'usuario') !== 'admin') {
                $usuario['categoria'] = $categoria;
            }
        }
        unset($usuario);
        return true;
    });

    modificar_json_bloqueado(ARCHIVO_PARTICIPANTES, function (&$participantes) use ($usuarioId, $nombreUsuario, $categoria) {
        foreach ($participantes as &$participante) {
            $coincide = ($usuarioId > 0 && (int)($participante['usuario_id'] ?? 0) === $usuarioId)
                || ($nombreUsuario !== '' && strcasecmp((string)($participante['usuario'] ?? ''), $nombreUsuario) === 0);
            if ($coincide && ($participante['origen'] ?? '') === 'registro_web') {
                $participante['categoria'] = $categoria;
                $participante['estado'] = $categoria;
            }
        }
        unset($participante);
        return true;
    });
    cerrar_bloqueo_estado_rifa($bloqueo);
    return $categoria;
}

// El administrador aprueba o rechaza comprobantes. Los números solo se vuelven
// ocupados cuando la aprobación se guarda correctamente.
function revisar_comprobante_admin(string $nombreArchivo, string $decision, string $administrador): array
{
    if (!in_array($decision, ['aceptar', 'rechazar'], true)) {
        return ['ok' => false, 'mensaje' => 'La decisión indicada no es válida.'];
    }
    if ($nombreArchivo === '' || basename($nombreArchivo) !== $nombreArchivo || str_starts_with($nombreArchivo, '.')) {
        return ['ok' => false, 'mensaje' => 'El comprobante indicado no es válido.'];
    }
    if (!is_file(CARPETA_COMPROBANTES . $nombreArchivo)) {
        return ['ok' => false, 'mensaje' => 'No se encontró el archivo físico del comprobante. No se puede revisar.'];
    }

    $bloqueo = abrir_bloqueo_estado_rifa(true);
    if ($bloqueo === false) return ['ok' => false, 'mensaje' => 'No se pudo bloquear el estado de la rifa. Intenta nuevamente.'];

    $comprobantesOriginales = leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);
    $reservasOriginales = leer_json_archivo_compartido(ARCHIVO_RESERVAS);
    $participantesOriginales = leer_json_archivo_compartido(ARCHIVO_PARTICIPANTES);
    $usuariosOriginales = leer_json_archivo_compartido(ARCHIVO_USUARIOS);

    $comprobantes = $comprobantesOriginales;
    $reservas = $reservasOriginales;
    $participantes = $participantesOriginales;
    $usuarios = $usuariosOriginales;

    $indice = null;
    foreach ($comprobantes as $i => $comprobante) {
        if ((string)($comprobante['archivo'] ?? '') === $nombreArchivo) {
            $indice = $i;
            break;
        }
    }
    if ($indice === null) {
        cerrar_bloqueo_estado_rifa($bloqueo);
        return ['ok' => false, 'mensaje' => 'No se encontró el comprobante.'];
    }

    $estadoActual = strtolower(trim((string)($comprobantes[$indice]['estado'] ?? '')));
    if (!in_array($estadoActual, ['pendiente', 'pendiente de revisión', 'pendiente_revision', 'comprobante_enviado'], true)) {
        cerrar_bloqueo_estado_rifa($bloqueo);
        return ['ok' => false, 'mensaje' => 'Este comprobante ya fue revisado.'];
    }

    $registro = $comprobantes[$indice];
    $numeros = array_values(array_unique(array_filter(array_map('intval', $registro['numeros'] ?? []), fn($n) => $n >= 1 && $n <= MAX_NUMERO)));
    sort($numeros);
    if (!$numeros) {
        cerrar_bloqueo_estado_rifa($bloqueo);
        return ['ok' => false, 'mensaje' => 'El comprobante no tiene números asociados.'];
    }

    $usuarioId = (int)($registro['usuario_id'] ?? 0);
    $nombreUsuario = (string)($registro['usuario'] ?? '');
    $indiceUsuario = null;
    foreach ($usuarios as $i => $usuario) {
        if (($usuarioId > 0 && (int)($usuario['id'] ?? 0) === $usuarioId)
            || ($nombreUsuario !== '' && strcasecmp((string)($usuario['usuario'] ?? ''), $nombreUsuario) === 0)) {
            $indiceUsuario = $i;
            $usuarioId = (int)($usuario['id'] ?? $usuarioId);
            $nombreUsuario = (string)($usuario['usuario'] ?? $nombreUsuario);
            break;
        }
    }

    $nuevosDatos = [];
    if ($decision === 'aceptar') {
        $numerosYaAsignados = [];
        foreach ($participantes as $participante) {
            $numsParticipante = $participante['numeros_rifa'] ?? [];
            if (!is_array($numsParticipante)) $numsParticipante = [];
            $numUnico = (int)($participante['numero_rifa'] ?? 0);
            if ($numUnico > 0) $numsParticipante[] = $numUnico;
            foreach ($numsParticipante as $num) {
                $num = (int)$num;
                if ($num > 0) $numerosYaAsignados[$num] = true;
            }
        }
        foreach ($reservas as $reserva) {
            if ((string)($reserva['estado'] ?? '') === 'confirmado'
                && (string)($reserva['comprobante'] ?? '') !== $nombreArchivo) {
                $num = (int)($reserva['numero'] ?? 0);
                if ($num > 0) $numerosYaAsignados[$num] = true;
            }
        }
        foreach ($numeros as $numero) {
            if (isset($numerosYaAsignados[$numero])) {
                cerrar_bloqueo_estado_rifa($bloqueo);
                return ['ok' => false, 'mensaje' => "No se puede aceptar el comprobante: el número $numero ya fue aprobado o asignado a otra persona. Rechaza este comprobante para liberar los demás números."];
            }
        }

        // La aprobación debe encontrar todas las reservas asociadas al comprobante.
        $reservasCoincidentes = 0;
        foreach ($reservas as &$reserva) {
            if ((string)($reserva['comprobante'] ?? '') === $nombreArchivo
                && in_array((int)($reserva['numero'] ?? 0), $numeros, true)) {
                $reserva['estado'] = 'confirmado';
                $reserva['confirmado_en'] = date(DATE_ATOM);
                $reservasCoincidentes++;
            }
        }
        unset($reserva);
        if ($reservasCoincidentes !== count($numeros)) {
            cerrar_bloqueo_estado_rifa($bloqueo);
            return ['ok' => false, 'mensaje' => 'No se encontraron todas las reservas asociadas. No se aprobó el comprobante.'];
        }

        $indiceParticipante = null;
        foreach ($participantes as $i => $participante) {
            if (($usuarioId > 0 && (int)($participante['usuario_id'] ?? 0) === $usuarioId)
                || ($nombreUsuario !== '' && strcasecmp((string)($participante['usuario'] ?? ''), $nombreUsuario) === 0 && ($participante['origen'] ?? '') === 'registro_web')) {
                $indiceParticipante = $i;
                break;
            }
        }
        if ($indiceParticipante === null && $indiceUsuario !== null) {
            $maxId = 0;
            foreach ($participantes as $p) $maxId = max($maxId, (int)($p['id'] ?? 0));
            $participantes[] = [
                'id' => $maxId + 1,
                'nombre' => (string)($usuarios[$indiceUsuario]['nombre'] ?? ''),
                'apellido' => (string)($usuarios[$indiceUsuario]['apellido'] ?? ''),
                'ciudad' => (string)($usuarios[$indiceUsuario]['ciudad'] ?? ''),
                'usuario' => $nombreUsuario,
                'usuario_id' => $usuarioId,
                'rol' => 'usuario',
                'origen' => 'registro_web',
                'numero_rifa' => null,
                'numeros_rifa' => [],
                'creado_en' => date(DATE_ATOM),
            ];
            $indiceParticipante = count($participantes) - 1;
        }
        if ($indiceParticipante !== null) {
            $listaNumeros = $participantes[$indiceParticipante]['numeros_rifa'] ?? [];
            if (!is_array($listaNumeros)) $listaNumeros = [];
            $anterior = (int)($participantes[$indiceParticipante]['numero_rifa'] ?? 0);
            if ($anterior > 0) $listaNumeros[] = $anterior;
            $listaNumeros = array_values(array_unique(array_merge(array_map('intval', $listaNumeros), $numeros)));
            sort($listaNumeros);
            $participantes[$indiceParticipante]['numeros_rifa'] = $listaNumeros;
            $participantes[$indiceParticipante]['numero_rifa'] = $listaNumeros[0] ?? null;
            $participantes[$indiceParticipante]['categoria'] = 'comprador';
            $participantes[$indiceParticipante]['estado'] = 'comprador';
        }
        $comprobantes[$indice]['estado'] = 'aceptado';
        $comprobantes[$indice]['mensaje_revision'] = 'Pago aprobado por el administrador.';
    } else {
        foreach ($reservas as &$reserva) {
            if ((string)($reserva['comprobante'] ?? '') === $nombreArchivo) {
                $reserva['estado'] = 'rechazado';
                $reserva['rechazado_en'] = date(DATE_ATOM);
            }
        }
        unset($reserva);
        $comprobantes[$indice]['estado'] = 'rechazado';
        $comprobantes[$indice]['mensaje_revision'] = 'Comprobante rechazado por el administrador. Los números vuelven a estar disponibles.';
    }

    $comprobantes[$indice]['revisado_por'] = $administrador;
    $comprobantes[$indice]['revisado_en'] = date(DATE_ATOM);

    $categoria = 'user';
    if ($usuarioId > 0 || $nombreUsuario !== '') {
        $categoria = calcular_categoria_automatica($usuarioId, $nombreUsuario, $comprobantes);
        if ($indiceUsuario !== null) {
            $usuarios[$indiceUsuario]['categoria'] = $categoria;
        }
        foreach ($participantes as &$participante) {
            if (($usuarioId > 0 && (int)($participante['usuario_id'] ?? 0) === $usuarioId)
                || ($nombreUsuario !== '' && strcasecmp((string)($participante['usuario'] ?? ''), $nombreUsuario) === 0 && ($participante['origen'] ?? '') === 'registro_web')) {
                $participante['categoria'] = $categoria;
                if ($categoria !== 'comprador') $participante['estado'] = $categoria;
            }
        }
        unset($participante);
    }

    $archivosParaGuardar = [
        ARCHIVO_RESERVAS => [$reservasOriginales, $reservas],
        ARCHIVO_PARTICIPANTES => [$participantesOriginales, $participantes],
        ARCHIVO_USUARIOS => [$usuariosOriginales, $usuarios],
        ARCHIVO_COMPROBANTES => [$comprobantesOriginales, $comprobantes],
    ];
    $guardados = [];
    foreach ($archivosParaGuardar as $ruta => [$original, $nuevo]) {
        if (!guardar_lista_json_segura($ruta, $nuevo)) {
            foreach ($guardados as $rutaGuardada => $datosOriginales) {
                guardar_lista_json_segura($rutaGuardada, $datosOriginales);
            }
            cerrar_bloqueo_estado_rifa($bloqueo);
            return ['ok' => false, 'mensaje' => 'No se pudieron guardar todos los cambios. No se completó la revisión.'];
        }
        $guardados[$ruta] = $original;
    }

    cerrar_bloqueo_estado_rifa($bloqueo);
    return [
        'ok' => true,
        'mensaje' => $decision === 'aceptar'
            ? 'Comprobante aceptado. Los números ahora aparecen ocupados y la categoría pasó a comprador.'
            : 'Comprobante rechazado. Los números vuelven a estar disponibles y la categoría se actualizó automáticamente.',
    ];
}

// Corrige categorías antiguas cada vez que el administrador abre el panel.
function actualizar_todas_las_categorias_automaticas(): void
{
    $comprobantes = leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);
    $usuarios = leer_usuarios();
    foreach ($usuarios as $usuario) {
        if (($usuario['rol'] ?? 'usuario') === 'admin') continue;
        $id = (int)($usuario['id'] ?? 0);
        $nombre = (string)($usuario['usuario'] ?? '');
        $categoria = calcular_categoria_automatica($id, $nombre, $comprobantes);
        if ((string)($usuario['categoria'] ?? '') !== $categoria) {
            sincronizar_categoria_automatica_usuario($id, $nombre);
        }
    }
}

function leer_usuarios(): array
{
    asegurar_usuarios_iniciales();
    return leer_json_archivo_compartido(ARCHIVO_USUARIOS);
}

function buscar_usuario(string $nombre): ?array
{
    foreach (leer_usuarios() as $usuario) {
        if (isset($usuario['usuario']) && hash_equals((string)$usuario['usuario'], $nombre)) {
            return $usuario;
        }
    }
    return null;
}

function es_admin(): bool
{
    return ($_SESSION['rol'] ?? '') === 'admin';
}

function requiere_sesion(): void
{
    if (empty($_SESSION['usuario'])) {
        header('Location: index.php');
        exit;
    }
    // Compatibilidad para una sesión iniciada antes de agregar los roles.
    if (empty($_SESSION['rol'])) {
        $_SESSION['rol'] = 'usuario';
    }
}

function requiere_admin(): void
{
    requiere_sesion();
    if (!es_admin()) {
        flash('error', 'Esta sección está disponible solamente para el administrador.');
        header('Location: panel.php');
        exit;
    }
}

function requiere_usuario(): void
{
    requiere_sesion();
    if (es_admin()) {
        header('Location: admin.php');
        exit;
    }
}

// Cada sesión tiene un identificador opaco para distinguir las reservas temporales.
function obtener_propietario_reservas(): string
{
    if (empty($_SESSION['reserva_owner']) || !is_string($_SESSION['reserva_owner'])) {
        $_SESSION['reserva_owner'] = bin2hex(random_bytes(16));
    }
    if (!isset($_SESSION['numeros_rifa']) || !is_array($_SESSION['numeros_rifa'])) {
        $_SESSION['numeros_rifa'] = [];
    }
    return $_SESSION['reserva_owner'];
}

function abrir_bloqueo_estado_rifa(bool $exclusivo = true)
{
    $directorio = dirname(ARCHIVO_BLOQUEO_RIFA);
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        return false;
    }
    $fp = fopen(ARCHIVO_BLOQUEO_RIFA, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, $exclusivo ? LOCK_EX : LOCK_SH)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function cerrar_bloqueo_estado_rifa($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function leer_estado_rifa(): array
{
    $bloqueo = abrir_bloqueo_estado_rifa(false);
    if ($bloqueo === false) {
        return [[], []];
    }
    $reservas = leer_json_archivo_compartido(ARCHIVO_RESERVAS);
    $participantes = leer_json_archivo_compartido(ARCHIVO_PARTICIPANTES);
    cerrar_bloqueo_estado_rifa($bloqueo);
    return [$reservas, $participantes];
}

// Todas las modificaciones de la rifa web pasan por el mismo bloqueo que la API.
function modificar_reservas_globales(callable $operacion): array
{
    $directorio = dirname(ARCHIVO_RESERVAS);
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        return ['ok' => false, 'mensaje' => 'No se pudo crear la carpeta de datos de la rifa.'];
    }

    $bloqueoEstado = abrir_bloqueo_estado_rifa(true);
    if ($bloqueoEstado === false) {
        return ['ok' => false, 'mensaje' => 'No se pudo bloquear el estado de la rifa. Intente nuevamente.'];
    }

    $fp = fopen(ARCHIVO_RESERVAS, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if (is_resource($fp)) fclose($fp);
        cerrar_bloqueo_estado_rifa($bloqueoEstado);
        return ['ok' => false, 'mensaje' => 'No se pudo acceder al archivo de reservas.'];
    }

    rewind($fp);
    $contenido = stream_get_contents($fp);
    $reservas = json_decode($contenido ?: '[]', true);
    if (!is_array($reservas)) $reservas = [];

    $resultado = $operacion($reservas);
    $json = json_encode(array_values($reservas), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    rewind($fp);
    ftruncate($fp, 0);
    $escrito = fwrite($fp, $json === false ? '[]' : $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    cerrar_bloqueo_estado_rifa($bloqueoEstado);

    if ($escrito === false) {
        return ['ok' => false, 'mensaje' => 'No se pudieron guardar los cambios de la reserva.'];
    }
    return is_array($resultado) ? $resultado : ['ok' => false, 'mensaje' => 'No se pudo completar la operación.'];
}

function liberar_reservas_propietario(string $propietario): void
{
    if ($propietario === '') return;
    modificar_reservas_globales(function (&$reservas) use ($propietario) {
        $reservas = array_values(array_filter($reservas, function ($r) use ($propietario) {
            if ((string)($r['propietario'] ?? '') !== $propietario) return true;
            // Se conserva el registro del comprobante para revisión, pero solo confirmado cuenta como ocupado.
            return in_array((string)($r['estado'] ?? 'temporal'), ['comprobante_enviado', 'confirmado'], true);
        }));
        return ['ok' => true, 'mensaje' => 'Reservas temporales liberadas.'];
    });
}

function encabezado(string $titulo, bool $menu = true, string $clase = ''): void
{
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($titulo) . ' - Rifa UPLA</title><link rel="stylesheet" href="estilos.css"></head><body>';
    if ($menu && !empty($_SESSION['usuario'])) {
        if (es_admin()) {
            echo '<nav><strong>Rifa UPLA · Administrador</strong>
                  <a href="admin.php">Administración</a>
                  <a href="premios.php">Premios</a>
                  <a class="salir" href="salir.php">Cerrar sesión</a></nav>';
        } else {
            echo '<nav><strong>Rifa UPLA</strong>
                  <a href="panel.php">Inicio</a>
                  <a href="rifa.php">Números de rifa</a>
                  <a href="premios.php">Premios</a>
                  <a class="salir" href="salir.php">Cerrar sesión</a></nav>';
        }
    }
    $claseSegura = preg_replace('/[^A-Za-z0-9 _-]/', '', $clase);
    echo '<main class="' . e($claseSegura) . '"><h1>' . e($titulo) . '</h1>';
}

function pie(): void
{
    echo '</main></body></html>';
}

asegurar_usuarios_iniciales();
