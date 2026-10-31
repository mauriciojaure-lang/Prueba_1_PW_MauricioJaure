<?php
require 'inc.php';
requiere_admin();

$carpeta = CARPETA_COMPROBANTES;
$metadata = leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);
$porArchivo = [];
foreach ($metadata as $registro) {
    if (!empty($registro['archivo'])) $porArchivo[(string)$registro['archivo']] = $registro;
}
$archivos = [];
if (is_dir($carpeta)) {
    foreach (scandir($carpeta) as $nombre) {
        if ($nombre === '.' || $nombre === '..' || str_starts_with($nombre, '.')) continue;
        if (is_file($carpeta . $nombre)) {
            $archivos[] = [
                'nombre' => $nombre,
                'tamano' => filesize($carpeta . $nombre),
                'fecha' => filemtime($carpeta . $nombre),
                'meta' => $porArchivo[$nombre] ?? [],
            ];
        }
    }
    usort($archivos, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
}

encabezado('Comprobantes cargados', true, 'admin-main');
?>
<p>Esta lista está protegida: solamente las cuentas con rol de administrador pueden consultar y abrir comprobantes.</p>
<?php if (!$archivos): ?>
    <p>No hay archivos cargados todavía.</p>
<?php else: ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Archivo</th><th>Usuario</th><th>Números</th><th>Tamaño</th><th>Fecha</th><th></th></tr></thead>
        <tbody><?php foreach ($archivos as $a): ?>
            <tr>
                <td><?= e($a['nombre']) ?></td>
                <td><?= e($a['meta']['usuario'] ?? 'No disponible') ?></td>
                <td><?= !empty($a['meta']['numeros']) ? e(implode(', ', array_map('intval', $a['meta']['numeros']))) : '—' ?></td>
                <td><?= e(number_format($a['tamano'] / 1024, 1, ',', '.')) ?> KB</td>
                <td><?= e(date('d-m-Y H:i', $a['fecha'])) ?></td>
                <td><a href="ver_comprobante.php?archivo=<?= rawurlencode($a['nombre']) ?>" target="_blank" rel="noopener">Abrir archivo</a></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>
<p><a href="admin.php">← Volver a administración</a></p>
<?php pie(); ?>
