<?php
  include 'db.php';
  include 'header.php';
  require_perm('inventario'); // MEJORA 2: Solo empleados con permiso de inventario

  
$error = '';
$success = '';
$isAdmin = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'entrada_stock') {
    $idProd   = (int)$_POST['id_producto'];
    $cantidad = max(1, (int)$_POST['cantidad']);
    $obs      = trim($_POST['observaciones'] ?? 'Entrada manual');

    // Bloquear reabastecimiento de productos vencidos
    $stmtProd = $conexion->prepare("SELECT fecha_caducidad, nombre FROM productos WHERE id_producto = ?");
    $stmtProd->execute([$idProd]);
    $prodCheck = $stmtProd->fetch();
    if ($prodCheck) {
        $expCheck = expiry_status($prodCheck['fecha_caducidad'] ?? null);
        if (in_array($expCheck['status'], ['expired', 'expired_today'])) {
            $error = 'No se puede reabastecer un producto vencido: ' . $prodCheck['nombre'] . '. Debe retirarse del catalogo.';
        }
    }

    if (!$error) {
        try {
            $conexion->beginTransaction();
            $stmtUpd = $conexion->prepare("UPDATE productos SET stock = stock + ? WHERE id_producto = ?");
            $stmtUpd->execute([$cantidad, $idProd]);
            $stmtMov = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo_movimiento, cantidad, observaciones, origen) VALUES (?, ?, 'entrada', ?, ?, 'entrada')");
            $stmtMov->execute([$idProd, $_SESSION['id_usuario'], $cantidad, $obs]);
            $conexion->commit();
            $success = 'Stock agregado exitosamente.';
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = 'Error al agregar stock: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_producto') {
    $id           = trim($_POST['id_producto'] ?? '');
    $nombre       = trim($_POST['nombre'] ?? '');
    $compuesto    = trim($_POST['compuesto'] ?? '');
    $lote         = trim($_POST['numero_lote'] ?? '');
    $caducidad    = trim($_POST['fecha_caducidad'] ?? '');
    $precio       = (float)($_POST['precio'] ?? 0);
    $precioMayoreo= (float)($_POST['precio_mayoreo'] ?? 0);
    $costo        = (float)($_POST['costo_adquisicion'] ?? 0);
    $stock        = max(0, (int)($_POST['stock'] ?? 0));
    $minimo       = max(0, (int)($_POST['stock_minimo'] ?? 0));
    $categoria    = trim($_POST['categoria'] ?? '');
    $laboratorio  = trim($_POST['laboratorio'] ?? '');
    $presentacion = trim($_POST['presentacion'] ?? '');
    $descripcion  = trim($_POST['descripcion'] ?? '');

    if ($nombre === '' || $lote === '' || $caducidad === '' || $precio <= 0 || $precioMayoreo <= 0) {
        $error = 'Completa los campos obligatorios antes de guardar.';
    } elseif (!$isAdmin && $id !== '') {
        $error = 'Permisos insuficientes: solo Administrador puede editar precios del catalogo.';
    } else {
        try {
            $stmtDup = $conexion->prepare("SELECT id_producto FROM productos WHERE numero_lote = ? AND (? = '' OR id_producto <> ?)");
            $stmtDup->execute([$lote, $id, $id ?: 0]);
            if ($stmtDup->fetchColumn()) {
                throw new Exception('El numero de lote ya existe en el catalogo');
            }

            if ($id === '') {
                $sql = "INSERT INTO productos (nombre, compuesto, numero_lote, categoria, laboratorio, presentacion, fecha_caducidad, precio, precio_mayoreo, costo_adquisicion, stock, stock_minimo, descripcion, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'disponible')";
                $stmt = $conexion->prepare($sql);
                $stmt->execute([$nombre, $compuesto, $lote, $categoria, $laboratorio, $presentacion, $caducidad, $precio, $precioMayoreo, $costo, $stock, $minimo, $descripcion]);
                $idNuevo = $conexion->lastInsertId();
                $stmtMov = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo_movimiento, cantidad, observaciones, origen) VALUES (?, ?, 'entrada', ?, 'Inventario inicial', 'entrada')");
                $stmtMov->execute([$idNuevo, $_SESSION['id_usuario'], $stock]);
                $success = 'Producto guardado en DB y visible en catalogo.';
            } else {
                $actual = $conexion->prepare("SELECT precio, precio_mayoreo FROM productos WHERE id_producto = ?");
                $actual->execute([$id]);
                $previo = $actual->fetch();
                $sql = "UPDATE productos SET nombre=?, compuesto=?, numero_lote=?, categoria=?, laboratorio=?, presentacion=?, fecha_caducidad=?, precio=?, precio_mayoreo=?, costo_adquisicion=?, stock_minimo=?, descripcion=? WHERE id_producto=?";
                $stmt = $conexion->prepare($sql);
                $stmt->execute([$nombre, $compuesto, $lote, $categoria, $laboratorio, $presentacion, $caducidad, $precio, $precioMayoreo, $costo, $minimo, $descripcion, $id]);
                if ($previo && ((float)$previo['precio'] !== $precio || (float)$previo['precio_mayoreo'] !== $precioMayoreo)) {
                    $hist = $conexion->prepare("INSERT INTO historial_precios (id_producto, id_usuario, precio_menudeo_anterior, precio_menudeo_nuevo, precio_mayoreo_anterior, precio_mayoreo_nuevo) VALUES (?, ?, ?, ?, ?, ?)");
                    $hist->execute([$id, $_SESSION['id_usuario'], $previo['precio'], $precio, $previo['precio_mayoreo'], $precioMayoreo]);
                }
                $success = 'Producto actualizado. Precios reflejados en POS inmediatamente.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['borrar'])) {
    require_admin('Permisos insuficientes');
    $id = (int)$_GET['borrar'];
    try {
        $conexion->prepare("DELETE FROM productos WHERE id_producto=?")->execute([$id]);
        $success = 'Producto eliminado.';
    } catch (Exception $e) {
        $error = 'No se pudo eliminar: ' . $e->getMessage();
    }
}

$busqueda = trim($_GET['q'] ?? '');
$stmt = $conexion->prepare("SELECT * FROM productos WHERE nombre LIKE ? OR compuesto LIKE ? OR numero_lote LIKE ? ORDER BY nombre ASC, fecha_caducidad ASC");
$like = "%$busqueda%";
$stmt->execute([$like, $like, $like]);
$productos = $stmt->fetchAll();

// Contar vencidos para alerta
$totalVencidos = 0;
foreach ($productos as $p) {
    $st = expiry_status($p['fecha_caducidad'] ?? null)['status'];
    if (in_array($st, ['expired','expired_today'])) $totalVencidos++;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">Gestion de Catalogo e Inventario</h3>
        <?php if ($totalVencidos > 0): ?>
            <div class="mt-1">
                <span class="badge bg-danger fs-6 px-3 py-2">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                    <?php echo $totalVencidos; ?> producto<?php echo $totalVencidos > 1 ? 's' : ''; ?> VENCIDO<?php echo $totalVencidos > 1 ? 'S' : ''; ?> — No apto<?php echo $totalVencidos > 1 ? 's' : ''; ?> para venta
                </span>
            </div>
        <?php endif; ?>
    </div>
    <button class="btn btn-primary" onclick="abrirModalNuevo()"><i class="bi bi-plus-lg"></i> Nuevo Item</button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?php echo h($success); ?></div><?php endif; ?>

<div class="card card-custom p-4 border-0 shadow-sm">
    <form method="GET" class="row mb-3">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" id="catalogSearch" list="catalogSuggestions" class="form-control border-start-0 ps-0" placeholder="Buscar por nombre, compuesto o lote..." value="<?php echo h($busqueda); ?>">
                <datalist id="catalogSuggestions"></datalist>
            </div>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Buscar</button></div>
        <?php if ($busqueda): ?><div class="col-auto"><a href="inventario.php" class="btn btn-outline-secondary">Limpiar</a></div><?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th>Compuesto</th>
                    <th>Lote</th>
                    <th>Caducidad</th>
                    <th class="text-center">Stock</th>
                    <th>Menudeo</th>
                    <th>Mayoreo</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $p):
                    $expiry    = expiry_status($p['fecha_caducidad'] ?? null);
                    $vencido   = in_array($expiry['status'], ['expired', 'expired_today']);
                    $warning   = $expiry['status'] === 'warning';
                    $rowClass  = $vencido ? 'table-danger' : ($warning ? 'table-warning bg-opacity-25' : '');
                    $badgeClass = $vencido ? 'bg-danger' : ($warning ? 'bg-warning text-dark' : 'bg-success-subtle text-success border');
                ?>
                <tr class="<?php echo $rowClass; ?>" <?php echo $vencido ? 'style="opacity:.8;"' : ''; ?>>
                    <td>
                        <div class="fw-bold <?php echo $vencido ? 'text-danger text-decoration-line-through' : ''; ?>"><?php echo h($p['nombre']); ?></div>
                        <div class="small text-muted"><?php echo h($p['presentacion']); ?></div>
                        <?php if ($vencido): ?>
                            <span class="badge bg-danger mt-1 px-2">
                                <i class="bi bi-x-octagon-fill me-1"></i>VENCIDO — NO APTO PARA VENTA
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo h($p['compuesto']); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo h($p['numero_lote']); ?></span></td>
                    <td>
                        <?php echo h($p['fecha_caducidad']); ?>
                        <?php if ($expiry['message']): ?>
                            <div class="badge <?php echo $badgeClass; ?> border mt-1 d-block" style="white-space:normal;">
                                <i class="bi bi-<?php echo $vencido ? 'x-octagon-fill' : 'exclamation-triangle-fill'; ?> me-1"></i><?php echo h($expiry['message']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center fw-bold <?php echo $vencido ? 'text-muted' : ''; ?>"><?php echo (int)$p['stock']; ?></td>
                    <td class="fw-bold <?php echo $vencido ? 'text-muted' : 'text-primary'; ?>">$<?php echo number_format((float)$p['precio'], 2); ?></td>
                    <td class="fw-bold <?php echo $vencido ? 'text-muted' : 'text-success'; ?>">$<?php echo number_format((float)$p['precio_mayoreo'], 2); ?></td>
                    <td class="text-end">
                        <?php if (!$vencido): ?>
                            <button class="btn btn-sm btn-success" onclick='abrirModalEntrada(<?php echo json_encode($p); ?>)' title="Reabastecer">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        <?php else: ?>
                            <span class="btn btn-sm btn-secondary disabled" title="No se puede reabastecer un producto vencido">
                                <i class="bi bi-plus-lg"></i>
                            </span>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-warning" onclick='abrirModalEditar(<?php echo json_encode($p); ?>)' title="<?php echo $vencido ? 'Ver / Editar (VENCIDO)' : 'Editar'; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ($isAdmin): ?>
                            <a href="inventario.php?borrar=<?php echo (int)$p['id_producto']; ?>"
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('<?php echo $vencido ? '⚠️ Este producto está VENCIDO. ' : ''; ?>Confirmar eliminacion del producto');">
                                <i class="bi bi-trash"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalEntrada" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header bg-success text-white"><h5 class="modal-title">Reabastecer</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="accion" value="entrada_stock">
                <input type="hidden" name="id_producto" id="idProdEntrada">
                <div class="fw-bold text-center mb-3" id="nombreProdEntrada"></div>
                <label class="small fw-bold text-muted">Cantidad</label>
                <input type="number" name="cantidad" class="form-control mb-3" min="1" required>
                <label class="small fw-bold text-muted">Observacion</label>
                <input type="text" name="observaciones" class="form-control mb-3" placeholder="Factura, ajuste o compra">
                <button class="btn btn-success w-100 fw-bold">Confirmar Entrada</button>
            </form>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalProducto" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="tituloModal">Nuevo Producto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="vencidoWarningModal"></div>
        <div class="modal-body">
            <form method="POST" id="formProducto">
                <input type="hidden" name="accion" value="guardar_producto">
                <input type="hidden" name="id_producto" id="inputId">
                <div class="row g-3">
                    <div class="col-md-6"><label class="small fw-bold text-muted">Nombre comercial *</label><input type="text" name="nombre" id="inputNombre" class="form-control campo-obligatorio" required></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted">Compuesto *</label><input type="text" name="compuesto" id="inputCompuesto" class="form-control campo-obligatorio" required></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Numero de lote *</label><input type="text" name="numero_lote" id="inputLote" class="form-control campo-obligatorio" required></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Caducidad *</label><input type="date" name="fecha_caducidad" id="inputCaducidad" class="form-control campo-obligatorio" required></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Presentacion</label><input type="text" name="presentacion" id="inputPresentacion" class="form-control"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Precio menudeo *</label><input type="number" step="0.01" name="precio" id="inputPrecio" class="form-control campo-obligatorio precio-admin" required></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Precio mayoreo *</label><input type="number" step="0.01" name="precio_mayoreo" id="inputPrecioMayoreo" class="form-control campo-obligatorio precio-admin" required></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Costo adquisicion</label><input type="number" step="0.01" name="costo_adquisicion" id="inputCosto" class="form-control"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Stock inicial</label><input type="number" name="stock" id="inputStock" class="form-control" min="0"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Stock minimo</label><input type="number" name="stock_minimo" id="inputMinimo" class="form-control" min="0" value="5"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Categoria</label><input type="text" name="categoria" id="inputCategoria" class="form-control"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Laboratorio</label><input type="text" name="laboratorio" id="inputLaboratorio" class="form-control"></div>
                    <div class="col-12"><label class="small fw-bold text-muted">Descripcion</label><textarea name="descripcion" id="inputDescripcion" class="form-control"></textarea></div>
                </div>
                <div class="alert alert-danger mt-3 d-none" id="msgObligatorio">Completa los campos obligatorios antes de guardar.</div>
                <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold mt-4">Guardar Cambios</button>
            </form>
        </div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const esAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
const modalProd = new bootstrap.Modal(document.getElementById('modalProducto'));
const modalEnt  = new bootstrap.Modal(document.getElementById('modalEntrada'));

function setValue(id, value) { document.getElementById(id).value = value || ''; }

function abrirModalNuevo() {
    document.getElementById('tituloModal').innerText = 'Nuevo Producto';
    document.getElementById('vencidoWarningModal').innerHTML = '';
    ['inputId','inputNombre','inputCompuesto','inputLote','inputCaducidad','inputPresentacion','inputPrecio','inputPrecioMayoreo','inputCosto','inputCategoria','inputLaboratorio','inputDescripcion'].forEach(id => setValue(id, ''));
    setValue('inputStock', '0');
    setValue('inputMinimo', '5');
    document.getElementById('inputStock').removeAttribute('readonly');
    document.querySelectorAll('.precio-admin').forEach(el => el.readOnly = false);
    modalProd.show();
}

function abrirModalEditar(p) {
    // Detectar si está vencido
    const today = new Date(); today.setHours(0,0,0,0);
    const cad   = p.fecha_caducidad ? new Date(p.fecha_caducidad + 'T00:00:00') : null;
    const vencido = cad && cad <= today;

    document.getElementById('tituloModal').innerText = 'Editar Producto' + (vencido ? ' — ⚠️ VENCIDO' : '');
    const warn = document.getElementById('vencidoWarningModal');
    warn.innerHTML = vencido
        ? '<div class="alert alert-danger fw-bold mb-0"><i class="bi bi-x-octagon-fill me-2"></i>Este producto está VENCIDO y NO puede venderse. Solo puede editarse para actualizar datos o eliminarse del catálogo.</div>'
        : '';

    setValue('inputId', p.id_producto);
    setValue('inputNombre', p.nombre);
    setValue('inputCompuesto', p.compuesto);
    setValue('inputLote', p.numero_lote);
    setValue('inputCaducidad', p.fecha_caducidad);
    setValue('inputPresentacion', p.presentacion);
    setValue('inputPrecio', p.precio);
    setValue('inputPrecioMayoreo', p.precio_mayoreo);
    setValue('inputCosto', p.costo_adquisicion);
    setValue('inputStock', p.stock);
    setValue('inputMinimo', p.stock_minimo);
    setValue('inputCategoria', p.categoria);
    setValue('inputLaboratorio', p.laboratorio);
    setValue('inputDescripcion', p.descripcion);
    document.getElementById('inputStock').setAttribute('readonly', 'readonly');
    document.querySelectorAll('.precio-admin').forEach(el => el.readOnly = !esAdmin);
    modalProd.show();
}

function abrirModalEntrada(p) {
    setValue('idProdEntrada', p.id_producto);
    document.getElementById('nombreProdEntrada').innerText = p.nombre + ' / Lote ' + (p.numero_lote || '');
    modalEnt.show();
}

document.getElementById('formProducto').addEventListener('submit', function(e) {
    let ok = true;
    document.querySelectorAll('.campo-obligatorio').forEach(el => {
        const empty = !String(el.value || '').trim();
        el.classList.toggle('required-empty', empty);
        if (empty) ok = false;
    });
    document.getElementById('msgObligatorio').classList.toggle('d-none', ok);
    if (!ok) e.preventDefault();
});

document.getElementById('catalogSearch').addEventListener('input', async function() {
    if (this.value.length < 2) return;
    const res = await fetch('buscar_productos.php?q=' + encodeURIComponent(this.value) + '&context=catalogo');
    const data = await res.json();
    const productos = data.productos || [];
    const dl = document.getElementById('catalogSuggestions');
    dl.innerHTML = productos.map(p => `<option value="${p.nombre}">Lote ${p.numero_lote || ''} - ${p.compuesto || ''}</option>`).join('');
});
</script>
</body></html>
