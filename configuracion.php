<?php
include 'db.php';
include 'header.php';
require_perm('configuracion'); // MEJORA 2: Permiso de configuración requerido

$success = '';
$error   = '';

// ── Cambiar nombre de la farmacia / datos generales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'datos_generales') {
    $nombre   = trim($_POST['nombre_farmacia'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion= trim($_POST['direccion'] ?? '');
    $rfc      = trim($_POST['rfc'] ?? '');
    // Guardar en tabla configuracion (clave-valor)
    $kv = ['nombre_farmacia'=>$nombre,'telefono'=>$telefono,'direccion'=>$direccion,'rfc'=>$rfc];
    foreach ($kv as $k => $v) {
        $conexion->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                 ->execute([$k,$v,$v]);
    }
    $success = 'Datos generales actualizados.';
}

// ── Parámetros del sistema
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'parametros') {
    $kv = [
        'inactividad_min'   => (int)($_POST['inactividad_min'] ?? 15),
        'intentos_max'      => (int)($_POST['intentos_max']    ?? 5),
        'dias_alerta_cad'   => (int)($_POST['dias_alerta_cad'] ?? 90),
        'limite_mayoreo_def'=> (int)($_POST['limite_mayoreo_def']?? 50),
    ];
    foreach ($kv as $k => $v) {
        $conexion->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                 ->execute([$k,$v,$v]);
    }
    // Aplicar inactividad a header.php en runtime
    $success = 'Parámetros actualizados.';
}

// ── Cambiar contraseña del admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_password') {
    $actual   = $_POST['password_actual']   ?? '';
    $nueva    = $_POST['password_nueva']    ?? '';
    $confirma = $_POST['password_confirma'] ?? '';

    $stmtU = $conexion->prepare("SELECT contraseña FROM usuarios WHERE id_usuario=?");
    $stmtU->execute([$_SESSION['id_usuario']]);
    $hashGuardado = $stmtU->fetchColumn();

    $ok = password_verify($actual, $hashGuardado) || hash('sha256',$actual)===$hashGuardado;

    if (!$ok) { $error = 'La contraseña actual no es correcta.'; }
    elseif (strlen($nueva) < 6) { $error = 'La nueva contraseña debe tener al menos 6 caracteres.'; }
    elseif ($nueva !== $confirma) { $error = 'Las contraseñas nuevas no coinciden.'; }
    else {
        $conexion->prepare("UPDATE usuarios SET contraseña=? WHERE id_usuario=?")
                 ->execute([password_hash($nueva, PASSWORD_DEFAULT), $_SESSION['id_usuario']]);
        $success = 'Contraseña actualizada correctamente.';
    }
}

// ── Crear usuario empleado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_empleado') {
    $nombre  = trim($_POST['emp_nombre']  ?? '');
    $correo  = trim($_POST['emp_correo']  ?? '');
    $pass    = trim($_POST['emp_pass']    ?? '');
    $rol     = in_array($_POST['emp_rol']??'', ['admin','vendedor']) ? $_POST['emp_rol'] : 'vendedor';

    if (!$nombre || !$correo || !$pass) { $error = 'Completa todos los campos del empleado.'; }
    elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { $error = 'Correo inválido.'; }
    else {
        $chk = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE correo=?");
        $chk->execute([$correo]);
        if ($chk->rowCount()>0) { $error = 'Ya existe un usuario con ese correo.'; }
        else {
            $conexion->prepare("INSERT INTO usuarios (nombre,correo,contraseña,rol,estado) VALUES (?,?,?,?,?)")
                     ->execute([$nombre,$correo,password_hash($pass,PASSWORD_DEFAULT),$rol,'activo']);
            $success = 'Empleado "'.$nombre.'" creado con rol '.strtoupper($rol).'.';
        }
    }
}

// ── Desbloquear usuario
if (isset($_GET['desbloquear'])) {
    $uid = (int)$_GET['desbloquear'];
    $conexion->prepare("UPDATE usuarios SET intentos_fallidos=0, bloqueado_hasta=NULL WHERE id_usuario=?")->execute([$uid]);
    $success = 'Usuario desbloqueado.';
}

// ── Activar/desactivar usuario
if (isset($_GET['toggle_estado'])) {
    $uid = (int)$_GET['toggle_estado'];
    $conexion->prepare("UPDATE usuarios SET estado = CASE WHEN estado='activo' THEN 'inactivo' ELSE 'activo' END WHERE id_usuario=? AND rol != 'admin'")->execute([$uid]);
    $success = 'Estado del usuario actualizado.';
}

// ── Leer configuración actual
function cfg(PDO $db, string $key, $default = '') {
    $s = $db->prepare("SELECT valor FROM configuracion WHERE clave=? LIMIT 1");
    $s->execute([$key]);
    $r = $s->fetchColumn();
    return $r !== false ? $r : $default;
}

// Asegurar tabla configuracion
$conexion->exec("CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(80) NOT NULL PRIMARY KEY,
    valor TEXT NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

// Estadísticas
$stats = [
    'productos'  => $conexion->query("SELECT COUNT(*) FROM productos")->fetchColumn(),
    'empleados'  => $conexion->query("SELECT COUNT(*) FROM usuarios WHERE rol IN ('admin','vendedor')")->fetchColumn(),
    'clientes'   => $conexion->query("SELECT COUNT(*) FROM usuarios WHERE rol='cliente' OR tipo_cliente='mayorista'")->fetchColumn(),
    'ventas_mes' => $conexion->query("SELECT COALESCE(SUM(total),0) FROM pedidos WHERE MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW()) AND estado='completado'")->fetchColumn(),
];

$empleados = $conexion->query("SELECT id_usuario, nombre, correo, rol, estado, bloqueado_hasta, intentos_fallidos FROM usuarios WHERE rol != 'cliente' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0">Configuración del Sistema</h3>
        <small class="text-muted">Solo el Administrador puede modificar estos ajustes</small>
    </div>
    <span class="badge bg-danger-subtle text-danger border px-3 py-2"><i class="bi bi-shield-lock me-1"></i>Admin</span>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible"><i class="bi bi-check-circle me-2"></i><?php echo h($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger alert-dismissible"><i class="bi bi-exclamation-triangle me-2"></i><?php echo h($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Estadísticas rápidas -->
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-custom border-0 p-3 text-center"><div class="small text-muted fw-bold">PRODUCTOS</div><div class="fs-2 fw-bold text-primary"><?php echo $stats['productos']; ?></div></div></div>
    <div class="col-md-3"><div class="card card-custom border-0 p-3 text-center"><div class="small text-muted fw-bold">EMPLEADOS</div><div class="fs-2 fw-bold text-success"><?php echo $stats['empleados']; ?></div></div></div>
    <div class="col-md-3"><div class="card card-custom border-0 p-3 text-center"><div class="small text-muted fw-bold">CLIENTES</div><div class="fs-2 fw-bold text-info"><?php echo $stats['clientes']; ?></div></div></div>
    <div class="col-md-3"><div class="card card-custom border-0 p-3 text-center"><div class="small text-muted fw-bold">VENTAS MES</div><div class="fs-2 fw-bold text-warning">$<?php echo number_format((float)$stats['ventas_mes'],0); ?></div></div></div>
</div>

<div class="row g-4">

    <!-- COL IZQUIERDA -->
    <div class="col-lg-7">

        <!-- DATOS DE LA FARMACIA -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-building text-primary fs-5"></i>
                <h5 class="fw-bold mb-0">Datos de la Farmacia</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="datos_generales">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">NOMBRE</label>
                            <input type="text" name="nombre_farmacia" class="form-control" value="<?php echo h(cfg($conexion,'nombre_farmacia','Farmacia Peñaloza')); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">TELÉFONO</label>
                            <input type="text" name="telefono" class="form-control" value="<?php echo h(cfg($conexion,'telefono')); ?>" placeholder="229-000-0000">
                        </div>
                        <div class="col-md-8">
                            <label class="small fw-bold text-muted">DIRECCIÓN</label>
                            <input type="text" name="direccion" class="form-control" value="<?php echo h(cfg($conexion,'direccion')); ?>" placeholder="Calle, Colonia, Ciudad">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted">RFC</label>
                            <input type="text" name="rfc" class="form-control" value="<?php echo h(cfg($conexion,'rfc')); ?>" placeholder="RFC">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3 px-4">Guardar</button>
                </form>
            </div>
        </div>

        <!-- PARÁMETROS DEL SISTEMA -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-sliders text-warning fs-5"></i>
                <h5 class="fw-bold mb-0">Parámetros del Sistema</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="parametros">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">TIEMPO INACTIVIDAD (minutos)</label>
                            <input type="number" name="inactividad_min" class="form-control" value="<?php echo h(cfg($conexion,'inactividad_min','15')); ?>" min="5" max="120">
                            <div class="form-text">Sesión se cierra después de X min sin actividad.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">INTENTOS DE LOGIN MÁXIMOS</label>
                            <input type="number" name="intentos_max" class="form-control" value="<?php echo h(cfg($conexion,'intentos_max','5')); ?>" min="1" max="20">
                            <div class="form-text">Bloquea la cuenta si supera este límite.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">ALERTA CADUCIDAD (días antes)</label>
                            <input type="number" name="dias_alerta_cad" class="form-control" value="<?php echo h(cfg($conexion,'dias_alerta_cad','90')); ?>" min="7" max="365">
                            <div class="form-text">Alerta visual en inventario y POS.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">CANTIDAD MÍNIMA MAYOREO (uds)</label>
                            <input type="number" name="limite_mayoreo_def" class="form-control" value="<?php echo h(cfg($conexion,'limite_mayoreo_def','50')); ?>" min="1">
                            <div class="form-text">Cantidad desde la que aplica precio mayoreo.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning mt-3 px-4">Guardar Parámetros</button>
                </form>
            </div>
        </div>

        <!-- AGREGAR EMPLEADO -->
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-person-plus text-success fs-5"></i>
                <h5 class="fw-bold mb-0">Agregar Empleado / Admin</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="crear_empleado">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">NOMBRE COMPLETO</label>
                            <input type="text" name="emp_nombre" class="form-control" placeholder="Ej. María López" required>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">CORREO</label>
                            <input type="email" name="emp_correo" class="form-control" placeholder="correo@farmacia.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">CONTRASEÑA INICIAL</label>
                            <input type="password" name="emp_pass" class="form-control" placeholder="Mínimo 6 caracteres" required>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">ROL</label>
                            <select name="emp_rol" class="form-select">
                                <option value="vendedor">Vendedor</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success mt-3 px-4"><i class="bi bi-person-plus me-2"></i>Crear</button>
                </form>
            </div>
        </div>
    </div>

    <!-- COL DERECHA -->
    <div class="col-lg-5">

        <!-- CAMBIAR CONTRASEÑA -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-key text-danger fs-5"></i>
                <h5 class="fw-bold mb-0">Cambiar Contraseña</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="cambiar_password">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">CONTRASEÑA ACTUAL</label>
                        <input type="password" name="password_actual" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">NUEVA CONTRASEÑA</label>
                        <input type="password" name="password_nueva" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">CONFIRMAR CONTRASEÑA</label>
                        <input type="password" name="password_confirma" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-danger w-100 fw-bold">Actualizar Contraseña</button>
                </form>
            </div>
        </div>

        <!-- GESTIÓN DE EMPLEADOS -->
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <i class="bi bi-people text-info fs-5"></i>
                <h5 class="fw-bold mb-0">Usuarios del Sistema</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($empleados as $emp):
                    $bloqueado = !empty($emp['bloqueado_hasta']) && strtotime($emp['bloqueado_hasta']) > time();
                    $inactivo  = $emp['estado'] === 'inactivo';
                ?>
                <div class="list-group-item px-3 py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold"><?php echo h($emp['nombre']); ?>
                                <?php if ($bloqueado): ?><span class="badge bg-danger ms-1">Bloqueado</span><?php endif; ?>
                                <?php if ($inactivo):  ?><span class="badge bg-secondary ms-1">Inactivo</span><?php endif; ?>
                            </div>
                            <small class="text-muted"><?php echo h($emp['correo']); ?></small><br>
                            <span class="badge <?php echo $emp['rol']==='admin'?'bg-danger':'bg-primary'; ?>-subtle text-<?php echo $emp['rol']==='admin'?'danger':'primary'; ?> border mt-1">
                                <?php echo strtoupper(h($emp['rol'])); ?>
                            </span>
                        </div>
                        <div class="d-flex flex-column gap-1 align-items-end">
                            <?php if ($bloqueado): ?>
                                <a href="configuracion.php?desbloquear=<?php echo (int)$emp['id_usuario']; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-unlock me-1"></i>Desbloquear
                                </a>
                            <?php endif; ?>
                            <?php if ($emp['id_usuario'] != $_SESSION['id_usuario']): ?>
                                <a href="configuracion.php?toggle_estado=<?php echo (int)$emp['id_usuario']; ?>"
                                   class="btn btn-outline-<?php echo $inactivo?'success':'secondary'; ?> btn-sm"
                                   onclick="return confirm('¿<?php echo $inactivo?'Activar':'Desactivar'; ?> a <?php echo h($emp['nombre']); ?>?');">
                                    <i class="bi bi-<?php echo $inactivo?'toggle-on':'toggle-off'; ?> me-1"></i>
                                    <?php echo $inactivo?'Activar':'Desactivar'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ((int)$emp['intentos_fallidos'] > 0 && !$bloqueado): ?>
                        <div class="mt-1"><small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php echo (int)$emp['intentos_fallidos']; ?> intento(s) fallido(s)</small></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
