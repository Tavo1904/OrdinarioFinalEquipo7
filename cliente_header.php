<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'cliente') {
    header("Location: index.php");
    exit;
}

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso']) > 1800) {
    session_unset();
    session_destroy();
    header("Location: index.php?expirada=1");
    exit;
}
$_SESSION['ultimo_acceso'] = time();

$current      = basename($_SERVER['PHP_SELF']);
$nombreSesion = $_SESSION['nombre'] ?? 'Cliente';
$tab          = $_GET['tab'] ?? 'tienda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Cuenta — Populares Peñaloza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        /* ── Paleta ───────────────────────────── */
        :root {
            --verde:    #5b21b6;
            --verde-md: #7c3aed;
            --verde-lt: #a78bfa;
            --crema:    #f7f3fb;
            --crema-dk: #ede8f8;
            --dorado:   #b89a5e;
            --dorado-lt:#d4b97a;
            --texto:    #2c2c2c;
        }

        /* ── Reset ───────────────────────────── */
        * { box-sizing: border-box; }
        body {
            background-color: var(--crema);
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: var(--texto);
            margin: 0; padding: 0;
        }

        /* ── Top Navbar ──────────────────────── */
        .top-navbar {
            background: var(--verde);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 12px rgba(0,0,0,.25);
        }
        .top-navbar .container-fluid {
            display: flex;
            align-items: center;
            padding: 0 28px;
            height: 64px;
        }
        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: white;
        }
        .nav-logo .logo-icon {
            width: 40px; height: 40px;
            background: var(--dorado);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            color: var(--verde);
            font-weight: 900;
        }
        .nav-logo .logo-text {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: .04em;
            line-height: 1.1;
        }
        .nav-logo .logo-sub {
            font-size: .65rem;
            opacity: .75;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .navbar-links {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-left: auto;
            list-style: none;
            padding: 0; margin-bottom: 0;
        }
        .navbar-links a {
            color: rgba(255,255,255,.85);
            text-decoration: none;
            font-size: .88rem;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: 6px;
            letter-spacing: .02em;
            transition: background .2s, color .2s;
            display: flex; align-items: center; gap: 6px;
        }
        .navbar-links a:hover, .navbar-links a.active {
            background: rgba(255,255,255,.12);
            color: white;
        }
        .navbar-links a.active {
            background: var(--dorado);
            color: var(--verde);
        }
        .navbar-links .nav-badge {
            background: #ff4444;
            color: white;
            font-size: .62rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }
        .btn-logout {
            margin-left: 10px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.25);
            color: rgba(255,255,255,.9);
            font-size: .82rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            text-decoration: none;
            transition: background .2s;
            display: flex; align-items: center; gap: 6px;
        }
        .btn-logout:hover { background: rgba(255,255,255,.2); color: white; }

        /* ── Hamburger móvil ──────────────────── */
        .hamburger-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            padding: 4px 8px;
            cursor: pointer;
            margin-left: auto;
        }
        .mobile-menu {
            display: none;
            flex-direction: column;
            background: var(--verde-md);
            padding: 12px 16px;
        }
        .mobile-menu a {
            color: rgba(255,255,255,.9);
            text-decoration: none;
            font-weight: 600;
            font-size: .92rem;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,.1);
            display: flex; align-items: center; gap: 8px;
        }
        .mobile-menu a:last-child { border-bottom: none; }
        .mobile-menu.open { display: flex; }

        /* ── Usuario pill ─────────────────────── */
        .user-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.08);
            border-radius: 30px;
            padding: 4px 12px 4px 6px;
            margin-left: 10px;
        }
        .user-pill .avatar {
            width: 30px; height: 30px;
            background: var(--dorado);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--verde);
            font-size: .85rem;
        }
        .user-pill .name {
            color: white;
            font-size: .82rem;
            font-weight: 600;
            max-width: 110px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Responsive ──────────────────────── */
        @media (max-width: 767px) {
            .navbar-links, .user-pill, .btn-logout { display: none !important; }
            .hamburger-btn { display: block; }
        }

        /* ── Contenido general ───────────────── */
        .page-wrapper { min-height: calc(100vh - 64px); }

        /* ── Cards ───────────────────────────── */
        .card-natural {
            background: white;
            border: 1px solid var(--crema-dk);
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }

        /* ── Botones ─────────────────────────── */
        .btn-verde {
            background: var(--verde);
            color: white;
            border: none;
            font-weight: 700;
            letter-spacing: .04em;
            font-size: .82rem;
            border-radius: 6px;
            padding: 8px 16px;
            transition: background .2s, transform .15s;
        }
        .btn-verde:hover { background: var(--verde-md); color: white; transform: translateY(-1px); }
        .btn-dorado {
            background: var(--dorado);
            color: var(--verde);
            border: none;
            font-weight: 700;
            font-size: .82rem;
            letter-spacing: .04em;
            border-radius: 6px;
            padding: 8px 16px;
            transition: background .2s, transform .15s;
        }
        .btn-dorado:hover { background: var(--dorado-lt); color: var(--verde); transform: translateY(-1px); }
    </style>
</head>
<body>

<nav class="top-navbar">
    <div class="container-fluid">
        <!-- Logo -->
        <a href="cliente.php?tab=tienda" class="nav-logo me-4">
            <div class="logo-icon"><i class="bi bi-capsule"></i></div>
            <div>
                <div class="logo-text">PEÑALOZA</div>
                <div class="logo-sub">Populares</div>
            </div>
        </a>

        <!-- Links desktop -->
        <ul class="navbar-links">
            <li><a href="cliente.php?tab=tienda"  class="<?php echo $tab==='tienda' ?'active':''; ?>"><i class="bi bi-shop-window"></i> Tienda</a></li>
            <li><a href="cliente.php?tab=pedidos" class="<?php echo $tab==='pedidos'?'active':''; ?>"><i class="bi bi-bag-check"></i> Mis Pedidos</a></li>
            <li><a href="cliente.php?tab=credito" class="<?php echo $tab==='credito'?'active':''; ?>"><i class="bi bi-credit-card"></i> Mi Crédito</a></li>
        </ul>

        <!-- Usuario -->
        <div class="user-pill d-none d-md-flex">
            <div class="avatar"><i class="bi bi-person-fill"></i></div>
            <span class="name"><?php echo h($nombreSesion); ?></span>
        </div>

        <!-- Salir -->
        <a href="logout.php" class="btn-logout d-none d-md-flex">
            <i class="bi bi-box-arrow-right"></i> Salir
        </a>

        <!-- Hamburger -->
        <button class="hamburger-btn" onclick="toggleMobileMenu()" id="hamburgerBtn">
            <i class="bi bi-list" id="hamburgerIcon"></i>
        </button>
    </div>

    <!-- Menú móvil -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="cliente.php?tab=tienda"><i class="bi bi-shop-window"></i> Tienda</a>
        <a href="cliente.php?tab=pedidos"><i class="bi bi-bag-check"></i> Mis Pedidos</a>
        <a href="cliente.php?tab=credito"><i class="bi bi-credit-card"></i> Mi Crédito</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</nav>

<div class="page-wrapper">
<script>
function toggleMobileMenu() {
    const m = document.getElementById('mobileMenu');
    const i = document.getElementById('hamburgerIcon');
    m.classList.toggle('open');
    i.className = m.classList.contains('open') ? 'bi bi-x-lg' : 'bi bi-list';
}
</script>
