<?php
  include 'db.php';
  include 'header.php';
  // MEJORA 1 & 2: Solo empleados con permiso específico de Pedidos Web
  require_perm('pedidos_web');

$error   = '';
$success = '';
$ticket_id = 0;

// ─── PROCESAR ACCIÓN ─────────────────────────────────────────────────
if (isset($_GET['accion']) && isset($_GET['id'])) {
    $id     = (int)$_GET['id'];
    $accion = $_GET['accion'];
    $id_sesion = (int)($_SESSION['id_usuario'] ?? 1);

    // MEJORA 3: Validar permiso pedidos_web antes de procesar cualquier acción
    if (!has_perm('pedidos_web')) {
        $error = 'No tienes permiso para gestionar pedidos web. Contacta al administrador.';
    } elseif (!in_array($accion, ['aceptado', 'rechazado'])) {
        $error = 'Acción no válida.';
    } else {
        // ── Leer estado actual ──────────────────────────────────────
        $stCheck = $conexion->prepare("SELECT p.estado_aprobacion, p.tipo_pago, p.id_cliente, p.total FROM pedidos p WHERE id_pedido = ?");
        $stCheck->execute([$id]);
        $pedidoActual = $stCheck->fetch(PDO::FETCH_ASSOC);
        $estadoActual = $pedidoActual['estado_aprobacion'] ?? 'pendiente';

        // ── Máquina de estados: bloquear aprobado → rechazado ───────
        if ($accion === 'rechazado' && $estadoActual === 'aprobado') {
            $error = 'No se puede rechazar el pedido #' . str_pad($id, 6, '0', STR_PAD_LEFT) . ' porque ya fue aceptado y está en proceso de preparación. Si necesitas cancelarlo, contacta al cliente directamente.';

        } elseif ($accion === 'rechazado') {
            if ($estadoActual !== 'pendiente') {
                $error = 'Solo se pueden rechazar pedidos en estado Pendiente.';
            } else {
                $comentario = trim($_GET['comentario'] ?? 'Pedido rechazado.');
                $conexion->prepare("UPDATE pedidos SET estado_aprobacion = 'rechazado', comentario_aprobacion = ?, fecha_resolucion = NOW() WHERE id_pedido = ?")
                         ->execute([$comentario, $id]);
                $success = 'Pedido #' . str_pad($id, 6, '0', STR_PAD_LEFT) . ' rechazado.';
            }

        } elseif ($accion === 'aceptado') {
            if ($estadoActual !== 'pendiente') {
                $error = 'Solo se pueden aceptar pedidos en estado Pendiente.';
            } else {
                $hora = $_GET['hora'] ?? '10:00';
                if (!preg_match('/^\d{2}:\d{2}$/', $hora)) $hora = '10:00';
                $hInt = (int)str_replace(':', '', $hora);
                if ($hInt < 900)  $hora = '09:00';
                if ($hInt > 1700) $hora = '17:00';

                try {
                    $conexion->beginTransaction();

                    // 1. Actualizar estado del pedido — usar 'aprobado' para coincidir con el ENUM de la BD
                    $conexion->prepare("UPDATE pedidos SET estado = 'completado', estado_aprobacion = 'aprobado', hora_recoleccion = ?, comentario_aprobacion = 'Pedido aceptado.', fecha_resolucion = NOW() WHERE id_pedido = ?")
                             ->execute([$hora . ':00', $id]);

                    // 2. Obtener detalle del pedido
                    $stDet = $conexion->prepare("SELECT dp.id_producto, dp.cantidad, pr.nombre FROM detalle_pedido dp INNER JOIN productos pr ON dp.id_producto = pr.id_producto WHERE dp.id_pedido = ?");
                    $stDet->execute([$id]);
                    $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC);

                    $stMov   = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo_movimiento, cantidad, observaciones, origen) VALUES (?, ?, 'salida', ?, ?, 'web')");
                    $stStock = $conexion->prepare("UPDATE productos SET stock = GREATEST(0, stock - ?) WHERE id_producto = ?");

                    foreach ($detalles as $det) {
                        // 3. Descontar stock
                        $stStock->execute([$det['cantidad'], $det['id_producto']]);
                        // 4. Registrar movimiento de inventario
                        $stMov->execute([
                            $det['id_producto'],
                            $id_sesion,
                            $det['cantidad'],
                            'Pedido Web #' . str_pad($id, 6, '0', STR_PAD_LEFT) . ' — Aceptado — ' . h($det['nombre'])
                        ]);
                    }

                    // 5. Si el pago es con crédito de tienda, descontar el saldo del cliente
                    $tipoPago  = $pedidoActual['tipo_pago'] ?? '';
                    $idCliente = $pedidoActual['id_cliente'] ?? null;
                    $totalPed  = (float)($pedidoActual['total'] ?? 0);

                    if ($tipoPago === 'credito' && $idCliente && $totalPed > 0) {
                        $stCred = $conexion->prepare("SELECT id_credito, saldo_disponible FROM creditos WHERE id_usuario = ? AND estado = 'aprobado' ORDER BY id_credito DESC LIMIT 1");
                        $stCred->execute([$idCliente]);
                        $credito = $stCred->fetch(PDO::FETCH_ASSOC);
                        if ($credito && (float)$credito['saldo_disponible'] >= $totalPed) {
                            $conexion->prepare("UPDATE creditos SET saldo_disponible = saldo_disponible - ? WHERE id_credito = ?")
                                     ->execute([$totalPed, $credito['id_credito']]);
                        }
                    }

                    $conexion->commit();
                    $ticket_id = $id;
                    $success = 'Pedido #' . str_pad($id, 6, '0', STR_PAD_LEFT) . ' aceptado. Recolección: ' . date('h:i A', strtotime($hora));

                } catch (Exception $e) {
                    $conexion->rollBack();
                    $error = 'Error al procesar el pedido: ' . $e->getMessage();
                }
            }
        }
    }
}

// ─── DATOS ──────────────────────────────────────────────────────────
$pedidos = $conexion->query("
    SELECT p.*, u.nombre AS cliente
    FROM pedidos p
    INNER JOIN usuarios u ON u.id_usuario = (CASE WHEN p.id_cliente IS NOT NULL THEN p.id_cliente ELSE p.id_usuario END)
    WHERE p.tipo_pago = 'web'
       OR p.origen = 'web'
       OR p.estado_aprobacion IN ('pendiente','aprobado','rechazado')
    ORDER BY 
        CASE p.estado_aprobacion WHEN 'pendiente' THEN 0 WHEN 'aprobado' THEN 1 ELSE 2 END,
        p.fecha DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pendientes_count = count(array_filter($pedidos, fn($p) => ($p['estado_aprobacion'] ?? '') === 'pendiente'));

// Labels para tipo_pago
function labelPago($tp) {
    return match($tp) {
        'efectivo'      => ['🟢 Efectivo',    'bg-success'],
        'tarjeta'       => ['💳 Tarjeta',      'bg-info text-dark'],
        'transferencia' => ['🏦 Transferencia','bg-primary'],
        'credito'       => ['📋 Crédito',      'bg-warning text-dark'],
        'web'           => ['🌐 Web',          'bg-secondary'],
        default         => [ucfirst($tp),       'bg-secondary'],
    };
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-0">Pedidos Web</h3>
        <small class="text-muted">Solicitudes de clientes registrados</small>
    </div>
    <?php if ($pendientes_count > 0): ?>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2">
            <i class="bi bi-clock me-1"></i><?php echo $pendientes_count; ?> pendiente<?php echo $pendientes_count > 1 ? 's' : ''; ?>
        </span>
    <?php endif; ?>
</div>

<?php if ($error): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="bi bi-shield-exclamation fs-5 flex-shrink-0"></i>
    <div><?php echo h($error); ?></div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <strong><?php echo h($success); ?></strong>
    </div>
    <?php if ($ticket_id > 0): ?>
    <div class="d-flex gap-2 mt-2 flex-wrap">
        <button class="btn btn-sm btn-dark" onclick="abrirTicket(<?php echo $ticket_id; ?>, 'interno')">
            <i class="bi bi-printer me-1"></i>Ticket Interno (Almacén)
        </button>
        <button class="btn btn-sm btn-outline-dark" onclick="abrirTicket(<?php echo $ticket_id; ?>, 'cliente')">
            <i class="bi bi-receipt me-1"></i>Ticket Cliente
        </button>
    </div>
    <script>
        // Abrir ambos tickets automáticamente
        (function() {
            setTimeout(() => abrirTicket(<?php echo $ticket_id; ?>, 'interno'), 300);
            setTimeout(() => abrirTicket(<?php echo $ticket_id; ?>, 'cliente'), 700);
        })();
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card card-custom border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Folio</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Total</th>
                    <th>Método de Pago</th>
                    <th>Estado</th>
                    <th>Recolección</th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pedidos)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox" style="font-size:2rem;opacity:.2;display:block;margin-bottom:8px;"></i>
                    No hay pedidos web aún.
                </td></tr>
            <?php else: foreach ($pedidos as $p):
                $ea = $p['estado_aprobacion'] ?? 'pendiente';
                if ($ea === 'pendiente')    { $col = 'bg-warning text-dark'; $txt = 'Pendiente'; }
                elseif ($ea === 'aprobado') { $col = 'bg-success';           $txt = 'Aceptado'; }
                else                        { $col = 'bg-danger';            $txt = 'Rechazado'; }
                [$pagoLabel, $pagoBadge] = labelPago($p['tipo_pago'] ?? 'web');
            ?>
            <tr>
                <td class="ps-3 fw-bold">#<?php echo str_pad($p['id_pedido'], 6, '0', STR_PAD_LEFT); ?></td>
                <td class="small"><?php echo date("d/m H:i", strtotime($p['fecha'])); ?></td>
                <td class="fw-bold"><?php echo h($p['cliente']); ?></td>
                <td class="fw-bold text-success">$<?php echo number_format((float)$p['total'], 2); ?></td>
                <td>
                    <span class="badge <?php echo $pagoBadge; ?> px-2" style="font-size:.78rem;">
                        <?php echo $pagoLabel; ?>
                    </span>
                </td>
                <td><span class="badge <?php echo $col; ?> px-3"><?php echo $txt; ?></span></td>
                <td>
                    <?php if ($ea === 'aprobado' && !empty($p['hora_recoleccion'])): ?>
                        <span class="fw-bold text-success small"><i class="bi bi-clock me-1"></i><?php echo date('h:i A', strtotime($p['hora_recoleccion'])); ?></span>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end pe-3">
                    <button class="btn btn-sm btn-outline-dark me-1" onclick="verDetallePedido(<?php echo (int)$p['id_pedido']; ?>)" title="Ver detalle">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if ($ea === 'aprobado'): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="abrirTicket(<?php echo (int)$p['id_pedido']; ?>, 'cliente')" title="Reimprimir ticket">
                            <i class="bi bi-receipt"></i>
                        </button>
                    <?php elseif ($ea === 'pendiente'): ?>
                        <button class="btn btn-sm btn-success me-1" 
                                onclick="abrirModalAceptar(<?php echo (int)$p['id_pedido']; ?>, '<?php echo h($p['cliente']); ?>', '<?php echo h($p['tipo_pago']); ?>')">
                            <i class="bi bi-check-lg me-1"></i>Aceptar
                        </button>
                        <button class="btn btn-sm btn-danger"
                                onclick="abrirModalRechazar(<?php echo (int)$p['id_pedido']; ?>, '<?php echo h($p['cliente']); ?>')">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>

<!-- Modal: Aceptar pedido -->
<div class="modal fade" id="modalAceptar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-check-circle me-2"></i>Aceptar Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Cliente: <strong id="clienteAceptar"></strong></p>
                <p class="mb-3 small text-muted">Método de pago solicitado: <strong id="pagoAceptar" class="text-primary"></strong></p>
                <label class="fw-bold small text-muted">HORA DE RECOLECCIÓN</label>
                <p class="small text-muted mb-2">Horario disponible: <strong>9:00 AM – 5:00 PM</strong></p>
                <input type="time" id="horaRecoleccion" class="form-control form-control-lg text-center fw-bold" 
                       value="10:00" min="09:00" max="17:00">
                <div class="mt-2 d-flex flex-wrap gap-1" id="horasRapidas"></div>
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Al aceptar: el stock se descuenta, se generan <strong>2 tickets</strong> (almacén + cliente) y el movimiento queda registrado.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold" onclick="confirmarAceptar()">
                    <i class="bi bi-check-lg me-1"></i>Confirmar y Generar Tickets
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Rechazar pedido -->
<div class="modal fade" id="modalRechazar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Rechazar Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Cliente: <strong id="clienteRechazar"></strong></p>
                <div class="alert alert-warning small py-2">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    Solo se pueden rechazar pedidos <strong>pendientes</strong>. Los pedidos ya aceptados no pueden rechazarse.
                </div>
                <label class="fw-bold small text-muted">MOTIVO DEL RECHAZO</label>
                <textarea id="motivoRechazo" class="form-control" rows="3" placeholder="Ej: Producto sin stock, pedido duplicado..."></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger fw-bold" onclick="confirmarRechazar()">
                    <i class="bi bi-x-lg me-1"></i>Confirmar Rechazo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ver detalle -->
<div class="modal fade" id="modalDetallePedido" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-list-ul me-2"></i>Detalle del Pedido</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detallePedidoBody">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let pedidoIdActual = 0;

function abrirModalAceptar(id, cliente, pago) {
    pedidoIdActual = id;
    document.getElementById('clienteAceptar').textContent = cliente;
    document.getElementById('pagoAceptar').textContent = pago || '—';

    const contenedor = document.getElementById('horasRapidas');
    contenedor.innerHTML = '';
    ['09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00'].forEach(h => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-success btn-sm';
        const [hh] = h.split(':');
        const ampm = parseInt(hh) < 12 ? 'AM' : 'PM';
        const h12  = parseInt(hh) > 12 ? parseInt(hh) - 12 : parseInt(hh);
        btn.textContent = h12 + ':00 ' + ampm;
        btn.onclick = () => { document.getElementById('horaRecoleccion').value = h; };
        contenedor.appendChild(btn);
    });

    new bootstrap.Modal(document.getElementById('modalAceptar')).show();
}

function confirmarAceptar() {
    const hora = document.getElementById('horaRecoleccion').value;
    if (!hora) { alert('Selecciona una hora de recolección.'); return; }
    window.location.href = 'pedidos.php?id=' + pedidoIdActual + '&accion=aceptado&hora=' + encodeURIComponent(hora);
}

function abrirModalRechazar(id, cliente) {
    pedidoIdActual = id;
    document.getElementById('clienteRechazar').textContent = cliente;
    document.getElementById('motivoRechazo').value = '';
    new bootstrap.Modal(document.getElementById('modalRechazar')).show();
}

function confirmarRechazar() {
    const motivo = document.getElementById('motivoRechazo').value.trim() || 'Pedido rechazado por el administrador.';
    window.location.href = 'pedidos.php?id=' + pedidoIdActual + '&accion=rechazado&comentario=' + encodeURIComponent(motivo);
}

function verDetallePedido(id) {
    document.getElementById('detallePedidoBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
    new bootstrap.Modal(document.getElementById('modalDetallePedido')).show();
    fetch('ver_detalle_pedido.php?id=' + id)
        .then(r => r.text())
        .then(html => { document.getElementById('detallePedidoBody').innerHTML = html; })
        .catch(() => { document.getElementById('detallePedidoBody').innerHTML = '<p class="text-center text-muted py-4">Error al cargar.</p>'; });
}

function abrirTicket(id, tipo) {
    const url = 'ver_ticket.php?id=' + id + '&tipo=' + tipo;
    window.open(url, '_blank', 'width=420,height=650,scrollbars=yes');
}
</script>
</body></html>
