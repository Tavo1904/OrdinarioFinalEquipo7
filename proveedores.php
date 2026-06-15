<?php
include 'db.php';
include 'header.php';
require_perm('compras'); // MEJORA 2: Permiso de compras requerido

$error   = '';
$success = '';

// ── Guardar nuevo proveedor ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_proveedor') {
    $rfcNuevo = strtoupper(trim($_POST['rfc']));
    try {
        // TC-EX-07 paso 2: verificar RFC duplicado antes de insertar
        $stmtRfc = $conexion->prepare("SELECT id_proveedor, nombre FROM proveedores WHERE UPPER(rfc) = ? LIMIT 1");
        $stmtRfc->execute([$rfcNuevo]);
        $existente = $stmtRfc->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $error = 'Ya existe un proveedor con el RFC <strong>' . h($rfcNuevo) . '</strong> '
                   . '(Proveedor: <strong>' . h($existente['nombre']) . '</strong>). '
                   . 'No se puede registrar un RFC duplicado.';
        } else {
            $stmt = $conexion->prepare("
                INSERT INTO proveedores (nombre, rfc, telefono, email, productos, estado)
                VALUES (?, ?, ?, ?, ?, 'activo')
            ");
            $stmt->execute([
                trim($_POST['nombre']),
                $rfcNuevo,
                trim($_POST['telefono']),
                trim($_POST['email'] ?? ''),
                trim($_POST['productos'] ?? ''),
            ]);
            $success = 'Proveedor guardado correctamente.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// ── Cambiar estado de proveedor ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_estado') {
    $idProv  = (int)$_POST['id_proveedor'];
    $newEst  = $_POST['estado'] === 'activo' ? 'inactivo' : 'activo';
    $conexion->prepare("UPDATE proveedores SET estado=? WHERE id_proveedor=?")->execute([$newEst, $idProv]);
    $success = 'Estado actualizado.';
}

// ── Parámetros de búsqueda ───────────────────────────────────────────────
$busNombre      = trim($_GET['nombre']         ?? '');
$busProd        = trim($_GET['productos']      ?? '');
$busEmail       = trim($_GET['email']          ?? '');
$busTel         = trim($_GET['telefono']       ?? '');
$busEstado      = in_array($_GET['estado'] ?? '', ['activo','inactivo']) ? $_GET['estado'] : '';
$verHistorial   = (int)($_GET['historial']     ?? 0);
// ── Nuevos filtros avanzados por producto real ─────────────────────────────
$busProductoNombre = trim($_GET['nombre_producto'] ?? '');
$busCategoria      = trim($_GET['categoria']       ?? '');

// ── Obtener categorías disponibles para el <select> ───────────────────────
$categorias = $conexion->query("SELECT DISTINCT categoria FROM productos ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);

// ── Construir query dinámica ──────────────────────────────────────────────
// Cuando hay filtro por nombre/categoría de producto real usamos JOIN
$usaFiltroProducto = ($busProductoNombre !== '' || $busCategoria !== '');

if ($usaFiltroProducto) {
    // JOIN: proveedor → facturas_compra → productos
    $conds  = ['1=1'];
    $params = [];
    if ($busNombre !== '')         { $conds[] = 'p.nombre LIKE ?';    $params[] = "%$busNombre%"; }
    if ($busEmail  !== '')         { $conds[] = 'p.email LIKE ?';     $params[] = "%$busEmail%";  }
    if ($busTel    !== '')         { $conds[] = 'p.telefono LIKE ?';  $params[] = "%$busTel%";    }
    if ($busEstado !== '')         { $conds[] = 'p.estado = ?';       $params[] = $busEstado;      }
    if ($busProductoNombre !== '') { $conds[] = 'pr.nombre LIKE ?';   $params[] = "%$busProductoNombre%"; }
    if ($busCategoria !== '')      { $conds[] = 'pr.categoria = ?';   $params[] = $busCategoria;   }
    $where = implode(' AND ', $conds);

    $stmtProv = $conexion->prepare("
        SELECT p.*,
               COUNT(DISTINCT f.id_factura)                        AS total_compras,
               COALESCE(SUM(f.costo_unitario * f.cantidad), 0)    AS total_invertido
        FROM proveedores p
        INNER JOIN facturas_compra f  ON f.id_proveedor = p.id_proveedor
        INNER JOIN productos pr       ON pr.id_producto  = f.id_producto
        WHERE $where
        GROUP BY p.id_proveedor
        ORDER BY p.nombre ASC
    ");
} else {
    // Búsqueda clásica por columnas del proveedor
    $conds  = ['1=1'];
    $params = [];
    if ($busNombre !== '') { $conds[] = 'nombre LIKE ?';   $params[] = "%$busNombre%"; }
    if ($busProd   !== '') { $conds[] = 'productos LIKE ?'; $params[] = "%$busProd%";   }
    if ($busEmail  !== '') { $conds[] = 'email LIKE ?';     $params[] = "%$busEmail%";  }
    if ($busTel    !== '') { $conds[] = 'telefono LIKE ?';  $params[] = "%$busTel%";    }
    if ($busEstado !== '') { $conds[] = 'estado = ?';       $params[] = $busEstado;      }
    $where = implode(' AND ', $conds);

    $stmtProv = $conexion->prepare("
        SELECT p.*,
               COUNT(f.id_factura)                              AS total_compras,
               COALESCE(SUM(f.costo_unitario * f.cantidad), 0) AS total_invertido
        FROM proveedores p
        LEFT JOIN facturas_compra f ON f.id_proveedor = p.id_proveedor
        WHERE $where
        GROUP BY p.id_proveedor
        ORDER BY p.nombre ASC
    ");
}
$stmtProv->execute($params);
$proveedores = $stmtProv->fetchAll(PDO::FETCH_ASSOC);

// ── Lista completa sin filtro para formularios ────────────────────────────
$todosProveedores = $conexion->query("SELECT * FROM proveedores WHERE estado='activo' ORDER BY nombre ASC")->fetchAll();
$productos = $conexion->query("SELECT id_producto, nombre, numero_lote, stock, costo_adquisicion FROM productos ORDER BY nombre ASC")->fetchAll();

// ── Productos reales por proveedor (vía facturas_compra) ──────────────────
$productosPorProveedor = [];
if (!empty($proveedores)) {
    $ids = implode(',', array_map(fn($p) => (int)$p['id_proveedor'], $proveedores));
    $stmtPP = $conexion->query("
        SELECT f.id_proveedor,
               pr.nombre       AS prod_nombre,
               pr.categoria    AS prod_categoria,
               pr.presentacion AS prod_presentacion,
               pr.stock        AS prod_stock,
               pr.precio       AS prod_precio,
               MAX(f.fecha)    AS ultima_compra
        FROM facturas_compra f
        INNER JOIN productos pr ON pr.id_producto = f.id_producto
        WHERE f.id_proveedor IN ($ids)
        GROUP BY f.id_proveedor, pr.id_producto
        ORDER BY f.id_proveedor, pr.categoria, pr.nombre
    ");
    foreach ($stmtPP->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productosPorProveedor[$row['id_proveedor']][] = $row;
    }
}

// ── Historial de un proveedor específico ─────────────────────────────────
$historialProv = null;
$historialRows = [];
if ($verHistorial > 0) {
    $stmtHP = $conexion->prepare("SELECT * FROM proveedores WHERE id_proveedor=? LIMIT 1");
    $stmtHP->execute([$verHistorial]);
    $historialProv = $stmtHP->fetch(PDO::FETCH_ASSOC);
    if ($historialProv) {
        $stmtHR = $conexion->prepare("
            SELECT f.*, pr.nombre AS producto_nombre
            FROM facturas_compra f
            INNER JOIN productos pr ON f.id_producto = pr.id_producto
            WHERE f.id_proveedor = ?
            ORDER BY f.fecha DESC
            LIMIT 100
        ");
        $stmtHR->execute([$verHistorial]);
        $historialRows = $stmtHR->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0"><i class="bi bi-truck me-2 text-primary"></i>Proveedores y Abastecimiento</h3>
        <small class="text-muted"><?php echo count($proveedores); ?> proveedor(es) encontrado(s)</small>
    </div>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>

<!-- ── Buscador avanzado ──────────────────────────────────────────────── -->
<div class="card card-custom border-0 shadow-sm p-3 mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-funnel-fill text-primary"></i>
        <span class="fw-bold small text-muted text-uppercase" style="letter-spacing:.06em;">Búsqueda Avanzada</span>
    </div>

    <!-- Pestañas de modo de búsqueda -->
    <ul class="nav nav-pills nav-sm mb-3" id="modoBusqueda" role="tablist" style="gap:6px;">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo !$usaFiltroProducto ? 'active' : ''; ?> py-1 px-3 small fw-bold"
                    id="tab-proveedor" data-bs-toggle="pill" data-bs-target="#panel-proveedor" type="button">
                <i class="bi bi-building me-1"></i>Por Proveedor
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $usaFiltroProducto ? 'active' : ''; ?> py-1 px-3 small fw-bold"
                    id="tab-producto" data-bs-toggle="pill" data-bs-target="#panel-producto" type="button">
                <i class="bi bi-capsule me-1"></i>Por Producto / Categoría
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Panel: búsqueda por datos del proveedor -->
        <div class="tab-pane fade <?php echo !$usaFiltroProducto ? 'show active' : ''; ?>" id="panel-proveedor">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="small fw-bold text-muted">Nombre del proveedor</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Farmex" value="<?php echo h($busNombre); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="small fw-bold text-muted">Producto / Categoría (texto)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-capsule"></i></span>
                        <input type="text" name="productos" class="form-control" placeholder="Ej: analgesicos" value="<?php echo h($busProd); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Correo electrónico</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="text" name="email" class="form-control" placeholder="@" value="<?php echo h($busEmail); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Teléfono</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="text" name="telefono" class="form-control" placeholder="Ej: 55..." value="<?php echo h($busTel); ?>">
                    </div>
                </div>
                <div class="col-md-1">
                    <label class="small fw-bold text-muted">Estado</label>
                    <select name="estado" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="activo"   <?php echo $busEstado==='activo'?'selected':''; ?>>Activo</option>
                        <option value="inactivo" <?php echo $busEstado==='inactivo'?'selected':''; ?>>Inactivo</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
                    <a href="proveedores.php" class="btn btn-outline-secondary btn-sm" title="Limpiar"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>
        </div>

        <!-- Panel: búsqueda por producto/categoría real (vía facturas_compra) -->
        <div class="tab-pane fade <?php echo $usaFiltroProducto ? 'show active' : ''; ?>" id="panel-producto">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="modo" value="producto">
                <div class="col-md-4">
                    <label class="small fw-bold text-muted">Nombre del producto suministrado</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="nombre_producto" class="form-control"
                               placeholder="Ej: Paracetamol, Amoxicilina…"
                               value="<?php echo h($busProductoNombre); ?>">
                    </div>
                    <div class="form-text small">Busca entre los productos con facturas registradas</div>
                </div>
                <div class="col-md-3">
                    <label class="small fw-bold text-muted">Categoría del producto</label>
                    <select name="categoria" class="form-select form-select-sm">
                        <option value="">— Todas las categorías —</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo h($cat); ?>" <?php echo $busCategoria===$cat?'selected':''; ?>>
                            <?php echo h($cat); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Nombre del proveedor</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input type="text" name="nombre" class="form-control" placeholder="Filtrar proveedor"
                               value="<?php echo h($busNombre); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Estado</label>
                    <select name="estado" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="activo"   <?php echo $busEstado==='activo'?'selected':''; ?>>Activo</option>
                        <option value="inactivo" <?php echo $busEstado==='inactivo'?'selected':''; ?>>Inactivo</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
                    <a href="proveedores.php" class="btn btn-outline-secondary btn-sm" title="Limpiar"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>

            <?php if ($usaFiltroProducto && ($busProductoNombre !== '' || $busCategoria !== '')): ?>
            <div class="mt-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">
                    <i class="bi bi-funnel me-1"></i>
                    Filtrando por:
                    <?php if ($busProductoNombre !== ''): ?> producto "<strong><?php echo h($busProductoNombre); ?></strong>"<?php endif; ?>
                    <?php if ($busCategoria !== ''): ?> categoría "<strong><?php echo h($busCategoria); ?></strong>"<?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- ── Panel izquierdo: formularios ──────────────────────────────────── -->
    <div class="col-md-4">
        <!-- Nuevo proveedor -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0"><i class="bi bi-person-plus me-1 text-primary"></i>Nuevo Proveedor</h5>
                <a href="facturas.php" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-plus me-1"></i>Cargar Factura
                </a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="guardar_proveedor">
                    <label class="small fw-bold text-muted">Nombre *</label>
                    <input name="nombre" class="form-control mb-2" required>
                    <label class="small fw-bold text-muted">RFC *</label>
                    <input name="rfc" class="form-control mb-2" required>
                    <label class="small fw-bold text-muted">Teléfono *</label>
                    <input name="telefono" class="form-control mb-2" required>
                    <label class="small fw-bold text-muted">Correo electrónico</label>
                    <input name="email" type="email" class="form-control mb-2" placeholder="proveedor@empresa.com">
                    <label class="small fw-bold text-muted">Productos / Categorías</label>
                    <input name="productos" class="form-control mb-3" placeholder="Analgesicos, antibioticos">
                    <button class="btn btn-primary w-100 fw-bold">Guardar Proveedor</button>
                </form>
            </div>
        </div>

    </div>

    <!-- ── Panel derecho: tabla de proveedores ───────────────────────────── -->
    <div class="col-md-8">
        <!-- Historial de proveedor -->
        <?php if ($historialProv): ?>
        <div class="card card-custom border-0 shadow-sm mb-4 border-start border-4 border-primary">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-clock-history me-1 text-primary"></i>
                    Historial de Compras — <?php echo h($historialProv['nombre']); ?>
                </h6>
                <a href="proveedores.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Cerrar
                </a>
            </div>
            <?php if (empty($historialRows)): ?>
                <div class="card-body text-center text-muted py-4">Sin compras registradas para este proveedor.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Folio</th>
                            <th>Producto</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-end">Costo unitario</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $totalH = 0;
                    foreach ($historialRows as $hr):
                        $sub = (float)$hr['costo_unitario'] * (int)$hr['cantidad'];
                        $totalH += $sub;
                    ?>
                    <tr>
                        <td class="text-muted small"><?php echo h(date('d/m/Y H:i', strtotime($hr['fecha']))); ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo h($hr['folio'] ?: '—'); ?></span></td>
                        <td class="fw-bold"><?php echo h($hr['producto_nombre']); ?></td>
                        <td class="text-center"><?php echo (int)$hr['cantidad']; ?></td>
                        <td class="text-end">$<?php echo number_format((float)$hr['costo_unitario'], 2); ?></td>
                        <td class="text-end fw-bold">$<?php echo number_format($sub, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4">Total invertido</td>
                            <td></td>
                            <td class="text-end text-success">$<?php echo number_format($totalH, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tabla de proveedores -->
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0"><i class="bi bi-list-ul me-1"></i>Proveedores</h5>
                <span class="badge bg-secondary"><?php echo count($proveedores); ?> registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>RFC</th>
                            <th>Contacto</th>
                            <th>Productos</th>
                            <th class="text-center">Compras</th>
                            <th class="text-end">Invertido</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($proveedores)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-search" style="font-size:2rem;opacity:.2;display:block;margin-bottom:6px;"></i>
                            Sin proveedores que coincidan con los filtros.
                        </td></tr>
                    <?php else: foreach ($proveedores as $p):
                        $pid = (int)$p['id_proveedor'];
                        $productosReales = $productosPorProveedor[$pid] ?? [];
                        $tieneProductosReales = !empty($productosReales);
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?php echo h($p['nombre']); ?></div>
                            <?php if (!empty($p['email'])): ?>
                            <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?php echo h($p['email']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo h($p['rfc']); ?></td>
                        <td>
                            <div class="small"><i class="bi bi-telephone me-1 text-muted"></i><?php echo h($p['telefono']); ?></div>
                        </td>
                        <td style="max-width:220px;">
                            <?php
                            $prods = array_filter(array_map('trim', explode(',', $p['productos'] ?? '')));
                            foreach ($prods as $prod):
                            ?>
                            <span class="badge bg-light text-dark border me-1 mb-1" style="font-size:.72rem;"><?php echo h($prod); ?></span>
                            <?php endforeach; ?>

                            <?php if ($tieneProductosReales): ?>
                            <!-- Botón para expandir productos reales -->
                            <div class="mt-1">
                                <button class="btn btn-link btn-sm p-0 text-primary fw-bold text-decoration-none"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#prods-<?php echo $pid; ?>"
                                        aria-expanded="false"
                                        style="font-size:.75rem;">
                                    <i class="bi bi-box-seam me-1"></i><?php echo count($productosReales); ?> producto(s) suministrado(s)
                                    <i class="bi bi-chevron-down ms-1" style="font-size:.65rem;"></i>
                                </button>
                                <div class="collapse mt-2" id="prods-<?php echo $pid; ?>">
                                    <div class="border rounded" style="background:#f8f9fa;font-size:.78rem;max-height:220px;overflow-y:auto;">
                                        <table class="table table-borderless table-sm mb-0">
                                            <thead style="position:sticky;top:0;background:#f0f0f0;">
                                                <tr>
                                                    <th class="py-1 px-2">Producto</th>
                                                    <th class="py-1 px-2">Categoría</th>
                                                    <th class="py-1 px-2 text-center">Stock</th>
                                                    <th class="py-1 px-2 text-end">Precio</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($productosReales as $pr): ?>
                                            <tr>
                                                <td class="py-1 px-2 fw-semibold"><?php echo h($pr['prod_nombre']); ?></td>
                                                <td class="py-1 px-2">
                                                    <span class="badge rounded-pill" style="background:#e9ecef;color:#495057;font-size:.7rem;"><?php echo h($pr['prod_categoria']); ?></span>
                                                </td>
                                                <td class="py-1 px-2 text-center">
                                                    <span class="<?php echo (int)$pr['prod_stock'] <= 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
                                                        <?php echo (int)$pr['prod_stock']; ?>
                                                    </span>
                                                </td>
                                                <td class="py-1 px-2 text-end text-success fw-bold">$<?php echo number_format((float)$pr['prod_precio'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary-subtle text-primary border"><?php echo number_format((int)$p['total_compras']); ?></span>
                        </td>
                        <td class="text-end fw-bold text-success">$<?php echo number_format((float)$p['total_invertido'], 2); ?></td>
                        <td class="text-center">
                            <?php $est = $p['estado'] ?? 'activo'; ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="accion" value="cambiar_estado">
                                <input type="hidden" name="id_proveedor" value="<?php echo $pid; ?>">
                                <input type="hidden" name="estado" value="<?php echo h($est); ?>">
                                <button type="submit" class="badge border-0 <?php echo $est==='activo'?'bg-success-subtle text-success':'bg-danger-subtle text-danger'; ?> border"
                                        style="cursor:pointer;font-size:.8rem;padding:5px 8px;" title="Clic para cambiar">
                                    <?php echo $est === 'activo' ? '✅ Activo' : '❌ Inactivo'; ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <a href="proveedores.php?historial=<?php echo $pid; ?>"
                               class="btn btn-outline-primary btn-sm" title="Ver historial de compras">
                                <i class="bi bi-clock-history"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
