<?php
require 'inc.php';
requiere_admin();

// Normaliza mayúsculas, tildes y espacios repetidos para evitar fallos por formato.
function normalizar_busqueda(string $s): string
{
    $s = trim($s);
    $s = strtr($s, [
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    $s = strtolower($s);
    return preg_replace('/\s+/', ' ', $s) ?? $s;
}

$nombre = get_texto('nombre');
$apellido = get_texto('apellido');
$ciudad = get_texto('ciudad');
$categoria = get_texto('categoria');
$buscado = isset($_GET['nombre']) || isset($_GET['apellido']) || isset($_GET['ciudad']) || isset($_GET['categoria']);
$coincidencias = [];

if ($buscado && $nombre !== '' && $apellido !== '' && $ciudad !== '' && $categoria !== '') {
    // Las cuentas registradas desde la web incluyen nombre, apellido y ciudad.
    // La categoría se asigna automáticamente según el estado del comprobante: user, cliente o comprador.
    foreach (leer_usuarios() as $u) {
        if (($u['rol'] ?? 'usuario') === 'admin') continue;
        if ((string)($u['categoria'] ?? '') === '') continue;
        if (normalizar_busqueda((string)($u['nombre'] ?? '')) === normalizar_busqueda($nombre)
            && normalizar_busqueda((string)($u['apellido'] ?? '')) === normalizar_busqueda($apellido)
            && normalizar_busqueda((string)($u['ciudad'] ?? '')) === normalizar_busqueda($ciudad)
            && normalizar_busqueda((string)($u['categoria'] ?? '')) === normalizar_busqueda($categoria)) {
            $coincidencias[] = [
                'nombre' => trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? '')),
                'ciudad' => (string)($u['ciudad'] ?? ''),
                'categoria' => (string)($u['categoria'] ?? ''),
                'tipo' => 'Cuenta registrada',
                'detalle' => 'Usuario: ' . (string)($u['usuario'] ?? ''),
            ];
        }
    }

    // Los participantes creados con la API también se pueden buscar una vez que
    // el administrador completa su ciudad y asigna una categoría desde el panel.
    foreach (leer_json_archivo_compartido(ARCHIVO_PARTICIPANTES) as $p) {
        // Las cuentas web ya se buscaron desde usuarios.json; no repetir su ficha espejo.
        if (!empty($p['usuario_id'])) continue;
        if ((string)($p['ciudad'] ?? '') === '') continue;
        $numerosApi = $p['numeros_rifa'] ?? [];
        if (!is_array($numerosApi)) $numerosApi = [];
        $numeroApi = (int)($p['numero_rifa'] ?? 0);
        if ($numeroApi > 0) $numerosApi[] = $numeroApi;
        $categoriaApi = count(array_filter(array_unique(array_map('intval', $numerosApi)), fn($n) => $n > 0)) > 0 ? 'comprador' : 'user';
        if (normalizar_busqueda((string)($p['nombre'] ?? '')) === normalizar_busqueda($nombre)
            && normalizar_busqueda((string)($p['apellido'] ?? '')) === normalizar_busqueda($apellido)
            && normalizar_busqueda((string)($p['ciudad'] ?? '')) === normalizar_busqueda($ciudad)
            && normalizar_busqueda($categoriaApi) === normalizar_busqueda($categoria)) {
            $coincidencias[] = [
                'nombre' => trim((string)($p['nombre'] ?? '') . ' ' . (string)($p['apellido'] ?? '')),
                'ciudad' => (string)($p['ciudad'] ?? ''),
                'categoria' => $categoriaApi,
                'tipo' => 'Participante de API',
                'detalle' => 'Números de rifa: ' . implode(', ', array_values(array_unique(array_map('intval', $numerosApi)))),
            ];
        }
    }
}

encabezado('Búsqueda de participantes');
mostrar_flash();
?>
<p>Busca cuentas registradas o participantes ingresados mediante la API. La categoría de las cuentas web se asigna automáticamente: user (sin compra), cliente (comprobante pendiente) o comprador (pago aceptado). Los cuatro campos deben coincidir.</p>
<form method="GET" action="buscar.php">
    <div class="fila">
        <div><label for="nombre">Nombre</label>
             <input type="text" id="nombre" name="nombre" value="<?= e($nombre) ?>" required></div>
        <div><label for="apellido">Apellido</label>
             <input type="text" id="apellido" name="apellido" value="<?= e($apellido) ?>" required></div>
        <div><label for="ciudad">Ciudad</label>
             <input type="text" id="ciudad" name="ciudad" value="<?= e($ciudad) ?>" required></div>
        <div><label for="categoria">Categoría</label>
             <select id="categoria" name="categoria" required>
                 <option value="">Selecciona una categoría</option>
                 <?php foreach (['user', 'cliente', 'comprador'] as $opcion): ?>
                 <option value="<?= e($opcion) ?>" <?= $categoria === $opcion ? 'selected' : '' ?>><?= e($opcion) ?></option>
                 <?php endforeach; ?>
             </select></div>
    </div>
    <button type="submit">Buscar</button>
</form>

<?php if ($buscado): ?>
    <?php if ($nombre === '' || $apellido === '' || $ciudad === '' || $categoria === ''): ?>
        <div class="msg error">Completa los cuatro campos para realizar la búsqueda.</div>
    <?php elseif ($coincidencias): ?>
        <div class="msg ok">Participante encontrado</div>
        <div class="tabla-scroll"><table>
            <thead><tr><th>Nombre completo</th><th>Ciudad</th><th>Categoría</th><th>Origen</th><th>Detalle</th></tr></thead>
            <tbody><?php foreach ($coincidencias as $coincidencia): ?>
                <tr><td><?= e($coincidencia['nombre']) ?></td><td><?= e($coincidencia['ciudad']) ?></td><td><?= e($coincidencia['categoria']) ?></td><td><?= e($coincidencia['tipo']) ?></td><td><?= e($coincidencia['detalle']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php else: ?>
        <div class="msg error">Participante no encontrado</div>
    <?php endif; ?>
<?php endif; ?>
<?php pie();
