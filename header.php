<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── MEJORA 2 & 4: verificar sesión activa; aceptar 'empleado' además de 'vendedor' ──
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'empleado', 'vendedor'], true)) {
    header("Location: index.php");
    exit;
}

// ── MEJORA 4: Actualización dinámica de permisos en cada petición ─────────────
// Los cambios del administrador se reflejan de inmediato sin relogin.
if (($_SESSION['rol'] ?? '') !== 'admin') {
    refresh_permisos($conexion);
}
// ──────────────────────────────────────────────────────────────────────────────

$inactividad_seg = 120; // 2 minutos por defecto
try {
    if (isset($conexion)) {
        $stmtCfg = $conexion->query("SELECT valor FROM configuracion WHERE clave='inactividad_min' LIMIT 1");
        if ($stmtCfg) {
            $valCfg = $stmtCfg->fetchColumn();
            if ($valCfg !== false && (int)$valCfg > 0) $inactividad_seg = (int)$valCfg * 60;
        }
    }
} catch (Exception $e) {}

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso']) > $inactividad_seg) {
    session_unset();
    session_destroy();
    header("Location: index.php?expirada=1");
    exit;
}
$_SESSION['ultimo_acceso'] = time();

$current      = basename($_SERVER['PHP_SELF']);
$nombreSesion = $_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Farmaceutico - Populares Penaloza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --sidebar-w: 230px; }
        body { background-color: #f5f7fb; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            min-height: 100vh; background-color: #7c3aed; color: white;
            position: fixed; width: var(--sidebar-w); top: 0; left: 0; z-index: 1040;
            display: flex; flex-direction: column; overflow-y: auto;
            transition: transform .28s ease;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }
        .main-content { margin-left: var(--sidebar-w); padding: 20px; width: calc(100% - var(--sidebar-w)); }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.9); padding: 8px 15px; font-size: 0.95rem;
            border-radius: 6px; margin-bottom: 3px; transition: all 0.2s;
            display: flex; align-items: center;
        }
        .sidebar .nav-link i { font-size: 1.1rem; margin-right: 10px; }
        .sidebar .nav-link:hover { background-color: rgba(255,255,255,0.15); color: white; transform: translateX(3px); }
        .sidebar .nav-link.active { background-color: white; color: #7c3aed; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card-custom { border: 1px solid #eaeaea; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); background: white; }
        .required-empty { border-color: #dc3545 !important; box-shadow: 0 0 0 .2rem rgba(220,53,69,.15); }
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 1039;
        }
        .sidebar-overlay.active { display: block; }
        .mobile-topbar {
            display: none;
            position: sticky; top: 0; z-index: 100;
            background: #7c3aed; color: white;
            padding: 10px 16px;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            margin: -20px -20px 20px -20px;
        }
        .mobile-topbar .topbar-title { font-weight: 700; font-size: 1rem; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .btn-hamburger { background: none; border: none; color: white; padding: 4px 6px; font-size: 1.4rem; line-height: 1; cursor: pointer; }
        .btn-hamburger:focus { outline: none; }
        @media (max-width: 767.98px) {
            :root { --sidebar-w: 230px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.sidebar-open { transform: translateX(0); }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 16px; }
            .mobile-topbar { display: flex; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .col-md-8, .col-md-4 { width: 100% !important; padding-right: 0 !important; }
            #gridProductos .col { width: 50% !important; }
        }
        @media (min-width: 768px) and (max-width: 991.98px) {
            :root { --sidebar-w: 200px; }
            .sidebar { font-size: .88rem; }
            .sidebar .nav-link { padding: 7px 10px; font-size: .88rem; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="d-flex">
    <div class="sidebar p-3" id="mainSidebar">
        <div class="d-flex align-items-center justify-content-between border-bottom border-white-50 pb-3 mb-3">
            <div class="d-flex align-items-center">
                <i class="bi bi-capsule fs-3 me-2"></i>
                <span class="fw-bold fs-5">Penaloza</span>
            </div>
            <button class="btn-hamburger d-md-none" onclick="closeSidebar()" title="Cerrar menú">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="d-flex align-items-center rounded p-2 mb-3 bg-white bg-opacity-10">
            <div class="bg-white rounded-circle p-1 d-flex justify-content-center align-items-center text-primary" style="width:35px;height:35px;">
                <i class="bi bi-person-fill fs-5"></i>
            </div>
            <div class="ms-2 lh-1 overflow-hidden">
                <div class="fw-bold text-truncate" style="font-size:.9rem;"><?php echo h($nombreSesion); ?></div>
                <div class="badge bg-warning text-dark p-1 mt-1" style="font-size:.65rem;"><?php echo strtoupper(h($_SESSION['rol'])); ?></div>
            </div>
        </div>

        <!-- ── MEJORA 2: Navegación con control de acceso basado en permisos ── -->
        <nav class="nav flex-column flex-grow-1">
            <!-- Dashboard: visible para todos -->
            <a class="nav-link <?php echo $current == 'dashboard.php' ? 'active' : ''; ?>"
               href="dashboard.php" onclick="closeSidebar()">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <?php if (has_perm('ventas')): ?>
            <a class="nav-link <?php echo $current == 'pos.php' ? 'active' : ''; ?>"
               href="pos.php" onclick="closeSidebar()">
                <i class="bi bi-shop"></i> Punto de Venta
            </a>
            <?php endif; ?>

            <?php if (has_perm('inventario')): ?>
            <a class="nav-link <?php echo $current == 'inventario.php' ? 'active' : ''; ?>"
               href="inventario.php" onclick="closeSidebar()">
                <i class="bi bi-box"></i> Inventario
            </a>
            <?php endif; ?>

            <?php if (has_perm('pedidos_web')): ?>
            <a class="nav-link <?php echo $current == 'pedidos.php' ? 'active' : ''; ?>"
               href="pedidos.php" onclick="closeSidebar()">
                <i class="bi bi-bag-check-fill"></i> Pedidos Web
            </a>
            <?php endif; ?>

            <?php if (has_perm('inventario')): ?>
            <a class="nav-link <?php echo $current == 'movimientos.php' ? 'active' : ''; ?>"
               href="movimientos.php" onclick="closeSidebar()">
                <i class="bi bi-arrow-left-right"></i> Movimientos
            </a>
            <?php endif; ?>

            <?php if (has_perm('compras')): ?>
            <a class="nav-link <?php echo $current == 'facturas.php' ? 'active' : ''; ?>"
               href="facturas.php" onclick="closeSidebar()">
                <i class="bi bi-receipt"></i> Facturas
            </a>
            <?php endif; ?>

            <?php if (has_perm('creditos')): ?>
            <a class="nav-link <?php echo $current == 'creditos.php' ? 'active' : ''; ?>"
               href="creditos.php" onclick="closeSidebar()">
                <i class="bi bi-credit-card"></i> Creditos
            </a>
            <?php endif; ?>

            <?php if (has_perm('clientes')): ?>
            <a class="nav-link <?php echo $current == 'clientes.php' ? 'active' : ''; ?>"
               href="clientes.php" onclick="closeSidebar()">
                <i class="bi bi-person-vcard"></i> Clientes
            </a>
            <?php endif; ?>

            <?php
            // Sección "Sistema": visible si tiene al menos uno de estos permisos
            $mostrarSistema = has_perm('compras') || has_perm('reportes') || has_perm('empleados') || has_perm('configuracion');
            if ($mostrarSistema):
            ?>
            <div class="mt-3 mb-1 px-2 text-uppercase small text-white-50 fw-bold border-top border-white-50 pt-2" style="font-size:.7rem;">Sistema</div>

            <?php if (has_perm('compras')): ?>
            <a class="nav-link <?php echo $current == 'proveedores.php' ? 'active' : ''; ?>"
               href="proveedores.php" onclick="closeSidebar()">
                <i class="bi bi-truck"></i> Proveedores
            </a>
            <?php endif; ?>

            <?php if (has_perm('reportes')): ?>
            <a class="nav-link <?php echo $current == 'reportes.php' ? 'active' : ''; ?>"
               href="reportes.php" onclick="closeSidebar()">
                <i class="bi bi-file-earmark-bar-graph"></i> Reportes
            </a>
            <?php endif; ?>

            <?php if (has_perm('empleados')): ?>
            <a class="nav-link <?php echo $current == 'empleados.php' ? 'active' : ''; ?>"
               href="empleados.php" onclick="closeSidebar()">
                <i class="bi bi-people-fill"></i> Empleados
            </a>
            <?php endif; ?>

            <?php if (has_perm('configuracion')): ?>
            <a class="nav-link <?php echo $current == 'configuracion.php' ? 'active' : ''; ?>"
               href="configuracion.php" onclick="closeSidebar()">
                <i class="bi bi-gear-fill"></i> Configuracion
            </a>
            <?php endif; ?>

            <?php endif; ?>

            <?php if (($_SESSION['rol'] ?? '') !== 'admin'): ?>
            <!-- MEJORA 3: Enlace a mis permisos para empleados -->
            <div class="mt-3 mb-1 px-2 text-uppercase small text-white-50 fw-bold border-top border-white-50 pt-2" style="font-size:.7rem;">Mi Cuenta</div>
            <a class="nav-link <?php echo $current == 'mis_permisos.php' ? 'active' : ''; ?>"
               href="mis_permisos.php" onclick="closeSidebar()">
                <i class="bi bi-shield-check"></i> Mis Permisos
            </a>
            <?php endif; ?>
        </nav>
        <!-- ──────────────────────────────────────────────────────────────── -->

        <div class="mt-auto pt-3">
            <a class="nav-link bg-danger bg-opacity-75 text-white justify-content-center fw-bold shadow-sm" href="logout.php">
                <i class="bi bi-box-arrow-left"></i> Salir
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="mobile-topbar">
            <button class="btn-hamburger" onclick="openSidebar()" title="Abrir menú">
                <i class="bi bi-list"></i>
            </button>
            <span class="topbar-title"><i class="bi bi-capsule me-1"></i> Populares Penaloza</span>
            <a href="logout.php" class="btn btn-sm btn-light btn-outline-light text-white border-white" title="Cerrar sesión">
                <i class="bi bi-box-arrow-left"></i>
            </a>
        </div>

        <?php if (isset($_GET['acceso_denegado'])): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="bi bi-shield-exclamation me-2 fs-5"></i>
            <div>
                <strong>Acceso denegado.</strong> No tienes permiso para acceder a ese módulo.
                Contacta al administrador si necesitas acceso.
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

<script>
function openSidebar() {
    document.getElementById('mainSidebar').classList.add('sidebar-open');
    document.getElementById('sidebarOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    document.getElementById('mainSidebar').classList.remove('sidebar-open');
    document.getElementById('sidebarOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) closeSidebar();
});

// TC-ALT-07: 2 min de inactividad → redirige al index automáticamente
(function () {
    var TIMEOUT_MS   = <?php echo (int)$inactividad_seg; ?> * 1000;
    var WARN_MS      = TIMEOUT_MS - 30000; // aviso a los 1:30
    var lastActivity = Date.now();

    // Cualquier interacción reinicia el contador
    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll', 'click'].forEach(function (e) {
        document.addEventListener(e, function () { lastActivity = Date.now(); }, true);
    });

    setInterval(function () {
        var idle      = Date.now() - lastActivity;
        var remaining = Math.max(0, Math.ceil((TIMEOUT_MS - idle) / 1000));
        var banner    = document.getElementById('_inac_warn');

        if (idle >= TIMEOUT_MS) {
            window.location.replace('index.php?expirada=1');
            return;
        }

        if (idle >= WARN_MS) {
            if (!banner) {
                banner = document.createElement('div');
                banner.id = '_inac_warn';
                banner.setAttribute('style',
                    'position:fixed;top:0;left:0;right:0;z-index:99999;' +
                    'background:#b91c1c;color:#fff;text-align:center;' +
                    'padding:13px 16px;font-size:15px;font-weight:600;' +
                    'box-shadow:0 3px 12px rgba(0,0,0,.45);letter-spacing:.01em');
                document.body.appendChild(banner);
            }
            banner.innerHTML =
                '⚠️ Sesión por expirar — ' +
                'la sesión se cerrará en <strong>' + remaining + ' seg</strong>. ' +
                'Mueve el mouse o pulsa cualquier tecla para continuar.';
        } else if (banner) {
            banner.remove();
        }
    }, 1000);
})();
</script>
