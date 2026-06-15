<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); die('Acceso denegado.');
}
$data = $_SESSION['corte_print'] ?? null;
if (!$data) { die('No hay datos de corte. <a href="movimientos.php">Volver</a>'); }
unset($_SESSION['corte_print']);

$corte       = $data['corte'];
$movimientos = $data['movimientos'];
$fecha       = $data['fecha'];
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de Caja — <?php echo htmlspecialchars($fecha); ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Arial,sans-serif; font-size:11px; color:#111; background:#fff; padding:20px; }
        .header { text-align:center; border-bottom:3px solid #1E3A5F; padding-bottom:12px; margin-bottom:14px; }
        .header h1 { font-size:17px; color:#1E3A5F; font-weight:bold; margin-bottom:3px; }
        .header p  { font-size:10px; color:#6B7280; }
        .kpis { display:flex; gap:10px; margin-bottom:14px; }
        .kpi  { flex:1; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; text-align:center; }
        .kpi .label { font-size:9px; color:#6B7280; font-weight:bold; text-transform:uppercase; }
        .kpi .val   { font-size:15px; font-weight:bold; }
        table { width:100%; border-collapse:collapse; font-size:10px; }
        thead tr { background:#111827; color:#fff; }
        thead th { padding:7px 8px; text-align:left; font-size:9px; font-weight:bold; letter-spacing:.4px; }
        thead th.c { text-align:center; }
        thead th.r { text-align:right; }
        tbody tr:nth-child(even) { background:#f9fafb; }
        tbody td { padding:5.5px 8px; border-bottom:1px solid #f1f5f9; }
        tbody td.c { text-align:center; }
        tbody td.badge-entrada { color:#0E9F6E; font-weight:bold; }
        tbody td.badge-salida  { color:#E02424; font-weight:bold; }
        .footer { text-align:center; margin-top:16px; padding-top:10px; border-top:1px solid #e5e7eb; font-size:9px; color:#9ca3af; }
        @media print {
            body { padding:0; }
            .no-print { display:none !important; }
        }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px; display:flex; gap:10px; align-items:center;">
    <button onclick="window.print()" style="padding:8px 20px; background:#1E3A5F; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:bold; cursor:pointer;">
        🖨 Imprimir / Guardar PDF
    </button>
    <a href="movimientos.php?fecha=<?php echo htmlspecialchars($fecha); ?>" style="padding:8px 16px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:6px; font-size:13px; color:#374151; text-decoration:none;">Volver</a>
    <small style="color:#6b7280;">Tip: En el diálogo de impresión, elige "Guardar como PDF".</small>
</div>

<div class="header">
    <h1>FARMACIA POPULARES PEÑALOZA — Corte de Caja</h1>
    <p>Fecha: <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha))); ?> &nbsp;|&nbsp; Impreso: <?php echo date('d/m/Y H:i'); ?></p>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="label">Total Ventas</div>
        <div class="val" style="color:#1E3A5F;">$<?php echo number_format((float)$corte['total_general'],2); ?></div>
        <div class="label"><?php echo (int)$corte['total_ventas']; ?> tickets</div>
    </div>
    <div class="kpi">
        <div class="label">Efectivo</div>
        <div class="val" style="color:#0E9F6E;">$<?php echo number_format((float)$corte['total_efectivo'],2); ?></div>
    </div>
    <div class="kpi">
        <div class="label">Tarjeta</div>
        <div class="val" style="color:#1D4ED8;">$<?php echo number_format((float)$corte['total_tarjeta'],2); ?></div>
    </div>
    <div class="kpi">
        <div class="label">Crédito</div>
        <div class="val" style="color:#D97706;">$<?php echo number_format((float)$corte['total_credito'],2); ?></div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th style="width:10%;">Hora</th>
            <th style="width:12%;" class="c">Tipo</th>
            <th style="width:30%;">Producto</th>
            <th style="width:9%;" class="c">Cantidad</th>
            <th style="width:16%;">Usuario</th>
            <th>Observación</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($movimientos)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;font-style:italic;">Sin movimientos registrados para este día.</td></tr>
    <?php else: foreach ($movimientos as $m):
        $esEntrada = $m['tipo_movimiento'] === 'entrada';
        $signo     = $esEntrada ? '+' : '-';
        $tipoClass = $esEntrada ? 'badge-entrada' : 'badge-salida';
    ?>
    <tr>
        <td><?php echo date('H:i', strtotime($m['fecha'])); ?></td>
        <td class="c <?php echo $tipoClass; ?>"><?php echo strtoupper(htmlspecialchars($m['tipo_movimiento'])); ?></td>
        <td><?php echo htmlspecialchars($m['producto']); ?></td>
        <td class="c <?php echo $tipoClass; ?>"><?php echo $signo . (int)$m['cantidad']; ?></td>
        <td><?php echo htmlspecialchars($m['usuario']); ?></td>
        <td style="color:#6B7280;font-style:italic;"><?php echo htmlspecialchars($m['observaciones'] ?? ''); ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<div class="footer">
    Farmacia Populares Peñaloza &mdash; Sistema ERP &mdash; Corte de Caja &mdash; <?php echo date('d/m/Y H:i'); ?>
</div>
<script>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
