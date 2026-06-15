<?php
session_start();
require_once 'db.php';
require_once 'app_helpers.php';

if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['admin','empleado','vendedor'])) {
    echo '<p class="text-danger">Acceso denegado.</p>';
    exit;
}

$id_pedido = (int)($_GET['id'] ?? 0);

$stP = $conexion->prepare("
    SELECT p.*, u.nombre AS cliente_nombre, COALESCE(u.correo,'') AS cliente_correo
    FROM pedidos p
    LEFT JOIN usuarios u ON u.id_usuario = (CASE WHEN p.id_cliente IS NOT NULL THEN p.id_cliente ELSE p.id_usuario END)
    WHERE p.id_pedido = ?
");
$stP->execute([$id_pedido]);
$pedido = $stP->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    echo '<p class="text-center text-muted py-4">Pedido no encontrado.</p>';
    exit;
}

$stD = $conexion->prepare("
    SELECT dp.*, pr.nombre AS producto_nombre, pr.numero_lote
    FROM detalle_pedido dp
    INNER JOIN productos pr ON dp.id_producto = pr.id_producto
    WHERE dp.id_pedido = ?
");
$stD->execute([$id_pedido]);
$detalle = $stD->fetchAll(PDO::FETCH_ASSOC);

$ea = $pedido['estado_aprobacion'] ?? 'pendiente';

// Mapa método de pago
$pagoIconos = [
    'efectivo'      => ['🟢', 'Efectivo',             'text-success'],
    'tarjeta'       => ['💳', 'Tarjeta',               'text-info'],
    'transferencia' => ['🏦', 'Transferencia',          'text-primary'],
    'credito'       => ['📋', 'Crédito en cuenta',      'text-warning'],
    'web'           => ['🌐', 'Web (por confirmar)',    'text-secondary'],
];
$tp = $pedido['tipo_pago'] ?? 'web';
[$pagoIco, $pagoTxt, $pagoColor] = $pagoIconos[$tp] ?? ['💬', ucfirst($tp), 'text-muted'];
?>
<div class="px-1">
    <!-- Encabezado pedido -->
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <div class="fw-bold fs-6 mb-1">#<?php echo str_pad($pedido['id_pedido'], 6, '0', STR_PAD_LEFT); ?> — <?php echo h($pedido['cliente_nombre']); ?></div>
            <div class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($pedido['fecha'])); ?></div>
        </div>
        <div class="text-end">
            <?php if ($ea === 'aprobado'): ?>
                <span class="badge bg-success px-3 py-2">✅ Aceptado</span>
                <?php if (!empty($pedido['hora_recoleccion'])): ?>
                    <div class="small text-success mt-1 fw-bold"><i class="bi bi-clock me-1"></i><?php echo date('h:i A', strtotime($pedido['hora_recoleccion'])); ?></div>
                <?php endif; ?>
            <?php elseif ($ea === 'rechazado'): ?>
                <span class="badge bg-danger px-3 py-2">❌ Rechazado</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark px-3 py-2">⏳ Pendiente</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Método de Pago (prominente) -->
    <div class="p-3 mb-3 rounded-3 border" style="background:#f8fff8;border-color:#a5d6a7 !important;">
        <div class="small fw-bold text-muted mb-1" style="letter-spacing:.08em;">MÉTODO DE PAGO DEL CLIENTE</div>
        <div class="fw-bold fs-5 <?php echo $pagoColor; ?>">
            <?php echo $pagoIco; ?> <?php echo h($pagoTxt); ?>
        </div>
        <?php if ($tp === 'web'): ?>
            <div class="small text-muted mt-1">El cliente no especificó método — confirmar al momento de la entrega.</div>
        <?php elseif ($tp === 'transferencia'): ?>
            <div class="small text-muted mt-1">⚠ Verificar comprobante de transferencia antes de entregar el pedido.</div>
        <?php elseif ($tp === 'credito'): ?>
            <div class="small text-muted mt-1">⚠ Verificar saldo de crédito disponible antes de entregar.</div>
        <?php endif; ?>
    </div>

    <!-- Productos -->
    <table class="table table-sm align-middle mb-2">
        <thead class="table-light">
            <tr>
                <th>Producto</th>
                <th class="text-center">Cant.</th>
                <th class="text-end">Precio</th>
                <th class="text-end">Subtotal</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detalle as $d): ?>
            <tr>
                <td>
                    <div class="fw-bold small"><?php echo h($d['producto_nombre']); ?></div>
                    <?php if (!empty($d['numero_lote'])): ?>
                        <div class="text-muted" style="font-size:.72rem;">Lote: <?php echo h($d['numero_lote']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?php echo (int)$d['cantidad']; ?></td>
                <td class="text-end small">$<?php echo number_format((float)$d['precio_unitario'], 2); ?></td>
                <td class="text-end fw-bold">$<?php echo number_format((float)$d['subtotal'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
            <tr>
                <th colspan="3" class="text-end">TOTAL</th>
                <th class="text-end text-success fs-5 fw-bold">$<?php echo number_format((float)$pedido['total'], 2); ?></th>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($pedido['comentario_aprobacion'])): ?>
        <div class="alert alert-light border small py-2 mb-2">
            <i class="bi bi-chat-left-text me-1"></i><?php echo h($pedido['comentario_aprobacion']); ?>
        </div>
    <?php endif; ?>

    <?php if ($ea === 'aprobado'): ?>
    <div class="d-flex gap-2 mt-2">
        <a href="ver_ticket.php?id=<?php echo (int)$pedido['id_pedido']; ?>&tipo=interno" target="_blank"
           class="btn btn-sm btn-dark">
            <i class="bi bi-printer me-1"></i>Ticket Almacén
        </a>
        <a href="ver_ticket.php?id=<?php echo (int)$pedido['id_pedido']; ?>&tipo=cliente" target="_blank"
           class="btn btn-sm btn-outline-dark">
            <i class="bi bi-receipt me-1"></i>Ticket Cliente
        </a>
    </div>
    <?php endif; ?>

    <?php
    // ─── HISTORIAL DE PEDIDOS DEL CLIENTE ───────────────────────────
    $idClienteHist = $pedido['id_cliente'] ?? null;
    if ($idClienteHist):
        $stHist = $conexion->prepare("
            SELECT p.id_pedido, p.fecha, p.total, p.estado_aprobacion, p.tipo_pago, p.origen
            FROM pedidos p
            WHERE p.id_cliente = ?
              AND p.id_pedido  <> ?
            ORDER BY p.fecha DESC
            LIMIT 10
        ");
        $stHist->execute([$idClienteHist, $id_pedido]);
        $historial = $stHist->fetchAll(PDO::FETCH_ASSOC);
    else:
        $historial = [];
    endif;
    ?>

    <?php if (!empty($historial)): ?>
    <div class="mt-4">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-clock-history text-muted"></i>
            <span class="fw-bold small text-muted" style="letter-spacing:.06em;text-transform:uppercase;">Historial del cliente</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr>
                        <th>Folio</th>
                        <th>Fecha</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Origen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historial as $h):
                    $hEa = $h['estado_aprobacion'] ?? 'pendiente';
                    if ($hEa === 'aprobado')  { $hCol = 'bg-success'; $hTxt = 'Aceptado'; }
                    elseif ($hEa === 'rechazado') { $hCol = 'bg-danger';  $hTxt = 'Rechazado'; }
                    else                      { $hCol = 'bg-warning text-dark'; $hTxt = 'Pendiente'; }
                ?>
                <tr>
                    <td class="fw-bold text-muted">#<?php echo str_pad($h['id_pedido'],6,'0',STR_PAD_LEFT); ?></td>
                    <td class="text-muted"><?php echo date('d/m/Y H:i', strtotime($h['fecha'])); ?></td>
                    <td class="fw-bold text-success">$<?php echo number_format((float)$h['total'],2); ?></td>
                    <td><span class="badge <?php echo $hCol; ?> px-2"><?php echo $hTxt; ?></span></td>
                    <td class="text-muted"><?php echo ucfirst(h($h['origen'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
