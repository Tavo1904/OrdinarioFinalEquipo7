<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); die('Acceso denegado.');
}
$data = $_SESSION['reporte_print'] ?? null;
if (!$data) { die('No hay datos de reporte. <a href="reportes.php">Volver</a>'); }
unset($_SESSION['reporte_print']);

$corte        = $data['corte'];
$lineas       = $data['lineas'];
$fecha        = $data['fecha'];
$costoTotal   = $data['costo_total'];
$ingresoTotal = $data['ingreso_total'];
$utilidad     = $data['utilidad'];
$margen       = $data['margen'];
$webResumen   = $data['web_resumen']  ?? [];
$movResumen   = $data['mov_resumen']  ?? [];
$topMov       = $data['top_mov']      ?? [];
$netoCambio   = $data['neto_cambio']  ?? 0;
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Financiero — <?php echo htmlspecialchars($fecha); ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Arial,sans-serif; font-size:11px; color:#111; background:#fff; padding:20px; }
        .header { text-align:center; border-bottom:3px solid #1A56DB; padding-bottom:12px; margin-bottom:14px; }
        .header h1 { font-size:18px; color:#1A56DB; font-weight:bold; margin-bottom:3px; }
        .header p  { font-size:10px; color:#6B7280; }
        .kpis { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .kpi  { flex:1; min-width:90px; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; }
        .kpi .label { font-size:9px; color:#6B7280; font-weight:bold; text-transform:uppercase; }
        .kpi .val   { font-size:15px; font-weight:bold; color:#111; }
        .kpi.blue .val   { color:#1A56DB; }
        .kpi.green .val  { color:#0E9F6E; }
        .kpi.purple .val { color:#7C3AED; }
        .kpi.red .val    { color:#E02424; }
        .kpi.orange .val { color:#D97706; }
        table { width:100%; border-collapse:collapse; font-size:10px; }
        thead tr { background:#111827; color:#fff; }
        thead th { padding:7px 8px; text-align:left; font-weight:bold; letter-spacing:.5px; font-size:9px; }
        thead th.r { text-align:right; }
        thead th.c { text-align:center; }
        tbody tr:nth-child(even) { background:#f9fafb; }
        tbody td { padding:6px 8px; border-bottom:1px solid #f1f5f9; }
        tbody td.r { text-align:right; }
        tbody td.c { text-align:center; }
        tfoot tr { background:#f3f4f6; font-weight:bold; }
        tfoot td  { padding:7px 8px; border-top:2px solid #d1d5db; }
        tfoot td.r { text-align:right; }
        .section-title { font-size:11px; font-weight:bold; color:#374151; margin-bottom:6px; margin-top:14px; border-left:3px solid #1A56DB; padding-left:8px; }
        .section-title.green { border-color:#0E9F6E; }
        .pago-row { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .pago-box { flex:1; min-width:80px; border:1px solid #d1d5db; border-radius:6px; padding:8px; text-align:center; }
        .pago-box .label { font-size:8px; color:#6B7280; font-weight:bold; text-transform:uppercase; }
        .pago-box .val   { font-size:13px; font-weight:bold; }
        .footer { text-align:center; margin-top:18px; padding-top:10px; border-top:1px solid #e5e7eb; font-size:9px; color:#9ca3af; }
        .page-break { page-break-before:always; }
        @media print {
            body { padding:0; }
            .no-print { display:none !important; }
        }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px; display:flex; gap:10px;">
    <button onclick="window.print()" style="padding:8px 20px; background:#1A56DB; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:bold; cursor:pointer;">
        🖨 Imprimir / Guardar PDF
    </button>
    <a href="reportes.php?fecha=<?php echo htmlspecialchars($fecha); ?>" style="padding:8px 16px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:6px; font-size:13px; color:#374151; text-decoration:none;">Volver</a>
    <small style="align-self:center; color:#6b7280;">Tip: En el diálogo de impresión, elige "Guardar como PDF" para obtener el archivo PDF.</small>
</div>

<div class="header">
    <h1>FARMACIA POPULARES PEÑALOZA</h1>
    <p>Reporte Financiero Diario &nbsp;|&nbsp; <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha))); ?> &nbsp;|&nbsp; Impreso: <?php echo date('d/m/Y H:i'); ?></p>
</div>

<!-- ── KPIs financieros ── -->
<div class="kpis">
    <div class="kpi blue">
        <div class="label">Venta Total POS</div>
        <div class="val">$<?php echo number_format((float)$corte['total_general'],2); ?></div>
        <div class="label"><?php echo (int)$corte['total_ventas']; ?> tickets</div>
    </div>
    <?php if (!empty($webResumen['total_web']) && (float)$webResumen['total_web'] > 0): ?>
    <div class="kpi" style="border-color:#1D4ED8;">
        <div class="label">Venta Web</div>
        <div class="val" style="color:#1D4ED8;">$<?php echo number_format((float)$webResumen['total_web'],2); ?></div>
        <div class="label"><?php echo (int)($webResumen['total_pedidos_web']??0); ?> pedidos</div>
    </div>
    <?php endif; ?>
    <div class="kpi">
        <div class="label">Costo Total</div>
        <div class="val">$<?php echo number_format($costoTotal,2); ?></div>
    </div>
    <div class="kpi green">
        <div class="label">Utilidad</div>
        <div class="val">$<?php echo number_format($utilidad,2); ?></div>
    </div>
    <div class="kpi purple">
        <div class="label">Margen</div>
        <div class="val"><?php echo number_format($margen,1); ?>%</div>
    </div>
</div>

<!-- ── Desglose pagos POS ── -->
<div class="pago-row">
    <div class="pago-box">
        <div class="label">Efectivo POS</div>
        <div class="val" style="color:#0E9F6E;">$<?php echo number_format((float)$corte['total_efectivo'],2); ?></div>
    </div>
    <div class="pago-box">
        <div class="label">Tarjeta POS</div>
        <div class="val" style="color:#1A56DB;">$<?php echo number_format((float)$corte['total_tarjeta'],2); ?></div>
    </div>
    <div class="pago-box">
        <div class="label">Crédito POS</div>
        <div class="val" style="color:#D97706;">$<?php echo number_format((float)$corte['total_credito'],2); ?></div>
    </div>
    <?php if (!empty($webResumen['total_web']) && (float)$webResumen['total_web'] > 0): ?>
    <div class="pago-box">
        <div class="label">Efectivo Web</div>
        <div class="val" style="color:#0E9F6E;">$<?php echo number_format((float)($webResumen['web_efectivo']??0),2); ?></div>
    </div>
    <div class="pago-box">
        <div class="label">Tarjeta Web</div>
        <div class="val" style="color:#1A56DB;">$<?php echo number_format((float)($webResumen['web_tarjeta']??0),2); ?></div>
    </div>
    <div class="pago-box">
        <div class="label">Transfer. Web</div>
        <div class="val" style="color:#7C3AED;">$<?php echo number_format((float)($webResumen['web_transferencia']??0),2); ?></div>
    </div>
    <div class="pago-box">
        <div class="label">Crédito Web</div>
        <div class="val" style="color:#D97706;">$<?php echo number_format((float)($webResumen['web_credito']??0),2); ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Desglose por Producto ── -->
<div class="section-title">Desglose por Producto</div>
<table>
    <thead>
        <tr>
            <th style="width:32%;">Producto</th>
            <th>Modalidad</th>
            <th class="c">Canal</th>
            <th class="r">Cantidad</th>
            <th class="r">Ingreso</th>
            <th class="r">Costo</th>
            <th class="r">Utilidad</th>
            <th class="r">Margen</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($lineas as $l):
        $util = (float)$l['ingreso'] - (float)$l['costo'];
        $marg = (float)$l['ingreso'] > 0 ? ($util/(float)$l['ingreso'])*100 : 0;
        $canal = ($l['origen'] ?? 'pos') === 'web' ? 'Web' : 'POS';
    ?>
    <tr>
        <td><?php echo htmlspecialchars($l['nombre']); ?></td>
        <td class="c"><?php echo ucfirst(htmlspecialchars($l['modalidad'])); ?></td>
        <td class="c"><?php echo $canal; ?></td>
        <td class="r"><?php echo (int)$l['cantidad']; ?></td>
        <td class="r">$<?php echo number_format((float)$l['ingreso'],2); ?></td>
        <td class="r">$<?php echo number_format((float)$l['costo'],2); ?></td>
        <td class="r" style="color:<?php echo $util>=0?'#0E9F6E':'#E02424'; ?>; font-weight:bold;">$<?php echo number_format($util,2); ?></td>
        <td class="r"><?php echo number_format($marg,1); ?>%</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3"><strong>TOTALES</strong></td>
            <td class="r"><?php echo array_sum(array_column($lineas,'cantidad')); ?></td>
            <td class="r">$<?php echo number_format($ingresoTotal,2); ?></td>
            <td class="r">$<?php echo number_format($costoTotal,2); ?></td>
            <td class="r" style="color:#0E9F6E;">$<?php echo number_format($utilidad,2); ?></td>
            <td class="r"><?php echo number_format($margen,1); ?>%</td>
        </tr>
    </tfoot>
</table>

<!-- ══════════════════════════════════════════════════════
     SECCIÓN: MOVIMIENTOS DE INVENTARIO
     ══════════════════════════════════════════════════════ -->
<div class="section-title green" style="margin-top:20px;">Movimientos de Inventario del Día</div>

<!-- KPIs inventario -->
<div class="kpis" style="margin-bottom:10px;">
    <div class="kpi green">
        <div class="label">📥 Entradas</div>
        <div class="val"><?php echo number_format((int)($movResumen['total_entradas']??0)); ?> uds</div>
    </div>
    <div class="kpi blue">
        <div class="label">🏪 Salidas POS</div>
        <div class="val"><?php echo number_format((int)($movResumen['salidas_pos']??0)); ?> uds</div>
    </div>
    <div class="kpi purple">
        <div class="label">🌐 Salidas Web</div>
        <div class="val"><?php echo number_format((int)($movResumen['salidas_web']??0)); ?> uds</div>
    </div>
    <div class="kpi orange">
        <div class="label">⚙️ Ajustes</div>
        <div class="val"><?php echo number_format((int)($movResumen['total_ajustes']??0)); ?> uds</div>
    </div>
    <div class="kpi red">
        <div class="label">📤 Total Salidas</div>
        <div class="val"><?php echo number_format((int)($movResumen['total_salidas']??0)); ?> uds</div>
    </div>
    <div class="kpi <?php echo $netoCambio >= 0 ? 'green' : 'red'; ?>">
        <div class="label"><?php echo $netoCambio >= 0 ? '📈' : '📉'; ?> Neto</div>
        <div class="val"><?php echo ($netoCambio >= 0 ? '+' : '') . number_format($netoCambio); ?> uds</div>
    </div>
</div>

<!-- Tabla top movimientos -->
<?php if (!empty($topMov)): ?>
<table style="margin-top:6px;">
    <thead>
        <tr>
            <th>#</th>
            <th style="width:40%;">Producto</th>
            <th class="c">Tipo</th>
            <th class="c">Canal / Origen</th>
            <th class="r">Unidades</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($topMov as $idx => $m):
        $tipoLabel = ['entrada' => 'Entrada', 'salida' => 'Salida', 'ajuste' => 'Ajuste'][$m['tipo_movimiento']] ?? ucfirst($m['tipo_movimiento']);
        $origenLabel = ['pos' => 'POS', 'web' => 'Web', 'entrada' => 'Abastecimiento', 'ajuste' => 'Ajuste'][$m['origen']] ?? ucfirst($m['origen']);
    ?>
    <tr>
        <td class="c"><?php echo $idx + 1; ?></td>
        <td><?php echo htmlspecialchars($m['nombre']); ?></td>
        <td class="c"><?php echo $tipoLabel; ?></td>
        <td class="c"><?php echo $origenLabel; ?></td>
        <td class="r"><strong><?php echo number_format((int)$m['total_unidades']); ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p style="color:#9CA3AF; font-size:10px; text-align:center; padding:10px 0;">Sin movimientos de inventario para esta fecha.</p>
<?php endif; ?>

<div class="footer">
    Farmacia Populares Peñaloza &mdash; Sistema ERP &mdash; Documento generado el <?php echo date('d/m/Y H:i'); ?>
</div>
<script>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
