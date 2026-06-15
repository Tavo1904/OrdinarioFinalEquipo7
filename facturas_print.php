<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); die('Acceso denegado.');
}
$data = $_SESSION['facturas_print'] ?? null;
if (!$data) { die('Sin datos. <a href="facturas.php">Volver</a>'); }
unset($_SESSION['facturas_print']);

$facturas    = $data['facturas'];
$kpis        = $data['kpis'];
$topProductos= $data['top_productos'];
$f_inicio    = $data['f_inicio'];
$f_fin       = $data['f_fin'];
$periodo     = $f_inicio === $f_fin ? date('d/m/Y', strtotime($f_inicio)) : date('d/m/Y', strtotime($f_inicio)) . ' al ' . date('d/m/Y', strtotime($f_fin));

function folio_p(int $id): string { return 'F-' . str_pad($id, 5, '0', STR_PAD_LEFT); }
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Facturas — <?php echo htmlspecialchars($periodo); ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Arial,sans-serif; font-size:11px; color:#111; background:#fff; padding:20px; }
        .header { text-align:center; border-bottom:3px solid #1A56DB; padding-bottom:12px; margin-bottom:14px; }
        .header h1 { font-size:17px; color:#1A56DB; font-weight:bold; }
        .header p  { font-size:10px; color:#6B7280; margin-top:3px; }
        .kpis { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .kpi  { flex:1; min-width:90px; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; }
        .kpi .label { font-size:9px; color:#6B7280; font-weight:bold; text-transform:uppercase; }
        .kpi .val   { font-size:15px; font-weight:bold; }
        .kpi.blue .val   { color:#1A56DB; }
        .kpi.green .val  { color:#0E9F6E; }
        .kpi.purple .val { color:#7C3AED; }
        .kpi.teal .val   { color:#0891B2; }
        .section-title { font-size:11px; font-weight:bold; color:#374151; margin:14px 0 6px 0; border-left:3px solid #1A56DB; padding-left:8px; }
        table { width:100%; border-collapse:collapse; font-size:10px; }
        thead tr { background:#111827; color:#fff; }
        thead th { padding:6px 8px; text-align:left; font-weight:bold; font-size:9px; letter-spacing:.4px; }
        thead th.r { text-align:right; }
        thead th.c { text-align:center; }
        tbody tr:nth-child(even) { background:#f9fafb; }
        tbody td { padding:5px 8px; border-bottom:1px solid #f1f5f9; }
        tbody td.r { text-align:right; }
        tbody td.c { text-align:center; }
        tfoot tr { background:#f3f4f6; font-weight:bold; }
        tfoot td  { padding:6px 8px; border-top:2px solid #d1d5db; }
        tfoot td.r { text-align:right; }
        .top-row { display:flex; gap:14px; margin-bottom:14px; }
        .top-card { flex:1; border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; }
        .top-card thead tr { background:#0E9F6E; }
        .footer { text-align:center; margin-top:16px; padding-top:10px; border-top:1px solid #e5e7eb; font-size:9px; color:#9ca3af; }
        @media print { .no-print { display:none !important; } body { padding:0; } }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px; display:flex; gap:10px;">
    <button onclick="window.print()" style="padding:8px 20px; background:#1A56DB; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:bold; cursor:pointer;">🖨 Imprimir / Guardar PDF</button>
    <a href="facturas.php" style="padding:8px 16px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:6px; font-size:13px; color:#374151; text-decoration:none;">Volver</a>
</div>

<div class="header">
    <h1>FARMACIA POPULARES PEÑALOZA — Reporte de Facturas</h1>
    <p>Período: <?php echo htmlspecialchars($periodo); ?> &nbsp;|&nbsp; Impreso: <?php echo date('d/m/Y H:i'); ?> &nbsp;|&nbsp; <?php echo number_format((int)$kpis['total_facturas']); ?> facturas</p>
</div>

<div class="kpis">
    <div class="kpi blue">
        <div class="label">Total Facturas</div>
        <div class="val"><?php echo number_format((int)$kpis['total_facturas']); ?></div>
    </div>
    <div class="kpi green">
        <div class="label">Total Ventas</div>
        <div class="val">$<?php echo number_format((float)$kpis['total_ventas'], 2); ?></div>
    </div>
    <div class="kpi purple">
        <div class="label">Promedio / Factura</div>
        <div class="val">$<?php echo number_format((float)$kpis['promedio'], 2); ?></div>
    </div>
    <div class="kpi teal">
        <div class="label">Ticket Máximo</div>
        <div class="val">$<?php echo number_format((float)$kpis['ticket_max'], 2); ?></div>
    </div>
    <div class="kpi">
        <div class="label">Ticket Mínimo</div>
        <div class="val">$<?php echo number_format((float)$kpis['ticket_min'], 2); ?></div>
    </div>
</div>

<!-- Top productos -->
<?php if (!empty($topProductos)): ?>
<div class="section-title">Productos Más Vendidos</div>
<div class="top-row">
    <div class="top-card">
        <table>
            <thead><tr>
                <th>#</th><th>Producto</th><th class="c">Unidades</th><th class="r">Ingreso</th>
            </tr></thead>
            <tbody>
            <?php foreach ($topProductos as $idx => $tp): ?>
            <tr>
                <td class="c"><?php echo $idx + 1; ?></td>
                <td><?php echo htmlspecialchars($tp['nombre']); ?></td>
                <td class="c"><strong><?php echo number_format((int)$tp['unidades']); ?></strong></td>
                <td class="r">$<?php echo number_format((float)$tp['ingreso'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Tabla de facturas -->
<div class="section-title">Detalle de Facturas</div>
<table>
    <thead>
        <tr>
            <th>Folio</th>
            <th>Cliente</th>
            <th class="c">Canal</th>
            <th>Fecha / Hora</th>
            <th class="c">Pago</th>
            <th class="r">Total</th>
            <th class="c">Estado</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $totalGen = 0;
    foreach ($facturas as $f):
        $totalGen += (float)$f['total'];
        $esWeb = $f['origen'] === 'web';
        $estadoLabel = $esWeb ? ucfirst($f['estado_aprobacion']) : ucfirst($f['estado']);
    ?>
    <tr>
        <td><strong><?php echo folio_p((int)$f['id_pedido']); ?></strong></td>
        <td><?php echo htmlspecialchars($f['cliente']); ?></td>
        <td class="c"><?php echo $esWeb ? 'Web' : 'POS'; ?></td>
        <td><?php echo date('d/m/Y H:i', strtotime($f['fecha'])); ?></td>
        <td class="c"><?php echo ucfirst(htmlspecialchars($f['tipo_pago'])); ?></td>
        <td class="r"><strong>$<?php echo number_format((float)$f['total'], 2); ?></strong></td>
        <td class="c"><?php echo htmlspecialchars($estadoLabel); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5"><strong>TOTAL</strong></td>
            <td class="r" style="color:#0E9F6E;"><strong>$<?php echo number_format($totalGen, 2); ?></strong></td>
            <td></td>
        </tr>
    </tfoot>
</table>

<div class="footer">Farmacia Populares Peñaloza &mdash; Sistema ERP &mdash; Generado: <?php echo date('d/m/Y H:i'); ?></div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
</body>
</html>
