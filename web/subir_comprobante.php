<?php
require 'inc.php';
requiere_usuario();

const TAMANO_MAXIMO = 5 * 1024 * 1024; // 5 MB
const EXTENSIONES_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'];

$propietario = obtener_propietario_reservas();
$numeros = array_values(array_unique(array_map('intval', $_SESSION['numeros_rifa'] ?? [])));
sort($numeros);

function mensaje_error_subida(int $codigo): string
{
    return match ($codigo) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subió solo parcialmente.',
        UPLOAD_ERR_NO_FILE => 'No seleccionaste ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Error interno del servidor al guardar el archivo.',
        default => 'Ocurrió un error desconocido al subir el archivo.',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$numeros) {
        flash('error', 'Primero debes seleccionar al menos un número en la rifa.');
        header('Location: rifa.php');
        exit;
    }

    $f = $_FILES['comprobante'] ?? null;
    if ($f === null || !isset($f['error']) || !is_int($f['error'])) {
        flash('error', 'No se recibió ningún archivo.');
        header('Location: subir_comprobante.php');
        exit;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('error', mensaje_error_subida((int)$f['error']));
        header('Location: subir_comprobante.php');
        exit;
    }
    if ((int)$f['size'] > TAMANO_MAXIMO) {
        flash('error', 'El archivo supera los 5 MB permitidos.');
        header('Location: subir_comprobante.php');
        exit;
    }

    $extension = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, EXTENSIONES_PERMITIDAS, true)) {
        flash('error', 'Tipo de archivo no permitido. Usa: ' . implode(', ', EXTENSIONES_PERMITIDAS) . '.');
        header('Location: subir_comprobante.php');
        exit;
    }

    if (!is_dir(CARPETA_COMPROBANTES) && !mkdir(CARPETA_COMPROBANTES, 0755, true) && !is_dir(CARPETA_COMPROBANTES)) {
        flash('error', 'No se pudo preparar la carpeta para guardar el comprobante.');
        header('Location: subir_comprobante.php');
        exit;
    }

    $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $usuarioSeguro = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$_SESSION['usuario']);
    $nombreArchivo = date('Ymd_His') . '_' . $usuarioSeguro . '_' . bin2hex(random_bytes(3)) . '_' . substr((string)$base, 0, 40) . '.' . $extension;
    $rutaFinal = CARPETA_COMPROBANTES . $nombreArchivo;

    if (!is_uploaded_file($f['tmp_name']) || !move_uploaded_file($f['tmp_name'], $rutaFinal)) {
        flash('error', 'No se pudo guardar el archivo en el servidor.');
        header('Location: subir_comprobante.php');
        exit;
    }

    $monto = count($numeros) * PRECIO_NUMERO;
    $usuarioActual = (string)$_SESSION['usuario'];
    $fecha = date(DATE_ATOM);

    // El envío del comprobante registra una solicitud pendiente. Los números quedan retenidos para evitar duplicados, pero no se consideran vendidos hasta la aprobación.
    $resultadoReserva = modificar_reservas_globales(function (&$reservas) use ($propietario, $numeros, $nombreArchivo, $monto, $usuarioActual, $fecha) {
        $modificados = 0;
        foreach ($reservas as &$reserva) {
            $numeroReserva = (int)($reserva['numero'] ?? 0);
            if ((string)($reserva['propietario'] ?? '') === $propietario
                && in_array($numeroReserva, $numeros, true)
                && !in_array((string)($reserva['estado'] ?? 'temporal'), ['comprobante_enviado', 'confirmado'], true)) {
                $reserva['estado'] = 'comprobante_enviado';
                $reserva['usuario'] = $usuarioActual;
                $reserva['comprobante'] = $nombreArchivo;
                $reserva['monto'] = $monto;
                $reserva['comprobante_fecha'] = $fecha;
                $modificados++;
            }
        }
        unset($reserva);
        return [
            'ok' => $modificados === count($numeros),
            'mensaje' => $modificados === count($numeros) ? 'Reservas actualizadas.' : 'Ya no están disponibles todas tus reservas. Vuelve a revisar la rifa.',
            'cantidad' => $modificados,
        ];
    });

    if (empty($resultadoReserva['ok'])) {
        @unlink($rutaFinal);
        flash('error', $resultadoReserva['mensaje'] ?? 'No se pudo asociar el comprobante a las reservas.');
        header('Location: rifa.php');
        exit;
    }

    $registro = [
        'archivo' => $nombreArchivo,
        'usuario' => $usuarioActual,
        'usuario_id' => (int)($_SESSION['user_id'] ?? 0),
        'numeros' => $numeros,
        'monto' => $monto,
        'fecha' => $fecha,
        'estado' => 'pendiente',
    ];
    $guardadoMetadata = modificar_json_bloqueado(ARCHIVO_COMPROBANTES, function (&$comprobantes) use ($registro) {
        $comprobantes[] = $registro;
        return true;
    });

    if (empty($guardadoMetadata['guardado'])) {
        // Si falla el índice del comprobante, revierte el estado de las reservas y elimina el archivo.
        modificar_reservas_globales(function (&$reservas) use ($propietario, $nombreArchivo) {
            foreach ($reservas as &$reserva) {
                if ((string)($reserva['propietario'] ?? '') === $propietario
                    && (string)($reserva['comprobante'] ?? '') === $nombreArchivo) {
                    $reserva['estado'] = 'temporal';
                    unset($reserva['comprobante'], $reserva['monto'], $reserva['comprobante_fecha']);
                }
            }
            unset($reserva);
            return ['ok' => true, 'mensaje' => 'Se revirtieron las reservas.'];
        });
        @unlink($rutaFinal);
        flash('error', 'No se pudo registrar el comprobante. Inténtalo nuevamente.');
        header('Location: subir_comprobante.php');
        exit;
    }

    // El comprobante queda pendiente, pero sus números NO se marcan como ocupados.
    // Solo el administrador puede confirmarlos al aceptar el pago.
    sincronizar_categoria_automatica_usuario((int)($_SESSION['user_id'] ?? 0), $usuarioActual);
    $_SESSION['numeros_rifa'] = [];
    $_SESSION['reserva_owner'] = bin2hex(random_bytes(16));
    flash('ok', 'Comprobante recibido y pendiente de revisión. Tus números quedan retenidos en amarillo, pero no se marcarán como comprados hasta que el administrador acepte el pago. Si lo rechaza, volverán a estar disponibles.');
    header('Location: panel.php');
    exit;
}

if (!$numeros) {
    flash('error', 'Para subir un comprobante, primero selecciona números desde la rifa y presiona «Pagar».');
    header('Location: rifa.php');
    exit;
}

$monto = count($numeros) * PRECIO_NUMERO;
encabezado('Subir comprobante');
mostrar_flash();
?>
<p>Estás enviando un comprobante por los siguientes números:</p>
<div class="chips">
    <?php foreach ($numeros as $numero): ?><span class="chip">N.º <?= (int)$numero ?></span><?php endforeach; ?>
</div>
<div class="resumen">
    <div>Cantidad de números: <strong><?= count($numeros) ?></strong></div>
    <div>Precio por número: <strong><?= pesos(PRECIO_NUMERO) ?></strong></div>
    <div class="total">Total informado: <?= pesos($monto) ?></div>
</div>
<form method="POST" action="subir_comprobante.php" enctype="multipart/form-data">
    <label for="comprobante">Selecciona el comprobante (<?= e(implode(', ', EXTENSIONES_PERMITIDAS)) ?> · máximo 5 MB)</label>
    <input type="file" id="comprobante" name="comprobante" accept=".pdf,.jpg,.jpeg,.png,.gif,.txt" required>
    <button type="submit">Enviar comprobante</button>
</form>
<p class="texto-secundario">El sistema no realiza un cobro bancario. Mientras el comprobante esté pendiente, los números quedan retenidos en amarillo para evitar duplicados, pero no se consideran comprados. Si el administrador rechaza el comprobante, vuelven a estar disponibles.</p>
<?php pie(); ?>
