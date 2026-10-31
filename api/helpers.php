<?php
// Funciones comunes de la API REST
session_name('RIFA_API');
session_start();
header('Content-Type: application/json; charset=utf-8');

const DATA_DIR   = __DIR__ . '/data/';
const ARCHIVO_USUARIOS_WEB = __DIR__ . '/../web/data/usuarios.json';
const ARCHIVO_BLOQUEO_RIFA = __DIR__ . '/data/estado_rifa.lock';

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function exigir_metodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        header('Allow: ' . $metodo);
        responder(405, ['ok' => false, 'mensaje' => "Método no permitido. Use $metodo."]);
    }
}

// Servicios protegidos: solo responden si existe sesión iniciada
function exigir_sesion(): void
{
    if (empty($_SESSION['api_usuario']) || ($_SESSION['api_rol'] ?? '') !== 'admin') {
        responder(401, ['ok' => false, 'mensaje' => 'No autorizado. Debe iniciar sesión como administrador.']);
    }
}

// La API utiliza las mismas cuentas del sistema web, pero solo admite el rol administrador.
function asegurar_usuarios_api(): void
{
    if (file_exists(ARCHIVO_USUARIOS_WEB)) return;
    $directorio = dirname(ARCHIVO_USUARIOS_WEB);
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) return;
    $usuarios = [
        [
            'id' => 1, 'nombre' => 'Administrador', 'apellido' => 'Rifa', 'ciudad' => 'Valparaíso',
            'categoria' => '', 'usuario' => 'admin', 'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'rol' => 'admin', 'origen' => 'demo', 'creado_en' => date(DATE_ATOM),
        ],
        [
            'id' => 2, 'nombre' => 'Usuario', 'apellido' => 'UPLA', 'ciudad' => 'Valparaíso',
            'categoria' => '', 'usuario' => 'userupla', 'password_hash' => password_hash('upla123', PASSWORD_DEFAULT),
            'rol' => 'usuario', 'origen' => 'demo', 'creado_en' => date(DATE_ATOM),
        ],
    ];
    $fp = @fopen(ARCHIVO_USUARIOS_WEB, 'x');
    if ($fp !== false) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

function buscar_cuenta_app(string $nombre): ?array
{
    asegurar_usuarios_api();
    if (!file_exists(ARCHIVO_USUARIOS_WEB)) return null;
    $fp = fopen(ARCHIVO_USUARIOS_WEB, 'r');
    if ($fp === false) return null;
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }
    $contenido = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $datos = json_decode($contenido ?: '[]', true);
    if (!is_array($datos)) return null;
    foreach ($datos as $cuenta) {
        if (isset($cuenta['usuario']) && hash_equals((string)$cuenta['usuario'], $nombre)) return $cuenta;
    }
    return null;
}

function leer_json(string $archivo): array
{
    $ruta = DATA_DIR . $archivo;
    if (!file_exists($ruta)) return [];
    $fp = fopen($ruta, 'r');
    if ($fp === false) return [];
    if (!flock($fp, LOCK_SH)) { fclose($fp); return []; }
    $contenido = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $datos = json_decode($contenido ?: '[]', true);
    return is_array($datos) ? $datos : [];
}

function guardar_json(string $archivo, array $datos): bool
{
    $ruta = DATA_DIR . $archivo;
    $directorio = dirname($ruta);
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) return false;
    $json = json_encode(array_values($datos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $fp = fopen($ruta, 'c+');
    if ($fp === false) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    rewind($fp);
    ftruncate($fp, 0);
    $escrito = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $escrito !== false;
}

// Acepta form-data / x-www-form-urlencoded y también JSON en el body (raw)
function entrada(): array
{
    $json = json_decode(file_get_contents('php://input'), true);
    return is_array($json) ? array_merge($_POST, $json) : $_POST;
}

function texto(array $in, string $clave): string
{
    return (isset($in[$clave]) && is_scalar($in[$clave])) ? trim((string)$in[$clave]) : '';
}

function entero_positivo($valor): int|false
{
    if ($valor === '' || $valor === null || is_array($valor)) {
        return false;
    }
    return filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

function numero_en_uso(array $participantes, int $numero, int $ignorarId = 0): bool
{
    foreach ($participantes as $p) {
        if ((int)($p['id'] ?? 0) === $ignorarId) continue;
        $lista = $p['numeros_rifa'] ?? [];
        if (!is_array($lista)) $lista = [];
        $unico = (int)($p['numero_rifa'] ?? 0);
        if ($unico > 0) $lista[] = $unico;
        foreach ($lista as $n) {
            if ((int)$n === $numero) return true;
        }
    }
    return false;
}


// Bloqueo común con la web: serializa los cambios en participantes y reservas.
function bloquear_estado_rifa_api()
{
    $fp = fopen(ARCHIVO_BLOQUEO_RIFA, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function desbloquear_estado_rifa_api($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Debe usarse mientras se mantiene el bloqueo común de la rifa.
function numero_reservado_en_web(int $numero): bool
{
    $ruta = __DIR__ . '/../web/data/reservas.json';
    if (!file_exists($ruta)) {
        return false;
    }
    $fp = fopen($ruta, 'r');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return false;
    }
    $contenido = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $reservas = json_decode($contenido ?: '[]', true);
    if (!is_array($reservas)) {
        return false;
    }
    foreach ($reservas as $reserva) {
        if ((int)($reserva['numero'] ?? 0) === $numero
            && in_array((string)($reserva['estado'] ?? ''), ['temporal', 'comprobante_enviado', 'confirmado', 'aceptado'], true)) {
            return true;
        }
    }
    return false;
}


// Migra de forma idempotente cuentas web antiguas a participantes.json.
// Las cuentas demo (admin y userupla) no se convierten en participantes.
function sincronizar_cuentas_web_a_participantes(): void
{
    if (!file_exists(ARCHIVO_USUARIOS_WEB)) return;
    $fpUsuarios = @fopen(ARCHIVO_USUARIOS_WEB, 'r');
    if ($fpUsuarios === false) return;
    if (!flock($fpUsuarios, LOCK_SH)) { fclose($fpUsuarios); return; }
    $contenidoUsuarios = stream_get_contents($fpUsuarios);
    flock($fpUsuarios, LOCK_UN);
    fclose($fpUsuarios);

    $usuarios = json_decode($contenidoUsuarios ?: '[]', true);
    if (!is_array($usuarios) || !$usuarios) return;

    $bloqueo = bloquear_estado_rifa_api();
    if ($bloqueo === false) return;
    $participantes = leer_json('participantes.json');
    $idsVinculados = [];
    $maxId = 0;
    foreach ($participantes as $participante) {
        $usuarioIdVinculado = (int)($participante['usuario_id'] ?? 0);
        if ($usuarioIdVinculado > 0) $idsVinculados[$usuarioIdVinculado] = true;
        $maxId = max($maxId, (int)($participante['id'] ?? 0));
    }

    $cambios = false;
    foreach ($usuarios as $cuenta) {
        $usuarioId = (int)($cuenta['id'] ?? 0);
        $nombreUsuario = (string)($cuenta['usuario'] ?? '');
        $rol = (string)($cuenta['rol'] ?? 'usuario');
        if ($usuarioId < 1 || $rol === 'admin' || $nombreUsuario === '') continue;
        if (($cuenta['origen'] ?? '') === 'demo' || $nombreUsuario === 'userupla') continue;
        if (isset($idsVinculados[$usuarioId])) continue;

        $maxId++;
        $participantes[] = [
            'id' => $maxId,
            'nombre' => (string)($cuenta['nombre'] ?? ''),
            'apellido' => (string)($cuenta['apellido'] ?? ''),
            'numero_rifa' => null,
            'numeros_rifa' => [],
            'ciudad' => (string)($cuenta['ciudad'] ?? ''),
            'categoria' => in_array((string)($cuenta['categoria'] ?? ''), ['user', 'cliente', 'comprador'], true) ? (string)$cuenta['categoria'] : 'user',
            'usuario_id' => $usuarioId,
            'usuario' => $nombreUsuario,
            'rol' => 'usuario',
            'origen' => 'registro_web',
            'estado' => in_array((string)($cuenta['categoria'] ?? ''), ['user', 'cliente', 'comprador'], true) ? (string)$cuenta['categoria'] : 'user',
            'creado_en' => (string)($cuenta['creado_en'] ?? date(DATE_ATOM)),
        ];
        $idsVinculados[$usuarioId] = true;
        $cambios = true;
    }
    if ($cambios) guardar_json('participantes.json', $participantes);
    desbloquear_estado_rifa_api($bloqueo);
}
