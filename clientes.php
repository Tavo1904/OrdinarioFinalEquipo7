<?php
  include 'db.php';
  include 'header.php';
  require_perm('clientes'); // MEJORA 2: Solo empleados con permiso de clientes

  
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = trim($_POST['id_usuario'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $rfc    = trim($_POST['rfc'] ?? '');
    $tipo   = ($_POST['tipo_cliente'] ?? '') === 'mayorista' ? 'mayorista' : 'minorista';
    $limite = max(1, (int)($_POST['limite_mayoreo'] ?? 50));

    if ($nombre === '' || $correo === '') {
        $error = 'Nombre y correo son obligatorios.';
    } else {
        try {
            if ($id === '') {
                $hash = password_hash('Cliente123', PASSWORD_DEFAULT);
                $col  = password_column($conexion);
                $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, correo, `$col`, rol, estado, rfc, tipo_cliente, limite_mayoreo) VALUES (?, ?, ?, 'cliente', 'activo', ?, ?, ?)");
                $stmt->execute([$nombre, $correo, $hash, $rfc, $tipo, $limite]);
                $success = 'Cliente registrado exitosamente. Contraseña inicial: Cliente123';
            } else {
                $stmt = $conexion->prepare("UPDATE usuarios SET nombre=?, correo=?, rfc=?, tipo_cliente=?, limite_mayoreo=? WHERE id_usuario=?");
                $stmt->execute([$nombre, $correo, $rfc, $tipo, $limite, $id]);
                $success = 'Cliente actualizado.';
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Cambiar estado (activar/desactivar)
if (isset($_GET['toggle']) && $_SESSION['rol'] === 'admin') {
    $uid = (int)$_GET['toggle'];
    $st  = $conexion->prepare("SELECT estado FROM usuarios WHERE id_usuario = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $nuevo = ($row['estado'] ?? 'activo') === 'activo' ? 'inactivo' : 'activo';
    $conexion->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?")->execute([$nuevo, $uid]);
    header("Location: clientes.php");
    exit;
}

$clientes = $conexion->query("
    SELECT * FROM usuarios
    WHERE rol = 'cliente'
    ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">Clientes Registrados</h3>
        <small class="text-muted"><?php echo count($clientes); ?> cliente<?php echo count($clientes) !== 1 ? 's' : ''; ?> en el sistema</small>
    </div>
    <?php if ($_SESSION['rol'] === 'admin'): ?>
    <button class="btn btn-primary" onclick="nuevoCliente()">
        <i class="bi bi-person-plus-fill me-2"></i>Agregar Cliente
    </button>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?php echo h($success); ?></div><?php endif; ?>

<div class="row g-4">
    <?php if ($_SESSION['rol'] === 'admin'): ?>
    <div class="col-md-4">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold mb-0" id="tituloCliente">Nuevo Cliente</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="id_usuario" id="inputId">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">NOMBRE COMPLETO *</label>
                        <input name="nombre" id="inputNombre" class="form-control" placeholder="Juan Pérez" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">CORREO *</label>
                        <input type="email" name="correo" id="inputCorreo" class="form-control" placeholder="nombre@correo.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">RFC</label>
                        <input name="rfc" id="inputRfc" class="form-control" placeholder="XAXX010101000" style="text-transform:uppercase;">
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">TIPO</label>
                        <select name="tipo_cliente" id="inputTipo" class="form-select">
                            <option value="minorista">Minorista</option>
                            <option value="mayorista">Mayorista</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="small fw-bold text-muted">MÍN. PARA MAYOREO (unidades)</label>
                        <input type="number" name="limite_mayoreo" id="inputLimite" class="form-control" value="50" min="1">
                    </div>
                    <button class="btn btn-primary w-100 fw-bold"><i class="bi bi-floppy me-1"></i>Guardar</button>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-2" onclick="nuevoCliente()">Cancelar</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?php echo $_SESSION['rol'] === 'admin' ? 'col-md-8' : 'col-12'; ?>">
        <div class="card card-custom border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Cliente</th>
                            <th>RFC</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Origen</th>
                            <th>Historial</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($clientes)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people" style="font-size:2rem;opacity:.2;display:block;margin-bottom:8px;"></i>
                            No hay clientes registrados aún.
                        </td></tr>
                    <?php else: foreach ($clientes as $c):
                        $stmtHist = $conexion->prepare("SELECT COUNT(*) AS compras, COALESCE(SUM(total),0) AS total_compras FROM pedidos WHERE id_cliente = ?");
                        $stmtHist->execute([$c['id_usuario']]);
                        $hist = $stmtHist->fetch();
                        $esActivo = ($c['estado'] ?? 'activo') === 'activo';
                        // Detectar si se registró vía web (tiene contraseña propia) o lo creó el admin
                        $registradoWeb = !empty($c['rfc']) || true; // todos los clientes aparecen aquí
                    ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-bold"><?php echo h($c['nombre']); ?></div>
                                <small class="text-muted"><?php echo h($c['correo']); ?></small>
                            </td>
                            <td class="small text-muted"><?php echo h($c['rfc'] ?: '—'); ?></td>
                            <td>
                                <span class="badge <?php echo $c['tipo_cliente'] === 'mayorista' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo ucfirst(h($c['tipo_cliente'] ?: 'minorista')); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $esActivo ? 'bg-success-subtle text-success border' : 'bg-danger-subtle text-danger border'; ?>">
                                    <?php echo $esActivo ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info border">
                                    <i class="bi bi-globe me-1"></i>Web
                                </span>
                            </td>
                            <td class="small">
                                <span class="fw-bold"><?php echo (int)$hist['compras']; ?></span> compras<br>
                                <span class="text-muted">$<?php echo number_format((float)$hist['total_compras'], 2); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <a class="btn btn-sm btn-outline-primary me-1" href="clientes.php?historial=<?php echo (int)$c['id_usuario']; ?>">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                                <?php if ($_SESSION['rol'] === 'admin'): ?>
                                    <button class="btn btn-sm btn-warning me-1" onclick='editarCliente(<?php echo json_encode([
                                        "id_usuario"    => $c["id_usuario"],
                                        "nombre"        => $c["nombre"],
                                        "correo"        => $c["correo"],
                                        "rfc"           => $c["rfc"] ?? "",
                                        "tipo_cliente"  => $c["tipo_cliente"] ?? "minorista",
                                        "limite_mayoreo"=> $c["limite_mayoreo"] ?? 50,
                                    ]); ?>)' title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="clientes.php?toggle=<?php echo (int)$c['id_usuario']; ?>"
                                       class="btn btn-sm <?php echo $esActivo ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                       onclick="return confirm('¿<?php echo $esActivo ? 'Desactivar' : 'Activar'; ?> a <?php echo h($c['nombre']); ?>?');"
                                       title="<?php echo $esActivo ? 'Desactivar' : 'Activar'; ?>">
                                        <i class="bi bi-<?php echo $esActivo ? 'slash-circle' : 'check-circle'; ?>"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (isset($_GET['historial'])): 
            $idHist = (int)$_GET['historial'];
            $stmtU  = $conexion->prepare("SELECT nombre FROM usuarios WHERE id_usuario = ?");
            $stmtU->execute([$idHist]);
            $nombreHist = $stmtU->fetchColumn();

            $stmt = $conexion->prepare("
                SELECT p.id_pedido, p.fecha, p.total, p.estado_aprobacion,
                       pr.nombre AS producto, dp.cantidad, dp.precio_unitario, dp.subtotal, dp.modalidad
                FROM pedidos p
                INNER JOIN detalle_pedido dp ON p.id_pedido = dp.id_pedido
                INNER JOIN productos pr ON dp.id_producto = pr.id_producto
                WHERE p.id_cliente = ?
                ORDER BY p.fecha DESC
                LIMIT 100
            ");
            $stmt->execute([$idHist]);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card card-custom border-0 shadow-sm mt-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Historial de compras &mdash; <?php echo h($nombreHist); ?></h5>
                <a href="clientes.php" class="btn btn-sm btn-outline-secondary">Cerrar</a>
            </div>
            <?php if (empty($historial)): ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size:1.5rem;opacity:.3;"></i><br>Sin compras registradas.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Folio</th>
                            <th>Fecha</th>
                            <th>Producto</th>
                            <th class="text-center">Cant.</th>
                            <th>Precio</th>
                            <th>Modalidad</th>
                            <th>Subtotal</th>
                            <th>Estado</th>
                            <th class="pe-3">Total compra</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historial as $h): 
                        $ea = $h['estado_aprobacion'] ?? 'aceptado';
                        $badgeE = $ea === 'aceptado' ? 'bg-success' : ($ea === 'rechazado' ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                        <tr>
                            <td class="ps-3 text-muted small">#<?php echo str_pad((string)$h['id_pedido'], 6, '0', STR_PAD_LEFT); ?></td>
                            <td class="small"><?php echo date('d/m/Y', strtotime($h['fecha'])); ?></td>
                            <td class="fw-bold"><?php echo h($h['producto']); ?></td>
                            <td class="text-center"><?php echo (int)$h['cantidad']; ?></td>
                            <td>$<?php echo number_format((float)$h['precio_unitario'], 2); ?></td>
                            <td><span class="badge bg-light text-dark border"><?php echo ucfirst(h($h['modalidad'])); ?></span></td>
                            <td>$<?php echo number_format((float)$h['subtotal'], 2); ?></td>
                            <td><span class="badge <?php echo $badgeE; ?> px-2"><?php echo ucfirst($ea); ?></span></td>
                            <td class="fw-bold pe-3">$<?php echo number_format((float)$h['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function nuevoCliente() {
    document.getElementById('tituloCliente').innerText = 'Nuevo Cliente';
    document.getElementById('inputId').value     = '';
    document.getElementById('inputNombre').value = '';
    document.getElementById('inputCorreo').value = '';
    document.getElementById('inputRfc').value    = '';
    document.getElementById('inputTipo').value   = 'minorista';
    document.getElementById('inputLimite').value = '50';
}
function editarCliente(c) {
    document.getElementById('tituloCliente').innerText  = 'Editar Cliente';
    document.getElementById('inputId').value     = c.id_usuario;
    document.getElementById('inputNombre').value = c.nombre || '';
    document.getElementById('inputCorreo').value = c.correo || '';
    document.getElementById('inputRfc').value    = c.rfc || '';
    document.getElementById('inputTipo').value   = c.tipo_cliente || 'minorista';
    document.getElementById('inputLimite').value = c.limite_mayoreo || 50;
    window.scrollTo({top: 0, behavior: 'smooth'});
}
</script>
</body></html>
