<?php
require 'inc.php';
requiere_usuario();

$propietario = obtener_propietario_reservas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = post_texto('accion');
    $numero = filter_var(post_texto('numero'), FILTER_VALIDATE_INT);

    if ($accion === 'agregar') {
        if ($numero === false || $numero < 1 || $numero > MAX_NUMERO) {
            flash('error', 'Seleccione un número entero entre 1 y ' . MAX_NUMERO . '.');
        } else {
            $usuarioActual = (string)($_SESSION['usuario'] ?? '');
            $resultado = modificar_reservas_globales(function (&$reservas) use ($numero, $propietario, $usuarioActual) {
                $participantes = leer_json_archivo_compartido(ARCHIVO_PARTICIPANTES);
                $asignados = [];
                foreach ($participantes as $participante) {
                    $listaNumeros = $participante['numeros_rifa'] ?? [];
                    if (!is_array($listaNumeros)) $listaNumeros = [];
                    $numeroUnico = (int)($participante['numero_rifa'] ?? 0);
                    if ($numeroUnico > 0) $listaNumeros[] = $numeroUnico;
                    foreach ($listaNumeros as $numeroAsignado) {
                        $numeroAsignado = (int)$numeroAsignado;
                        if ($numeroAsignado >= 1 && $numeroAsignado <= MAX_NUMERO) $asignados[] = $numeroAsignado;
                    }
                }

                if (in_array($numero, $asignados, true)) {
                    return ['ok' => false, 'mensaje' => "El número $numero ya está asignado a un participante y no se puede elegir."];
                }
                foreach ($reservas as $reserva) {
                    if ((int)($reserva['numero'] ?? 0) !== $numero) continue;
                    $estadoReserva = (string)($reserva['estado'] ?? 'temporal');
                    $propietarioReserva = (string)($reserva['propietario'] ?? '');
                    if (in_array($estadoReserva, ['confirmado', 'aceptado'], true)) {
                        return ['ok' => false, 'mensaje' => "El número $numero ya fue aprobado por el administrador y está ocupado."];
                    }
                    if (in_array($estadoReserva, ['temporal', 'comprobante_enviado'], true)) {
                        if ($propietarioReserva === $propietario && $estadoReserva === 'temporal') {
                            return ['ok' => false, 'mensaje' => "El número $numero ya está en tu selección."];
                        }
                        return ['ok' => false, 'mensaje' => "El número $numero está reservado temporalmente o tiene un comprobante pendiente de revisión. No se puede elegir por ahora."];
                    }
                }

                $reservas[] = [
                    'numero' => $numero,
                    'propietario' => $propietario,
                    'usuario' => $usuarioActual,
                    'estado' => 'temporal',
                    'reservado_en' => date(DATE_ATOM),
                ];
                return ['ok' => true, 'mensaje' => "Número $numero reservado correctamente."];
            });

            if (!empty($resultado['ok'])) {
                $_SESSION['numeros_rifa'][] = $numero;
                $_SESSION['numeros_rifa'] = array_values(array_unique(array_map('intval', $_SESSION['numeros_rifa'])));
            }
            flash(!empty($resultado['ok']) ? 'ok' : 'error', $resultado['mensaje']);
        }
    } elseif ($accion === 'quitar') {
        if ($numero === false) {
            flash('error', 'El número indicado no es válido.');
        } else {
            $resultado = modificar_reservas_globales(function (&$reservas) use ($numero, $propietario) {
                foreach ($reservas as $i => $reserva) {
                    if ((int)($reserva['numero'] ?? 0) === $numero
                        && (string)($reserva['propietario'] ?? '') === $propietario
                        && !in_array((string)($reserva['estado'] ?? 'temporal'), ['comprobante_enviado', 'confirmado'], true)) {
                        array_splice($reservas, $i, 1);
                        return ['ok' => true, 'mensaje' => "Número $numero eliminado de sus reservas."];
                    }
                }
                return ['ok' => false, 'mensaje' => 'Solo puede quitar números reservados por esta sesión.'];
            });
            if (!empty($resultado['ok'])) {
                $_SESSION['numeros_rifa'] = array_values(array_filter(
                    $_SESSION['numeros_rifa'], fn($n) => (int)$n !== $numero
                ));
            }
            flash(!empty($resultado['ok']) ? 'ok' : 'error', $resultado['mensaje']);
        }
    } elseif ($accion === 'vaciar') {
        $resultado = modificar_reservas_globales(function (&$reservas) use ($propietario) {
            $cantidadAntes = count($reservas);
            $reservas = array_values(array_filter(
                $reservas,
                fn($r) => (string)($r['propietario'] ?? '') !== $propietario
            ));
            $eliminados = $cantidadAntes - count($reservas);
            return ['ok' => true, 'mensaje' => "Se liberaron $eliminados número(s) de esta sesión."];
        });
        $_SESSION['numeros_rifa'] = [];
        flash('ok', $resultado['mensaje']);
    } else {
        flash('error', 'La operación solicitada no es válida.');
    }

    header('Location: rifa.php');
    exit;
}

[$reservas, $participantes] = leer_estado_rifa();
$asignados = [];
foreach ($participantes as $participante) {
    $numerosParticipante = $participante['numeros_rifa'] ?? [];
    if (!is_array($numerosParticipante)) $numerosParticipante = [];
    $numeroUnico = (int)($participante['numero_rifa'] ?? 0);
    if ($numeroUnico > 0) $numerosParticipante[] = $numeroUnico;
    foreach ($numerosParticipante as $numeroParticipante) {
        $numeroParticipante = (int)$numeroParticipante;
        if ($numeroParticipante >= 1 && $numeroParticipante <= MAX_NUMERO) $asignados[] = $numeroParticipante;
    }
}
$asignados = array_values(array_unique($asignados));
$misNumerosGlobales = [];
$reservadosOtros = [];
$pendientesRevision = [];
$ocupados = $asignados;
foreach ($reservas as $reserva) {
    $n = (int)($reserva['numero'] ?? 0);
    if ($n >= 1 && $n <= MAX_NUMERO) {
        $estadoReserva = (string)($reserva['estado'] ?? 'temporal');
        $propietarioReserva = (string)($reserva['propietario'] ?? '');
        if (in_array($estadoReserva, ['confirmado', 'aceptado'], true)) {
            $ocupados[] = $n;
        } elseif ($estadoReserva === 'comprobante_enviado') {
            // No está vendido todavía, pero se mantiene retenido mientras el administrador revisa el pago.
            $pendientesRevision[] = $n;
        } elseif ($estadoReserva === 'temporal' && $propietarioReserva !== $propietario) {
            // Una selección temporal de otra sesión evita que dos personas elijan el mismo número.
            $reservadosOtros[] = $n;
        }
        if ($propietarioReserva === $propietario
            && $estadoReserva === 'temporal'
            && !in_array($n, $asignados, true)) {
            $misNumerosGlobales[] = $n;
        }
    }
}

// La selección queda guardada en la sesión para conservar cantidad y total al actualizar F5.
// Se sincroniza con el registro compartido para corregir cualquier cambio hecho en otra pestaña.
$misNumeros = array_values(array_unique(array_map('intval', $misNumerosGlobales)));
sort($misNumeros);
$_SESSION['numeros_rifa'] = $misNumeros;
$ocupados = array_values(array_unique(array_map('intval', $ocupados)));
sort($ocupados);
$cantidad = count($_SESSION['numeros_rifa']);
$total = $cantidad * PRECIO_NUMERO;

if (get_texto('estado') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'mios' => $misNumeros,
        'ocupados' => $ocupados,
        'reservados' => array_values(array_unique(array_map('intval', $reservadosOtros))),
        'pendientes' => array_values(array_unique(array_map('intval', $pendientesRevision))),
        'maximo' => MAX_NUMERO,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

encabezado('Reserva de números de rifa');
mostrar_flash();
?>
<p>Elija uno o más números disponibles. Una selección de otra sesión o un comprobante pendiente se mostrará en amarillo y no podrá elegirlo otra persona. Ese estado no significa que esté vendido: solo se marcará como comprado (rojo) cuando el administrador acepte el comprobante. Si lo rechaza, volverá a estar disponible (verde).</p>
<form method="POST" action="rifa.php" class="formulario-numero-manual">
    <input type="hidden" name="accion" value="agregar">
    <div class="fila-inline">
        <div>
            <label for="numero">O escribir un número (1 a <?= MAX_NUMERO ?>)</label>
            <input type="number" id="numero" name="numero" min="1" max="<?= MAX_NUMERO ?>" step="1" required>
        </div>
        <button type="submit">Reservar número</button>
    </div>
</form>

<div class="leyenda-numeros">
    <span><i class="estado libre"></i> Disponible</span>
    <span><i class="estado reservado"></i> Seleccionado por otra sesión</span>
    <span><i class="estado pendiente"></i> Comprobante pendiente de revisión</span>
    <span><i class="estado ocupado"></i> Comprado y aprobado</span>
    <span><i class="estado mio"></i> Mi selección (clic para quitar)</span>
</div>
<div id="estado-live" class="texto-actualizacion" aria-live="polite">La disponibilidad se actualiza cada 5 segundos.</div>
<div class="cuadricula-numeros" aria-label="Disponibilidad de números de rifa">
<?php for ($n = 1; $n <= MAX_NUMERO; $n++): ?>
    <?php
        $esMio = in_array($n, $misNumeros, true);
        $esOcupado = in_array($n, $ocupados, true);
        $esPendiente = in_array($n, $pendientesRevision, true);
        $esReservadoOtro = in_array($n, $reservadosOtros, true);
        $clase = $esOcupado ? 'ocupado' : ($esPendiente ? 'pendiente' : ($esReservadoOtro ? 'reservado' : ($esMio ? 'mio' : 'libre')));
        $accionBoton = $esMio ? 'quitar' : 'agregar';
        $deshabilitado = ($esOcupado || $esPendiente || $esReservadoOtro) && !$esMio;
        $ayuda = $esOcupado ? 'Comprado y aprobado. No se puede elegir.' : ($esPendiente ? 'Comprobante pendiente de revisión. No está vendido, pero permanece reservado.' : ($esReservadoOtro ? 'Seleccionado temporalmente por otra sesión.' : ($esMio ? 'Seleccionado por ti. Haz clic para quitarlo.' : 'Disponible. Haz clic para seleccionar.')));
    ?>
    <form method="POST" action="rifa.php" class="form-numero">
        <input type="hidden" name="accion" value="<?= e($accionBoton) ?>">
        <button class="numero-rifa <?= $clase ?>" type="submit" name="numero" value="<?= $n ?>"
                data-numero="<?= $n ?>" title="<?= e($ayuda) ?>" aria-label="Número <?= $n ?>: <?= e($ayuda) ?>"
                <?= $deshabilitado ? 'disabled aria-disabled="true"' : '' ?>><?= str_pad((string)$n, 2, '0', STR_PAD_LEFT) ?></button>
    </form>
<?php endfor; ?>
</div>

<h3>Mis números reservados</h3>
<?php if ($cantidad === 0): ?>
    <p>Aún no ha reservado números en esta sesión.</p>
<?php else: ?>
    <div class="chips">
    <?php foreach ($_SESSION['numeros_rifa'] as $n): ?>
        <div class="chip">
            <strong>N° <?= (int)$n ?></strong>
            <form method="POST" action="rifa.php">
                <input type="hidden" name="accion" value="quitar">
                <input type="hidden" name="numero" value="<?= (int)$n ?>">
                <button type="submit" class="peligro">Quitar</button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="resumen">
    <div>Cantidad de números reservados por ti: <strong><?= $cantidad ?></strong></div>
    <div>Precio por número: <strong><?= pesos(PRECIO_NUMERO) ?></strong></div>
    <div class="total">Total a pagar: <?= pesos($total) ?></div>
</div>

<?php if ($cantidad > 0): ?>
<div class="acciones-rifa">
    <form method="GET" action="subir_comprobante.php" class="form-pagar">
        <button type="submit" class="boton-pagar">Pagar</button>
    </form>
    <form method="POST" action="rifa.php" class="form-vaciar">
        <input type="hidden" name="accion" value="vaciar">
        <button type="submit" class="peligro">Vaciar mis reservas</button>
    </form>
</div>
<p class="nota-pago">Al presionar «Pagar», continuarás a la página para subir el comprobante de pago.</p>
<?php endif; ?>

<script>
(() => {
    const botones = document.querySelectorAll('button[data-numero]');
    const estado = document.getElementById('estado-live');

    async function actualizarDisponibilidad() {
        try {
            const respuesta = await fetch('rifa.php?estado=1&v=' + Date.now(), {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            if (!respuesta.ok) return;
            const datos = await respuesta.json();
            const mios = new Set((datos.mios || []).map(Number));
            const ocupados = new Set((datos.ocupados || []).map(Number));
            const reservados = new Set((datos.reservados || []).map(Number));
            const pendientes = new Set((datos.pendientes || []).map(Number));

            botones.forEach((boton) => {
                const numero = Number(boton.dataset.numero);
                const formulario = boton.closest('form');
                const campoAccion = formulario.querySelector('input[name="accion"]');
                boton.classList.remove('libre', 'ocupado', 'mio');

                if (ocupados.has(numero)) {
                    boton.classList.add('ocupado');
                    boton.disabled = true;
                    boton.type = 'button';
                    campoAccion.value = 'agregar';
                    boton.title = 'Comprado y aprobado. No se puede elegir.';
                    boton.setAttribute('aria-label', 'Número ' + numero + ': comprado y aprobado. No se puede elegir.');
                } else if (pendientes.has(numero)) {
                    boton.classList.add('pendiente');
                    boton.disabled = true;
                    boton.type = 'button';
                    campoAccion.value = 'agregar';
                    boton.title = 'Comprobante pendiente de revisión. No está vendido, pero permanece reservado.';
                    boton.setAttribute('aria-label', 'Número ' + numero + ': comprobante pendiente de revisión.');
                } else if (reservados.has(numero)) {
                    boton.classList.add('reservado');
                    boton.disabled = true;
                    boton.type = 'button';
                    campoAccion.value = 'agregar';
                    boton.title = 'Seleccionado temporalmente por otra sesión.';
                    boton.setAttribute('aria-label', 'Número ' + numero + ': seleccionado temporalmente por otra sesión.');
                } else if (mios.has(numero)) {
                    boton.classList.add('mio');
                    boton.disabled = false;
                    boton.type = 'submit';
                    campoAccion.value = 'quitar';
                    boton.title = 'Seleccionado por ti. Haz clic para quitarlo.';
                    boton.setAttribute('aria-label', 'Número ' + numero + ': seleccionado por ti. Haz clic para quitarlo.');
                } else {
                    boton.classList.add('libre');
                    boton.disabled = false;
                    boton.type = 'submit';
                    campoAccion.value = 'agregar';
                    boton.title = 'Disponible. Haz clic para seleccionar.';
                    boton.setAttribute('aria-label', 'Número ' + numero + ': disponible. Haz clic para seleccionar.');
                }
            });
            if (estado) estado.textContent = 'Disponibilidad actualizada: ' + new Date().toLocaleTimeString();
        } catch (error) {
            if (estado) estado.textContent = 'No se pudo actualizar la disponibilidad automáticamente. Recargue la página para comprobarla.';
        }
    }

    window.setInterval(actualizarDisponibilidad, 5000);
})();
</script>
<?php pie(); ?>
