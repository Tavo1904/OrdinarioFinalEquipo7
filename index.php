<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['id_usuario'])) {
    header("Location: " . (($_SESSION['rol'] ?? '') === 'cliente' ? 'cliente.php' : 'dashboard.php'));
    exit;
}

$error = '';
$info  = isset($_GET['expirada'])    ? 'Sesion expirada por inactividad.'           : '';
$info  = isset($_GET['registro_ok']) ? '¡Cuenta creada! Ahora puedes iniciar sesión.' : $info;

$intentos_max = 5;
try {
    $stmtCfg = $conexion->query("SELECT valor FROM configuracion WHERE clave='intentos_max' LIMIT 1");
    if ($stmtCfg) {
        $valCfg = $stmtCfg->fetchColumn();
        if ($valCfg !== false && (int)$valCfg > 0) $intentos_max = (int)$valCfg;
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo   = trim($_POST['correo']   ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($correo === '' || $password === '') {
        $error = 'Por favor ingrese correo y contrasena.';
    } else {
        try {
            // ── MEJORA 1: incluir 'empleado' además de 'admin','vendedor','cliente' ──
            $stmt = $conexion->prepare("
                SELECT * FROM usuarios
                WHERE correo = ? AND rol IN ('admin','empleado','vendedor','cliente')
                LIMIT 1
            ");
            $stmt->execute([$correo]);
            $usuario = $stmt->fetch();

            if (!$usuario) {
                $error = 'Credenciales incorrectas.';
            } elseif (!empty($usuario['bloqueado_hasta']) && strtotime($usuario['bloqueado_hasta']) > time()) {
                $error = 'Cuenta suspendida temporalmente. Contacta al Administrador.';
            } elseif (($usuario['estado'] ?? '') !== 'activo') {
                $error = 'Acceso denegado: usuario inactivo.';
            } else {
                $col          = password_column($conexion);
                $hashGuardado = $usuario[$col] ?? '';
                $passwordOk   = password_verify($password, $hashGuardado) || hash('sha256', $password) === $hashGuardado;

                if ($passwordOk) {
                    $conexion->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
                             ->execute([$usuario['id_usuario']]);

                    $_SESSION['id_usuario']    = $usuario['id_usuario'];
                    $_SESSION['nombre']        = $usuario['nombre'];
                    $_SESSION['usuario']       = $usuario['nombre'];
                    $_SESSION['rol']           = $usuario['rol'];
                    $_SESSION['ultimo_acceso'] = time();

                    // ── MEJORA 1: Cargar permisos en sesión al iniciar sesión ─────────
                    if ($usuario['rol'] !== 'cliente') {
                        load_permisos_session($conexion, (int)$usuario['id_usuario']);
                    }
                    // ─────────────────────────────────────────────────────────────────

                    header("Location: " . ($usuario['rol'] === 'cliente' ? 'cliente.php' : 'dashboard.php'));
                    exit;
                }

                $intentos = (int)$usuario['intentos_fallidos'] + 1;
                if ($intentos >= $intentos_max) {
                    $conexion->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id_usuario = ?")
                             ->execute([$intentos, $usuario['id_usuario']]);
                    $error = 'Cuenta suspendida temporalmente. Contacta al Administrador.';
                } else {
                    $conexion->prepare("UPDATE usuarios SET intentos_fallidos = ? WHERE id_usuario = ?")
                             ->execute([$intentos, $usuario['id_usuario']]);
                    $restantes = $intentos_max - $intentos;
                    $error = 'Credenciales incorrectas. Intento ' . $intentos . ' de ' . $intentos_max . '. (' . $restantes . ' restante' . ($restantes === 1 ? '' : 's') . ')';
                }
            }
        } catch (Exception $e) {
            $error = 'Error de sistema: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - Populares Penaloza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color:#f0f2f5; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:'Segoe UI',system-ui,sans-serif; }
        .login-card { width:100%; max-width:420px; border:none; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.08); background:white; overflow:hidden; }
        .login-header { background: linear-gradient(135deg, #7c3aed, #6d28d9); padding:30px; text-align:center; color:white; }
        .btn-login { background-color:#7c3aed; border:none; padding:12px; font-weight:700; border-radius:8px; transition:all .3s; }
        .btn-login:hover { background-color:#6d28d9; transform:translateY(-1px); }
        .form-control { padding:12px; border-radius:8px; background-color:#f8f9fa; border:1px solid #dee2e6; }
        .form-control:focus { background-color:#fff; border-color:#7c3aed; box-shadow:0 0 0 4px rgba(124,58,237,.15); }
        .divider { display:flex; align-items:center; color:#adb5bd; font-size:.85rem; }
        .divider::before, .divider::after { content:''; flex:1; border-top:1px solid #dee2e6; }
        .divider::before { margin-right:.8rem; }
        .divider::after { margin-left:.8rem; }
        .btn-register { border:2px solid #7c3aed; color:#7c3aed; font-weight:700; border-radius:8px; padding:11px; transition:all .3s; }
        .btn-register:hover { background-color:#7c3aed; color:white; transform:translateY(-1px); }
    </style>
</head>
<body>
    <div class="card login-card">
        <div class="login-header">
            <div class="mb-2"><i class="bi bi-capsule" style="font-size:2.5rem;"></i></div>
            <h4 class="mb-0 fw-bold">Populares Penaloza</h4>
            <small class="opacity-75">Sistema de Gestion Web</small>
        </div>
        <div class="card-body p-4">
            <?php if ($info):  ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i><?php echo h($info); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger d-flex align-items-center mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><div><?php echo h($error); ?></div></div><?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">CORREO ELECTRONICO</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                        <input type="email" name="correo" class="form-control border-start-0" placeholder="correo@ejemplo.com" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold">CONTRASENA</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                        <input type="password" name="password" class="form-control border-start-0" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 btn-login mb-3">INGRESAR AL SISTEMA</button>
            </form>

            <div class="divider mb-3">o si eres cliente nuevo</div>

            <a href="registro.php" class="btn btn-register w-100 mb-1">
                <i class="bi bi-person-plus-fill me-2"></i>CREAR CUENTA DE CLIENTE
            </a>
            <p class="text-center text-muted small mt-2 mb-0">Registrate para realizar pedidos y solicitar credito</p>
        </div>
        <div class="card-footer text-center bg-white border-0 pb-3">
            <small class="text-muted">ERP Farmaceutico &mdash; Populares Penaloza</small>
        </div>
    </div>
</body>
</html>
