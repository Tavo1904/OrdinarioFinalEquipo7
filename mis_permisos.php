<?php
// ── MEJORA 3: Visualización de permisos asignados al empleado actual ──────────
include 'db.php';
include 'header.php';

$id = (int)$_SESSION['id_usuario'];

// Obtener datos del usuario actual
$stmtU = $conexion->prepare("SELECT nombre, correo, rol, estado, fecha_registro FROM usuarios WHERE id_usuario = ? LIMIT 1");
$stmtU->execute([$id]);
$usuario = $stmtU->fetch(PDO::FETCH_ASSOC);

// Obtener permisos actuales desde la BD (siempre frescos)
$permisos = get_permisos_usuario($conexion, $id);

$permisosConfig = [
    'ventas'        => ['label' => 'Ventas / Punto de Venta',        'icon' => 'bi-cart3',                   'desc' => 'Acceso al punto de venta (POS)',                          'modulos' => 'pos.php'],
    'pedidos_web'   => ['label' => 'Aprobación de Pedidos Web',      'icon' => 'bi-bag-check-fill',          'desc' => 'Aceptar o rechazar pedidos realizados desde la plataforma web', 'modulos' => 'pedidos.php'],
    'inventario'    => ['label' => 'Inventario',                     'icon' => 'bi-box-seam',                'desc' => 'Ver y editar productos en inventario y movimientos',      'modulos' => 'inventario.php, movimientos.php'],
    'compras'       => ['label' => 'Compras',                        'icon' => 'bi-truck',                   'desc' => 'Gestión de proveedores y facturas de compra',            'modulos' => 'proveedores.php, facturas.php'],
    'clientes'      => ['label' => 'Clientes',                       'icon' => 'bi-person-vcard',            'desc' => 'Administrar información y datos de clientes',             'modulos' => 'clientes.php'],
    'creditos'      => ['label' => 'Créditos',                       'icon' => 'bi-credit-card',             'desc' => 'Gestión de créditos y abonos',                            'modulos' => 'creditos.php'],
    'reportes'      => ['label' => 'Reportes',                       'icon' => 'bi-file-earmark-bar-graph',  'desc' => 'Acceso a reportes y estadísticas del sistema',            'modulos' => 'reportes.php'],
    'configuracion' => ['label' => 'Configuración',                  'icon' => 'bi-gear-fill',               'desc' => 'Ajustes generales del sistema',                           'modulos' => 'configuracion.php'],
    'empleados'     => ['label' => 'Empleados',                      'icon' => 'bi-people-fill',             'desc' => 'Administrar empleados y asignar permisos',                'modulos' => 'empleados.php'],
];

$esAdmin = ($_SESSION['rol'] ?? '') === 'admin';
$totalPerms = count($permisosConfig);
$activosCount = 0;
if (!$esAdmin) {
    foreach (array_keys($permisosConfig) as $perm) {
        if ((int)($permisos[$perm] ?? 0)) $activosCount++;
    }
} else {
    $activosCount = $totalPerms;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">
            <i class="bi bi-shield-check me-2 text-primary"></i>Mis Permisos de Acceso
        </h3>
        <small class="text-muted">Módulos y funciones habilitados para tu cuenta</small>
    </div>
</div>

<div class="row g-4">
    <!-- ── Tarjeta de perfil ───────────────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                     style="width:80px;height:80px;">
                    <i class="bi bi-person-fill text-primary" style="font-size:2.5rem;"></i>
                </div>
                <h5 class="fw-bold mb-1"><?php echo h($usuario['nombre'] ?? ''); ?></h5>
                <p class="text-muted small mb-2"><?php echo h($usuario['correo'] ?? ''); ?></p>
                <span class="badge <?php echo $esAdmin ? 'bg-dark' : 'bg-info text-dark'; ?> mb-3">
                    <i class="bi <?php echo $esAdmin ? 'bi-shield-fill' : 'bi-person'; ?> me-1"></i>
                    <?php echo $esAdmin ? 'Administrador' : 'Empleado'; ?>
                </span>

                <?php if ($esAdmin): ?>
                <div class="alert alert-success py-2 small text-start">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <strong>Acceso completo.</strong> Como administrador tienes todos los módulos habilitados.
                </div>
                <?php else: ?>
                <div class="border rounded p-3 text-start">
                    <div class="small text-muted fw-bold mb-2">RESUMEN DE ACCESO</div>
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small">Módulos activos</span>
                        <span class="fw-bold text-primary"><?php echo $activosCount; ?> / <?php echo $totalPerms; ?></span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-primary" style="width:<?php echo ($totalPerms > 0 ? round($activosCount/$totalPerms*100) : 0); ?>%"></div>
                    </div>
                    <?php if ($activosCount === 0): ?>
                    <div class="text-danger small mt-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Sin permisos asignados. Contacta al administrador.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white text-center border-0 pb-3">
                <small class="text-muted">
                    <i class="bi bi-calendar3 me-1"></i>
                    Miembro desde <?php echo date('d/m/Y', strtotime($usuario['fecha_registro'] ?? 'now')); ?>
                </small>
            </div>
        </div>
    </div>

    <!-- ── Panel de permisos ──────────────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-shield-lock me-2 text-primary"></i>Módulos Disponibles
                </h5>
                <small class="text-muted">
                    <?php echo $esAdmin
                        ? 'Como administrador tienes acceso a todos los módulos del sistema.'
                        : 'Los módulos marcados en verde están habilitados para tu usuario.'; ?>
                </small>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                <?php foreach ($permisosConfig as $perm => $cfg):
                    $activo = $esAdmin || (int)($permisos[$perm] ?? 0) === 1;
                ?>
                <div class="col-sm-6">
                    <div class="border rounded p-3 h-100 <?php echo $activo ? 'bg-success-subtle border-success' : 'bg-light border-secondary-subtle'; ?>">
                        <div class="d-flex align-items-start gap-2">
                            <div class="mt-1">
                                <?php if ($activo): ?>
                                <span class="badge bg-success rounded-circle p-1" style="width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-check-lg" style="font-size:.7rem;"></i>
                                </span>
                                <?php else: ?>
                                <span class="badge bg-secondary rounded-circle p-1" style="width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-lock-fill" style="font-size:.7rem;"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center gap-1 mb-1">
                                    <i class="bi <?php echo h($cfg['icon']); ?> <?php echo $activo ? 'text-success' : 'text-muted'; ?>"></i>
                                    <?php echo h($cfg['label']); ?>
                                </div>
                                <div class="text-muted" style="font-size:.74rem;"><?php echo h($cfg['desc']); ?></div>
                                <div class="mt-1" style="font-size:.68rem;">
                                    <?php if ($activo): ?>
                                    <span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Habilitado</span>
                                    <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-x-circle me-1"></i>Sin acceso</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <?php if (!$esAdmin): ?>
                <div class="alert alert-info mt-4 py-2 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Si necesitas acceso a algún módulo adicional, solicítaselo al <strong>Administrador</strong>.
                    Los cambios de permisos se aplican <strong>de forma inmediata</strong> sin necesidad de volver a iniciar sesión.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
