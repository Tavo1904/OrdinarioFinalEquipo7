<?php
session_start();
require_once 'db.php';
require_once 'app_helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']   ?? '');
    $correo   = trim($_POST['correo']   ?? '');
    $password = trim($_POST['password'] ?? '');
    $rfc      = trim($_POST['rfc']      ?? '');

    if ($nombre === '' || $correo === '' || $password === '') {
        $error = 'Los campos con asterisco (*) son obligatorios.';
    } elseif (strlen($password) < 6) {
        $error = 'La contrasena debe tener minimo 6 caracteres.';
    } else {
        try {
            $stmt = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE correo = ? LIMIT 1");
            $stmt->execute([$correo]);
            if ($stmt->fetch()) {
                $error = 'Este correo electronico ya fue registrado previamente.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $col  = password_column($conexion);
                $stmtIns = $conexion->prepare("INSERT INTO usuarios (nombre, correo, `$col`, rol, estado, rfc, tipo_cliente) VALUES (?, ?, ?, 'cliente', 'activo', ?, 'minorista')");
                $stmtIns->execute([$nombre, $correo, $hash, $rfc]);
                header('Location: index.php?registro_ok=1');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error interno del servidor: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Cliente - Populares Penaloza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background:#f4f6f9; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:'Segoe UI',system-ui,sans-serif; }
        .register-card { width:100%; max-width:460px; border:none; border-radius:16px; box-shadow:0 12px 30px rgba(0,0,0,.06); background:white; overflow:hidden; }
        .register-header { background:linear-gradient(135deg,#0d6efd,#0a58ca); padding:28px 20px; text-align:center; color:white; }
        .form-control { padding:11px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; }
        .form-control:focus { background:#fff; border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.12); }
        .btn-register { padding:12px; font-weight:700; border-radius:8px; transition:all .2s; }
        .btn-register:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(13,110,253,.3); }
    </style>
</head>
<body>
<div class="card register-card">
    <div class="register-header">
        <i class="bi bi-person-plus-fill" style="font-size:2rem;margin-bottom:8px;display:block;"></i>
        <h4 class="mb-1 fw-bold">Crear Cuenta de Cliente</h4>
        <p class="mb-0 small opacity-75">Registrate para realizar pedidos y solicitar credito</p>
    </div>
    <div class="card-body p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center mb-3">
                <i class="bi bi-exclamation-octagon-fill me-2"></i>
                <div><?php echo h($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">NOMBRE COMPLETO *</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-muted"></i></span>
                    <input type="text" name="nombre" class="form-control border-start-0" placeholder="Juan Carlos Perez" required
                           value="<?php echo h($_POST['nombre'] ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">CORREO ELECTRONICO *</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" name="correo" class="form-control border-start-0" placeholder="nombre@correo.com" required
                           value="<?php echo h($_POST['correo'] ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">CONTRASENA DE ACCESO *</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" name="password" class="form-control border-start-0" placeholder="Minimo 6 caracteres" required>
                </div>
                <div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>Minimo 6 caracteres. Guarda bien tu contrasena.</div>
            </div>
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">RFC (OPCIONAL)</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-card-text text-muted"></i></span>
                    <input type="text" name="rfc" class="form-control border-start-0" placeholder="XAXX010101000"
                           style="text-transform:uppercase;"
                           value="<?php echo h($_POST['rfc'] ?? ''); ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-register mb-3 shadow-sm">
                <i class="bi bi-check-circle me-2"></i>CREAR MI CUENTA
            </button>
            <div class="text-center">
                <a href="index.php" class="small text-decoration-none fw-semibold text-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Regresar al inicio de sesion
                </a>
            </div>
        </form>
    </div>
    <div class="card-footer bg-white border-0 text-center pb-3">
        <small class="text-muted">ERP Farmaceutico &mdash; Populares Penaloza</small>
    </div>
</div>
</body>
</html>
