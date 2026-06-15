<?php
  include 'db.php';
  if (session_status() === PHP_SESSION_NONE) session_start();
  // MEJORA 2 & 4: Verificar sesión y permisos dinámicos
  if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }
  refresh_permisos($conexion);
  if (!has_perm('compras')) { header("Location: dashboard.php?acceso_denegado=1"); exit; }
  
// ── Parámetros ────────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$f_inicio    = $_GET['f_inicio'] ?? $hoy;
$f_fin       = $_GET['f_fin']    ?? $hoy;
$orden       = in_array($_GET['orden'] ?? '', ['id_pedido','total','fecha']) ? $_GET['orden'] : 'fecha';
$dir         = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$canal       = in_array($_GET['canal'] ?? '', ['pos','web']) ? $_GET['canal'] : 'todos';
$export      = $_GET['export'] ?? '';
$pagina      = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina  = 20;
$offset      = ($pagina - 1) * $por_pagina;

if ($f_inicio > $f_fin) [$f_inicio, $f_fin] = [$f_fin, $f_inicio];

// ── Condición de canal ────────────────────────────────────────────────────
$canalCond = match($canal) {
    'pos' => "AND (p.origen='pos' OR p.origen IS NULL OR p.origen='') AND p.estado='completado'",
    'web' => "AND p.origen='web' AND p.estado_aprobacion='aprobado'",
    default => "AND (
                    (p.estado='completado' AND (p.origen='pos' OR p.origen IS NULL OR p.origen=''))
                    OR (p.estado_aprobacion='aprobado' AND p.origen='web')
                )",
};

// ── KPIs del período ──────────────────────────────────────────────────────
$stmtKpi = $conexion->prepare("
    SELECT COUNT(*)                    AS total_facturas,
           COALESCE(SUM(total), 0)     AS total_ventas,
           COALESCE(AVG(total), 0)     AS promedio,
           COALESCE(MAX(total), 0)     AS ticket_max,
           COALESCE(MIN(total), 0)     AS ticket_min
    FROM pedidos p
    WHERE DATE(p.fecha) BETWEEN ? AND ?
    $canalCond
");
$stmtKpi->execute([$f_inicio, $f_fin]);
$kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// ── Top 8 productos más vendidos ──────────────────────────────────────────
$stmtTop = $conexion->prepare("
    SELECT pr.nombre,
           SUM(dp.cantidad)  AS unidades,
           SUM(dp.subtotal)  AS ingreso
    FROM detalle_pedido dp
    INNER JOIN pedidos   p  ON dp.id_pedido  = p.id_pedido
    INNER JOIN productos pr ON dp.id_producto = pr.id_producto
    WHERE DATE(p.fecha) BETWEEN ? AND ?
    $canalCond
    GROUP BY pr.id_producto
    ORDER BY unidades DESC
    LIMIT 8
");
$stmtTop->execute([$f_inicio, $f_fin]);
$topProductos = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

// ── Total de filas para paginación ────────────────────────────────────────
$stmtCount = $conexion->prepare("
    SELECT COUNT(*) FROM pedidos p
    WHERE DATE(p.fecha) BETWEEN ? AND ?
    $canalCond
");
$stmtCount->execute([$f_inicio, $f_fin]);
$totalFilas = (int)$stmtCount->fetchColumn();
$totalPaginas = max(1, ceil($totalFilas / $por_pagina));

// ── Listado de facturas ───────────────────────────────────────────────────
$stmtFact = $conexion->prepare("
    SELECT p.id_pedido, p.total, p.fecha, p.tipo_pago,
           p.estado, p.estado_aprobacion,
           COALESCE(p.origen, 'pos')    AS origen,
           COALESCE(u.nombre, u.correo, 'Cliente POS') AS cliente
    FROM pedidos p
    LEFT JOIN usuarios u ON p.id_cliente = u.id_usuario
    WHERE DATE(p.fecha) BETWEEN ? AND ?
    $canalCond
    ORDER BY p.$orden $dir
    LIMIT $por_pagina OFFSET $offset
");
$stmtFact->execute([$f_inicio, $f_fin]);
$facturas = $stmtFact->fetchAll(PDO::FETCH_ASSOC);

// ── Para exportar: todas las filas sin paginación ─────────────────────────
function get_all_facturas(PDO $cx, string $f_ini, string $f_fin, string $canalCond, string $orden, string $dir): array {
    $st = $cx->prepare("
        SELECT p.id_pedido, p.total, p.fecha, p.tipo_pago,
               p.estado, p.estado_aprobacion,
               COALESCE(p.origen,'pos')                      AS origen,
               COALESCE(u.nombre, u.correo, 'Cliente POS')  AS cliente
        FROM pedidos p
        LEFT JOIN usuarios u ON p.id_cliente = u.id_usuario
        WHERE DATE(p.fecha) BETWEEN ? AND ?
        $canalCond
        ORDER BY p.$orden $dir
    ");
    $st->execute([$f_ini, $f_fin]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────
// INC-UI-08: CARGAR FACTURA DE COMPRA
// ─────────────────────────────────────────
$errorFactura   = '';
$successFactura = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cargar_factura_compra') {
    $idProveedor = (int)$_POST['id_proveedor'];
    $idProducto  = (int)$_POST['id_producto'];
    $cantidad    = (int)$_POST['cantidad'];
    $costo       = (float)$_POST['costo_unitario'];
    $folio       = trim($_POST['folio'] ?? '');
    if ($idProveedor <= 0 || $idProducto <= 0 || $cantidad <= 0 || $costo < 0) {
        $errorFactura = 'Completa todos los campos correctamente.';
    } else {
        try {
            $conexion->beginTransaction();
            $conexion->prepare("INSERT INTO facturas_compra (id_proveedor, id_producto, cantidad, costo_unitario, folio) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$idProveedor, $idProducto, $cantidad, $costo, $folio]);
            $conexion->prepare("UPDATE productos SET stock = stock + ?, costo_adquisicion = ? WHERE id_producto = ?")
                     ->execute([$cantidad, $costo, $idProducto]);
            $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo_movimiento, cantidad, observaciones, origen) VALUES (?, ?, 'entrada', ?, ?, 'entrada')")
                     ->execute([$idProducto, $_SESSION['id_usuario'], $cantidad, 'Factura de compra ' . ($folio ?: 'sin folio')]);
            $conexion->commit();
            $successFactura = 'Factura registrada. Stock y costo actualizados correctamente.';
        } catch (Exception $e) {
            $conexion->rollBack();
            $errorFactura = 'Error al registrar la factura: ' . $e->getMessage();
        }
    }
}

// ── Gastos de compra del período ──────────────────────────────────────────
$stmtGastos = $conexion->prepare("
    SELECT COUNT(*)                                           AS total_compras,
           COALESCE(SUM(f.costo_unitario * f.cantidad), 0)  AS total_gastos
    FROM facturas_compra f
    WHERE DATE(f.fecha) BETWEEN ? AND ?
");
$stmtGastos->execute([$f_inicio, $f_fin]);
$gastos = $stmtGastos->fetch(PDO::FETCH_ASSOC);

// ── Listado de facturas de compra del período ─────────────────────────────
$stmtCompras = $conexion->prepare("
    SELECT f.id_factura, f.folio, f.fecha, f.cantidad, f.costo_unitario,
           (f.costo_unitario * f.cantidad)  AS subtotal,
           pv.nombre AS proveedor,
           pr.nombre AS producto
    FROM facturas_compra f
    INNER JOIN proveedores pv ON f.id_proveedor = pv.id_proveedor
    INNER JOIN productos   pr ON f.id_producto  = pr.id_producto
    WHERE DATE(f.fecha) BETWEEN ? AND ?
    ORDER BY f.fecha DESC
");
$stmtCompras->execute([$f_inicio, $f_fin]);
$facturasCompra = $stmtCompras->fetchAll(PDO::FETCH_ASSOC);

// ── Proveedores y productos para el formulario ────────────────────────────
$proveedoresForm = $conexion->query(
    "SELECT id_proveedor, nombre FROM proveedores WHERE estado='activo' ORDER BY nombre ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Todos los productos (fallback cuando proveedor no tiene historial)
$productosForm = $conexion->query(
    "SELECT id_producto, nombre, numero_lote, stock, costo_adquisicion FROM productos ORDER BY nombre ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Productos vinculados a cada proveedor (por historial en facturas_compra)
// Incluye el último costo pagado a ese proveedor por cada producto
$stmtPP = $conexion->query("
    SELECT f.id_proveedor,
           pr.id_producto,
           pr.nombre,
           pr.numero_lote,
           pr.stock,
           (SELECT fc2.costo_unitario
            FROM facturas_compra fc2
            WHERE fc2.id_proveedor = f.id_proveedor AND fc2.id_producto = pr.id_producto
            ORDER BY fc2.fecha DESC LIMIT 1)          AS ultimo_costo,
           pr.costo_adquisicion
    FROM facturas_compra f
    INNER JOIN productos pr ON f.id_producto = pr.id_producto
    GROUP BY f.id_proveedor, pr.id_producto
    ORDER BY f.id_proveedor, pr.nombre
");
$productosPorProveedor = [];
foreach ($stmtPP->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $productosPorProveedor[(int)$row['id_proveedor']][] = [
        'id'     => (int)$row['id_producto'],
        'nombre' => $row['nombre'],
        'lote'   => $row['numero_lote'] ?? '',
        'stock'  => (int)$row['stock'],
        'costo'  => number_format((float)($row['ultimo_costo'] ?? $row['costo_adquisicion'] ?? 0), 2, '.', ''),
    ];
}

// ─────────────────────────────────────────
// EXPORT PDF

// ─────────────────────────────────────────
if ($export === 'pdf') {
    $todasFact = get_all_facturas($conexion, $f_inicio, $f_fin, $canalCond, $orden, $dir);
    $_SESSION['facturas_print'] = [
        'facturas'     => $todasFact,
        'kpis'         => $kpis,
        'top_productos'=> $topProductos,
        'f_inicio'     => $f_inicio,
        'f_fin'        => $f_fin,
        'canal'        => $canal,
    ];
    header('Location: facturas_print.php');
    exit;
}

// ─────────────────────────────────────────
// EXPORT EXCEL
// ─────────────────────────────────────────
if ($export === 'excel') {
    include_once 'export_helper.php';
    $todasFact = get_all_facturas($conexion, $f_inicio, $f_fin, $canalCond, $orden, $dir);
    $pyCode = <<<'PYTHON'
import sys, json, io
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

data    = json.loads(sys.stdin.read())
facts   = data['facturas']
kpis    = data['kpis']
top     = data['top_productos']
f_ini   = data['f_inicio']
f_fin   = data['f_fin']

wb = Workbook()
thin   = Side(style='thin', color="D1D5DB")
border = Border(left=thin, right=thin, top=thin, bottom=thin)

def fill(c):  return PatternFill("solid", fgColor=c)
def font(bold=False, size=11, color="111827", white=False):
    return Font(bold=bold, size=size, color="FFFFFF" if white else color)

# ── Hoja 1: Facturas ──────────────────────────────────────────────────────
ws = wb.active
ws.title = "Facturas"

periodo = f_ini if f_ini == f_fin else f"{f_ini} al {f_fin}"

ws.merge_cells("A1:G1")
ws["A1"] = f"POPULARES PEÑALOZA — Reporte de Facturas"
ws["A1"].font = font(bold=True, size=14, white=True)
ws["A1"].fill = fill("1A56DB")
ws["A1"].alignment = Alignment(horizontal="center", vertical="center")
ws.row_dimensions[1].height = 30

ws.merge_cells("A2:G2")
ws["A2"] = f"Período: {periodo}"
ws["A2"].font = font(size=10, color="6B7280")
ws["A2"].alignment = Alignment(horizontal="center")

kpi_defs = [
    ("TOTAL FACTURAS", str(int(kpis['total_facturas'])),      "1A56DB"),
    ("TOTAL VENTAS",   f"${float(kpis['total_ventas']):,.2f}","0E9F6E"),
    ("PROMEDIO",       f"${float(kpis['promedio']):,.2f}",    "7C3AED"),
    ("TICKET MÁX.",    f"${float(kpis['ticket_max']):,.2f}",  "065F46"),
    ("TICKET MÍN.",    f"${float(kpis['ticket_min']):,.2f}",  "D97706"),
]
for i, (lbl, val, clr) in enumerate(kpi_defs):
    c = get_column_letter(i + 1)
    ws[f"{c}4"] = lbl
    ws[f"{c}4"].font = font(bold=True, size=8, color=clr)
    ws[f"{c}4"].fill = fill("F3F4F6")
    ws[f"{c}4"].alignment = Alignment(horizontal="center")
    ws[f"{c}5"] = val
    ws[f"{c}5"].font = font(bold=True, size=12, color=clr)
    ws[f"{c}5"].alignment = Alignment(horizontal="center")

hdrs   = ["Folio", "Cliente", "Canal", "Fecha / Hora", "Forma de Pago", "Total", "Estado"]
widths = [10, 28, 10, 20, 16, 14, 16]
for i, (h, w) in enumerate(zip(hdrs, widths)):
    c = get_column_letter(i + 1)
    ws[f"{c}7"] = h
    ws[f"{c}7"].font  = font(bold=True, size=9, white=True)
    ws[f"{c}7"].fill  = fill("111827")
    ws[f"{c}7"].alignment = Alignment(horizontal="center", vertical="center")
    ws[f"{c}7"].border = border
    ws.column_dimensions[c].width = w

for r, f in enumerate(facts):
    row = 8 + r
    folio = f"F-{int(f['id_pedido']):05d}"
    canal = "Web" if f['origen'] == 'web' else "POS"
    estado = "Aprobado" if f['estado_aprobacion'] == 'aprobado' else f['estado'].capitalize()
    bg = "EFF6FF" if r % 2 == 0 else "F9FAFB"
    vals = [folio, f['cliente'], canal, f['fecha'], f['tipo_pago'].capitalize(), f"${float(f['total']):,.2f}", estado]
    for i, (v, a) in enumerate(zip(vals, ['C','L','C','L','C','R','C'])):
        c = get_column_letter(i + 1)
        ws[f"{c}{row}"] = v
        ws[f"{c}{row}"].fill  = fill(bg)
        ws[f"{c}{row}"].border = border
        ws[f"{c}{row}"].alignment = Alignment(horizontal=a)

# ── Hoja 2: Top Productos ──────────────────────────────────────────────────
ws2 = wb.create_sheet("Top Productos")
ws2.merge_cells("A1:C1")
ws2["A1"] = "Productos Más Vendidos"
ws2["A1"].font = font(bold=True, size=13, white=True)
ws2["A1"].fill = fill("0E9F6E")
ws2["A1"].alignment = Alignment(horizontal="center", vertical="center")
ws2.row_dimensions[1].height = 26

for i, (h, w) in enumerate([("Producto", 36), ("Unidades", 14), ("Ingreso", 16)]):
    c = get_column_letter(i + 1)
    ws2[f"{c}3"] = h
    ws2[f"{c}3"].font  = font(bold=True, size=9, white=True)
    ws2[f"{c}3"].fill  = fill("111827")
    ws2[f"{c}3"].alignment = Alignment(horizontal="center")
    ws2.column_dimensions[c].width = w

for r, p in enumerate(top):
    row = 4 + r
    bg = "ECFDF5" if r % 2 == 0 else "F9FAFB"
    ws2[f"A{row}"] = p['nombre'];     ws2[f"A{row}"].fill = fill(bg); ws2[f"A{row}"].border = border
    ws2[f"B{row}"] = int(p['unidades']); ws2[f"B{row}"].fill = fill(bg); ws2[f"B{row}"].border = border; ws2[f"B{row}"].alignment = Alignment(horizontal="center")
    ws2[f"C{row}"] = f"${float(p['ingreso']):,.2f}"; ws2[f"C{row}"].fill = fill(bg); ws2[f"C{row}"].border = border; ws2[f"C{row}"].alignment = Alignment(horizontal="right")

buf = io.BytesIO()
wb.save(buf)
sys.stdout.buffer.write(buf.getvalue())
PYTHON;
    $payload = [
        'facturas' => $todasFact, 'kpis' => $kpis,
        'top_productos' => $topProductos,
        'f_inicio' => $f_inicio, 'f_fin' => $f_fin, 'canal' => $canal,
    ];
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="facturas_' . $f_inicio . '_' . $f_fin . '.xlsx"');
    echo run_python_export($pyCode, $payload);
    exit;
}

// ─────────────────────────────────────────
// VISTA HTML
// ─────────────────────────────────────────
include 'header.php';

function folio(int $id): string { return 'F-' . str_pad($id, 5, '0', STR_PAD_LEFT); }
function sortUrl(string $col, string $currentOrden, string $currentDir, string $fi, string $ff, string $c, int $pag): string {
    $newDir = ($currentOrden === $col && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    return "facturas.php?f_inicio=$fi&f_fin=$ff&canal=$c&orden=$col&dir=$newDir&pagina=$pag";
}
function sortIcon(string $col, string $currentOrden, string $currentDir): string {
    if ($currentOrden !== $col) return '<i class="bi bi-arrow-down-up text-muted opacity-50 ms-1"></i>';
    return $currentDir === 'ASC'
        ? '<i class="bi bi-arrow-up ms-1 text-primary"></i>'
        : '<i class="bi bi-arrow-down ms-1 text-primary"></i>';
}
$paginaUrl = "facturas.php?f_inicio=$f_inicio&f_fin=$f_fin&canal=$canal&orden=$orden&dir=$dir";
?>

<?php if ($successFactura): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
    <div><?php echo h($successFactura); ?></div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($errorFactura): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
    <div><?php echo h($errorFactura); ?></div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold text-dark mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Módulo de Facturas</h3>
        <small class="text-muted">Ventas POS y pedidos web · <?php echo number_format($totalFilas); ?> ventas &nbsp;|&nbsp;
            <span class="text-danger fw-bold"><?php echo (int)($gastos['total_compras']??0); ?> compras en el período</span>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalNuevaFactura">
            <i class="bi bi-file-earmark-plus me-1"></i>Nueva Factura de Compra
        </button>
        <a href="facturas.php?f_inicio=<?php echo h($f_inicio); ?>&f_fin=<?php echo h($f_fin); ?>&canal=<?php echo h($canal); ?>&orden=<?php echo h($orden); ?>&dir=<?php echo h($dir); ?>&export=pdf"
           class="btn btn-dark btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
        <a href="facturas.php?f_inicio=<?php echo h($f_inicio); ?>&f_fin=<?php echo h($f_fin); ?>&canal=<?php echo h($canal); ?>&orden=<?php echo h($orden); ?>&dir=<?php echo h($dir); ?>&export=excel"
           class="btn btn-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
    </div>
</div>

<!-- Filtros -->
<div class="card card-custom border-0 shadow-sm p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-auto">
            <label class="small fw-bold text-muted">Fecha inicio</label>
            <input type="date" name="f_inicio" class="form-control" value="<?php echo h($f_inicio); ?>">
        </div>
        <div class="col-md-auto">
            <label class="small fw-bold text-muted">Fecha fin</label>
            <input type="date" name="f_fin" class="form-control" value="<?php echo h($f_fin); ?>">
        </div>
        <div class="col-md-auto">
            <label class="small fw-bold text-muted">Canal</label>
            <select name="canal" class="form-select">
                <option value="todos" <?php echo $canal==='todos'?'selected':''; ?>>Todos</option>
                <option value="pos"   <?php echo $canal==='pos'?'selected':''; ?>>🏪 POS</option>
                <option value="web"   <?php echo $canal==='web'?'selected':''; ?>>🌐 Web</option>
            </select>
        </div>
        <input type="hidden" name="orden" value="<?php echo h($orden); ?>">
        <input type="hidden" name="dir"   value="<?php echo h($dir); ?>">
        <div class="col-md-auto"><button class="btn btn-primary">Consultar</button></div>
        <div class="col-md-auto">
            <div class="d-flex gap-1">
                <a class="btn btn-outline-secondary btn-sm" href="facturas.php?f_inicio=<?php echo $hoy; ?>&f_fin=<?php echo $hoy; ?>">Hoy</a>
                <?php
                $lun = date('Y-m-d', strtotime('monday this week'));
                $dom = date('Y-m-d', strtotime('sunday this week'));
                $m1  = date('Y-m-01'); $m2 = date('Y-m-t');
                ?>
                <a class="btn btn-outline-secondary btn-sm" href="facturas.php?f_inicio=<?php echo $lun; ?>&f_fin=<?php echo $dom; ?>">Semana</a>
                <a class="btn btn-outline-secondary btn-sm" href="facturas.php?f_inicio=<?php echo $m1; ?>&f_fin=<?php echo $m2; ?>">Mes</a>
            </div>
        </div>
    </form>
</div>

<!-- KPIs -->
<?php
$utilidad = (float)($kpis['total_ventas'] ?? 0) - (float)($gastos['total_gastos'] ?? 0);
$utilidad_color = $utilidad >= 0 ? 'success' : 'danger';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 h-100 border-start border-4 border-primary">
            <div class="small text-muted fw-bold">🧾 FACTURAS VENTA</div>
            <div class="fs-2 fw-bold text-primary"><?php echo number_format((int)$kpis['total_facturas']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold">💰 INGRESOS (VENTAS)</div>
            <div class="fs-2 fw-bold text-success">$<?php echo number_format((float)$kpis['total_ventas'], 2); ?></div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 h-100 border-start border-4 border-danger">
            <div class="small text-muted fw-bold">🛒 GASTOS (COMPRAS)</div>
            <div class="fs-2 fw-bold text-danger">$<?php echo number_format((float)($gastos['total_gastos'] ?? 0), 2); ?></div>
            <small class="text-muted"><?php echo (int)($gastos['total_compras']??0); ?> facturas de compra</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 h-100 border-start border-4 border-<?php echo $utilidad_color; ?>">
            <div class="small text-muted fw-bold">📊 UTILIDAD BRUTA</div>
            <div class="fs-2 fw-bold text-<?php echo $utilidad_color; ?>">$<?php echo number_format($utilidad, 2); ?></div>
            <small class="text-<?php echo $utilidad_color; ?>">ventas − compras</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 h-100 border-start border-4 border-info">
            <div class="small text-muted fw-bold">📈 TICKET PROMEDIO</div>
            <div class="fs-2 fw-bold text-info">$<?php echo number_format((float)$kpis['promedio'], 2); ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Top Productos -->
    <div class="col-md-4">
        <div class="card card-custom border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-trophy-fill text-warning"></i>
                <h6 class="fw-bold mb-0">Top Productos</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topProductos)): ?>
                    <p class="text-center text-muted py-4">Sin ventas en el período.</p>
                <?php else:
                    $maxUds = (int)$topProductos[0]['unidades'];
                    foreach ($topProductos as $idx => $tp):
                        $pct = $maxUds > 0 ? round((int)$tp['unidades'] / $maxUds * 100) : 0;
                ?>
                <div class="px-3 py-2 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-dark"><?php echo h($tp['nombre']); ?></span>
                        <span class="badge bg-primary-subtle text-primary border"><?php echo number_format((int)$tp['unidades']); ?> uds</span>
                    </div>
                    <div class="progress" style="height:5px;">
                        <div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%;"></div>
                    </div>
                    <div class="text-end small text-muted mt-1">$<?php echo number_format((float)$tp['ingreso'], 2); ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabla facturas -->
    <div class="col-md-8">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Listado de Facturas</h6>
                <span class="small text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th><a href="<?php echo sortUrl('id_pedido',$orden,$dir,$f_inicio,$f_fin,$canal,$pagina); ?>" class="text-dark text-decoration-none">Folio<?php echo sortIcon('id_pedido',$orden,$dir); ?></a></th>
                            <th>Cliente</th>
                            <th class="text-center">Canal</th>
                            <th><a href="<?php echo sortUrl('fecha',$orden,$dir,$f_inicio,$f_fin,$canal,$pagina); ?>" class="text-dark text-decoration-none">Fecha / Hora<?php echo sortIcon('fecha',$orden,$dir); ?></a></th>
                            <th class="text-center">Pago</th>
                            <th class="text-end"><a href="<?php echo sortUrl('total',$orden,$dir,$f_inicio,$f_fin,$canal,$pagina); ?>" class="text-dark text-decoration-none">Total<?php echo sortIcon('total',$orden,$dir); ?></a></th>
                            <th class="text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($facturas)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-receipt" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:8px;"></i>
                            Sin facturas para el período seleccionado.
                        </td></tr>
                    <?php else: foreach ($facturas as $f):
                        $esWeb = $f['origen'] === 'web';
                        $estadoLabel = $esWeb ? ucfirst($f['estado_aprobacion']) : ucfirst($f['estado']);
                        $estadoClass = match(strtolower($estadoLabel)) {
                            'completado','aprobado' => 'bg-success-subtle text-success border',
                            'pendiente'             => 'bg-warning-subtle text-warning border',
                            'rechazado','cancelado' => 'bg-danger-subtle text-danger border',
                            default                 => 'bg-light text-dark border',
                        };
                    ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border fw-bold"><?php echo folio((int)$f['id_pedido']); ?></span></td>
                        <td class="fw-bold text-truncate" style="max-width:130px;"><?php echo h($f['cliente']); ?></td>
                        <td class="text-center">
                            <?php echo $esWeb
                                ? '<span class="badge bg-primary-subtle text-primary border">🌐 Web</span>'
                                : '<span class="badge bg-success-subtle text-success border">🏪 POS</span>'; ?>
                        </td>
                        <td class="text-muted small"><?php echo h(date('d/m/Y H:i', strtotime($f['fecha']))); ?></td>
                        <td class="text-center"><span class="badge bg-light text-dark border"><?php echo ucfirst(h($f['tipo_pago'])); ?></span></td>
                        <td class="text-end fw-bold">$<?php echo number_format((float)$f['total'], 2); ?></td>
                        <td class="text-center"><span class="badge <?php echo $estadoClass; ?>"><?php echo h($estadoLabel); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
            <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center py-2">
                <small class="text-muted">
                    Mostrando <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $por_pagina, $totalFilas)); ?> de <?php echo number_format($totalFilas); ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($pagina > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $paginaUrl; ?>&pagina=<?php echo $pagina - 1; ?>">‹</a></li>
                        <?php endif;
                        $rng = range(max(1, $pagina - 2), min($totalPaginas, $pagina + 2));
                        if (!in_array(1, $rng)) { echo '<li class="page-item"><a class="page-link" href="' . $paginaUrl . '&pagina=1">1</a></li><li class="page-item disabled"><span class="page-link">…</span></li>'; }
                        foreach ($rng as $p): ?>
                        <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $paginaUrl; ?>&pagina=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                        <?php endforeach;
                        if (!in_array($totalPaginas, $rng)) { echo '<li class="page-item disabled"><span class="page-link">…</span></li><li class="page-item"><a class="page-link" href="' . $paginaUrl . '&pagina=' . $totalPaginas . '">' . $totalPaginas . '</a></li>'; }
                        if ($pagina < $totalPaginas): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $paginaUrl; ?>&pagina=<?php echo $pagina + 1; ?>">›</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div></div>

<!-- ═══ FACTURAS DE COMPRA DEL PERÍODO ══════════════════════════════════════ -->
<div class="card card-custom border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <div>
            <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-cart-fill me-2"></i>Facturas de Compra del Período</h5>
            <small class="text-muted">Insumos comprados a proveedores · descuentan de la utilidad</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger fs-6 px-3">
                −$<?php echo number_format((float)($gastos['total_gastos']??0), 2); ?>
            </span>
            <button class="btn btn-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalNuevaFactura">
                <i class="bi bi-plus-lg me-1"></i>Nueva
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.9rem;">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Folio</th>
                    <th>Proveedor</th>
                    <th>Producto</th>
                    <th class="text-center">Cantidad</th>
                    <th class="text-end">Costo Unit.</th>
                    <th class="text-end">Subtotal</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($facturasCompra)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">
                    <i class="bi bi-cart" style="font-size:2rem;opacity:.2;display:block;margin-bottom:6px;"></i>
                    No hay facturas de compra en el período seleccionado.
                </td></tr>
            <?php else:
                $totalComprasPeriodo = 0;
                foreach ($facturasCompra as $fc):
                    $totalComprasPeriodo += (float)$fc['subtotal'];
            ?>
            <tr>
                <td class="ps-3">
                    <span class="badge bg-danger-subtle text-danger border fw-bold">
                        <?php echo h($fc['folio'] ?: '—'); ?>
                    </span>
                </td>
                <td class="fw-bold"><?php echo h($fc['proveedor']); ?></td>
                <td><?php echo h($fc['producto']); ?></td>
                <td class="text-center fw-bold"><?php echo (int)$fc['cantidad']; ?></td>
                <td class="text-end text-muted">$<?php echo number_format((float)$fc['costo_unitario'], 2); ?></td>
                <td class="text-end fw-bold text-danger">$<?php echo number_format((float)$fc['subtotal'], 2); ?></td>
                <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($fc['fecha'])); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-danger fw-bold">
                <td colspan="5" class="text-end ps-3">Total gastos del período:</td>
                <td class="text-end text-danger fs-6">$<?php echo number_format($totalComprasPeriodo, 2); ?></td>
                <td></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ MODAL: Registrar Factura de Compra ═══════════════════════════════════ -->
<div class="modal fade" id="modalNuevaFactura" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-file-earmark-plus me-2"></i>Registrar Factura de Compra
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="cargar_factura_compra">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold small text-muted">PROVEEDOR *</label>
                            <select name="id_proveedor" id="selectProveedorFactura" class="form-select" required>
                                <option value="">Seleccione proveedor...</option>
                                <?php foreach ($proveedoresForm as $pv): ?>
                                <option value="<?php echo (int)$pv['id_proveedor']; ?>">
                                    <?php echo h($pv['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small text-muted">PRODUCTO *</label>
                            <select name="id_producto" id="selectProductoFactura" class="form-select" required>
                                <option value="">— Selecciona un proveedor primero —</option>
                            </select>
                            <small class="text-muted" id="msgProductosProveedor"></small>
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold small text-muted">FOLIO DE FACTURA</label>
                            <input type="text" name="folio" class="form-control" placeholder="FAC-001">
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold small text-muted">CANTIDAD *</label>
                            <input type="number" name="cantidad" id="campoCantidadFactura" class="form-control"
                                   min="1" placeholder="1" required oninput="calcularSubtotalFactura()">
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold small text-muted">COSTO UNITARIO ($) *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="costo_unitario" id="campoCostoFactura"
                                       class="form-control fw-bold" min="0" step="0.01" placeholder="0.00"
                                       required oninput="calcularSubtotalFactura()">
                            </div>
                            <small class="text-success" id="msgCostoAuto" style="display:none;">
                                <i class="bi bi-check-circle me-1"></i>Precio del último pedido a este proveedor
                            </small>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-secondary py-2 mb-0 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-calculator me-1"></i> Subtotal de esta factura:</span>
                                <strong class="fs-5 text-danger" id="subtotalFactura">$0.00</strong>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0 py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Los productos mostrados son los que este proveedor ha suministrado anteriormente.
                        Si es nuevo, se muestran todos los productos del catálogo.
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-success fw-bold flex-fill">
                            <i class="bi bi-floppy me-1"></i>Registrar Factura
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Datos precargados desde PHP ──────────────────────────────────────────────
var PRODUCTOS_POR_PROVEEDOR = <?php echo json_encode($productosPorProveedor); ?>;
var TODOS_PRODUCTOS = <?php echo json_encode(array_map(function($p) {
    return [
        'id'     => (int)$p['id_producto'],
        'nombre' => $p['nombre'],
        'lote'   => $p['numero_lote'] ?? '',
        'stock'  => (int)$p['stock'],
        'costo'  => number_format((float)($p['costo_adquisicion'] ?? 0), 2, '.', ''),
    ];
}, $productosForm)); ?>;

// ── Actualizar lista de productos al cambiar proveedor ───────────────────────
document.getElementById('selectProveedorFactura').addEventListener('change', function () {
    var idProv    = parseInt(this.value) || 0;
    var selProd   = document.getElementById('selectProductoFactura');
    var msgProd   = document.getElementById('msgProductosProveedor');
    var lista     = (idProv && PRODUCTOS_POR_PROVEEDOR[idProv])
                        ? PRODUCTOS_POR_PROVEEDOR[idProv]
                        : TODOS_PRODUCTOS;
    var esFiltrado = (idProv && PRODUCTOS_POR_PROVEEDOR[idProv]);

    // Limpiar y repoblar select
    selProd.innerHTML = '<option value="">Seleccione producto...</option>';
    lista.forEach(function (p) {
        var opt  = document.createElement('option');
        opt.value = p.id;
        opt.setAttribute('data-costo', p.costo);
        var lote  = p.lote ? ' · Lote ' + p.lote : '';
        opt.textContent = p.nombre + lote + ' — Stock: ' + p.stock;
        selProd.appendChild(opt);
    });

    msgProd.textContent = esFiltrado
        ? lista.length + ' producto(s) de este proveedor'
        : 'Mostrando catálogo completo (proveedor nuevo)';

    // Reset precio
    document.getElementById('campoCostoFactura').value = '';
    document.getElementById('msgCostoAuto').style.display = 'none';
    calcularSubtotalFactura();
});

// ── Auto-rellenar precio al seleccionar producto ─────────────────────────────
document.getElementById('selectProductoFactura').addEventListener('change', function () {
    var costo = this.options[this.selectedIndex]?.getAttribute('data-costo') ?? '0';
    var campo = document.getElementById('campoCostoFactura');
    var msg   = document.getElementById('msgCostoAuto');
    if (parseFloat(costo) > 0) {
        campo.value = costo;
        msg.style.display = 'inline';
    } else {
        campo.value = '';
        msg.style.display = 'none';
    }
    calcularSubtotalFactura();
});

function calcularSubtotalFactura() {
    var qty   = parseFloat(document.getElementById('campoCantidadFactura').value) || 0;
    var costo = parseFloat(document.getElementById('campoCostoFactura').value)    || 0;
    document.getElementById('subtotalFactura').textContent =
        '$' + (qty * costo).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Auto-abrir modal si hubo error al registrar
<?php if ($errorFactura): ?>
var modalNF = new bootstrap.Modal(document.getElementById('modalNuevaFactura'));
modalNF.show();
<?php endif; ?>
</script>
</body>
</html>