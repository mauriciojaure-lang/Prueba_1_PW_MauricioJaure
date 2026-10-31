<?php
// GET /api/participantes.php (protegido): devuelve participantes creados por API y cuentas nuevas registradas en la web.
require __DIR__ . '/helpers.php';
exigir_metodo('GET');
exigir_sesion();

// Migra cuentas web anteriores que todavía no tengan ficha vinculada.
sincronizar_cuentas_web_a_participantes();

$participantes = leer_json('participantes.json');
$totalConNumero = 0;
foreach ($participantes as $participante) {
    $lista = $participante['numeros_rifa'] ?? [];
    if (!is_array($lista)) $lista = [];
    $unico = (int)($participante['numero_rifa'] ?? 0);
    if ($unico > 0) $lista[] = $unico;
    if (count(array_unique(array_filter(array_map('intval', $lista), fn($n) => $n > 0))) > 0) {
        $totalConNumero++;
    }
}

responder(200, [
    'ok' => true,
    'total' => count($participantes),
    'total_con_numero' => $totalConNumero,
    'total_pendientes' => count($participantes) - $totalConNumero,
    'participantes' => $participantes,
]);
