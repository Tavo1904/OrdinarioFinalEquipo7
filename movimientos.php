<?php
  include 'db.php';
  include 'export_helper.php';
  if (session_status() === PHP_SESSION_NONE) session_start();
  // MEJORA 2 & 4: Verificar sesión y permisos dinámicos
  if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }
  refresh_permisos($conexion);
  if (!has_perm('inventario')) { header("Location: dashboard.php?acceso_denegado=1"); exit; }
  
// ─── Parámetros de filtro ───────────────────────────────────────────
$hoy          = date('Y-m-d');
$fecha_inicio = $_GET['fecha_inicio'] ?? $hoy;
$fecha_fin    = $_GET['fecha_fin']    ?? $hoy;
$filtro_tipo  = $_GET['tipo']         ?? 'todos';
$filtro_orig  = $_GET['origen']       ?? 'todos';
$export       = $_GET['export']       ?? '';

// Normalizar
if ($fecha_inicio > $fecha_fin) [$fecha_inicio, $fecha_fin] = [$fecha_fin, $fecha_inicio];

// ─── Condiciones dinámicas ──────────────────────────────────────────
$conds  = ["DATE(m.fecha) BETWEEN ? AND ?"];
$params = [$fecha_inicio, $fecha_fin];

if ($filtro_tipo !== 'todos') {
    $conds[]  = "m.tipo_movimiento = ?";
    $params[] = $filtro_tipo;
}
if ($filtro_orig !== 'todos') {
    $conds[]  = "m.origen = ?";
    $params[] = $filtro_orig;
}

$where = implode(' AND ', $conds);

// ─── Movimientos del rango ─────────────────────────────────────────
$stmtMovs = $conexion->prepare("
    SELECT m.id_movimiento, m.fecha, m.tipo_movimiento, m.origen,
           m.cantidad, m.observaciones,
           p.nombre AS producto, u.nombre AS usuario
    FROM movimientos_inventario m
    INNER JOIN productos p ON m.id_producto = p.id_producto
    INNER JOIN usuarios  u ON m.id_usuario  = u.id_usuario
    WHERE $where
    ORDER BY m.fecha DESC
");
$stmtMovs->execute($params);
$movimientos = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

// ─── KPIs del rango ────────────────────────────────────────────────
$kpiStmt = $conexion->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN tipo_movimiento='entrada'               THEN cantidad ELSE 0 END),0) AS total_entradas,
        COALESCE(SUM(CASE WHEN tipo_movimiento='salida' AND origen='pos' THEN cantidad ELSE 0 END),0) AS salidas_pos,
        COALESCE(SUM(CASE WHEN tipo_movimiento='salida' AND origen='web' THEN cantidad ELSE 0 END),0) AS salidas_web,
        COALESCE(SUM(CASE WHEN tipo_movimiento='ajuste'                THEN cantidad ELSE 0 END),0) AS ajustes,
        COUNT(*) AS total_registros
    FROM movimientos_inventario
    WHERE DATE(fecha) BETWEEN ? AND ?
");
$kpiStmt->execute([$fecha_inicio, $fecha_fin]);
$kpis = $kpiStmt->fetch(PDO::FETCH_ASSOC);

// ─── Corte de caja del rango ───────────────────────────────────────
$stmtCorte = $conexion->prepare("
    SELECT
        COALESCE(SUM(total),0)                                                AS total_general,
        COALESCE(SUM(CASE WHEN tipo_pago='efectivo' THEN total ELSE 0 END),0) AS total_efectivo,
        COALESCE(SUM(CASE WHEN tipo_pago='tarjeta'  THEN total ELSE 0 END),0) AS total_tarjeta,
        COALESCE(SUM(CASE WHEN tipo_pago='credito'  THEN total ELSE 0 END),0) AS total_credito,
        COUNT(*) AS total_ventas
    FROM pedidos
    WHERE DATE(fecha) BETWEEN ? AND ? AND estado='completado'
");
$stmtCorte->execute([$fecha_inicio, $fecha_fin]);
$corte = $stmtCorte->fetch(PDO::FETCH_ASSOC);

// ─── Gastos de compra del rango ────────────────────────────────────────────
$stmtGC = $conexion->prepare("
    SELECT COUNT(*)                                           AS total_compras,
           COALESCE(SUM(f.costo_unitario * f.cantidad), 0)  AS total_gastos
    FROM facturas_compra f
    WHERE DATE(f.fecha) BETWEEN ? AND ?
");
$stmtGC->execute([$fecha_inicio, $fecha_fin]);
$gastos_compra = $stmtGC->fetch(PDO::FETCH_ASSOC);

// ─── Listado de facturas de compra del rango ──────────────────────────────
$stmtFC = $conexion->prepare("
    SELECT f.id_factura, f.folio, f.fecha, f.cantidad, f.costo_unitario,
           (f.costo_unitario * f.cantidad) AS subtotal,
           pv.nombre AS proveedor, pr.nombre AS producto
    FROM facturas_compra f
    INNER JOIN proveedores pv ON f.id_proveedor = pv.id_proveedor
    INNER JOIN productos   pr ON f.id_producto  = pr.id_producto
    WHERE DATE(f.fecha) BETWEEN ? AND ?
    ORDER BY f.fecha DESC
");
$stmtFC->execute([$fecha_inicio, $fecha_fin]);
$lista_compras = $stmtFC->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────────
// HELPERS de presentación
// ─────────────────────────────────────────────────────────────────
function origen_badge(string $origen): string {
    return match($origen) {
        'pos'     => '<span class="badge bg-primary-subtle text-primary border px-2"><i class="bi bi-shop me-1"></i>POS</span>',
        'web'     => '<span class="badge bg-purple-subtle text-purple border px-2"><i class="bi bi-globe2 me-1"></i>Web</span>',
        'entrada' => '<span class="badge bg-success-subtle text-success border px-2"><i class="bi bi-box-arrow-in-down me-1"></i>Entrada</span>',
        'ajuste'  => '<span class="badge bg-warning-subtle text-warning border px-2"><i class="bi bi-sliders me-1"></i>Ajuste</span>',
        default   => '<span class="badge bg-secondary-subtle text-secondary border px-2">' . htmlspecialchars($origen, ENT_QUOTES, 'UTF-8') . '</span>',
    };
}

function tipo_badge(string $tipo): string {
    return match($tipo) {
        'entrada' => '<span class="badge bg-success px-2"><i class="bi bi-arrow-down-circle-fill me-1"></i>Entrada</span>',
        'salida'  => '<span class="badge bg-danger px-2"><i class="bi bi-arrow-up-circle-fill me-1"></i>Salida</span>',
        'ajuste'  => '<span class="badge bg-warning text-dark px-2"><i class="bi bi-sliders me-1"></i>Ajuste</span>',
        default   => '<span class="badge bg-secondary px-2">' . ucfirst(htmlspecialchars($tipo, ENT_QUOTES)) . '</span>',
    };
}

function origen_label(string $origen): string {
    return match($origen) {
        'pos'     => 'Punto de Venta (POS)',
        'web'     => 'Pedido Web',
        'entrada' => 'Abastecimiento',
        'ajuste'  => 'Ajuste de Inventario',
        default   => ucfirst($origen),
    };
}

// ─────────────────────────────────────────────────────────────────
// EXPORT EXCEL
// ─────────────────────────────────────────────────────────────────
if ($export === 'excel') {
    $pyCode = <<<'PYTHON'
import sys, json, io
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

data = json.loads(sys.stdin.read())
kpis  = data['kpis']
corte = data['corte']
movs  = data['movimientos']
fi    = data['fecha_inicio']
ff    = data['fecha_fin']
titulo_rango = fi if fi == ff else fi + ' al ' + ff

wb = Workbook()
ws = wb.active
ws.title = "Movimientos"

thin   = Side(style='thin', color="D1D5DB")
border = Border(left=thin, right=thin, top=thin, bottom=thin)

def fill(c):  return PatternFill("solid", fgColor=c)
def font(bold=False, size=11, color="111827", white=False):
    return Font(bold=bold, size=size, color="FFFFFF" if white else color)

# Título
ws.merge_cells("A1:H1")
ws["A1"] = "FARMACIA PEÑALOZA — Historial de Movimientos de Inventario"
ws["A1"].font = font(bold=True, size=14, white=True)
ws["A1"].fill = fill("1E3A5F")
ws["A1"].alignment = Alignment(horizontal="center", vertical="center")
ws.row_dimensions[1].height = 30

ws.merge_cells("A2:H2")
ws["A2"] = "Período: " + titulo_rango
ws["A2"].font = font(size=10, color="6B7280")
ws["A2"].alignment = Alignment(horizontal="center")
ws.row_dimensions[2].height = 16

# KPIs — fila 4 y 5
gastos_val  = float(data.get('gastos_compra', {}).get('total_gastos', 0))
utilidad    = float(corte['total_general']) - gastos_val
util_color  = "065F46" if utilidad >= 0 else "DC2626"
kpi_items = [
    ("ENTRADAS",       str(int(kpis['total_entradas'])), "065F46"),
    ("SALIDAS POS",    str(int(kpis['salidas_pos'])),   "1D4ED8"),
    ("SALIDAS WEB",    str(int(kpis['salidas_web'])),   "7C3AED"),
    ("INGRESOS",       "${:,.2f}".format(float(corte['total_general'])), "374151"),
    ("GASTOS COMPRA",  "${:,.2f}".format(gastos_val),  "DC2626"),
    ("UTILIDAD BRUTA", "${:,.2f}".format(utilidad),    util_color),
]
for i, (lbl, val, clr) in enumerate(kpi_items):
    c = get_column_letter(i + 1)
    ws[f"{c}4"] = lbl
    ws[f"{c}4"].font = font(bold=True, size=8, color=clr)
    ws[f"{c}4"].fill = fill("F3F4F6")
    ws[f"{c}4"].alignment = Alignment(horizontal="center")
    ws[f"{c}5"] = val
    ws[f"{c}5"].font = font(bold=True, size=13, color=clr)
    ws[f"{c}5"].alignment = Alignment(horizontal="center")
    ws.row_dimensions[5].height = 24

# Cabecera tabla
hdrs   = ["Fecha/Hora", "Tipo", "Origen", "Producto", "Cantidad", "Usuario", "Observación"]
widths = [18, 12, 22, 38, 10, 22, 55]
for i, (h, w) in enumerate(zip(hdrs, widths)):
    c = get_column_letter(i + 1)
    ws[f"{c}7"] = h
    ws[f"{c}7"].font  = font(bold=True, size=9, white=True)
    ws[f"{c}7"].fill  = fill("111827")
    ws[f"{c}7"].alignment = Alignment(horizontal="center", vertical="center")
    ws[f"{c}7"].border = border
    ws.column_dimensions[c].width = w
ws.row_dimensions[7].height = 20

ORIG_LABELS = {
    'pos':     'Punto de Venta (POS)',
    'web':     'Pedido Web',
    'entrada': 'Abastecimiento',
    'ajuste':  'Ajuste de Inventario',
}
TIPO_COLORS = {'entrada': 'ECFDF5', 'salida': 'FEF2F2', 'ajuste': 'FFFBEB'}
ORIG_COLORS = {'pos': 'EFF6FF', 'web': 'F5F3FF', 'entrada': 'F0FDF4', 'ajuste': 'FFFBEB'}

for r, m in enumerate(movs):
    row = 8 + r
    tipo = m['tipo_movimiento']
    orig = m.get('origen', '')
    es_entrada = tipo == 'entrada'
    signo = '+' if es_entrada else ('-' if tipo == 'salida' else '±')
    vals = [
        m['fecha'],
        tipo.upper(),
        ORIG_LABELS.get(orig, orig.upper()),
        m['producto'],
        signo + str(m['cantidad']),
        m['usuario'],
        m['observaciones'] or ''
    ]
    bg_tipo = TIPO_COLORS.get(tipo, 'FFFFFF')
    bg_orig = ORIG_COLORS.get(orig, 'FFFFFF')
    bg_alt  = 'F9FAFB' if r % 2 == 0 else 'FFFFFF'
    aligns  = ["center","center","center","left","center","center","left"]
    for i, (v, a) in enumerate(zip(vals, aligns)):
        c = get_column_letter(i + 1)
        ws[f"{c}{row}"] = v
        ws[f"{c}{row}"].border = border
        ws[f"{c}{row}"].alignment = Alignment(horizontal=a, wrap_text=(i == 6))
        if i == 1:
            ws[f"{c}{row}"].fill = fill(bg_tipo)
            ws[f"{c}{row}"].font = Font(bold=True)
        elif i == 2:
            ws[f"{c}{row}"].fill = fill(bg_orig)
            ws[f"{c}{row}"].font = Font(bold=True)
        else:
            ws[f"{c}{row}"].fill = fill(bg_alt)
    ws.row_dimensions[row].height = 16

buf = io.BytesIO()
wb.save(buf)
sys.stdout.buffer.write(buf.getvalue())
PYTHON;

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="movimientos_' . $fecha_inicio . '_' . $fecha_fin . '.xlsx"');
    echo run_python_export($pyCode, [
        'kpis'          => $kpis,
        'corte'         => $corte,
        'movimientos'   => $movimientos,
        'lista_compras' => $lista_compras,
        'gastos_compra' => $gastos_compra,
        'fecha_inicio'  => $fecha_inicio,
        'fecha_fin'     => $fecha_fin,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// EXPORT PDF — página imprimible
// ─────────────────────────────────────────────────────────────────
if ($export === 'pdf') {
    $_SESSION['corte_print'] = [
        'corte'        => $corte,
        'movimientos'  => $movimientos,
        'fecha'        => ($fecha_inicio === $fecha_fin) ? $fecha_inicio : "$fecha_inicio al $fecha_fin",
    ];
    header('Location: corte_print.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────
// VISTA HTML
// ─────────────────────────────────────────────────────────────────
include 'header.php';
$titulo_rango = ($fecha_inicio === $fecha_fin) ? $fecha_inicio : "$fecha_inicio al $fecha_fin";
$rango_label  = ($fecha_inicio === $fecha_fin && $fecha_inicio === $hoy) ? 'Hoy' : $titulo_rango;
?>
<style>
.bg-purple-subtle { background-color: #f5f3ff !important; }
.text-purple       { color: #7c3aed !important; }
.badge-origen      { font-size: .75rem; font-weight: 600; letter-spacing: .02em; }
@media print {
    .sidebar, .no-print { display:none !important; }
    .main-content { margin-left:0 !important; width:100% !important; padding:0 !important; }
}
</style>

<!-- Encabezado -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h3 class="fw-bold text-dark mb-0">Movimientos de Inventario</h3>
        <small class="text-muted">Historial unificado · <?php echo h($rango_label); ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="movimientos.php?fecha_inicio=<?php echo h($fecha_inicio); ?>&fecha_fin=<?php echo h($fecha_fin); ?>&tipo=<?php echo h($filtro_tipo); ?>&origen=<?php echo h($filtro_orig); ?>&export=pdf"
           class="btn btn-dark btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
        <a href="movimientos.php?fecha_inicio=<?php echo h($fecha_inicio); ?>&fecha_fin=<?php echo h($fecha_fin); ?>&tipo=<?php echo h($filtro_tipo); ?>&origen=<?php echo h($filtro_orig); ?>&export=excel"
           class="btn btn-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
        <button class="btn btn-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
    </div>
</div>

<!-- Filtros -->
<div class="card card-custom border-0 shadow-sm p-3 mb-4 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-auto">
            <label class="small fw-bold text-muted d-block">Fecha inicio</label>
            <input type="date" name="fecha_inicio" class="form-control form-control-sm"
                   value="<?php echo h($fecha_inicio); ?>">
        </div>
        <div class="col-sm-auto">
            <label class="small fw-bold text-muted d-block">Fecha fin</label>
            <input type="date" name="fecha_fin" class="form-control form-control-sm"
                   value="<?php echo h($fecha_fin); ?>">
        </div>
        <div class="col-sm-auto">
            <label class="small fw-bold text-muted d-block">Tipo</label>
            <select name="tipo" class="form-select form-select-sm">
                <option value="todos"   <?php echo $filtro_tipo==='todos'   ?'selected':'';?>>Todos los tipos</option>
                <option value="entrada" <?php echo $filtro_tipo==='entrada' ?'selected':'';?>>Entradas</option>
                <option value="salida"  <?php echo $filtro_tipo==='salida'  ?'selected':'';?>>Salidas</option>
                <option value="ajuste"  <?php echo $filtro_tipo==='ajuste'  ?'selected':'';?>>Ajustes</option>
            </select>
        </div>
        <div class="col-sm-auto">
            <label class="small fw-bold text-muted d-block">Origen</label>
            <select name="origen" class="form-select form-select-sm">
                <option value="todos"   <?php echo $filtro_orig==='todos'   ?'selected':'';?>>Todos los orígenes</option>
                <option value="pos"     <?php echo $filtro_orig==='pos'     ?'selected':'';?>>Punto de Venta (POS)</option>
                <option value="web"     <?php echo $filtro_orig==='web'     ?'selected':'';?>>Pedidos Web</option>
                <option value="entrada" <?php echo $filtro_orig==='entrada' ?'selected':'';?>>Abastecimiento</option>
                <option value="ajuste"  <?php echo $filtro_orig==='ajuste'  ?'selected':'';?>>Ajuste de Inventario</option>
            </select>
        </div>
        <div class="col-sm-auto d-flex gap-1">
            <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            <a href="movimientos.php" class="btn btn-outline-secondary btn-sm">Hoy</a>
        </div>
        <!-- Atajos rápidos -->
        <div class="col-12 d-flex gap-1 flex-wrap mt-1">
            <?php
            $ayer     = date('Y-m-d', strtotime('-1 day'));
            $ini_sem  = date('Y-m-d', strtotime('monday this week'));
            $ini_mes  = date('Y-m-01');
            $fin_mes  = date('Y-m-t');
            ?>
            <a href="movimientos.php?fecha_inicio=<?php echo $ayer; ?>&fecha_fin=<?php echo $ayer; ?>&tipo=<?php echo h($filtro_tipo); ?>&origen=<?php echo h($filtro_orig); ?>"
               class="btn btn-outline-secondary btn-xs px-2 py-1" style="font-size:.75rem;">Ayer</a>
            <a href="movimientos.php?fecha_inicio=<?php echo $ini_sem; ?>&fecha_fin=<?php echo $hoy; ?>&tipo=<?php echo h($filtro_tipo); ?>&origen=<?php echo h($filtro_orig); ?>"
               class="btn btn-outline-secondary btn-xs px-2 py-1" style="font-size:.75rem;">Esta semana</a>
            <a href="movimientos.php?fecha_inicio=<?php echo $ini_mes; ?>&fecha_fin=<?php echo $fin_mes; ?>&tipo=<?php echo h($filtro_tipo); ?>&origen=<?php echo h($filtro_orig); ?>"
               class="btn btn-outline-secondary btn-xs px-2 py-1" style="font-size:.75rem;">Este mes</a>
        </div>
    </form>
</div>

<!-- KPIs de movimientos -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-custom border-0 shadow-sm p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold text-uppercase"><i class="bi bi-box-arrow-in-down me-1 text-success"></i>Entradas</div>
            <div class="fs-2 fw-bold text-success"><?php echo number_format((int)$kpis['total_entradas']); ?></div>
            <div class="small text-muted">unidades ingresadas</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom border-0 shadow-sm p-3 h-100 border-start border-4 border-primary">
            <div class="small text-muted fw-bold text-uppercase"><i class="bi bi-shop me-1 text-primary"></i>Salidas POS</div>
            <div class="fs-2 fw-bold text-primary"><?php echo number_format((int)$kpis['salidas_pos']); ?></div>
            <div class="small text-muted">unidades vendidas en tienda</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom border-0 shadow-sm p-3 h-100 border-start border-4" style="border-color:#7c3aed!important;">
            <div class="small text-muted fw-bold text-uppercase"><i class="bi bi-globe2 me-1" style="color:#7c3aed;"></i>Salidas Web</div>
            <div class="fs-2 fw-bold" style="color:#7c3aed;"><?php echo number_format((int)$kpis['salidas_web']); ?></div>
            <div class="small text-muted">unidades por pedido web</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom border-0 shadow-sm p-3 h-100 border-start border-4 border-warning">
            <div class="small text-muted fw-bold text-uppercase"><i class="bi bi-sliders me-1 text-warning"></i>Ajustes</div>
            <div class="fs-2 fw-bold text-warning"><?php echo number_format((int)$kpis['ajustes']); ?></div>
            <div class="small text-muted">unidades ajustadas</div>
        </div>
    </div>
</div>

<!-- Resumen de ventas (corte de caja) -->
<?php $utilidad_mov = (float)$corte['total_general'] - (float)($gastos_compra['total_gastos'] ?? 0); ?>
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card card-custom bg-dark text-white border-0 p-3 h-100">
            <div class="small opacity-75 fw-bold">INGRESOS</div>
            <div class="fs-4 fw-bold">$<?php echo number_format((float)$corte['total_general'],2); ?></div>
            <div class="small opacity-60"><?php echo (int)$corte['total_ventas']; ?> tickets</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-danger">
            <div class="small text-muted fw-bold">GASTOS (COMPRAS)</div>
            <div class="fs-4 fw-bold text-danger">$<?php echo number_format((float)($gastos_compra['total_gastos']??0),2); ?></div>
            <small class="text-muted"><?php echo (int)($gastos_compra['total_compras']??0); ?> facturas</small>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-<?php echo $utilidad_mov>=0?'success':'danger'; ?>">
            <div class="small text-muted fw-bold">UTILIDAD BRUTA</div>
            <div class="fs-4 fw-bold text-<?php echo $utilidad_mov>=0?'success':'danger'; ?>">$<?php echo number_format($utilidad_mov,2); ?></div>
            <small class="text-muted">ventas − compras</small>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold">EFECTIVO</div>
            <div class="fs-4 fw-bold text-success">$<?php echo number_format((float)$corte['total_efectivo'],2); ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-info">
            <div class="small text-muted fw-bold">TARJETA</div>
            <div class="fs-4 fw-bold text-info">$<?php echo number_format((float)$corte['total_tarjeta'],2); ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-warning">
            <div class="small text-muted fw-bold">CRÉDITO</div>
            <div class="fs-4 fw-bold text-warning">$<?php echo number_format((float)$corte['total_credito'],2); ?></div>
        </div>
    </div>
</div>

<!-- Tabla de movimientos -->
<div class="card card-custom border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0">Historial de Movimientos</h5>
            <small class="text-muted">
                <?php if ($filtro_tipo !== 'todos' || $filtro_orig !== 'todos'): ?>
                    Filtrado por:
                    <?php if ($filtro_tipo !== 'todos'): ?><span class="badge bg-secondary"><?php echo h(ucfirst($filtro_tipo)); ?></span><?php endif; ?>
                    <?php if ($filtro_orig !== 'todos'): ?><span class="badge bg-secondary"><?php echo h(origen_label($filtro_orig)); ?></span><?php endif; ?>
                <?php else: ?>
                    Todos los tipos y orígenes
                <?php endif; ?>
            </small>
        </div>
        <span class="badge bg-secondary fs-6"><?php echo count($movimientos); ?> registros</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th style="width:90px;">Hora</th>
                    <th style="width:100px;">Tipo</th>
                    <th style="width:160px;">Origen</th>
                    <th>Producto</th>
                    <th class="text-center" style="width:80px;">Cantidad</th>
                    <th style="width:150px;">Usuario</th>
                    <th>Observación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movimientos)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:8px;"></i>
                        Sin movimientos para los filtros seleccionados.
                    </td></tr>
                <?php else: foreach ($movimientos as $m):
                    $tipo    = $m['tipo_movimiento'];
                    $orig    = $m['origen'] ?? 'desconocido';
                    $signo   = $tipo === 'entrada' ? '+' : ($tipo === 'salida' ? '-' : '±');
                    $colCant = $tipo === 'entrada' ? 'text-success' : ($tipo === 'salida' ? 'text-danger' : 'text-warning');
                    $rowBg   = '';
                    if ($orig === 'web') $rowBg = 'style="background:rgba(124,58,237,.03);"';
                ?>
                <tr <?php echo $rowBg; ?>>
                    <td class="fw-bold text-muted"><?php echo date('H:i', strtotime($m['fecha'])); ?></td>
                    <td><?php echo tipo_badge($tipo); ?></td>
                    <td><?php echo origen_badge($orig); ?></td>
                    <td class="fw-bold"><?php echo h($m['producto']); ?></td>
                    <td class="text-center fw-bold fs-6 <?php echo $colCant; ?>">
                        <?php echo $signo . (int)$m['cantidad']; ?>
                    </td>
                    <td class="text-muted text-uppercase" style="font-size:.7rem;"><?php echo h($m['usuario']); ?></td>
                    <td class="text-muted fst-italic"><?php echo h($m['observaciones']); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>

<!-- ═══ FACTURAS DE COMPRA DEL PERÍODO ══════════════════════════════════════ -->
<div class="card card-custom border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <div>
            <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-cart-fill me-2"></i>Compras a Proveedores — Facturas del Período</h5>
            <small class="text-muted">Gastos de reabastecimiento registrados en el rango seleccionado</small>
        </div>
        <span class="badge bg-danger fs-6 px-3">
            −$<?php echo number_format((float)($gastos_compra['total_gastos']??0), 2); ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th style="width:90px;">Fecha</th>
                    <th style="width:120px;">Folio</th>
                    <th>Proveedor</th>
                    <th>Producto</th>
                    <th class="text-center" style="width:80px;">Cant.</th>
                    <th class="text-end" style="width:110px;">Costo Unit.</th>
                    <th class="text-end" style="width:110px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($lista_compras)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">
                    <i class="bi bi-cart" style="font-size:2rem;opacity:.2;display:block;margin-bottom:6px;"></i>
                    Sin facturas de compra en el período seleccionado.
                </td></tr>
            <?php else:
                $totCompras = 0;
                foreach ($lista_compras as $lc):
                    $totCompras += (float)$lc['subtotal'];
            ?>
            <tr>
                <td class="fw-bold text-muted"><?php echo date('d/m H:i', strtotime($lc['fecha'])); ?></td>
                <td><span class="badge bg-danger-subtle text-danger border"><?php echo h($lc['folio'] ?: '—'); ?></span></td>
                <td class="fw-bold"><?php echo h($lc['proveedor']); ?></td>
                <td><?php echo h($lc['producto']); ?></td>
                <td class="text-center fw-bold text-success">+<?php echo (int)$lc['cantidad']; ?></td>
                <td class="text-end text-muted">$<?php echo number_format((float)$lc['costo_unitario'], 2); ?></td>
                <td class="text-end fw-bold text-danger">$<?php echo number_format((float)$lc['subtotal'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-danger fw-bold">
                <td colspan="6" class="text-end">Total gastos del período:</td>
                <td class="text-end fs-6 text-danger">$<?php echo number_format($totCompras, 2); ?></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
