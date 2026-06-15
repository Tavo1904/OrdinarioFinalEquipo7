<?php 
include 'db.php'; 
include 'header.php'; 

// MEJORA 2: Requiere permiso de empleados
require_perm('empleados');

$error   = '';
$success = '';

// ── Permisos disponibles y sus etiquetas ──────────────────────────────────
$permisosConfig = [
    'ventas'        => ['label' => 'Ventas',              'icon' => 'bi-cart3',                  'desc' => 'Acceso al punto de venta (POS)'],
    'pedidos_web'   => ['label' => 'Pedidos Web',         'icon' => 'bi-bag-check-fill',         'desc' => 'Aceptar o rechazar pedidos de la plataforma web'],
    'inventario'    => ['label' => 'Inventario',          'icon' => 'bi-box-seam',               'desc' => 'Ver y editar productos en inventario'],
    'compras'       => ['label' => 'Compras',             'icon' => 'bi-truck',                  'desc' => 'Gestión de proveedores y facturas'],
    'clientes'      => ['label' => 'Clientes',            'icon' => 'bi-person-vcard',           'desc' => 'Administrar información de clientes'],
    'creditos'      => ['label' => 'Créditos',            'icon' => 'bi-credit-card',            'desc' => 'Gestión de créditos y pagos'],
    'reportes'      => ['label' => 'Reportes',            'icon' => 'bi-file-earmark-bar-graph', 'desc' => 'Acceso a reportes y estadísticas'],
    'configuracion' => ['label' => 'Configuración',       'icon' => 'bi-gear-fill',              'desc' => 'Ajustes generales del sistema'],
    'empleados'     => ['label' => 'Empleados',           'icon' => 'bi-people-fill',            'desc' => 'Administrar empleados y permisos'],
];

// 2. GUARDAR (CREAR O EDITAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar_usuario';

    // ── Guardar permisos ─────────────────────────────────────────────────
    if ($accion === 'guardar_permisos') {
        $id = (int)$_POST['id_usuario'];
        if ($id > 0 && $id != $_SESSION['id_usuario']) {
            $cols   = array_keys($permisosConfig);
            $vals   = array_map(fn($c) => isset($_POST['perm_' . $c]) ? 1 : 0, $cols);
            // Upsert
            $setStr = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
            $colStr = implode(', ', array_map(fn($c) => "`$c`", $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $conexion->prepare("
                INSERT INTO empleados_permisos (id_usuario, $colStr)
                VALUES (?, $placeholders)
                ON DUPLICATE KEY UPDATE $setStr
            ")->execute(array_merge([$id], $vals, $vals));
            $success = 'Permisos actualizados correctamente.';
        }

    // ── Crear o editar usuario ────────────────────────────────────────────
    } else {
        $id     = $_POST['id_usuario'] ?? '';
        $nombre = trim($_POST['nombre']);
        $correo = trim($_POST['correo']);
        $rol    = $_POST['rol'];
        $pass   = $_POST['password'];
        $pwCol  = password_column($conexion);

        try {
            if (empty($id)) {
                // CREAR
                $check = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE correo = ?");
                $check->execute([$correo]);
                if ($check->rowCount() > 0) {
                    $error = "El correo ya está registrado.";
                } else {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $conexion->prepare("INSERT INTO usuarios (nombre, correo, `$pwCol`, rol, estado) VALUES (?, ?, ?, ?, 'activo')")
                             ->execute([$nombre, $correo, $hash, $rol]);
                    // Crear fila de permisos vacía para el nuevo empleado
                    $newId = (int)$conexion->lastInsertId();
                    // Admins reciben todos los permisos por defecto; empleados ninguno
                    $allPerms = $rol === 'admin' ? 1 : 0;
                    $cols   = array_keys($permisosConfig);
                    $colStr = implode(', ', array_map(fn($c) => "`$c`", $cols));
                    $pholdr = implode(', ', array_fill(0, count($cols), '?'));
                    $conexion->prepare("INSERT IGNORE INTO empleados_permisos (id_usuario, $colStr) VALUES (?, $pholdr)")
                             ->execute(array_merge([$newId], array_fill(0, count($cols), $allPerms)));
                    $success = "Usuario registrado correctamente.";
                }
            } else {
                // EDITAR
                if (!empty($pass)) {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $conexion->prepare("UPDATE usuarios SET nombre=?, correo=?, rol=?, `$pwCol`=? WHERE id_usuario=?")
                             ->execute([$nombre, $correo, $rol, $hash, $id]);
                } else {
                    $conexion->prepare("UPDATE usuarios SET nombre=?, correo=?, rol=? WHERE id_usuario=?")
                             ->execute([$nombre, $correo, $rol, $id]);
                }
                $success = "Usuario actualizado correctamente.";
            }
        } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
    }
}

// 3. CAMBIAR ESTADO (ACTIVAR / DESACTIVAR)
if (isset($_GET['cambiar_estado']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $nuevo_estado = $_GET['cambiar_estado'];
    if ($id != (int)$_SESSION['id_usuario'] && in_array($nuevo_estado, ['activo', 'inactivo'])) {
        $conexion->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?")
                 ->execute([$nuevo_estado, $id]);
        echo "<script>window.location='empleados.php';</script>"; exit;
    } else {
        $error = 'No puedes cambiar tu propio estado.';
    }
}

// 4. CONSULTA: TRAER TODOS (sin clientes)
$usuarios = $conexion->query("
    SELECT u.*,
           ep.ventas, ep.inventario, ep.compras, ep.clientes,
           ep.creditos, ep.reportes, ep.configuracion, ep.empleados AS perm_empleados
    FROM usuarios u
    LEFT JOIN empleados_permisos ep ON ep.id_usuario = u.id_usuario
    WHERE u.rol != 'cliente'
    ORDER BY u.estado ASC, u.id_usuario DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Precargar permisos del usuario seleccionado para el modal ─────────────
$verPermisos = (int)($_GET['permisos'] ?? 0);
$usuarioPermisos = null;
if ($verPermisos > 0) {
    $stmtVP = $conexion->prepare("
        SELECT u.nombre, u.rol, ep.*
        FROM usuarios u
        LEFT JOIN empleados_permisos ep ON ep.id_usuario = u.id_usuario
        WHERE u.id_usuario = ?
        LIMIT 1
    ");
    $stmtVP->execute([$verPermisos]);
    $usuarioPermisos = $stmtVP->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>Administración de Empleados</h3>
        <small class="text-muted"><?php echo count($usuarios); ?> usuario(s) en el sistema</small>
    </div>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>

<div class="row g-4">
    <!-- ── Panel izquierdo: formulario ───────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card card-custom border-0 shadow-sm sticky-top" style="top: 20px;">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0" id="tituloForm">Nuevo Empleado</h5>
                <button type="button" class="btn btn-sm btn-light" onclick="limpiarForm()">Limpiar</button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="guardar_usuario">
                    <input type="hidden" name="id_usuario" id="inputId">
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">NOMBRE COMPLETO</label>
                        <input type="text" name="nombre" id="inputNombre" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">CORREO</label>
                        <input type="email" name="correo" id="inputCorreo" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">CONTRASEÑA</label>
                        <input type="password" name="password" class="form-control" placeholder="Dejar vacío si no cambia">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small text-muted fw-bold">ROL</label>
                        <select name="rol" id="inputRol" class="form-select">
                            <option value="empleado">Empleado</option>
                            <option value="admin">Administrador</option>
                        </select>
                        <div class="form-text small text-muted mt-1">
                            <i class="bi bi-info-circle me-1"></i>Los administradores reciben todos los permisos automáticamente.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold" id="btnGuardar">
                        <i class="bi bi-person-plus-fill me-2"></i> Registrar Empleado
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Panel derecho: tabla ──────────────────────────────────────────── -->
    <div class="col-md-8">

        <!-- Panel de permisos (visible cuando se hace clic en un empleado) -->
        <?php if ($usuarioPermisos): ?>
        <div class="card card-custom border-0 shadow-sm mb-4 border-start border-4 border-warning">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-shield-check me-1 text-warning"></i>
                        Permisos de acceso — <span class="text-primary"><?php echo h($usuarioPermisos['nombre']); ?></span>
                    </h6>
                    <small class="text-muted">Marca los módulos a los que puede acceder este empleado</small>
                </div>
                <a href="empleados.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Cerrar
                </a>
            </div>
            <div class="card-body">
                <?php if ($usuarioPermisos['rol'] === 'admin'): ?>
                    <div class="alert alert-info py-2 mb-3 small">
                        <i class="bi bi-shield-fill-check me-1"></i>
                        Los administradores tienen acceso completo al sistema y no requieren configuración de permisos individuales.
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="accion" value="guardar_permisos">
                    <input type="hidden" name="id_usuario" value="<?php echo (int)$usuarioPermisos['id_usuario']; ?>">

                    <div class="row g-3 mb-4">
                    <?php foreach ($permisosConfig as $perm => $cfg):
                        $colPerm = $perm === 'empleados' ? 'perm_empleados' : $perm;
                        $activo  = (int)($usuarioPermisos[$colPerm] ?? 0);
                        $isAdmin = $usuarioPermisos['rol'] === 'admin';
                    ?>
                    <div class="col-sm-6 col-md-6">
                        <div class="border rounded p-3 h-100 <?php echo ($activo || $isAdmin) ? 'bg-success-subtle border-success' : 'bg-light'; ?>"
                             id="card-<?php echo $perm; ?>">
                            <div class="d-flex align-items-start gap-2">
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox"
                                           name="perm_<?php echo $perm; ?>"
                                           id="perm_<?php echo $perm; ?>"
                                           <?php echo ($activo || $isAdmin) ? 'checked' : ''; ?>
                                           <?php echo $isAdmin ? 'disabled' : ''; ?>
                                           onchange="actualizarCardPermiso('<?php echo $perm; ?>', this.checked)">
                                </div>
                                <div>
                                    <label class="form-check-label fw-bold small d-flex align-items-center gap-1"
                                           for="perm_<?php echo $perm; ?>">
                                        <i class="bi <?php echo h($cfg['icon']); ?> text-primary"></i>
                                        <?php echo h($cfg['label']); ?>
                                    </label>
                                    <div class="text-muted" style="font-size:.75rem;"><?php echo h($cfg['desc']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($usuarioPermisos['rol'] !== 'admin'): ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning fw-bold px-4">
                            <i class="bi bi-shield-check me-1"></i>Guardar Permisos
                        </button>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="toggleTodosPermisos(true)">
                            <i class="bi bi-check2-all me-1"></i>Marcar todos
                        </button>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="toggleTodosPermisos(false)">
                            <i class="bi bi-x-circle me-1"></i>Desmarcar todos
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de usuarios -->
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">Personal del Sistema</h5>
                <span class="badge bg-secondary"><?php echo count($usuarios); ?> registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Permisos</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $u):
                        $esActivo  = $u['estado'] === 'activo';
                        $esAdmin   = $u['rol'] === 'admin';
                        $claseFila = $esActivo ? '' : 'bg-light text-muted';
                        $uid       = (int)$u['id_usuario'];

                        // Contar permisos activos
                        $permsCols   = array_keys($permisosConfig);
                        $totalPerms  = count($permsCols);
                        $activosPerms = 0;
                        foreach ($permsCols as $pc) {
                            $colKey = $pc === 'empleados' ? 'perm_empleados' : $pc;
                            if ((int)($u[$colKey] ?? 0)) $activosPerms++;
                        }
                    ?>
                    <tr class="<?php echo $claseFila; ?>">
                        <td>
                            <div class="fw-bold"><?php echo h($u['nombre']); ?></div>
                            <small class="text-muted"><?php echo h($u['correo']); ?></small>
                        </td>
                        <td>
                            <?php if ($u['rol'] === 'admin'): ?>
                                <span class="badge bg-dark"><i class="bi bi-shield-fill me-1"></i>Admin</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark"><i class="bi bi-person me-1"></i>Empleado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($esAdmin): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle small">
                                    <i class="bi bi-check-all me-1"></i>Acceso completo
                                </span>
                            <?php elseif ($activosPerms === 0): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle small">
                                    <i class="bi bi-x-circle me-1"></i>Sin permisos
                                </span>
                            <?php else: ?>
                                <!-- Mini indicadores de permisos -->
                                <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($permsCols as $pc):
                                    $colKey  = $pc === 'empleados' ? 'perm_empleados' : $pc;
                                    $activo  = (int)($u[$colKey] ?? 0);
                                    $lbl     = $permisosConfig[$pc]['label'];
                                    $ico     = $permisosConfig[$pc]['icon'];
                                ?>
                                <span class="badge <?php echo $activo ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-muted border'; ?>"
                                      style="font-size:.68rem;" title="<?php echo h($lbl); ?>">
                                    <i class="bi <?php echo h($ico); ?>"></i>
                                </span>
                                <?php endforeach; ?>
                                </div>
                                <div class="text-muted" style="font-size:.72rem;margin-top:2px;">
                                    <?php echo $activosPerms; ?>/<?php echo $totalPerms; ?> módulos activos
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($esActivo): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Activo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <!-- Editar datos básicos -->
                            <button class="btn btn-sm btn-warning text-dark border-0 me-1"
                                    title="Editar datos"
                                    onclick='editarUsuario(<?php echo json_encode($u); ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>

                            <!-- Gestionar permisos (solo para no-admins y para usuarios distintos al propio) -->
                            <?php if ($uid != (int)$_SESSION['id_usuario']): ?>
                                <a href="empleados.php?permisos=<?php echo $uid; ?>"
                                   class="btn btn-sm btn-outline-warning border me-1"
                                   title="Gestionar permisos">
                                    <i class="bi bi-shield-check"></i>
                                </a>

                                <?php if ($esActivo): ?>
                                    <a href="empleados.php?id=<?php echo $uid; ?>&cambiar_estado=inactivo"
                                       class="btn btn-sm btn-danger border-0"
                                       onclick="return confirm('¿Desactivar a este usuario? Ya no podrá entrar al sistema.');"
                                       title="Desactivar acceso">
                                        <i class="bi bi-person-x-fill"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="empleados.php?id=<?php echo $uid; ?>&cambiar_estado=activo"
                                       class="btn btn-sm btn-success border-0"
                                       onclick="return confirm('¿Reactivar a este usuario? Podrá entrar nuevamente.');"
                                       title="Reactivar acceso">
                                        <i class="bi bi-check-lg"></i>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div></div>

<script>
function editarUsuario(u) {
    document.getElementById('tituloForm').innerText = "Editar Usuario #" + u.id_usuario;
    document.getElementById('btnGuardar').innerHTML = "<i class='bi bi-pencil-square me-2'></i> Actualizar";
    document.getElementById('inputId').value    = u.id_usuario;
    document.getElementById('inputNombre').value = u.nombre;
    document.getElementById('inputCorreo').value  = u.correo;
    document.getElementById('inputRol').value     = u.rol;
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function limpiarForm() {
    document.getElementById('tituloForm').innerText = "Nuevo Empleado";
    document.getElementById('btnGuardar').innerHTML = "<i class='bi bi-person-plus-fill me-2'></i> Registrar Empleado";
    document.getElementById('inputId').value     = "";
    document.getElementById('inputNombre').value = "";
    document.getElementById('inputCorreo').value  = "";
    document.getElementById('inputRol').value     = "empleado";
}

function actualizarCardPermiso(perm, activo) {
    var card = document.getElementById('card-' + perm);
    if (!card) return;
    if (activo) {
        card.classList.remove('bg-light');
        card.classList.add('bg-success-subtle', 'border-success');
    } else {
        card.classList.remove('bg-success-subtle', 'border-success');
        card.classList.add('bg-light');
    }
}

function toggleTodosPermisos(activar) {
    var checks = document.querySelectorAll('[name^="perm_"]');
    checks.forEach(function(c) {
        if (!c.disabled) {
            c.checked = activar;
            var perm = c.name.replace('perm_', '');
            actualizarCardPermiso(perm, activar);
        }
    });
}

// Si venimos de ?permisos=X, hacer scroll al panel
<?php if ($usuarioPermisos): ?>
(function() {
    var panel = document.querySelector('.border-warning');
    if (panel) panel.scrollIntoView({behavior: 'smooth', block: 'start'});
})();
<?php endif; ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
