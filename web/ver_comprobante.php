<?php
require 'inc.php';
requiere_admin();

$nombre = get_texto('archivo');
if ($nombre === '' || basename($nombre) !== $nombre || str_starts_with($nombre, '.')) {
    http_response_code(400);
    exit('Nombre de archivo no válido.');
}
$ruta = CARPETA_COMPROBANTES . $nombre;
if (!is_file($ruta)) {
    http_response_code(404);
    exit('El comprobante no existe.');
}
$extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
$tipos = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'txt' => 'text/plain; charset=utf-8',
];
header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="' . addcslashes($nombre, '"\\') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
exit;
