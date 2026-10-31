<?php
// Router para el servidor integrado de PHP. Evita servir archivos de /private directamente.
$rutaSolicitada = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rutaDecodificada = rawurldecode($rutaSolicitada);
$rutaNormalizada = str_replace('\\', '/', $rutaDecodificada);

if (preg_match('#(^|/)\.\.(/|$)#', $rutaNormalizada)) {
    http_response_code(400);
    exit('Ruta no válida.');
}

$directorioPrivado = realpath(__DIR__ . '/private');
$rutaArchivo = realpath(__DIR__ . $rutaNormalizada);
if (($directorioPrivado && ($rutaNormalizada === '/private' || str_starts_with($rutaNormalizada, '/private/')))
    || ($directorioPrivado && $rutaArchivo && ($rutaArchivo === $directorioPrivado || str_starts_with($rutaArchivo, $directorioPrivado . DIRECTORY_SEPARATOR)))) {
    http_response_code(404);
    exit('No encontrado.');
}

if ($rutaNormalizada === '/') {
    header('Location: /web/index.php');
    exit;
}

// Para el resto, el servidor integrado procesa PHP o entrega archivos estáticos normalmente.
return false;
