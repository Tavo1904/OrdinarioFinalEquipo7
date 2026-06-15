<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'cliente') {
    echo '<p class="text-danger">Acceso denegado.</p>';
    exit;
}

$id_pedido = (int)($_GET['id'] ?? 0);
$id_usuario = (int)$_SESSION['id_usuario'];

$stP = $conexion->prepare("SELECT * FROM pedidos WHERE id_pedido = ? AND id_cliente = ?");
$stP->execute([$id_pedido, $id_usuario]);
$pedido = $stP->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    echo '<p class="text-center text-muted py-4">Pedido no encontrado.</p>';
    exit;
}

$stD = $conexion->prepare("
    SELECT dp.*, pr.nombre AS producto_nombre
    FROM detalle_pedido dp
    INNER JOIN productos pr ON dp.id_producto = pr.id_producto
    WHERE dp.id_pedido = ?
");
$stD->execute([$id_pedido]);
$detalle = $stD->fetchAll(PDO::FETCH_ASSOC);

$ea = $pedido['estado_aprobacion'] ?? 'pendiente';
if ($ea === 'aceptado')      { $badge = 'bg-success'; $txt = 'Aceptado'; }
elseif ($ea === 'rechazado') { $badge = 'bg-danger';  $txt = 'Rechazado'; }
else                          { $badge = 'bg-warning text-dark'; $txt = 'En revisión'; }
?>
<div class="d-flex justify-content-between align-items-center mb-3 px-2">
    <div>
        <span class="fw-bold fs-5">#<?php echo str_pad($pedido['id_pedido'], 6, '0', STR_PAD_LEFT); ?></span>
        <span class="badge <?php echo $badge; ?> ms-2 px-3"><?php echo $txt; ?></span>
    </div>
    <span class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($pedido['fecha'])); ?></span>
</div>

<?php if ($ea === 'aceptado' && !empty($pedido['hora_recoleccion'])): ?>
<div class="alert alert-success d-flex align-items-center mb-3">
    <i class="bi bi-clock-fill me-2 fs-5"></i>
    <div>
        <strong>¡Pedido listo para recolectar!</strong><br>
        Hora de recolección: <strong><?php echo date('h:i A', strtotime($pedido['hora_recoleccion'])); ?></strong><br>
        <small class="text-muted">Horario de atención: 9:00 AM – 5:00 PM</small>
    </div>
</div>
<?php elseif ($ea === 'aceptado'): ?>
<div class="alert alert-success mb-3"><i class="bi bi-check-circle-fill me-2"></i>Pedido aceptado. Acércate a la farmacia en horario de 9:00 AM a 5:00 PM.</div>
<?php elseif ($ea === 'rechazado'): ?>
<div class="alert alert-danger mb-3">
    <i class="bi bi-x-circle-fill me-2"></i><strong>Pedido rechazado.</strong>
    <?php if (!empty($pedido['comentario_aprobacion'])): ?>
        <br><span class="small">Motivo: <?php echo h($pedido['comentario_aprobacion']); ?></span>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-warning mb-3"><i class="bi bi-clock me-2"></i>Tu pedido está siendo revisado. Te notificaremos cuando haya una respuesta.</div>
<?php endif; ?>

<table class="table table-sm align-middle mb-3">
    <thead class="table-light">
        <tr><th>Producto</th><th class="text-center">Cantidad</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th></tr>
    </thead>
    <tbody>
    <?php foreach ($detalle as $d): ?>
        <tr>
            <td class="fw-bold"><?php echo h($d['producto_nombre']); ?></td>
            <td class="text-center"><?php echo (int)$d['cantidad']; ?></td>
            <td class="text-end">$<?php echo number_format((float)$d['precio_unitario'], 2); ?></td>
            <td class="text-end fw-bold">$<?php echo number_format((float)$d['subtotal'], 2); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light">
        <tr><th colspan="3" class="text-end">TOTAL</th><th class="text-end fw-bold text-success fs-5">$<?php echo number_format((float)$pedido['total'], 2); ?></th></tr>
    </tfoot>
</table>
