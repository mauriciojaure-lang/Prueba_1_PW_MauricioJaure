<?php
// POST /api/registrar.php (protegido): nombre, apellido y numero_rifa
require __DIR__ . '/helpers.php';
exigir_metodo('POST');
exigir_sesion();

$in = entrada();
$nombre = texto($in, 'nombre');
$apellido = texto($in, 'apellido');
$numero = entero_positivo($in['numero_rifa'] ?? null);

if ($nombre === '' || $apellido === '' || $numero === false) {
    responder(400, ['ok' => false, 'mensaje' => 'Debe enviar nombre, apellido y numero_rifa (entero mayor a 0).']);
}

$bloqueo = bloquear_estado_rifa_api();
if ($bloqueo === false) {
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo bloquear el estado de la rifa.']);
}

$error = null;
$codigoError = 0;
$nuevo = null;
$participantes = leer_json('participantes.json');

if (numero_en_uso($participantes, $numero)) {
    $error = "El número de rifa $numero ya está asignado a un participante.";
    $codigoError = 409;
} elseif (numero_reservado_en_web($numero)) {
    $error = "El número de rifa $numero está reservado en la web y no se puede asignar.";
    $codigoError = 409;
} else {
    $maxId = 0;
    foreach ($participantes as $p) {
        $maxId = max($maxId, (int)($p['id'] ?? 0));
    }
    $nuevo = [
        'id' => $maxId + 1,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'numero_rifa' => $numero,
        'numeros_rifa' => [$numero],
        'ciudad' => '',
        'categoria' => 'comprador',
        'origen' => 'api',
        'estado' => 'comprador',
    ];
    $participantes[] = $nuevo;
    if (!guardar_json('participantes.json', $participantes)) {
        $error = 'No se pudo guardar el archivo.';
        $codigoError = 500;
        $nuevo = null;
    }
}

desbloquear_estado_rifa_api($bloqueo);
if ($error !== null) {
    responder($codigoError, ['ok' => false, 'mensaje' => $error]);
}
responder(201, ['ok' => true, 'mensaje' => 'Participante registrado.', 'participante' => $nuevo]);
