<?php
require 'inc.php';
requiere_usuario();

$cuentaActual = buscar_usuario((string)$_SESSION['usuario']);
$categoriaActual = calcular_categoria_automatica((int)($_SESSION['user_id'] ?? 0), (string)$_SESSION['usuario']);
$nombreCategoria = ['user' => 'Usuario registrado', 'cliente' => 'Comprobante pendiente de revisión', 'comprador' => 'Comprador con pago aceptado'][$categoriaActual] ?? 'Usuario registrado';
encabezado('Panel de usuario');
mostrar_flash();
?>
<p>Bienvenido/a, <strong><?= e($_SESSION['usuario']) ?></strong>.</p>
<p>Estado de tu cuenta: <strong><?= e($categoriaActual) ?></strong> — <?= e($nombreCategoria) ?>.</p>
<p>Desde esta cuenta puedes reservar números y consultar los premios. Los datos de otros participantes y la lista de comprobantes son privados y solo los administra el administrador. Los números solo se marcarán como ocupados cuando el administrador acepte tu comprobante.</p>
<div class="cards">
    <a class="card" href="rifa.php"><h3>Elegir números</h3>Consulta la disponibilidad y reserva tus números.</a>
    <a class="card" href="premios.php"><h3>Ver premios</h3>Consulta los premios de la rifa y sus estados.</a>
</div>
<?php pie(); ?>
