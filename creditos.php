<?php
  include 'db.php';
  include 'header.php';
  require_perm('creditos'); // MEJORA 2: Solo empleados con permiso de créditos

  
$error   = '';
$success = '';

// ─── ELIMINAR CRÉDITO (solo admin) ──────────────────────────────────
if (isset($_GET['borrar'])) {
    if ($_SESSION['rol'] !== 'admin') { $error = 'Acceso denegado.'; }
    else {
        try {
            $conexion->prepare("DELETE FROM creditos WHERE id_credito = ?")->execute([(int)$_GET['borrar']]);
            $success = 'Cuenta de crédito eliminada.';
        } catch (Exception $e) { $error = 'No se puede eliminar: tiene historial asociado.'; }
    }
}

// ─── APROBAR SOLICITUD DE CRÉDITO (solo admin) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'aprobar_solicitud') {
    if ($_SESSION['rol'] !== 'admin') { $error = 'Acceso denegado.'; }
    else {
        $id_credito = (int)$_POST['id_credito'];
        $limite     = (float)$_POST['monto_total'];
        $comentario = trim($_POST['comentario'] ?? 'Crédito aprobado.');
        if ($limite <= 0) { $error = 'El límite debe ser mayor a cero.'; }
        else {
            $conexion->prepare("UPDATE creditos SET estado = 'aprobado', monto_total = ?, saldo_disponible = ?, comentario_revision = ?, fecha_autorizacion = NOW() WHERE id_credito = ?")
                     ->execute([$limite, $limite, $comentario, $id_credito]);
            $success = 'Crédito aprobado con límite de $' . number_format($limite, 2) . '.';
        }
    }
}

// ─── RECHAZAR SOLICITUD DE CRÉDITO (solo admin) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'rechazar_solicitud') {
    if ($_SESSION['rol'] !== 'admin') { $error = 'Acceso denegado.'; }
    else {
        $id_credito = (int)$_POST['id_credito'];
        $comentario = trim($_POST['comentario'] ?? 'Solicitud rechazada.');
        $conexion->prepare("UPDATE creditos SET estado = 'rechazado', comentario_revision = ? WHERE id_credito = ?")
                 ->execute([$comentario, $id_credito]);
        $success = 'Solicitud de crédito rechazada.';
    }
}

// ─── REGISTRAR ABONO ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'abonar') {
    $id_credito = (int)$_POST['id_credito'];
    $monto      = (float)$_POST['monto_pago'];
    $obs        = trim($_POST['observaciones'] ?? '') ?: 'Abono en efectivo';

    if ($monto <= 0) { $error = 'El monto debe ser mayor a cero.'; }
    else {
        try {
            $conexion->beginTransaction();
            $stmtC = $conexion->prepare("SELECT * FROM creditos WHERE id_credito = ? FOR UPDATE");
            $stmtC->execute([$id_credito]);
            $cred = $stmtC->fetch();
            if (!$cred) throw new Exception('Crédito no encontrado.');
            $deuda_actual = (float)$cred['monto_total'] - (float)$cred['saldo_disponible'];
            if ($monto > $deuda_actual + 0.001) throw new Exception('El abono supera la deuda actual ($' . number_format($deuda_actual,2) . ').');
            $nuevo_saldo = min((float)$cred['saldo_disponible'] + $monto, (float)$cred['monto_total']);
            $conexion->prepare("INSERT INTO pagos_credito (id_credito, monto_pagado, observaciones) VALUES (?,?,?)")
                     ->execute([$id_credito, $monto, $obs . ' — por ' . ($_SESSION['nombre'] ?? 'Sistema')]);
            $conexion->prepare("UPDATE creditos SET saldo_disponible = ? WHERE id_credito = ?")
                     ->execute([$nuevo_saldo, $id_credito]);
            $conexion->commit();
            $success = 'Abono de $' . number_format($monto,2) . ' registrado correctamente.';
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ─── CREAR CRÉDITO DIRECTO (solo admin) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_credito') {
    if ($_SESSION['rol'] !== 'admin') { $error = 'Acceso denegado.'; }
    else {
        $id_usuario = (int)$_POST['id_usuario'];
        $limite     = (float)$_POST['monto_total'];
        if ($limite <= 0) { $error = 'El límite debe ser mayor a cero.'; }
        else {
            $check = $conexion->prepare("SELECT id_credito FROM creditos WHERE id_usuario = ? AND estado != 'rechazado'");
            $check->execute([$id_usuario]);
            if ($check->rowCount() > 0) { $error = 'Este usuario ya tiene un crédito activo o pendiente.'; }
            else {
                $conexion->prepare("INSERT INTO creditos (id_usuario, monto_total, saldo_disponible, estado, fecha_autorizacion) VALUES (?,?,?,'aprobado',NOW())")
                         ->execute([$id_usuario, $limite, $limite]);
                $success = 'Crédito aperturado por $' . number_format($limite,2) . '.';
            }
        }
    }
}

// ─── ACTUALIZAR LÍMITE (solo admin) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_limite') {
    if ($_SESSION['rol'] !== 'admin') { $error = 'Acceso denegado.'; }
    else {
        $id_credito   = (int)$_POST['id_credito'];
        $nuevo_limite = (float)$_POST['nuevo_limite'];
        if ($nuevo_limite <= 0) { $error = 'El límite debe ser mayor a cero.'; }
        else {
            $cred = $conexion->prepare("SELECT saldo_disponible, monto_total FROM creditos WHERE id_credito=?");
            $cred->execute([$id_credito]);
            $row = $cred->fetch();
            $deuda = (float)$row['monto_total'] - (float)$row['saldo_disponible'];
            $nuevo_saldo = max(0, $nuevo_limite - $deuda);
            $conexion->prepare("UPDATE creditos SET monto_total=?, saldo_disponible=? WHERE id_credito=?")
                     ->execute([$nuevo_limite, $nuevo_saldo, $id_credito]);
            $success = 'Límite actualizado a $' . number_format($nuevo_limite,2) . '.';
        }
    }
}

// ─── CONSULTAS ──────────────────────────────────────────────────────
// Solicitudes pendientes (solo admin las ve con botones)
$solicitudes_pendientes = $conexion->query("
    SELECT c.*, u.nombre AS cliente, u.correo
    FROM creditos c
    INNER JOIN usuarios u ON c.id_usuario = u.id_usuario
    WHERE c.estado = 'pendiente'
    ORDER BY c.fecha_solicitud ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Créditos aprobados
$creditos = $conexion->query("
    SELECT c.*, u.nombre AS cliente, u.correo
    FROM creditos c
    INNER JOIN usuarios u ON c.id_usuario = u.id_usuario
    WHERE c.estado = 'aprobado'
    ORDER BY c.id_credito DESC
")->fetchAll(PDO::FETCH_ASSOC);

$clientes = $conexion->query("SELECT * FROM usuarios WHERE rol = 'cliente' ORDER BY nombre ASC")->fetchAll();

$total_deuda   = 0;
$total_limites = 0;
$total_saldo   = 0;
foreach ($creditos as $c) {
    $total_deuda   += (float)$c['monto_total'] - (float)$c['saldo_disponible'];
    $total_limites += (float)$c['monto_total'];
    $total_saldo   += (float)$c['saldo_disponible'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">Cartera de Créditos</h3>
        <small class="text-muted">Solicitudes, aprobaciones y cuentas activas</small>
    </div>
    <?php if ($_SESSION['rol'] === 'admin'): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoCredito">
            <i class="bi bi-person-plus-fill me-2"></i>Aperturar Crédito
        </button>
    <?php endif; ?>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?php echo h($success); ?></div><?php endif; ?>

<!-- ═══ SOLICITUDES PENDIENTES (solo admin) ══════════════════════════ -->
<?php if ($_SESSION['rol'] === 'admin' && !empty($solicitudes_pendientes)): ?>
<div class="card card-custom border-0 border-start border-4 border-warning shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="fw-bold mb-0 text-warning"><i class="bi bi-clock-history me-2"></i>Solicitudes Pendientes</h5>
        <span class="badge bg-warning text-dark fs-6 px-3"><?php echo count($solicitudes_pendientes); ?> solicitud<?php echo count($solicitudes_pendientes) > 1 ? 'es' : ''; ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Cliente</th>
                    <th>Monto Solicitado</th>
                    <th>Motivo</th>
                    <th>Fecha</th>
                    <th class="text-end pe-3">Acción (solo Admin)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($solicitudes_pendientes as $sol): ?>
            <tr>
                <td class="ps-3">
                    <div class="fw-bold"><?php echo h($sol['cliente']); ?></div>
                    <small class="text-muted"><?php echo h($sol['correo']); ?></small>
                </td>
                <td class="fw-bold text-primary fs-5">$<?php echo number_format((float)$sol['monto_solicitado'], 2); ?></td>
                <td class="small text-muted fst-italic"><?php echo h($sol['motivo_solicitud'] ?: '—'); ?></td>
                <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($sol['fecha_solicitud'])); ?></td>
                <td class="text-end pe-3">
                    <button class="btn btn-sm btn-success fw-bold me-1"
                            onclick='abrirModalAprobar(<?php echo json_encode($sol); ?>)'>
                        <i class="bi bi-check-lg me-1"></i>Aprobar
                    </button>
                    <button class="btn btn-sm btn-danger fw-bold"
                            onclick='abrirModalRechazarCredito(<?php echo json_encode($sol); ?>)'>
                        <i class="bi bi-x-lg me-1"></i>Rechazar
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══ KPIs ════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card card-custom p-3 border-0 border-start border-4 border-danger shadow-sm">
            <div class="text-muted small fw-bold text-uppercase">Total por cobrar</div>
            <div class="fs-2 fw-bold text-danger">$<?php echo number_format($total_deuda, 2); ?></div>
            <small class="text-muted">Suma de deudas activas</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom p-3 border-0 border-start border-4 border-success shadow-sm">
            <div class="text-muted small fw-bold text-uppercase">Saldo disponible</div>
            <div class="fs-2 fw-bold text-success">$<?php echo number_format($total_saldo, 2); ?></div>
            <small class="text-muted">Crédito no utilizado</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom p-3 border-0 border-start border-4 border-primary shadow-sm">
            <div class="text-muted small fw-bold text-uppercase">Total créditos otorgados</div>
            <div class="fs-2 fw-bold text-primary">$<?php echo number_format($total_limites, 2); ?></div>
            <small class="text-muted"><?php echo count($creditos); ?> cuentas activas</small>
        </div>
    </div>
</div>

<!-- ═══ TABLA CRÉDITOS ACTIVOS ══════════════════════════════════════ -->
<div class="card card-custom border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-credit-card me-2 text-primary"></i>Créditos Activos</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Cliente</th>
                    <th>Límite</th>
                    <th>Disponible</th>
                    <th>Deuda</th>
                    <th style="width:22%">Uso</th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($creditos)): ?>
                <tr><td colspan="6" class="text-center py-5 text-muted">
                    <i class="bi bi-credit-card" style="font-size:2rem;opacity:.2;display:block;margin-bottom:8px;"></i>
                    No hay créditos activos.
                </td></tr>
            <?php else: foreach ($creditos as $c):
                $deuda = (float)$c['monto_total'] - (float)$c['saldo_disponible'];
                $pct   = $c['monto_total'] > 0 ? ($deuda / $c['monto_total']) * 100 : 0;
                $color = $pct > 80 ? 'bg-danger' : ($pct > 40 ? 'bg-warning' : 'bg-success');
            ?>
            <tr>
                <td class="ps-3">
                    <div class="fw-bold"><?php echo h($c['cliente']); ?></div>
                    <small class="text-muted"><?php echo h($c['correo']); ?></small>
                </td>
                <td class="fw-bold text-secondary">$<?php echo number_format((float)$c['monto_total'], 2); ?></td>
                <td class="fw-bold text-success">$<?php echo number_format((float)$c['saldo_disponible'], 2); ?></td>
                <td>
                    <?php if ($deuda > 0.01): ?>
                        <span class="fw-bold text-danger">$<?php echo number_format($deuda, 2); ?></span>
                    <?php else: ?>
                        <span class="badge bg-success-subtle text-success border">Sin deuda</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Usado</span><span class="fw-bold"><?php echo round($pct); ?>%</span>
                    </div>
                    <div class="progress" style="height:7px;border-radius:4px;">
                        <div class="progress-bar <?php echo $color; ?>" style="width:<?php echo $pct; ?>%;border-radius:4px;"></div>
                    </div>
                </td>
                <td class="text-end pe-3">
                    <?php if ($deuda > 0.01): ?>
                        <button class="btn btn-sm btn-success fw-bold me-1"
                                onclick='abrirModalAbono(<?php echo json_encode($c); ?>, <?php echo $deuda; ?>)'>
                            <i class="bi bi-cash me-1"></i>Abonar
                        </button>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border me-1">Al día</span>
                    <?php endif; ?>
                    <?php if ($_SESSION['rol'] === 'admin'): ?>
                        <button class="btn btn-sm btn-outline-secondary me-1"
                                onclick='abrirModalLimite(<?php echo json_encode($c); ?>)' title="Editar límite">
                            <i class="bi bi-sliders"></i>
                        </button>
                        <a href="creditos.php?borrar=<?php echo (int)$c['id_credito']; ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('¿Eliminar la cuenta de crédito de <?php echo h($c['cliente']); ?>?');">
                            <i class="bi bi-trash"></i>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>

<!-- Modal: Aprobar Solicitud (solo admin) -->
<div class="modal fade" id="modalAprobar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-check-circle me-2"></i>Aprobar Solicitud de Crédito</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="formAprobar">
                    <input type="hidden" name="accion" value="aprobar_solicitud">
                    <input type="hidden" name="id_credito" id="idAprobar">
                    <p>Cliente: <strong id="clienteAprobar"></strong></p>
                    <div class="alert alert-info small py-2">Monto solicitado: <strong>$<span id="montoSolicitado"></span></strong></div>
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">LÍMITE DE CRÉDITO A OTORGAR ($)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_total" id="montoAprobar" class="form-control fw-bold" step="0.01" min="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">COMENTARIO (OPCIONAL)</label>
                        <input type="text" name="comentario" class="form-control" placeholder="Crédito aprobado." value="Crédito aprobado.">
                    </div>
                    <button type="submit" class="btn btn-success w-100 fw-bold">Confirmar Aprobación</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Rechazar Solicitud (solo admin) -->
<div class="modal fade" id="modalRechazarCredito" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Rechazar Solicitud</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="rechazar_solicitud">
                    <input type="hidden" name="id_credito" id="idRechazarCredito">
                    <p>Cliente: <strong id="clienteRechazarCredito"></strong></p>
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">MOTIVO DEL RECHAZO</label>
                        <textarea name="comentario" class="form-control" rows="3" placeholder="Ej: Historial crediticio insuficiente..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger w-100 fw-bold">Confirmar Rechazo</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Crédito Directo -->
<div class="modal fade" id="modalNuevoCredito" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Aperturar Crédito</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="crear_credito">
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">CLIENTE</label>
                        <select name="id_usuario" class="form-select" required>
                            <option value="">Seleccione un cliente...</option>
                            <?php foreach ($clientes as $cli): ?>
                                <option value="<?php echo (int)$cli['id_usuario']; ?>"><?php echo h($cli['nombre']); ?> — <?php echo h($cli['correo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="fw-bold small text-muted">LÍMITE ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_total" class="form-control form-control-lg fw-bold" placeholder="0.00" step="0.01" min="1" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Aperturar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Abonar -->
<div class="modal fade" id="modalAbono" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-cash me-2"></i>Registrar Abono</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="abonar">
                    <input type="hidden" name="id_credito" id="idCreditoAbono">
                    <div class="text-center mb-3 p-3 bg-light rounded">
                        <div class="fw-bold fs-6 mb-1" id="clienteAbono"></div>
                        <div class="small text-muted mb-1">Deuda actual</div>
                        <div class="fs-4 fw-bold text-danger">$<span id="deudaAbono"></span></div>
                    </div>
                    <div class="mb-2">
                        <label class="fw-bold small text-muted">MONTO A ABONAR</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_pago" id="montoAbono" class="form-control form-control-lg fw-bold text-center text-success" step="0.01" min="0.01" required oninput="calcularAbono()">
                        </div>
                        <div id="msgAbono" class="small mt-1"></div>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">OBSERVACIÓN</label>
                        <input type="text" name="observaciones" class="form-control" placeholder="Ej. Pago en efectivo...">
                    </div>
                    <div class="d-flex gap-1 mb-3" id="botonesRapidos"></div>
                    <button type="submit" class="btn btn-success w-100 fw-bold" id="btnConfirmarAbono">Confirmar Abono</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Editar Límite -->
<div class="modal fade" id="modalLimite" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-sliders me-2"></i>Editar Límite</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="actualizar_limite">
                    <input type="hidden" name="id_credito" id="idCreditoLimite">
                    <div class="text-center mb-3">
                        <div class="fw-bold" id="clienteLimite"></div>
                        <small class="text-muted">Límite actual: $<span id="limiteActual"></span></small>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-muted">NUEVO LÍMITE ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="nuevo_limite" class="form-control form-control-lg fw-bold" step="0.01" min="1" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Actualizar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let deudaActual = 0;

function abrirModalAprobar(sol) {
    document.getElementById('idAprobar').value        = sol.id_credito;
    document.getElementById('clienteAprobar').textContent = sol.cliente;
    document.getElementById('montoSolicitado').textContent = parseFloat(sol.monto_solicitado || 0).toFixed(2);
    document.getElementById('montoAprobar').value     = parseFloat(sol.monto_solicitado || 0).toFixed(2);
    new bootstrap.Modal(document.getElementById('modalAprobar')).show();
}

function abrirModalRechazarCredito(sol) {
    document.getElementById('idRechazarCredito').value        = sol.id_credito;
    document.getElementById('clienteRechazarCredito').textContent = sol.cliente;
    new bootstrap.Modal(document.getElementById('modalRechazarCredito')).show();
}

function abrirModalAbono(credito, deuda) {
    deudaActual = parseFloat(deuda);
    document.getElementById('idCreditoAbono').value   = credito.id_credito;
    document.getElementById('clienteAbono').innerText  = credito.cliente;
    document.getElementById('deudaAbono').innerText    = deudaActual.toFixed(2);
    document.getElementById('montoAbono').value        = '';
    document.getElementById('montoAbono').max          = deudaActual;
    document.getElementById('msgAbono').innerHTML      = '';

    const btns = document.getElementById('botonesRapidos');
    btns.innerHTML = '';
    [0.25, 0.5, 0.75, 1].forEach(pct => {
        const monto = (deudaActual * pct).toFixed(2);
        const label = pct === 1 ? 'Total' : (pct*100)+'%';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-success btn-sm flex-fill';
        btn.innerHTML = `${label}<br><small>$${monto}</small>`;
        btn.onclick = () => { document.getElementById('montoAbono').value = monto; calcularAbono(); };
        btns.appendChild(btn);
    });
    new bootstrap.Modal(document.getElementById('modalAbono')).show();
}

function calcularAbono() {
    const monto = parseFloat(document.getElementById('montoAbono').value) || 0;
    const msg   = document.getElementById('msgAbono');
    const btn   = document.getElementById('btnConfirmarAbono');
    if (monto <= 0) { msg.innerHTML = ''; btn.disabled = true; return; }
    if (monto > deudaActual + 0.001) {
        msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>El abono supera la deuda.</span>';
        btn.disabled = true;
    } else {
        const resto = Math.max(0, deudaActual - monto);
        msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Saldo pendiente: <strong>$' + resto.toFixed(2) + '</strong></span>';
        btn.disabled = false;
    }
}

function abrirModalLimite(credito) {
    document.getElementById('idCreditoLimite').value   = credito.id_credito;
    document.getElementById('clienteLimite').innerText = credito.cliente;
    document.getElementById('limiteActual').innerText  = parseFloat(credito.monto_total).toFixed(2);
    new bootstrap.Modal(document.getElementById('modalLimite')).show();
}
</script>
</body></html>
