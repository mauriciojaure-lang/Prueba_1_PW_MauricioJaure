<?php
// GET /api/premios.php   (protegido)
require __DIR__ . '/helpers.php';
exigir_metodo('GET');
exigir_sesion();

$premios = leer_json('premios.json');
responder(200, ['ok' => true, 'total' => count($premios), 'premios' => $premios]);
