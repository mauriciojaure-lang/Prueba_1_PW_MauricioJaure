<?php
require 'inc.php';
requiere_admin();

// Recalcula categorías de cuentas antiguas según los comprobantes que existen.
actualizar_todas_las_categorias_automaticas();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_texto('accion') === 'revisar_comprobante') {
    if (!validar_csrf_token()) {
        flash('error', 'La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.');
        header('Location: admin.php#comprobantes-pendientes');
        exit;
    }

    $archivo = post_texto('archivo');
    $decision = post_texto('decision');
    $resultado = revisar_comprobante_admin($archivo, $decision, (string)($_SESSION['usuario'] ?? 'admin'));
    flash(!empty($resultado['ok']) ? 'ok' : 'error', (string)($resultado['mensaje'] ?? 'No se pudo revisar el comprobante.'));
    header('Location: admin.php#comprobantes-pendientes');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_texto('accion') === 'actualizar_categoria_usuario') {
    if (!validar_csrf_token()) {
        flash('error', 'La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.');
        header('Location: admin.php#usuarios-registrados');
        exit;
    }
    $idUsuario = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $usuarioObjetivo = null;
    foreach (leer_usuarios() as $cuenta) {
        if ((int)($cuenta['id'] ?? 0) === (int)$idUsuario && ($cuenta['rol'] ?? 'usuario') !== 'admin') {
            $usuarioObjetivo = $cuenta;
            break;
        }
    }
    if (!$idUsuario || !$usuarioObjetivo) {
        flash('error', 'No se encontró una cuenta de usuario válida.');
    } else {
        $categoriaGuardada = sincronizar_categoria_automatica_usuario((int)$usuarioObjetivo['id'], (string)$usuarioObjetivo['usuario']);
        flash('ok', 'Categoría actualizada automáticamente: ' . $categoriaGuardada . '.');
    }
    header('Location: admin.php#usuarios-registrados');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_texto('accion') === 'guardar_categoria_api') {
    if (!validar_csrf_token()) {
        flash('error', 'La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.');
        header('Location: admin.php#participantes-api');
        exit;
    }
    $idRegistro = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $categoriaApi = post_texto('categoria');
    if (!$idRegistro || !in_array($categoriaApi, ['user', 'cliente', 'comprador'], true)) {
        flash('error', 'Selecciona un participante y una categoría válida.');
        header('Location: admin.php#participantes-api');
        exit;
    }
    $actualizacionCategoria = modificar_json_bloqueado(ARCHIVO_PARTICIPANTES, function (&$lista) use ($idRegistro, $categoriaApi) {
        foreach ($lista as &$item) {
            if ((int)($item['id'] ?? 0) === (int)$idRegistro && empty($item['usuario_id'])) {
                $item['categoria'] = $categoriaApi;
                $item['estado'] = $categoriaApi;
                unset($item);
                return ['ok' => true, 'mensaje' => 'Categoría del participante actualizada.'];
            }
        }
        unset($item);
        return ['ok' => false, 'mensaje' => 'No se encontró un participante API editable.'];
    });
    $resultadoCategoria = $actualizacionCategoria['resultado'] ?? null;
    flash(!empty($actualizacionCategoria['guardado']) && !empty($resultadoCategoria['ok']) ? 'ok' : 'error', (string)($resultadoCategoria['mensaje'] ?? 'No se pudo guardar la categoría.'));
    header('Location: admin.php#participantes-api');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_texto('accion') === 'guardar_ciudad_api') {
    if (!validar_csrf_token()) {
        flash('error', 'La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.');
        header('Location: admin.php#participantes-api');
        exit;
    }
    $idRegistro = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $ciudadNueva = post_texto('ciudad');
    if ($idRegistro === false || $idRegistro === null || $idRegistro < 1 || $ciudadNueva === '' || preg_match('/\A.{1,80}\z/us', $ciudadNueva) !== 1) {
        flash('error', 'Indica un participante válido y una ciudad de hasta 80 caracteres.');
        header('Location: admin.php#participantes-api');
        exit;
    }
    $actualizacionCiudad = modificar_json_bloqueado(ARCHIVO_PARTICIPANTES, function (&$lista) use ($idRegistro, $ciudadNueva) {
        foreach ($lista as &$item) {
            if ((int)($item['id'] ?? 0) === (int)$idRegistro && empty($item['usuario_id'])) {
                $item['ciudad'] = $ciudadNueva;
                unset($item);
                return ['ok' => true, 'mensaje' => 'Ciudad del participante actualizada.'];
            }
        }
        unset($item);
        return ['ok' => false, 'mensaje' => 'No se encontró ese participante de API o está vinculado a una cuenta web.'];
    });
    $resultadoCiudad = $actualizacionCiudad['resultado'] ?? null;
    flash(!empty($actualizacionCiudad['guardado']) && !empty($resultadoCiudad['ok']) ? 'ok' : 'error', (string)($resultadoCiudad['mensaje'] ?? 'No se pudo guardar la ciudad.'));
    header('Location: admin.php#participantes-api');
    exit;
}

$tokenCsrf = csrf_token();
[$reservas, $participantes] = leer_estado_rifa();
$usuarios = leer_usuarios();
$comprobantes = leer_json_archivo_compartido(ARCHIVO_COMPROBANTES);

$metadataPorArchivo = [];
foreach ($comprobantes as $registro) {
    if (!empty($registro['archivo'])) $metadataPorArchivo[(string)$registro['archivo']] = $registro;
}

$archivosFisicos = [];
if (is_dir(CARPETA_COMPROBANTES)) {
    foreach (scandir(CARPETA_COMPROBANTES) as $nombre) {
        if ($nombre === '.' || $nombre === '..' || str_starts_with($nombre, '.')) continue;
        if (is_file(CARPETA_COMPROBANTES . $nombre)) {
            $archivosFisicos[] = [
                'archivo' => $nombre,
                'tamano' => filesize(CARPETA_COMPROBANTES . $nombre),
                'fecha_archivo' => filemtime(CARPETA_COMPROBANTES . $nombre),
                'registro' => $metadataPorArchivo[$nombre] ?? [],
            ];
        }
    }
}
usort($archivosFisicos, fn($a, $b) => $b['fecha_archivo'] <=> $a['fecha_archivo']);

$archivosPorNombre = [];
foreach ($archivosFisicos as $archivoFisico) $archivosPorNombre[$archivoFisico['archivo']] = $archivoFisico;

$pendientes = [];
foreach ($comprobantes as $registro) {
    $estado = strtolower(trim((string)($registro['estado'] ?? '')));
    if (in_array($estado, ['pendiente', 'pendiente de revisión', 'pendiente_revision', 'comprobante_enviado'], true)) {
        $registro['archivo_existe'] = isset($archivosPorNombre[(string)($registro['archivo'] ?? '')]);
        $pendientes[] = $registro;
    }
}
usort($pendientes, fn($a, $b) => strcmp((string)($a['fecha'] ?? ''), (string)($b['fecha'] ?? '')));

// Los chips cuentan solo compras aprobadas. El mapa también distingue
// selecciones temporales y comprobantes pendientes, sin marcarlos como vendidos.
$numerosSeleccionados = [];
$estadoVisualNumeros = [];
foreach ($reservas as $reserva) {
    $numero = (int)($reserva['numero'] ?? 0);
    if ($numero < 1 || $numero > MAX_NUMERO) continue;
    $estadoReserva = (string)($reserva['estado'] ?? 'temporal');
    if (in_array($estadoReserva, ['confirmado', 'aceptado'], true)) {
        $numerosSeleccionados[$numero] = 'Comprado y aprobado';
        $estadoVisualNumeros[$numero] = 'Comprado y aprobado';
    } elseif ($estadoReserva === 'comprobante_enviado' && !isset($estadoVisualNumeros[$numero])) {
        $estadoVisualNumeros[$numero] = 'Comprobante pendiente de revisión';
    } elseif ($estadoReserva === 'temporal' && !isset($estadoVisualNumeros[$numero])) {
        $estadoVisualNumeros[$numero] = 'Selección temporal';
    }
}
foreach ($participantes as $participante) {
    $lista = $participante['numeros_rifa'] ?? [];
    if (!is_array($lista)) $lista = [];
    $unico = (int)($participante['numero_rifa'] ?? 0);
    if ($unico > 0) $lista[] = $unico;
    foreach ($lista as $numero) {
        $numero = (int)$numero;
        if ($numero > 0 && $numero <= MAX_NUMERO) {
            $numerosSeleccionados[$numero] = 'Asignado a comprador';
            $estadoVisualNumeros[$numero] = 'Comprado y aprobado';
        }
    }
}
ksort($numerosSeleccionados);
ksort($estadoVisualNumeros);

$cantidadPendientes = count($pendientes);
encabezado('Administración de la rifa', true, 'admin-main');
mostrar_flash();
?>
<p>Bienvenido/a, administrador <strong><?= e($_SESSION['usuario']) ?></strong>. Desde aquí puedes revisar los comprobantes. Un número solo se marca como ocupado cuando aceptas el pago.</p>
<div class="stats-admin">
    <div class="stat-admin"><strong><?= count($participantes) ?></strong><span>Participantes registrados</span></div>
    <div class="stat-admin"><strong><?= count($numerosSeleccionados) ?></strong><span>Números comprados y aprobados</span></div>
    <div class="stat-admin"><strong><?= $cantidadPendientes ?></strong><span>Comprobantes pendientes de revisión</span></div>
    <div class="stat-admin"><strong><?= count($usuarios) ?></strong><span>Cuentas registradas</span></div>
</div>

<section class="seccion-admin" id="usuarios-registrados">
    <h2>Usuarios registrados y categoría automática</h2>
    <p>La categoría cambia automáticamente: <strong>user</strong> cuando se registra sin enviar comprobantes; <strong>cliente</strong> mientras su pago está pendiente; y <strong>comprador</strong> cuando el administrador acepta al menos un comprobante. El rol de seguridad (Usuario o Administrador) es independiente.</p>
    <?php if (!$usuarios): ?><p>Todavía no hay cuentas registradas.</p><?php else: ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Apellido</th><th>Ciudad</th><th>Usuario</th><th>Rol</th><th>Categoría</th><th>Fecha de registro</th></tr></thead>
        <tbody><?php foreach ($usuarios as $u): ?>
            <?php
            $rolCuenta = (string)($u['rol'] ?? 'usuario');
            $esAdminCuenta = $rolCuenta === 'admin';
            $categoria = $esAdminCuenta ? '—' : calcular_categoria_automatica((int)($u['id'] ?? 0), (string)($u['usuario'] ?? ''), $comprobantes);
            $categoriaClase = $categoria === 'comprador' ? 'categoria-comprador' : ($categoria === 'cliente' ? 'categoria-cliente' : 'categoria-user');
            ?>
            <tr>
                <td><?= (int)($u['id'] ?? 0) ?></td>
                <td><?= e($u['nombre'] ?? '—') ?></td>
                <td><?= e($u['apellido'] ?? '—') ?></td>
                <td><?= e($u['ciudad'] ?? '—') ?></td>
                <td><?= e($u['usuario'] ?? '') ?></td>
                <td><span class="rol-etiqueta <?= $esAdminCuenta ? 'rol-admin' : 'rol-usuario' ?>"><?= $esAdminCuenta ? 'Administrador' : 'Usuario' ?></span></td>
                <td>
                    <?php if (!$esAdminCuenta): ?>
                    <div class="categoria-con-guardar">
                        <span class="categoria-etiqueta <?= e($categoriaClase) ?>"><?= e($categoria) ?></span>
                        <form method="POST" action="admin.php#usuarios-registrados">
                            <input type="hidden" name="_csrf_token" value="<?= e($tokenCsrf) ?>">
                            <input type="hidden" name="accion" value="actualizar_categoria_usuario">
                            <input type="hidden" name="id" value="<?= (int)($u['id'] ?? 0) ?>">
                            <button type="submit" class="btn-guardar-categoria">Guardar / actualizar</button>
                        </form>
                    </div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= !empty($u['creado_en']) ? e(date('d-m-Y H:i', strtotime((string)$u['creado_en']) ?: time())) : '—' ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="seccion-admin" id="participantes-api">
    <h2>Participantes registrados mediante la API REST</h2>
    <p>Incluye las cuentas registradas desde la web y los participantes creados mediante Postman. Las personas registradas por la web comienzan sin número; sus números se agregan solamente cuando se aprueba el comprobante.</p>
    <?php if (!$participantes): ?><p>No hay participantes registrados todavía.</p><?php else: ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Apellido</th><th>Números aprobados</th><th>Categoría</th><th>Origen</th></tr></thead>
        <tbody><?php foreach ($participantes as $p): ?>
            <?php
            $nums = $p['numeros_rifa'] ?? [];
            if (!is_array($nums)) $nums = [];
            $numeroUnico = (int)($p['numero_rifa'] ?? 0);
            if ($numeroUnico > 0) $nums[] = $numeroUnico;
            $nums = array_values(array_unique(array_filter(array_map('intval', $nums), fn($n) => $n > 0)));
            sort($nums);
            $cat = !empty($p['usuario_id'])
                ? calcular_categoria_automatica((int)($p['usuario_id'] ?? 0), (string)($p['usuario'] ?? ''), $comprobantes)
                : (string)($p['categoria'] ?? ($nums ? 'comprador' : 'user'));
            if (!in_array($cat, ['user', 'cliente', 'comprador'], true)) $cat = $nums ? 'comprador' : 'user';
            $catClass = $cat === 'comprador' ? 'categoria-comprador' : ($cat === 'cliente' ? 'categoria-cliente' : 'categoria-user');
            ?>
            <tr>
                <td><?= (int)($p['id'] ?? 0) ?></td>
                <td><?= e($p['nombre'] ?? '') ?></td>
                <td><?= e($p['apellido'] ?? '') ?></td>
                <td><?= $nums ? e(implode(', ', $nums)) : 'Sin números aprobados' ?></td>
                <td>
                    <?php if (empty($p['usuario_id'])): ?>
                    <form method="POST" action="admin.php#participantes-api" class="form-categoria-api">
                        <input type="hidden" name="_csrf_token" value="<?= e($tokenCsrf) ?>">
                        <input type="hidden" name="accion" value="guardar_categoria_api">
                        <input type="hidden" name="id" value="<?= (int)($p['id'] ?? 0) ?>">
                        <select name="categoria" aria-label="Categoría del participante">
                            <?php foreach (['user', 'cliente', 'comprador'] as $opcionCategoria): ?>
                            <option value="<?= e($opcionCategoria) ?>" <?= $cat === $opcionCategoria ? 'selected' : '' ?>><?= e($opcionCategoria) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Guardar</button>
                    </form>
                    <?php else: ?>
                    <span class="categoria-etiqueta <?= e($catClass) ?>"><?= e($cat) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e(($p['origen'] ?? '') === 'registro_web' ? 'Registro web' : 'API') ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="seccion-admin" id="comprobantes-pendientes">
    <h2>Comprobantes pendientes de revisión</h2>
    <p>Revisa el archivo antes de tomar una decisión. <strong>Aceptar</strong> confirma la compra y marca los números como ocupados. <strong>Rechazar</strong> libera la solicitud y devuelve la categoría a user, salvo que el usuario tenga otro comprobante pendiente o aceptado.</p>
    <?php if (!$pendientes): ?><p>No hay comprobantes pendientes.</p><?php else: ?>
    <div class="comprobantes-admin-lista">
        <?php foreach ($pendientes as $p): ?>
            <article class="comprobante-admin-card">
                <div class="comprobante-admin-info">
                    <h3><?= e($p['usuario'] ?? 'Usuario') ?> <span class="estado-pendiente">Pendiente</span></h3>
                    <p><strong>Números:</strong> <?= !empty($p['numeros']) ? e(implode(', ', array_map('intval', $p['numeros']))) : '—' ?></p>
                    <p><strong>Total:</strong> <?= isset($p['monto']) ? e(pesos((int)$p['monto'])) : '—' ?></p>
                    <p><strong>Fecha:</strong> <?= !empty($p['fecha']) ? e(date('d-m-Y H:i', strtotime((string)$p['fecha']) ?: time())) : '—' ?></p>
                    <p><strong>Archivo:</strong> <?= e($p['archivo'] ?? '—') ?></p>
                    <?php if (!empty($p['archivo_existe'])): ?>
                    <div class="acciones-revision">
                        <form method="POST" action="admin.php#comprobantes-pendientes">
                            <input type="hidden" name="_csrf_token" value="<?= e($tokenCsrf) ?>">
                            <input type="hidden" name="accion" value="revisar_comprobante">
                            <input type="hidden" name="archivo" value="<?= e($p['archivo']) ?>">
                            <input type="hidden" name="decision" value="aceptar">
                            <button type="submit" class="btn-aceptar" onclick="return confirm('¿Confirmas que el pago fue recibido correctamente? Los números quedarán ocupados.');">Aceptar comprobante</button>
                        </form>
                        <form method="POST" action="admin.php#comprobantes-pendientes">
                            <input type="hidden" name="_csrf_token" value="<?= e($tokenCsrf) ?>">
                            <input type="hidden" name="accion" value="revisar_comprobante">
                            <input type="hidden" name="archivo" value="<?= e($p['archivo']) ?>">
                            <input type="hidden" name="decision" value="rechazar">
                            <button type="submit" class="btn-rechazar" onclick="return confirm('¿Rechazar el comprobante? Los números volverán a estar disponibles.');">Rechazar comprobante</button>
                        </form>
                    </div>
                    <?php else: ?><span class="msg error">Archivo no encontrado; no se puede aprobar.</span><?php endif; ?>
                </div>
                <aside class="comprobante-admin-preview">
                    <?php if (!empty($p['archivo_existe'])): ?>
                        <?php $extPreview = strtolower(pathinfo((string)$p['archivo'], PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($extPreview, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?>
                            <img src="ver_comprobante.php?archivo=<?= rawurlencode((string)$p['archivo']) ?>" alt="Vista previa del comprobante">
                        <?php elseif ($extPreview === 'pdf'): ?>
                            <iframe title="Vista previa del comprobante PDF" src="ver_comprobante.php?archivo=<?= rawurlencode((string)$p['archivo']) ?>#toolbar=0&navpanes=0"></iframe>
                        <?php else: ?>
                            <a href="ver_comprobante.php?archivo=<?= rawurlencode((string)$p['archivo']) ?>">Ver archivo dentro del navegador</a>
                        <?php endif; ?>
                    <?php else: ?><span>Vista previa no disponible</span><?php endif; ?>
                </aside>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="seccion-admin" id="numeros-seleccionados">
    <h2>Estado general de los números</h2>
    <p>El mapa muestra todos los estados: verde disponible, amarillo seleccionado o pendiente de revisión, y rojo comprado con comprobante aceptado. Solo los números rojos cuentan como ventas aprobadas.</p>
    <?php if (!$numerosSeleccionados): ?><p>Todavía no hay compras aprobadas.</p><?php else: ?>
    <h3>Números comprados y aprobados</h3>
    <div class="chips"><?php foreach ($numerosSeleccionados as $numero => $tipo): ?><span class="chip"><strong>N.º <?= (int)$numero ?></strong> <?= e($tipo) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="leyenda-numeros">
        <span><i class="estado libre"></i> Disponible</span>
        <span><i class="estado reservado"></i> Selección temporal o comprobante pendiente</span>
        <span><i class="estado ocupado"></i> Comprado y aprobado</span>
    </div>
    <div class="mapa-numeros-admin" aria-label="Estado de todos los números de la rifa">
        <?php for ($n = 1; $n <= MAX_NUMERO; $n++): ?>
            <?php
                $estadoTexto = $estadoVisualNumeros[$n] ?? 'Disponible';
                $claseEstado = $estadoTexto === 'Comprado y aprobado' ? 'ocupado' : (isset($estadoVisualNumeros[$n]) ? 'pendiente' : 'libre');
            ?>
            <span class="numero-admin <?= $claseEstado ?>" title="Número <?= $n ?>: <?= e($estadoTexto) ?>"><?= str_pad((string)$n, 2, '0', STR_PAD_LEFT) ?></span>
        <?php endfor; ?>
    </div>
</section>

<section class="seccion-admin" id="comprobantes">
    <h2>Historial de comprobantes</h2>
    <p>Los comprobantes aceptados y rechazados permanecen en el historial para consulta administrativa.</p>
    <?php if (!$archivosFisicos): ?><p>No se han cargado comprobantes todavía.</p><?php else: ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Archivo</th><th>Usuario</th><th>Números</th><th>Total</th><th>Fecha</th><th>Estado</th><th></th></tr></thead>
        <tbody><?php foreach ($archivosFisicos as $archivo): ?>
            <?php $meta = $archivo['registro']; ?>
            <tr>
                <td><?= e($archivo['archivo']) ?></td>
                <td><?= e($meta['usuario'] ?? 'No disponible') ?></td>
                <td><?= !empty($meta['numeros']) ? e(implode(', ', array_map('intval', $meta['numeros']))) : '—' ?></td>
                <td><?= isset($meta['monto']) ? e(pesos((int)$meta['monto'])) : '—' ?></td>
                <td><?= !empty($meta['fecha']) ? e(date('d-m-Y H:i', strtotime((string)$meta['fecha']) ?: time())) : e(date('d-m-Y H:i', $archivo['fecha_archivo'])) ?></td>
                <td><?= e($meta['estado'] ?? 'Archivo almacenado') ?></td>
                <td><a href="ver_comprobante.php?archivo=<?= rawurlencode($archivo['archivo']) ?>">Abrir</a></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<div class="cards">
    <a class="card" href="premios.php"><h3>Ver premios</h3>Consulta y revisa los premios de la rifa.</a>
    <a class="card" href="buscar.php"><h3>Buscar participante</h3>Busca por nombre, apellido, ciudad y categoría automática.</a>
    <a class="card" href="archivos.php"><h3>Todos los comprobantes</h3>Abre el listado privado de archivos recibidos.</a>
</div>
<?php pie(); ?>
