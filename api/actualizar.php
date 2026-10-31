<?php
// POST /api/actualizar.php (protegido): id + campos que se desean modificar
require __DIR__ . '/helpers.php';
exigir_metodo('POST');
exigir_sesion();

$in = entrada();
$id = entero_positivo($in['id'] ?? null);
if ($id === false) {
    responder(400, ['ok' => false, 'mensaje' => 'Debe enviar un id válido.']);
}

$bloqueo = bloquear_estado_rifa_api();
if ($bloqueo === false) {
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo bloquear el estado de la rifa.']);
}

$error = null;
$codigoError = 0;
$actualizado = null;
$participantes = leer_json('participantes.json');
$pos = null;
foreach ($participantes as $i => $p) {
    if ((int)($p['id'] ?? 0) === $id) {
        $pos = $i;
        break;
    }
}

if ($pos === null) {
    $error = "No existe un participante con id $id.";
    $codigoError = 404;
} else {
    $candidato = $participantes[$pos];
    $cambios = 0;
    foreach (['nombre', 'apellido'] as $campo) {
        if (array_key_exists($campo, $in)) {
            $valor = texto($in, $campo);
            if ($valor === '') {
                $error = "El campo $campo no puede estar vacío.";
                $codigoError = 400;
                break;
            }
            $candidato[$campo] = $valor;
            $cambios++;
        }
    }

    if ($error === null && array_key_exists('numero_rifa', $in)) {
        $numero = entero_positivo($in['numero_rifa']);
        if ($numero === false) {
            $error = 'numero_rifa debe ser un entero mayor a 0.';
            $codigoError = 400;
        } elseif (numero_en_uso($participantes, $numero, $id)) {
            $error = "El número de rifa $numero ya está asignado a otro participante.";
            $codigoError = 409;
        } elseif (numero_reservado_en_web($numero)) {
            $error = "El número de rifa $numero está reservado en la web y no se puede asignar.";
            $codigoError = 409;
        } else {
            $listaNumeros = $candidato['numeros_rifa'] ?? [];
            if (!is_array($listaNumeros)) $listaNumeros = [];
            $listaNumeros[] = $numero;
            $listaNumeros = array_values(array_unique(array_map('intval', $listaNumeros)));
            sort($listaNumeros);
            $candidato['numeros_rifa'] = $listaNumeros;
            $candidato['numero_rifa'] = $listaNumeros[0] ?? $numero;
            $candidato['categoria'] = 'comprador';
            $candidato['estado'] = 'comprador';
            $cambios++;
        }
    }

    if ($error === null && $cambios === 0) {
        $error = 'No se enviaron datos para modificar.';
        $codigoError = 400;
    }

    if ($error === null) {
        $participantes[$pos] = $candidato;
        if (!guardar_json('participantes.json', $participantes)) {
            $error = 'No se pudo guardar el archivo.';
            $codigoError = 500;
        } else {
            $actualizado = $candidato;
        }
    }
}

desbloquear_estado_rifa_api($bloqueo);
if ($error !== null) {
    responder($codigoError, ['ok' => false, 'mensaje' => $error]);
}
responder(200, ['ok' => true, 'mensaje' => 'Participante actualizado.', 'participante' => $actualizado]);
