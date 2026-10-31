<?php
require 'inc.php';
requiere_sesion();
$premios = leer_json_archivo_compartido(ARCHIVO_PREMIOS);
encabezado('Premios de la rifa');
?>
<p>Estos son los premios informados para la rifa.</p>
<?php if (!$premios): ?>
    <p>No hay premios registrados por el momento.</p>
<?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Premio</th><th>Descripción</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($premios as $premio): ?>
            <tr>
                <td><?= e($premio['nombre'] ?? 'Sin nombre') ?></td>
                <td><?= e($premio['descripcion'] ?? 'Sin descripción') ?></td>
                <td><span class="estado-premio <?= strtolower(preg_replace('/[^a-z]/i', '', (string)($premio['estado'] ?? ''))) ?>"><?= e(ucfirst((string)($premio['estado'] ?? 'Sin estado'))) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
<?php pie(); ?>
