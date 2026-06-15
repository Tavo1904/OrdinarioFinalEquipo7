<?php
  include 'db.php';
  if (session_status() === PHP_SESSION_NONE) session_start();
  // MEJORA 2 & 4: Verificar sesión y permisos dinámicos
  if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }
  refresh_permisos($conexion);
  if (!has_perm('reportes')) { header("Location: dashboard.php?acceso_denegado=1"); exit; }
  
$fecha  = $_GET['fecha']  ?? date('Y-m-d');
$export = $_GET['export'] ?? '';

// ── Resumen ventas POS del día ──────────────────────────────────────────
$stmtCorte = $conexion->prepare("
    SELECT COALESCE(SUM(total),0)                                               AS total_general,
           COALESCE(SUM(CASE WHEN tipo_pago='efectivo' THEN total ELSE 0 END),0) AS total_efectivo,
           COALESCE(SUM(CASE WHEN tipo_pago='tarjeta'  THEN total ELSE 0 END),0) AS total_tarjeta,
           COALESCE(SUM(CASE WHEN tipo_pago='credito'  THEN total ELSE 0 END),0) AS total_credito,
           COUNT(*) AS total_ventas
    FROM pedidos WHERE DATE(fecha)=? AND estado='completado' AND (origen='pos' OR origen IS NULL OR origen='')
");
$stmtCorte->execute([$fecha]);
$corte = $stmtCorte->fetch(PDO::FETCH_ASSOC);

// ── Resumen pedidos web aprobados del día ─────────────────────────────── (FIX: aprobado, no aceptado)
$stmtWeb = $conexion->prepare("
    SELECT COALESCE(SUM(total),0) AS total_web,
           COALESCE(SUM(CASE WHEN tipo_pago='efectivo'      THEN total ELSE 0 END),0) AS web_efectivo,
           COALESCE(SUM(CASE WHEN tipo_pago='tarjeta'       THEN total ELSE 0 END),0) AS web_tarjeta,
           COALESCE(SUM(CASE WHEN tipo_pago='transferencia' THEN total ELSE 0 END),0) AS web_transferencia,
           COALESCE(SUM(CASE WHEN tipo_pago='credito'       THEN total ELSE 0 END),0) AS web_credito,
           COUNT(*) AS total_pedidos_web
    FROM pedidos WHERE DATE(fecha)=? AND estado_aprobacion='aprobado' AND origen='web'
");
$stmtWeb->execute([$fecha]);
$webResumen = $stmtWeb->fetch(PDO::FETCH_ASSOC);

// ── Detalle por producto (POS + web aprobados) ────────────────────────── (FIX: aprobado)
$stmtDet = $conexion->prepare("
    SELECT pr.nombre, dp.modalidad,
           SUM(dp.cantidad)                        AS cantidad,
           SUM(dp.subtotal)                        AS ingreso,
           SUM(dp.cantidad * pr.costo_adquisicion) AS costo,
           COALESCE(p.origen,'pos')                AS origen
    FROM detalle_pedido dp
    INNER JOIN pedidos   p  ON dp.id_pedido   = p.id_pedido
    INNER JOIN productos pr ON dp.id_producto = pr.id_producto
    WHERE DATE(p.fecha)=? AND (
        (p.estado='completado' AND (p.origen='pos' OR p.origen IS NULL OR p.origen=''))
        OR (p.estado_aprobacion='aprobado' AND p.origen='web')
    )
    GROUP BY pr.id_producto, dp.modalidad, p.origen
    ORDER BY ingreso DESC
");
$stmtDet->execute([$fecha]);
$lineas = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

$costoTotal = 0; $ingresoTotal = 0;
foreach ($lineas as $l) { $costoTotal += (float)$l['costo']; $ingresoTotal += (float)$l['ingreso']; }
$utilidad = $ingresoTotal - $costoTotal;
$margen   = $ingresoTotal > 0 ? round(($utilidad / $ingresoTotal) * 100, 2) : 0;

$ingresoWeb = (float)($webResumen['total_web'] ?? 0);
$ingresoPOS = (float)($corte['total_general'] ?? 0);
$ingresoTotal_canales = $ingresoPOS + $ingresoWeb;

// ── Resumen movimientos de inventario del día ─────────────────────────────
$stmtMov = $conexion->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN tipo_movimiento='entrada'                      THEN cantidad ELSE 0 END),0) AS total_entradas,
        COALESCE(SUM(CASE WHEN tipo_movimiento='salida'  AND origen='pos'     THEN cantidad ELSE 0 END),0) AS salidas_pos,
        COALESCE(SUM(CASE WHEN tipo_movimiento='salida'  AND origen='web'     THEN cantidad ELSE 0 END),0) AS salidas_web,
        COALESCE(SUM(CASE WHEN tipo_movimiento='salida'                       THEN cantidad ELSE 0 END),0) AS total_salidas,
        COALESCE(SUM(CASE WHEN tipo_movimiento='ajuste'                       THEN cantidad ELSE 0 END),0) AS total_ajustes,
        COUNT(*)                                                                                            AS total_movimientos
    FROM movimientos_inventario
    WHERE DATE(fecha)=?
");
$stmtMov->execute([$fecha]);
$movResumen = $stmtMov->fetch(PDO::FETCH_ASSOC);
$netoCambio = (int)$movResumen['total_entradas'] - (int)$movResumen['total_salidas'];

// ── Gastos de compra (facturas_compra) del día ────────────────────────────
$stmtGastos = $conexion->prepare("
    SELECT COALESCE(SUM(costo_unitario * cantidad), 0) AS gastos_compra,
           COUNT(*) AS num_facturas
    FROM facturas_compra
    WHERE DATE(fecha) = ?
");
$stmtGastos->execute([$fecha]);
$gastosRow     = $stmtGastos->fetch(PDO::FETCH_ASSOC);
$gastosCompra  = (float)($gastosRow['gastos_compra']   ?? 0);
$numFacturas   = (int)  ($gastosRow['num_facturas']    ?? 0);
$utilidadNeta  = $ingresoTotal - $costoTotal - $gastosCompra;
$margenNeto    = $ingresoTotal > 0 ? round(($utilidadNeta / $ingresoTotal) * 100, 2) : 0;

// ── Top 10 productos con más unidades movidas del día ────────────────────
$stmtTopMov = $conexion->prepare("
    SELECT pr.nombre,
           m.tipo_movimiento,
           m.origen,
           SUM(m.cantidad) AS total_unidades
    FROM movimientos_inventario m
    INNER JOIN productos pr ON m.id_producto = pr.id_producto
    WHERE DATE(m.fecha)=?
    GROUP BY m.id_producto, m.tipo_movimiento, m.origen
    ORDER BY total_unidades DESC
    LIMIT 10
");
$stmtTopMov->execute([$fecha]);
$topMov = $stmtTopMov->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────
// EXPORT PDF
// ─────────────────────────────────────────
if ($export === 'pdf') {
    $_SESSION['reporte_print'] = [
        'corte'          => $corte,
        'lineas'         => $lineas,
        'fecha'          => $fecha,
        'costo_total'    => $costoTotal,
        'ingreso_total'  => $ingresoTotal,
        'utilidad'       => $utilidad,
        'margen'         => $margen,
        'gastos_compra'  => $gastosCompra,
        'num_facturas'   => $numFacturas,
        'utilidad_neta'  => $utilidadNeta,
        'margen_neto'    => $margenNeto,
        'web_resumen'    => $webResumen,
        'mov_resumen'    => $movResumen,
        'top_mov'        => $topMov,
        'neto_cambio'    => $netoCambio,
    ];
    header('Location: reporte_print.php');
    exit;
}

// ─────────────────────────────────────────
// EXPORT EXCEL
// ─────────────────────────────────────────
if ($export === 'excel') {
    include_once 'export_helper.php';
    $pyCode = <<<'PYTHON'
import sys, json, io
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

data           = json.loads(sys.stdin.read())
corte          = data['corte']
lineas         = data['lineas']
fecha          = data['fecha']
costo_total    = data['costo_total']
utilidad       = data['utilidad']
margen         = data['margen']
ingresoTotal   = data.get('ingreso_total', 0)
gastos_compra  = float(data.get('gastos_compra', 0))
num_facturas   = int(data.get('num_facturas', 0))
utilidad_neta  = float(data.get('utilidad_neta', utilidad - gastos_compra))
margen_neto    = float(data.get('margen_neto', 0))
web            = data.get('web_resumen', {})
mov            = data.get('mov_resumen', {})
top_mov        = data.get('top_mov', [])
neto           = data.get('neto_cambio', 0)

wb = Workbook()

thin   = Side(style='thin', color="D1D5DB")
border = Border(left=thin, right=thin, top=thin, bottom=thin)

def fill(c):  return PatternFill("solid", fgColor=c)
def font(bold=False, size=11, color="111827", white=False):
    return Font(bold=bold, size=size, color="FFFFFF" if white else color)

# ─────────────── Hoja 1: Reporte Financiero ────────────────────────────
ws = wb.active
ws.title = "Reporte Financiero"

ws.merge_cells("A1:H1")
ws["A1"] = "POPULARES PEÑALOZA — Reporte Financiero Diario"
ws["A1"].font = font(bold=True, size=14, white=True)
ws["A1"].fill = fill("1A56DB")
ws["A1"].alignment = Alignment(horizontal="center", vertical="center")
ws.row_dimensions[1].height = 30

ws.merge_cells("A2:H2")
ws["A2"] = "Fecha: " + fecha
ws["A2"].font = font(size=10, color="6B7280")
ws["A2"].alignment = Alignment(horizontal="center")

total_web = float(web.get('total_web', 0))
kpis = [
    ("INGRESO TOTAL",   f"${ingresoTotal:,.2f}",   "1A56DB"),
    ("COSTO TOTAL",     f"${costo_total:,.2f}",    "6B7280"),
    ("GASTOS COMPRAS",  f"${gastos_compra:,.2f}",  "E02424"),
    ("UTILIDAD NETA",   f"${utilidad_neta:,.2f}",  "0E9F6E"),
    ("MARGEN NETO",     f"{margen_neto:.1f}%",     "7C3AED"),
    ("POS",             f"${float(corte['total_general']):,.2f}", "065F46"),
    ("WEB",             f"${total_web:,.2f}",       "1D4ED8"),
    ("CRÉDITO",         f"${float(corte['total_credito']):,.2f}", "92400E"),
]
for i, (lbl, val, clr) in enumerate(kpis):
    c = get_column_letter(i + 1)
    ws[f"{c}4"] = lbl
    ws[f"{c}4"].font = font(bold=True, size=8, color=clr)
    ws[f"{c}4"].fill = fill("F3F4F6")
    ws[f"{c}4"].alignment = Alignment(horizontal="center")
    ws[f"{c}5"] = val
    ws[f"{c}5"].font = font(bold=True, size=12, color=clr)
    ws[f"{c}5"].alignment = Alignment(horizontal="center")

hdrs   = ["Producto","Modalidad","Canal","Cantidad","Ingreso","Costo","Utilidad","Margen %"]
widths = [34, 14, 8, 11, 16, 16, 16, 12]
for i, (h, w) in enumerate(zip(hdrs, widths)):
    c = get_column_letter(i + 1)
    ws[f"{c}7"] = h
    ws[f"{c}7"].font  = font(bold=True, size=9, white=True)
    ws[f"{c}7"].fill  = fill("111827")
    ws[f"{c}7"].alignment = Alignment(horizontal="center", vertical="center")
    ws[f"{c}7"].border = border
    ws.column_dimensions[c].width = w

for r, l in enumerate(lineas):
    row  = 8 + r
    ing  = float(l['ingreso'])
    cos  = float(l['costo'])
    util = ing - cos
    marg = (util / ing * 100) if ing > 0 else 0
    bg   = "ECFDF5" if r % 2 == 0 else "F9FAFB"
    vals = [l['nombre'], l['modalidad'], l.get('origen','pos').upper(), int(l['cantidad']),
            f"${ing:,.2f}", f"${cos:,.2f}", f"${util:,.2f}", f"{marg:.1f}%"]
    for i, (v, w, a) in enumerate(zip(vals, widths, ['L','C','C','C','R','R','R','C'])):
        c = get_column_letter(i + 1)
        ws[f"{c}{row}"] = v
        ws[f"{c}{row}"].fill  = fill(bg)
        ws[f"{c}{row}"].border = border
        ws[f"{c}{row}"].alignment = Alignment(horizontal=a)

# ─────────────── Hoja 2: Movimientos de Inventario ─────────────────────
ws2 = wb.create_sheet("Movimientos Inventario")

ws2.merge_cells("A1:F1")
ws2["A1"] = "POPULARES PEÑALOZA — Movimientos de Inventario"
ws2["A1"].font = font(bold=True, size=14, white=True)
ws2["A1"].fill = fill("0E9F6E")
ws2["A1"].alignment = Alignment(horizontal="center", vertical="center")
ws2.row_dimensions[1].height = 30

ws2.merge_cells("A2:F2")
ws2["A2"] = "Fecha: " + fecha
ws2["A2"].font = font(size=10, color="6B7280")
ws2["A2"].alignment = Alignment(horizontal="center")

# KPIs movimientos
mov_kpis = [
    ("ENTRADAS",      str(int(mov.get('total_entradas', 0))) + " uds", "0E9F6E"),
    ("SALIDAS POS",   str(int(mov.get('salidas_pos', 0)))    + " uds", "1D4ED8"),
    ("SALIDAS WEB",   str(int(mov.get('salidas_web', 0)))    + " uds", "7C3AED"),
    ("AJUSTES",       str(int(mov.get('total_ajustes', 0)))  + " uds", "D97706"),
    ("TOTAL SALIDAS", str(int(mov.get('total_salidas', 0)))  + " uds", "E02424"),
    ("NETO",          ("+" if int(neto) >= 0 else "") + str(int(neto)) + " uds", "059669" if int(neto) >= 0 else "DC2626"),
]
for i, (lbl, val, clr) in enumerate(mov_kpis):
    c = get_column_letter(i + 1)
    ws2[f"{c}4"] = lbl
    ws2[f"{c}4"].font = font(bold=True, size=8, color=clr)
    ws2[f"{c}4"].fill = fill("F3F4F6")
    ws2[f"{c}4"].alignment = Alignment(horizontal="center")
    ws2.column_dimensions[c].width = 18
    ws2[f"{c}5"] = val
    ws2[f"{c}5"].font = font(bold=True, size=12, color=clr)
    ws2[f"{c}5"].alignment = Alignment(horizontal="center")

# Tabla top movimientos
hdrs2   = ["Producto", "Tipo", "Canal / Origen", "Unidades"]
widths2 = [36, 14, 18, 14]
for i, (h, w) in enumerate(zip(hdrs2, widths2)):
    c = get_column_letter(i + 1)
    ws2[f"{c}7"] = h
    ws2[f"{c}7"].font  = font(bold=True, size=9, white=True)
    ws2[f"{c}7"].fill  = fill("111827")
    ws2[f"{c}7"].alignment = Alignment(horizontal="center")
    ws2[f"{c}7"].border = border
    ws2.column_dimensions[c].width = w

for r, m in enumerate(top_mov):
    row = 8 + r
    tipo_label = {"entrada": "Entrada", "salida": "Salida", "ajuste": "Ajuste"}.get(m['tipo_movimiento'], m['tipo_movimiento'].capitalize())
    orig_label = {"pos": "POS", "web": "Web", "entrada": "Abastecimiento", "ajuste": "Ajuste"}.get(m['origen'], m['origen'].capitalize())
    bg = "ECFDF5" if r % 2 == 0 else "F9FAFB"
    vals2 = [m['nombre'], tipo_label, orig_label, int(m['total_unidades'])]
    for i, (v, a) in enumerate(zip(vals2, ['L','C','C','C'])):
        c = get_column_letter(i + 1)
        ws2[f"{c}{row}"] = v
        ws2[f"{c}{row}"].fill  = fill(bg)
        ws2[f"{c}{row}"].border = border
        ws2[f"{c}{row}"].alignment = Alignment(horizontal=a)

if not top_mov:
    ws2.merge_cells("A8:D8")
    ws2["A8"] = "Sin movimientos de inventario para esta fecha."
    ws2["A8"].font = font(size=10, color="6B7280")
    ws2["A8"].alignment = Alignment(horizontal="center")

buf = io.BytesIO()
wb.save(buf)
sys.stdout.buffer.write(buf.getvalue())
PYTHON;
    $payload = [
        'corte'          => $corte,        'lineas'          => $lineas,
        'fecha'          => $fecha,        'costo_total'     => $costoTotal,
        'ingreso_total'  => $ingresoTotal, 'utilidad'        => $utilidad,
        'margen'         => $margen,       'gastos_compra'   => $gastosCompra,
        'num_facturas'   => $numFacturas,  'utilidad_neta'   => $utilidadNeta,
        'margen_neto'    => $margenNeto,   'web_resumen'     => $webResumen,
        'mov_resumen'    => $movResumen,   'top_mov'         => $topMov,
        'neto_cambio'    => $netoCambio,
    ];
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="reporte_' . $fecha . '.xlsx"');
    echo run_python_export($pyCode, $payload);
    exit;
}

// ─────────────────────────────────────────
// VISTA HTML
// ─────────────────────────────────────────
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">Reportes Financieros</h3>
        <small class="text-muted">Ventas POS, pedidos web, costos, utilidades y movimientos de inventario</small>
    </div>
    <div class="d-flex gap-2">
        <a href="reportes.php?fecha=<?php echo h($fecha); ?>&export=pdf"
           class="btn btn-dark btn-sm">
            <i class="bi bi-filetype-pdf me-1"></i>Imprimir / PDF
        </a>
        <a href="reportes.php?fecha=<?php echo h($fecha); ?>&export=excel"
           class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
        </a>
    </div>
</div>

<!-- Filtro -->
<div class="card card-custom border-0 shadow-sm p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="small fw-bold text-muted">Fecha</label>
            <input type="date" name="fecha" class="form-control" value="<?php echo h($fecha); ?>">
        </div>
        <div class="col-auto"><button class="btn btn-primary">Ver</button></div>
        <div class="col-auto"><a href="reportes.php" class="btn btn-outline-secondary">Hoy</a></div>
    </form>
</div>

<!-- ── KPIs por Canal ────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card card-custom border-0 p-3 h-100" style="background:linear-gradient(135deg,#1e3a5f,#2c5282);color:white;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small fw-bold opacity-75">INGRESO TOTAL (POS + WEB)</div>
                    <div class="fs-2 fw-bold">$<?php echo number_format($ingresoTotal_canales, 2); ?></div>
                    <div class="small opacity-75"><?php echo (int)$corte['total_ventas'] + (int)($webResumen['total_pedidos_web'] ?? 0); ?> transacciones</div>
                </div>
                <i class="bi bi-cash-coin opacity-50" style="font-size:2.5rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold">🏪 VENTAS POS</div>
            <div class="fs-3 fw-bold text-success">$<?php echo number_format($ingresoPOS, 2); ?></div>
            <div class="small text-muted"><?php echo (int)$corte['total_ventas']; ?> tickets</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-primary">
            <div class="small text-muted fw-bold">🌐 PEDIDOS WEB</div>
            <div class="fs-3 fw-bold text-primary">$<?php echo number_format($ingresoWeb, 2); ?></div>
            <div class="small text-muted"><?php echo (int)($webResumen['total_pedidos_web'] ?? 0); ?> pedidos aprobados</div>
        </div>
    </div>
</div>

<!-- ── KPIs financieros ──────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-secondary">
            <div class="small text-muted fw-bold">COSTO DE VENTAS</div>
            <div class="fs-4 fw-bold text-secondary">$<?php echo number_format($costoTotal, 2); ?></div>
            <div class="small text-muted">(costo adquisición × unidades vendidas)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-danger">
            <div class="small text-danger fw-bold">GASTOS DE COMPRA</div>
            <div class="fs-4 fw-bold text-danger">$<?php echo number_format($gastosCompra, 2); ?></div>
            <div class="small text-muted"><?php echo $numFacturas; ?> factura(s) de proveedor</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 <?php echo $utilidadNeta >= 0 ? 'border-success' : 'border-danger'; ?>">
            <div class="small fw-bold <?php echo $utilidadNeta >= 0 ? 'text-success' : 'text-danger'; ?>">UTILIDAD NETA</div>
            <div class="fs-4 fw-bold <?php echo $utilidadNeta >= 0 ? 'text-success' : 'text-danger'; ?>">
                $<?php echo number_format(abs($utilidadNeta), 2); ?>
                <?php if ($utilidadNeta < 0): ?><small class="fs-6"> pérdida</small><?php endif; ?>
            </div>
            <div class="small text-muted">Ingresos − costos − compras</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-info">
            <div class="small text-muted fw-bold">MARGEN NETO</div>
            <div class="fs-4 fw-bold text-info"><?php echo number_format($margenNeto, 1); ?>%</div>
            <div class="small text-muted">sobre ingreso total</div>
        </div>
    </div>
</div>

<!-- ── Desglose POS por forma de pago ───────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12"><div class="small fw-bold text-muted mb-1" style="letter-spacing:.08em;">DESGLOSE POS — FORMA DE PAGO</div></div>
    <div class="col-md-4">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold">EFECTIVO POS</div>
            <div class="fs-4 fw-bold text-success">$<?php echo number_format((float)$corte['total_efectivo'], 2); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-info">
            <div class="small text-muted fw-bold">TARJETA POS</div>
            <div class="fs-4 fw-bold text-info">$<?php echo number_format((float)$corte['total_tarjeta'], 2); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-warning">
            <div class="small text-muted fw-bold">CRÉDITO POS</div>
            <div class="fs-4 fw-bold text-warning">$<?php echo number_format((float)$corte['total_credito'], 2); ?></div>
        </div>
    </div>
</div>

<?php if ($ingresoWeb > 0): ?>
<!-- ── Desglose WEB por forma de pago ─────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12"><div class="small fw-bold text-muted mb-1" style="letter-spacing:.08em;">DESGLOSE WEB — FORMA DE PAGO</div></div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-success">
            <div class="small text-muted fw-bold">EFECTIVO WEB</div>
            <div class="fs-4 fw-bold text-success">$<?php echo number_format((float)($webResumen['web_efectivo'] ?? 0), 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-info">
            <div class="small text-muted fw-bold">TARJETA WEB</div>
            <div class="fs-4 fw-bold text-info">$<?php echo number_format((float)($webResumen['web_tarjeta'] ?? 0), 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-primary">
            <div class="small text-muted fw-bold">TRANSFERENCIA WEB</div>
            <div class="fs-4 fw-bold text-primary">$<?php echo number_format((float)($webResumen['web_transferencia'] ?? 0), 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom border-0 p-3 h-100 border-start border-4 border-warning">
            <div class="small text-muted fw-bold">CRÉDITO WEB</div>
            <div class="fs-4 fw-bold text-warning">$<?php echo number_format((float)($webResumen['web_credito'] ?? 0), 2); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Tabla detalle productos ──────────────────────────────── -->
<div class="card card-custom border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0">Desglose por Producto</h5>
        <span class="badge bg-secondary"><?php echo count($lineas); ?> líneas</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th>Modalidad</th>
                    <th class="text-center">Canal</th>
                    <th class="text-center">Cantidad</th>
                    <th class="text-end">Ingreso</th>
                    <th class="text-end">Costo</th>
                    <th class="text-end">Utilidad</th>
                    <th class="text-center">Margen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($lineas)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-bar-chart-line" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:8px;"></i>Sin ventas para este día.
                </td></tr>
            <?php else: foreach ($lineas as $l):
                $util = (float)$l['ingreso'] - (float)$l['costo'];
                $marg = (float)$l['ingreso'] > 0 ? ($util/(float)$l['ingreso'])*100 : 0;
                $canalBadge = ($l['origen'] ?? 'pos') === 'web'
                    ? '<span class="badge bg-primary-subtle text-primary border">🌐 Web</span>'
                    : '<span class="badge bg-success-subtle text-success border">🏪 POS</span>';
            ?>
            <tr>
                <td class="fw-bold"><?php echo h($l['nombre']); ?></td>
                <td><span class="badge bg-light text-dark border"><?php echo ucfirst(h($l['modalidad'])); ?></span></td>
                <td class="text-center"><?php echo $canalBadge; ?></td>
                <td class="text-center"><?php echo (int)$l['cantidad']; ?></td>
                <td class="text-end fw-bold">$<?php echo number_format((float)$l['ingreso'], 2); ?></td>
                <td class="text-end text-muted">$<?php echo number_format((float)$l['costo'], 2); ?></td>
                <td class="text-end fw-bold <?php echo $util>=0?'text-success':'text-danger'; ?>">$<?php echo number_format($util, 2); ?></td>
                <td class="text-center">
                    <span class="badge <?php echo $marg>=30?'bg-success-subtle text-success':($marg>=15?'bg-warning-subtle text-warning':'bg-danger-subtle text-danger'); ?> border">
                        <?php echo number_format($marg, 1); ?>%
                    </span>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($lineas)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">TOTALES</td>
                    <td class="text-center"><?php echo array_sum(array_column($lineas,'cantidad')); ?></td>
                    <td class="text-end">$<?php echo number_format($ingresoTotal, 2); ?></td>
                    <td class="text-end">$<?php echo number_format($costoTotal, 2); ?></td>
                    <td class="text-end text-success">$<?php echo number_format($utilidad, 2); ?></td>
                    <td class="text-center"><?php echo number_format($margen, 1); ?>%</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECCIÓN: MOVIMIENTOS DE INVENTARIO
     ══════════════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center mb-3 mt-2">
    <div class="me-3" style="width:4px;height:28px;background:linear-gradient(180deg,#0E9F6E,#065F46);border-radius:2px;"></div>
    <h5 class="fw-bold mb-0 text-dark">Movimientos de Inventario del Día</h5>
    <span class="badge bg-success-subtle text-success border ms-2"><?php echo (int)$movResumen['total_movimientos']; ?> registros</span>
</div>

<!-- KPIs de inventario -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="card border-0 p-3 h-100 border-start border-4 border-success shadow-sm">
            <div class="small text-muted fw-bold">📥 ENTRADAS</div>
            <div class="fs-3 fw-bold text-success"><?php echo number_format((int)$movResumen['total_entradas']); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 p-3 h-100 border-start border-4 border-primary shadow-sm">
            <div class="small text-muted fw-bold">🏪 SALIDAS POS</div>
            <div class="fs-3 fw-bold text-primary"><?php echo number_format((int)$movResumen['salidas_pos']); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 p-3 h-100 border-start border-4" style="border-color:#7C3AED!important;">
            <div class="small text-muted fw-bold">🌐 SALIDAS WEB</div>
            <div class="fs-3 fw-bold" style="color:#7C3AED;"><?php echo number_format((int)$movResumen['salidas_web']); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 p-3 h-100 border-start border-4 border-warning shadow-sm">
            <div class="small text-muted fw-bold">⚙️ AJUSTES</div>
            <div class="fs-3 fw-bold text-warning"><?php echo number_format((int)$movResumen['total_ajustes']); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 p-3 h-100 border-start border-4 border-danger shadow-sm">
            <div class="small text-muted fw-bold">📤 TOTAL SALIDAS</div>
            <div class="fs-3 fw-bold text-danger"><?php echo number_format((int)$movResumen['total_salidas']); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <?php $netoColor = $netoCambio >= 0 ? 'success' : 'danger'; $netoIcon = $netoCambio >= 0 ? '📈' : '📉'; ?>
        <div class="card border-0 p-3 h-100 border-start border-4 border-<?php echo $netoColor; ?> shadow-sm">
            <div class="small text-muted fw-bold"><?php echo $netoIcon; ?> NETO</div>
            <div class="fs-3 fw-bold text-<?php echo $netoColor; ?>"><?php echo ($netoCambio >= 0 ? '+' : '') . number_format($netoCambio); ?></div>
            <div class="small text-muted">unidades</div>
        </div>
    </div>
</div>

<!-- Top productos con más movimiento -->
<div class="card card-custom border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Top Productos — Movimientos de Inventario</h6>
        <span class="badge bg-success-subtle text-success border">Top <?php echo count($topMov); ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Producto</th>
                    <th class="text-center">Tipo</th>
                    <th class="text-center">Canal / Origen</th>
                    <th class="text-center">Unidades</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($topMov)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">
                    <i class="bi bi-boxes" style="font-size:2rem;opacity:.2;display:block;margin-bottom:6px;"></i>Sin movimientos de inventario para este día.
                </td></tr>
            <?php else: foreach ($topMov as $idx => $m):
                $tipoBadge = match($m['tipo_movimiento']) {
                    'entrada' => '<span class="badge bg-success-subtle text-success border">📥 Entrada</span>',
                    'salida'  => '<span class="badge bg-danger-subtle text-danger border">📤 Salida</span>',
                    'ajuste'  => '<span class="badge bg-warning-subtle text-warning border">⚙️ Ajuste</span>',
                    default   => '<span class="badge bg-light text-dark border">' . h($m['tipo_movimiento']) . '</span>',
                };
                $origenBadge = match($m['origen']) {
                    'pos'     => '<span class="badge bg-primary-subtle text-primary border">🏪 POS</span>',
                    'web'     => '<span class="badge" style="background:#ede9fe;color:#7C3AED;border:1px solid #c4b5fd;">🌐 Web</span>',
                    'entrada' => '<span class="badge bg-success-subtle text-success border">📦 Abastecimiento</span>',
                    'ajuste'  => '<span class="badge bg-warning-subtle text-warning border">⚙️ Ajuste</span>',
                    default   => '<span class="badge bg-light text-dark border">' . h($m['origen']) . '</span>',
                };
            ?>
            <tr>
                <td class="text-muted small fw-bold"><?php echo $idx + 1; ?></td>
                <td class="fw-bold"><?php echo h($m['nombre']); ?></td>
                <td class="text-center"><?php echo $tipoBadge; ?></td>
                <td class="text-center"><?php echo $origenBadge; ?></td>
                <td class="text-center fw-bold fs-6"><?php echo number_format((int)$m['total_unidades']); ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
