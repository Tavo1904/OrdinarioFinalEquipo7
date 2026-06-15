<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_GET['id'])) die('Falta el ID del pedido.');
$idPedido = (int)$_GET['id'];
// tipo: 'cliente' (default) | 'interno' (almacén con más detalle)
$tipo = in_array($_GET['tipo'] ?? 'cliente', ['interno', 'cliente']) ? ($_GET['tipo'] ?? 'cliente') : 'cliente';

$stmt = $conexion->prepare("
    SELECT p.*, u.nombre AS vendedor, COALESCE(c.nombre, 'Venta de Mostrador') AS cliente,
           COALESCE(c.correo, '') AS cliente_correo
    FROM pedidos p
    INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
    LEFT JOIN usuarios  c ON p.id_cliente = c.id_usuario
    WHERE p.id_pedido = ?
");
$stmt->execute([$idPedido]);
$pedido = $stmt->fetch();
if (!$pedido) die('Pedido no encontrado.');

$stmtDet = $conexion->prepare("
    SELECT dp.*, prod.nombre, prod.numero_lote, prod.laboratorio, prod.presentacion
    FROM detalle_pedido dp
    INNER JOIN productos prod ON dp.id_producto = prod.id_producto
    WHERE dp.id_pedido = ?
");
$stmtDet->execute([$idPedido]);
$detalles = $stmtDet->fetchAll();

// Leer nombre farmacia de configuracion
$confRow = $conexion->query("SELECT valor FROM configuracion WHERE clave='nombre_farmacia'")->fetch();
$nombreFarmacia = $confRow ? $confRow['valor'] : 'Farmacia Peñaloza';

$folio = str_pad($idPedido, 6, '0', STR_PAD_LEFT);

// Labels pago
function labelPago2($tp) {
    return match($tp) {
        'efectivo'      => 'Efectivo',
        'tarjeta'       => 'Tarjeta de crédito/débito',
        'transferencia' => 'Transferencia bancaria',
        'credito'       => 'Crédito en cuenta',
        'web'           => 'Portal web (por definir)',
        default         => ucfirst($tp),
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo $tipo === 'interno' ? 'Ticket Interno' : 'Ticket Cliente'; ?> #<?php echo $folio; ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: #f0f0f0;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 20px;
            min-height: 100vh;
        }
        .ticket {
            background: #fff;
            width: 330px;
            padding: 18px 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,.15);
            border-radius: 4px;
        }
        .tipo-banner {
            text-align: center;
            padding: 5px;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 2px;
            margin-bottom: 10px;
            border-radius: 3px;
        }
        .tipo-interno { background: #111; color: #fff; }
        .tipo-cliente { background: #1e4d2b; color: #fff; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #aaa; margin-bottom: 10px; }
        .header h2 { font-size: 15px; font-weight: bold; letter-spacing: 1px; }
        .header p  { font-size: 11px; margin-top: 2px; color: #555; }
        .info-row  { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 11px; }
        .info-row .lbl { color: #555; }
        .info-row .val { font-weight: bold; text-align: right; max-width: 180px; word-break: break-word; }
        .pago-row { background: #f0f8f0; border: 1px solid #c8e6c9; border-radius: 3px; padding: 5px 8px; margin-bottom: 8px; }
        .pago-row .lbl { color: #2e7d32; font-weight: bold; font-size: 10px; letter-spacing: 1px; }
        .pago-row .val { font-size: 13px; font-weight: bold; color: #1b5e20; }
        .sep { border: none; border-top: 1px dashed #aaa; margin: 8px 0; }
        .section-title { font-size: 9px; font-weight: bold; letter-spacing: 2px; color: #888; text-transform: uppercase; margin-bottom: 5px; }
        .items-header { display: flex; font-size: 10px; font-weight: bold; color: #888; text-transform: uppercase; margin-bottom: 4px; }
        .items-header .c1 { width: 30px; }
        .items-header .c2 { flex: 1; }
        .items-header .c3 { width: 70px; text-align: right; }
        .item-row { display: flex; align-items: flex-start; padding: 4px 0; border-bottom: 1px dotted #eee; }
        .item-row .c1 { width: 30px; font-weight: bold; color: #333; }
        .item-row .c2 { flex: 1; }
        .item-row .c2 .nombre { font-weight: bold; line-height: 1.3; }
        .item-row .c2 .detalle { font-size: 10px; color: #888; margin-top: 1px; }
        .item-row .c2 .lote { font-size: 9px; color: #b00; margin-top: 1px; }
        .item-row .c3 { width: 70px; text-align: right; font-weight: bold; }
        .total-section { padding-top: 8px; }
        .total-row { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px; }
        .total-row.grande { font-size: 16px; font-weight: bold; margin-top: 6px; padding-top: 6px; border-top: 2px solid #000; }
        .recoleccion-box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 3px; padding: 7px 10px; margin-top: 8px; text-align: center; }
        .recoleccion-box .hora { font-size: 18px; font-weight: bold; color: #1b5e20; }
        .recoleccion-box .lbl { font-size: 10px; color: #555; }
        .almacen-box { background: #fff3e0; border: 1px solid #ffcc80; border-radius: 3px; padding: 7px 10px; margin-top: 8px; }
        .almacen-box .title { font-size: 9px; font-weight: bold; letter-spacing: 2px; color: #e65100; }
        .almacen-item { font-size: 11px; padding: 3px 0; border-bottom: 1px dotted #ffe0b2; }
        .footer { text-align: center; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #aaa; font-size: 11px; color: #666; line-height: 1.6; }
        .no-print { margin-top: 16px; text-align: center; }
        .no-print button {
            padding: 7px 16px; margin: 3px;
            border: none; border-radius: 6px;
            cursor: pointer; font-size: 12px; font-weight: bold;
        }
        .btn-print { background: #0d6efd; color: #fff; }
        .btn-close-w { background: #e9ecef; color: #333; }
        @media print {
            body { background: #fff; padding: 0; }
            .ticket { box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<div class="ticket">
    <!-- Banner de tipo -->
    <div class="tipo-banner tipo-<?php echo $tipo; ?>">
        <?php echo $tipo === 'interno' ? '▌ USO INTERNO — ALMACÉN ▐' : '▌ COMPROBANTE DE PEDIDO ▐'; ?>
    </div>

    <div class="header">
        <h2><?php echo strtoupper(h($nombreFarmacia)); ?></h2>
        <p><?php echo $tipo === 'interno' ? 'Hoja de Preparación de Pedido' : 'Ticket de Pedido Web'; ?></p>
        <p><?php echo date('d/m/Y H:i', strtotime($pedido['fecha'])); ?></p>
    </div>

    <div class="info-row">
        <span class="lbl">Folio:</span>
        <span class="val">#<?php echo $folio; ?></span>
    </div>
    <div class="info-row">
        <span class="lbl">Cliente:</span>
        <span class="val"><?php echo h($pedido['cliente']); ?></span>
    </div>
    <?php if ($tipo === 'interno'): ?>
    <div class="info-row">
        <span class="lbl">Atendió:</span>
        <span class="val"><?php echo h($pedido['vendedor']); ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($pedido['hora_recoleccion'])): ?>
    <div class="info-row">
        <span class="lbl">Recolección:</span>
        <span class="val"><?php echo date('h:i A', strtotime($pedido['hora_recoleccion'])); ?></span>
    </div>
    <?php endif; ?>

    <!-- Método de pago (prominente) -->
    <div class="pago-row">
        <div class="lbl">MÉTODO DE PAGO</div>
        <div class="val"><?php echo labelPago2($pedido['tipo_pago'] ?? 'web'); ?></div>
    </div>

    <hr class="sep">

    <?php if ($tipo === 'interno'): ?>
    <!-- TICKET INTERNO: detalle para almacén con lote y laboratorio -->
    <div class="section-title">Productos a preparar</div>
    <?php foreach ($detalles as $d): ?>
    <div class="almacen-item">
        <strong><?php echo (int)$d['cantidad']; ?>x <?php echo h($d['nombre']); ?></strong><br>
        <span style="font-size:10px;color:#666;">
            Lote: <?php echo h($d['numero_lote'] ?: '—'); ?> | Lab: <?php echo h($d['laboratorio'] ?: '—'); ?><br>
            Presentación: <?php echo h($d['presentacion'] ?: '—'); ?> | $<?php echo number_format((float)$d['precio_unitario'],2); ?>/u
        </span>
    </div>
    <?php endforeach; ?>
    <div class="total-section">
        <div class="total-row grande">
            <span>TOTAL</span>
            <span>$<?php echo number_format((float)$pedido['total'],2); ?></span>
        </div>
        <div class="total-row" style="font-size:10px;color:#777;">
            <span>Pago:</span>
            <span><?php echo labelPago2($pedido['tipo_pago'] ?? 'web'); ?></span>
        </div>
    </div>
    <?php if (!empty($pedido['hora_recoleccion'])): ?>
    <div class="recoleccion-box" style="background:#fff3e0;border-color:#ffcc80;">
        <div class="lbl">⚠ HORA LÍMITE DE PREPARACIÓN</div>
        <div class="hora" style="color:#e65100;"><?php echo date('h:i A', strtotime($pedido['hora_recoleccion'])); ?></div>
    </div>
    <?php endif; ?>
    <div class="footer">
        Preparado por: ____________<br>
        Revisado por: ____________<br>
        Fecha: <?php echo date('d/m/Y H:i'); ?>
    </div>

    <?php else: ?>
    <!-- TICKET CLIENTE: presentación amigable -->
    <div class="items-header">
        <div class="c1">Cant</div>
        <div class="c2">Producto</div>
        <div class="c3">Importe</div>
    </div>
    <?php foreach ($detalles as $d): ?>
    <div class="item-row">
        <div class="c1"><?php echo (int)$d['cantidad']; ?></div>
        <div class="c2">
            <div class="nombre"><?php echo h($d['nombre']); ?></div>
            <div class="detalle">$<?php echo number_format((float)$d['precio_unitario'],2); ?> c/u | <?php echo ucfirst(h($d['modalidad'])); ?></div>
        </div>
        <div class="c3">$<?php echo number_format((float)$d['subtotal'],2); ?></div>
    </div>
    <?php endforeach; ?>

    <div class="total-section">
        <div class="total-row grande">
            <span>TOTAL</span>
            <span>$<?php echo number_format((float)$pedido['total'],2); ?></span>
        </div>
        <div class="total-row" style="font-size:10px;color:#777;margin-top:3px;">
            <span>Pago con:</span>
            <span><?php echo labelPago2($pedido['tipo_pago'] ?? 'web'); ?></span>
        </div>
    </div>

    <?php if (!empty($pedido['hora_recoleccion'])): ?>
    <div class="recoleccion-box">
        <div class="lbl">📍 HORA DE RECOLECCIÓN EN FARMACIA</div>
        <div class="hora"><?php echo date('h:i A', strtotime($pedido['hora_recoleccion'])); ?></div>
        <div style="font-size:10px;color:#555;margin-top:3px;">Horario de atención: 9:00 AM – 5:00 PM</div>
    </div>
    <?php endif; ?>

    <div class="footer">
        Gracias por su compra.<br>
        Conserve este comprobante.<br>
        <?php echo h($nombreFarmacia); ?> — Folio #<?php echo $folio; ?>
    </div>
    <?php endif; ?>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨 Imprimir</button>
        <button class="btn-close-w" onclick="window.close()">Cerrar</button>
    </div>
</div>
</body>
</html>
