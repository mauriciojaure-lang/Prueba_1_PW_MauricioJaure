<?php
// GET /api/eliminar.php?id=3 (protegido; el método GET es el exigido por la pauta)
require __DIR__ . '/helpers.php';
exigir_metodo('GET');
exigir_sesion();

$id = entero_positivo($_GET['id'] ?? null);
if ($id === false) {
    responder(400, ['ok' => false, 'mensaje' => 'Debe enviar un id válido por GET (?id=1).']);
}

$bloqueo = bloquear_estado_rifa_api();
if ($bloqueo === false) {
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo bloquear el estado de la rifa.']);
}

$participantes = leer_json('participantes.json');
foreach ($participantes as $participante) {
    if ((int)($participante['id'] ?? 0) === $id && !empty($participante['usuario_id'])) {
        desbloquear_estado_rifa_api($bloqueo);
        responder(409, ['ok' => false, 'mensaje' => 'Este participante está vinculado a una cuenta web. No se puede eliminar solo desde la API.']);
    }
}
$restantes = array_values(array_filter($participantes, fn($p) => (int)($p['id'] ?? 0) !== $id));
if (count($restantes) === count($participantes)) {
    desbloquear_estado_rifa_api($bloqueo);
    responder(404, ['ok' => false, 'mensaje' => "No existe un participante con id $id."]);
}

$guardado = guardar_json('participantes.json', $restantes);
desbloquear_estado_rifa_api($bloqueo);
if (!$guardado) {
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo guardar el archivo.']);
}
responder(200, ['ok' => true, 'mensaje' => "Participante $id eliminado."]);
