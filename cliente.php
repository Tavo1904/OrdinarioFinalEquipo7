<?php
require_once 'db.php';
require_once 'app_helpers.php';
include 'cliente_header.php';

$id_usuario = (int)$_SESSION['id_usuario'];
$tab        = $_GET['tab'] ?? 'tienda';
$msg_ok     = '';
$msg_err    = '';

// ─── SOLICITAR CRÉDITO ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'solicitar_credito') {
    $monto_sol = (float)($_POST['monto_solicitado'] ?? 0);
    $motivo    = trim($_POST['motivo'] ?? '');

    // INC-SYS-07: Verificar que el usuario tenga RFC registrado y válido
    $stmtRfc = $conexion->prepare("SELECT rfc FROM usuarios WHERE id_usuario = ?");
    $stmtRfc->execute([$id_usuario]);
    $rfcUser = trim((string)($stmtRfc->fetchColumn() ?? ''));

    if (empty($rfcUser)) {
        $msg_err = 'Para solicitar crédito es obligatorio tener un RFC registrado. '
                 . 'Por favor actualiza tu perfil con tu RFC antes de continuar.';
    } elseif (!preg_match('/^[A-Z\xd1&]{3,4}\d{6}[A-Z0-9]{3}$/i', $rfcUser)) {
        $msg_err = 'El RFC registrado (' . htmlspecialchars($rfcUser, ENT_QUOTES, 'UTF-8')
                 . ') no tiene un formato válido (ej. GAMA880101H11). '
                 . 'Actualiza tu perfil con un RFC correcto.';
    } elseif ($monto_sol < 100) {
        $msg_err = 'El monto mínimo a solicitar es $100.';
    } else {
        $chk = $conexion->prepare("SELECT id_credito, estado FROM creditos WHERE id_usuario = ? ORDER BY id_credito DESC LIMIT 1");
        $chk->execute([$id_usuario]);
        $exist = $chk->fetch();
        if ($exist && in_array($exist['estado'], ['pendiente','aprobado'])) {
            $msg_err = $exist['estado'] === 'pendiente'
                ? 'Ya tienes una solicitud de crédito en revisión. Espera la respuesta del administrador.'
                : 'Ya tienes un crédito activo.';
        } else {
            try {
                $conexion->prepare("INSERT INTO creditos (id_usuario, monto_total, saldo_disponible, estado, monto_solicitado, motivo_solicitud, fecha_solicitud) VALUES (?,?,?,'pendiente',?,?,NOW())")
                         ->execute([$id_usuario, 0, 0, $monto_sol, $motivo]);
                $msg_ok = 'Tu solicitud de crédito fue enviada. El administrador la revisará pronto.';
            } catch (Exception $e) {
                $msg_err = 'Ocurrió un error al enviar tu solicitud. Por favor intenta nuevamente o contacta a la farmacia.';
            }
        }
    }
    $tab = 'credito';
}

// ─── GUARDAR PEDIDO WEB ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_pedido') {
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items) || !is_array($items)) {
        $msg_err = 'El carrito está vacío.';
    } else {
        try {
            $conexion->beginTransaction();
            $total = 0;
            foreach ($items as &$it) {
                $st = $conexion->prepare("SELECT * FROM productos WHERE id_producto = ?");
                $st->execute([(int)$it['id']]);
                $p = $st->fetch();
                if (!$p || (int)$p['stock'] < (int)$it['cantidad']) {
                    throw new Exception('Stock insuficiente para: ' . ($p['nombre'] ?? 'producto'));
                }
                if (!can_sell_product($p)) {
                    throw new Exception($p['nombre'] . ': producto vencido, no disponible.');
                }
                $it['precio']      = (float)$p['precio'];
                $it['subtotal']    = $it['precio'] * (int)$it['cantidad'];
                $it['nombre']      = $p['nombre'];
                $it['id_producto'] = $p['id_producto'];
                $total += $it['subtotal'];
            }
            unset($it);

            $metodoPago = in_array($_POST['metodo_pago'] ?? '', ['efectivo','tarjeta','transferencia','credito'])
                ? $_POST['metodo_pago'] : 'efectivo';
            $stP = $conexion->prepare("INSERT INTO pedidos (id_usuario, id_cliente, estado, estado_aprobacion, tipo_pago, origen, total, fecha) VALUES (?,?,'pendiente','pendiente',?,?,?,NOW())");
            $stP->execute([$id_usuario, $id_usuario, $metodoPago, 'web', $total]);
            $id_pedido = $conexion->lastInsertId();

            $stD = $conexion->prepare("INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad, subtotal, precio_unitario, modalidad) VALUES (?,?,?,?,?,'menudeo')");
            foreach ($items as $it) {
                $stD->execute([$id_pedido, $it['id_producto'], $it['cantidad'], $it['subtotal'], $it['precio']]);
            }
            $conexion->commit();
            $msg_ok = '¡Pedido #' . str_pad($id_pedido, 6, '0', STR_PAD_LEFT) . ' enviado! Espera la confirmación del administrador.';
        } catch (Exception $e) {
            $conexion->rollBack();
            $msg_err = $e->getMessage();
        }
    }
    $tab = 'pedidos';
}

// ─── CONSULTAS ────────────────────────────────────────────────────────
$stPed = $conexion->prepare("SELECT p.*, u.nombre as vendedor_nombre FROM pedidos p LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario WHERE p.id_cliente = ? ORDER BY p.fecha DESC LIMIT 50");
$stPed->execute([$id_usuario]);
$mis_pedidos = $stPed->fetchAll(PDO::FETCH_ASSOC);

$stCred = $conexion->prepare("SELECT * FROM creditos WHERE id_usuario = ? ORDER BY id_credito DESC LIMIT 1");
$stCred->execute([$id_usuario]);
$mi_credito = $stCred->fetch();

$productos = $conexion->query("SELECT * FROM productos WHERE stock > 0 AND estado='disponible' ORDER BY nombre ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$productos  = array_values(array_filter($productos, 'can_sell_product'));

$pendientes_count = count(array_filter($mis_pedidos, fn($p) => ($p['estado_aprobacion'] ?? '') === 'pendiente'));
?>

<style>
/* ── Hero ─────────────────────────────────────────────── */
.hero-section {
    background:
        linear-gradient(135deg,
            rgba(67,20,140,.94) 0%,
            rgba(91,33,182,.88) 45%,
            rgba(109,40,217,.80) 100%),
        repeating-linear-gradient(
            45deg,
            rgba(255,255,255,.015) 0px, rgba(255,255,255,.015) 2px,
            transparent 2px, transparent 20px
        );
    background-color: var(--verde);
    padding: 72px 32px 80px;
    position: relative;
    overflow: hidden;
    color: white;
}
.hero-section::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at 70% 50%, rgba(184,154,94,.18) 0%, transparent 65%);
    pointer-events: none;
}
.hero-leaf {
    position: absolute;
    opacity: .07;
    font-size: 160px;
    color: white;
    pointer-events: none;
    line-height: 1;
    user-select: none;
}
.hero-leaf.l1 { top:-20px; right:8%; transform: rotate(-20deg); }
.hero-leaf.l2 { bottom:-30px; right:22%; font-size:100px; transform: rotate(15deg); }
.hero-leaf.l3 { top:10px; right:30%; font-size:70px; transform: rotate(-40deg); }

.hero-title {
    font-size: clamp(1.8rem, 4vw, 2.9rem);
    font-weight: 900;
    letter-spacing: -.01em;
    line-height: 1.15;
    text-shadow: 0 2px 20px rgba(0,0,0,.3);
    margin-bottom: 12px;
}
.hero-sub {
    font-size: 1rem;
    opacity: .85;
    font-weight: 400;
    margin-bottom: 28px;
}
.btn-hero {
    background: var(--dorado);
    color: var(--verde);
    font-weight: 800;
    font-size: .85rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 10px 28px;
    border-radius: 6px;
    border: none;
    text-decoration: none;
    display: inline-block;
    transition: background .2s, transform .15s;
}
.btn-hero:hover { background: var(--dorado-lt); color: var(--verde); transform: translateY(-2px); }

/* ── Categorías ───────────────────────────────────────── */
.cat-section { padding: 36px 32px 0; background: white; }
.cat-card {
    background: var(--verde);
    color: white;
    border-radius: 10px;
    padding: 22px 16px;
    text-align: center;
    cursor: pointer;
    transition: background .2s, transform .18s, box-shadow .18s;
    border: 2px solid transparent;
    text-decoration: none;
    display: block;
}
.cat-card:hover {
    background: var(--verde-md);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(30,77,43,.25);
}
.cat-card.active-cat {
    background: var(--dorado);
    color: var(--verde);
    border-color: var(--verde);
}
.cat-icon {
    font-size: 2rem;
    margin-bottom: 8px;
    display: block;
}
.cat-label {
    font-weight: 800;
    font-size: .82rem;
    letter-spacing: .06em;
    text-transform: uppercase;
    line-height: 1.3;
}

/* ── Productos ────────────────────────────────────────── */
.products-section { padding: 36px 32px 56px; background: white; }
.section-title {
    font-size: 1.15rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--verde);
    text-align: center;
    margin-bottom: 28px;
    position: relative;
}
.section-title::after {
    content: '';
    display: block;
    width: 48px; height: 3px;
    background: var(--dorado);
    border-radius: 2px;
    margin: 8px auto 0;
}

/* ── Tarjeta de producto ──────────────────────────────── */
.prod-card {
    background: white;
    border: 1px solid #ede8de;
    border-radius: 14px;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s, border-color .2s;
    position: relative;
    display: flex;
    flex-direction: column;
}
.prod-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 32px rgba(91,33,182,.12);
    border-color: var(--dorado-lt);
}
.prod-card-img {
    background: linear-gradient(135deg, #f0ebe0, #ede8f8);
    height: 130px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: var(--verde-md);
}
.prod-card-body {
    padding: 14px 14px 8px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.prod-name {
    font-weight: 700;
    font-size: .88rem;
    color: var(--texto);
    margin-bottom: 4px;
    line-height: 1.3;
}
.prod-compuesto {
    font-size: .75rem;
    color: #8a8a8a;
    margin-bottom: 8px;
}
.prod-price {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--verde);
    margin-top: auto;
}
.prod-price .currency { font-size: .72rem; font-weight: 600; vertical-align: super; }
.prod-price .unit-label { font-size: .65rem; color: #aaa; font-weight: 400; margin-left: 4px; }
.prod-stock-badge {
    position: absolute;
    top: 10px; right: 10px;
    background: rgba(255,255,255,.9);
    color: #666;
    font-size: .65rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    border: 1px solid #ddd;
    backdrop-filter: blur(4px);
}
.btn-agregar {
    background: var(--verde);
    color: white;
    border: none;
    font-size: .75rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 9px;
    width: 100%;
    border-radius: 0 0 13px 13px;
    cursor: pointer;
    transition: background .2s;
    margin-top: 10px;
}
.btn-agregar:hover { background: var(--verde-md); }

/* ── Carrito flotante ─────────────────────────────────── */
.carrito-fab {
    position: fixed;
    bottom: 28px; right: 28px;
    background: var(--verde);
    color: white;
    border: none;
    width: 58px; height: 58px;
    border-radius: 50%;
    font-size: 1.4rem;
    box-shadow: 0 6px 24px rgba(91,33,182,.35);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    z-index: 900;
    transition: background .2s, transform .2s;
}
.carrito-fab:hover { background: var(--verde-md); transform: scale(1.07); }
.carrito-fab-badge {
    position: absolute;
    top: -4px; right: -4px;
    background: var(--dorado);
    color: var(--verde);
    font-size: .65rem;
    font-weight: 900;
    width: 20px; height: 20px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
}

/* ── Panel carrito ────────────────────────────────────── */
.carrito-panel {
    position: fixed;
    top: 0; right: 0;
    width: 360px;
    height: 100vh;
    background: white;
    box-shadow: -6px 0 32px rgba(0,0,0,.15);
    z-index: 1100;
    transform: translateX(110%);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
    display: flex; flex-direction: column;
}
.carrito-panel.open { transform: translateX(0); }
.carrito-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.4);
    z-index: 1099;
    display: none;
}
.carrito-overlay.active { display: block; }
.carrito-header {
    background: var(--verde);
    color: white;
    padding: 20px 20px 16px;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.carrito-body {
    flex: 1; overflow-y: auto; padding: 16px;
}
.carrito-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #f0ebe0;
}
.carrito-item-icon {
    width: 42px; height: 42px;
    background: #f0ebe0;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: var(--verde-md);
    font-size: 1.2rem;
    flex-shrink: 0;
}
.carrito-item-info { flex: 1; min-width: 0; }
.carrito-item-name { font-weight: 700; font-size: .82rem; color: var(--texto); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.carrito-item-price { font-size: .78rem; color: var(--verde); font-weight: 600; }
.qty-ctrl { display: flex; align-items: center; gap: 6px; }
.qty-btn {
    width: 26px; height: 26px; border-radius: 50%;
    border: 1.5px solid #ddd; background: white;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: .9rem; font-weight: 700;
    color: var(--verde); transition: background .15s, border-color .15s;
}
.qty-btn:hover { background: var(--verde); color: white; border-color: var(--verde); }
.qty-num { font-weight: 700; font-size: .88rem; min-width: 20px; text-align: center; }
.carrito-footer { padding: 16px; border-top: 1px solid #f0ebe0; flex-shrink: 0; }
.carrito-total { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.carrito-total .label { font-size: 1rem; font-weight: 700; color: var(--texto); }
.carrito-total .amount { font-size: 1.35rem; font-weight: 900; color: var(--verde); }

/* ── Buscador ─────────────────────────────────────────── */
.search-bar {
    display: flex; align-items: center;
    background: #f5f1e8;
    border: 1.5px solid #e0d9cc;
    border-radius: 8px;
    padding: 8px 14px;
    margin-bottom: 24px;
    gap: 10px;
}
.search-bar input {
    border: none; background: transparent;
    font-size: .9rem; width: 100%; outline: none;
    color: var(--texto);
}
.search-bar i { color: #aaa; }

/* ── Tabs internas (Pedidos, Crédito) ─────────────────── */
.inner-tabs { display: flex; gap: 8px; margin-bottom: 28px; padding: 0 32px; background: white; padding-top: 24px; }
.inner-tab {
    padding: 8px 22px;
    border-radius: 30px;
    font-weight: 700;
    font-size: .88rem;
    text-decoration: none;
    color: var(--verde);
    background: #f0ebe0;
    border: 1.5px solid transparent;
    transition: all .2s;
    display: flex; align-items: center; gap: 7px;
}
.inner-tab:hover { background: var(--crema-dk); color: var(--verde); }
.inner-tab.active {
    background: var(--verde);
    color: white;
    border-color: var(--verde);
}
.inner-tab .tab-badge {
    background: #ff4444;
    color: white;
    font-size: .62rem;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 10px;
}

/* ── Páginas internas ─────────────────────────────────── */
.inner-page { padding: 0 32px 48px; background: white; min-height: 50vh; }

/* ── Alertas ─────────────────────────────────────────── */
.alert-natural {
    border-radius: 10px;
    border: none;
    padding: 14px 18px;
    font-size: .9rem;
    display: flex; align-items: flex-start; gap: 10px;
    margin: 0 32px 20px;
}
.alert-ok { background: #e8f5e9; color: #1b5e20; }
.alert-err { background: #fdecea; color: #c62828; }

/* ── Responsive ───────────────────────────────────────── */
@media (max-width: 767px) {
    .hero-section { padding: 48px 20px 60px; }
    .cat-section, .products-section, .inner-page, .inner-tabs { padding-left: 16px; padding-right: 16px; }
    .alert-natural { margin: 0 16px 16px; }
    .carrito-panel { width: 100%; }
}
</style>

<?php if ($msg_ok): ?>
<div class="alert-natural alert-ok">
    <i class="bi bi-check-circle-fill fs-5 flex-shrink-0"></i>
    <div><?php echo h($msg_ok); ?></div>
</div>
<?php endif; ?>
<?php if ($msg_err): ?>
<div class="alert-natural alert-err">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
    <div><?php echo h($msg_err); ?></div>
</div>
<?php endif; ?>

<?php /* ═══════════════════════════ TIENDA (home) ═══════════════════════════ */ ?>
<?php if ($tab === 'tienda'): ?>

<!-- HERO -->
<section class="hero-section">
    <span class="hero-leaf l1"><i class="bi bi-flower1"></i></span>
    <span class="hero-leaf l2"><i class="bi bi-flower2"></i></span>
    <span class="hero-leaf l3"><i class="bi bi-leaf-fill"></i></span>
    <div style="position:relative;z-index:1;max-width:640px;">
        <p class="mb-2" style="font-size:.8rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;opacity:.75;">
            <i class="bi bi-capsule me-1"></i> Farmacia Populares Peñaloza
        </p>
        <h1 class="hero-title">BIENESTAR NATURAL<br>A TU ALCANCE</h1>
        <p class="hero-sub">Descubre nuestra selección de medicamentos y productos de salud.</p>
        <a href="#catalogo-grid" class="btn-hero" onclick="document.getElementById('catalogo-grid').scrollIntoView({behavior:'smooth'});return false;">
            COMPRAR AHORA
        </a>
    </div>
</section>

<!-- CATEGORÍAS -->
<section class="cat-section">
    <div class="row g-3 mb-2 pb-4">
        <div class="col-4">
            <a class="cat-card active-cat" href="#catalogo-grid" onclick="filtrarCategoria('medicamentos');return false;">
                <span class="cat-icon"><i class="bi bi-capsule-pill"></i></span>
                <div class="cat-label">Medicamentos<br>Esenciales</div>
            </a>
        </div>
        <div class="col-4">
            <a class="cat-card" href="#catalogo-grid" onclick="filtrarCategoria('naturales');return false;">
                <span class="cat-icon"><i class="bi bi-flower2"></i></span>
                <div class="cat-label">Remedios<br>Naturales</div>
            </a>
        </div>
        <div class="col-4">
            <a class="cat-card" href="#catalogo-grid" onclick="filtrarCategoria('cuidado');return false;">
                <span class="cat-icon"><i class="bi bi-heart-pulse"></i></span>
                <div class="cat-label">Cuidado<br>Integral</div>
            </a>
        </div>
    </div>
</section>

<!-- CATÁLOGO -->
<section class="products-section" id="catalogo-grid">
    <div class="section-title">PRODUCTOS DESTACADOS</div>

    <div class="search-bar mb-4">
        <i class="bi bi-search"></i>
        <input type="text" id="buscarProd" placeholder="Buscar medicamento por nombre o compuesto…" oninput="filtrarProductos()">
    </div>

    <div class="row g-3" id="catalogoGrid">
    <?php
    $shown = 0;
    foreach ($productos as $pr):
        if (!can_sell_product($pr)) continue;
        $shown++;
        $exp = expiry_status($pr['fecha_caducidad'] ?? null);
        $cats = strtolower($pr['categoria'] ?? '');
        // Asignar data-cat para filtro
        if (strpos($cats,'natur') !== false || strpos($cats,'herb') !== false || strpos($cats,'plant') !== false) {
            $dataCat = 'naturales';
        } elseif (strpos($cats,'cuida') !== false || strpos($cats,'higien') !== false || strpos($cats,'bello') !== false) {
            $dataCat = 'cuidado';
        } else {
            $dataCat = 'medicamentos';
        }
    ?>
    <div class="col-6 col-md-4 col-lg-3 prod-col"
         data-nombre="<?php echo strtolower(h($pr['nombre'])); ?>"
         data-compuesto="<?php echo strtolower(h($pr['compuesto'] ?? '')); ?>"
         data-cat="<?php echo $dataCat; ?>">
        <div class="prod-card h-100">
            <div class="prod-card-img">
                <?php
                $ico = 'bi-capsule';
                $c   = strtolower($pr['categoria'] ?? '');
                if (str_contains($c,'crema') || str_contains($c,'ungüento') || str_contains($c,'gel'))  $ico = 'bi-droplet-half';
                elseif (str_contains($c,'jarabe') || str_contains($c,'soluci'))                         $ico = 'bi-cup-straw';
                elseif (str_contains($c,'inyec') || str_contains($c,'ampol'))                           $ico = 'bi-eyedropper';
                elseif (str_contains($c,'natur') || str_contains($c,'herb') || str_contains($c,'plant'))$ico = 'bi-flower2';
                elseif (str_contains($c,'cuida') || str_contains($c,'bello'))                           $ico = 'bi-heart-pulse';
                elseif (str_contains($c,'vitamina') || str_contains($c,'suple'))                        $ico = 'bi-stars';
                ?>
                <i class="bi <?php echo $ico; ?>"></i>
            </div>
            <span class="prod-stock-badge"><?php echo (int)$pr['stock']; ?> disp.</span>
            <div class="prod-card-body">
                <div class="prod-name"><?php echo h($pr['nombre']); ?></div>
                <?php if (!empty($pr['compuesto'])): ?>
                    <div class="prod-compuesto"><?php echo h($pr['compuesto']); ?></div>
                <?php endif; ?>
                <?php if ($exp['status'] === 'warning'): ?>
                    <div style="font-size:.7rem;color:#b8860b;margin-bottom:4px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>Caduca en <?php echo $exp['days']; ?> días
                    </div>
                <?php endif; ?>
                <div class="prod-price">
                    <span class="currency">$</span><?php echo number_format((float)$pr['precio'], 2); ?>
                    <span class="unit-label">MXN</span>
                </div>
            </div>
            <button class="btn-agregar" onclick='agregarAlCarrito(<?php echo json_encode([
                "id"     => (int)$pr["id_producto"],
                "nombre" => $pr["nombre"],
                "precio" => (float)$pr["precio"],
                "stock"  => (int)$pr["stock"]
            ]); ?>)'>
                <i class="bi bi-cart-plus me-1"></i>AGREGAR AL CARRITO
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
        <div class="col-12 text-center py-5">
            <i class="bi bi-box-seam" style="font-size:3rem;color:#ccc;"></i>
            <p class="text-muted mt-3">No hay productos disponibles en este momento.</p>
        </div>
    <?php endif; ?>
    </div>

    <div class="text-center mt-4 d-none" id="sinResultados">
        <i class="bi bi-search" style="font-size:2.5rem;color:#ccc;"></i>
        <p class="text-muted mt-2">No encontramos productos con ese término.</p>
    </div>
</section>

<?php /* ═══════════════════════════ MIS PEDIDOS ═══════════════════════════ */ ?>
<?php elseif ($tab === 'pedidos'): ?>

<div class="inner-tabs">
    <a href="cliente.php?tab=tienda"  class="inner-tab"><i class="bi bi-shop-window"></i> Tienda</a>
    <a href="cliente.php?tab=pedidos" class="inner-tab active">
        <i class="bi bi-bag-check"></i> Mis Pedidos
        <?php if ($pendientes_count > 0): ?><span class="tab-badge"><?php echo $pendientes_count; ?></span><?php endif; ?>
    </a>
    <a href="cliente.php?tab=credito" class="inner-tab"><i class="bi bi-credit-card"></i> Mi Crédito</a>
</div>

<div class="inner-page">
    <h5 class="fw-bold mb-4" style="color:var(--verde);">
        <i class="bi bi-bag-check me-2"></i>Historial de Pedidos
    </h5>

    <?php if (empty($mis_pedidos)): ?>
    <div class="text-center py-5" style="background:#f9f7f2;border-radius:14px;">
        <i class="bi bi-bag-x" style="font-size:3rem;color:#ccc;"></i>
        <p class="text-muted mt-3 mb-1 fw-bold">Aún no tienes pedidos</p>
        <a href="cliente.php?tab=tienda" class="btn-verde" style="display:inline-block;margin-top:8px;text-decoration:none;border-radius:6px;padding:8px 20px;font-size:.85rem;">Ir a la Tienda</a>
    </div>
    <?php else: ?>
    <div class="card-natural overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr style="background:#f7f3eb;">
                        <th class="ps-3 py-3 fw-bold" style="color:var(--verde);font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;">Folio</th>
                        <th class="py-3 fw-bold" style="color:var(--verde);font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;">Fecha</th>
                        <th class="py-3 fw-bold" style="color:var(--verde);font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;">Total</th>
                        <th class="py-3 fw-bold" style="color:var(--verde);font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;">Estado</th>
                        <th class="py-3 fw-bold" style="color:var(--verde);font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;">Recolección</th>
                        <th class="pe-3 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mis_pedidos as $p):
                    $ea = $p['estado_aprobacion'] ?? 'pendiente';
                    if ($ea === 'pendiente') {
                        $bg='#fff8e1'; $color='#795800'; $icon='bi-clock'; $txt='En revisión';
                    } elseif ($ea === 'aprobado') {
                        $bg='#e8f5e9'; $color='#1b5e20'; $icon='bi-check-circle-fill'; $txt='Aceptado';
                    } else {
                        $bg='#fdecea'; $color='#c62828'; $icon='bi-x-circle-fill'; $txt='Rechazado';
                    }
                ?>
                <tr>
                    <td class="ps-3 fw-bold text-muted" style="font-size:.85rem;">#<?php echo str_pad($p['id_pedido'],6,'0',STR_PAD_LEFT); ?></td>
                    <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($p['fecha'])); ?></td>
                    <td class="fw-bold" style="color:var(--verde);font-size:.95rem;">$<?php echo number_format((float)$p['total'],2); ?></td>
                    <td>
                        <span style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;font-weight:700;font-size:.78rem;padding:5px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">
                            <i class="bi <?php echo $icon; ?>"></i><?php echo $txt; ?>
                        </span>
                        <?php if (!empty($p['comentario_aprobacion'])): ?>
                            <div class="small text-muted mt-1 fst-italic"><?php echo h($p['comentario_aprobacion']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($ea==='aprobado' && !empty($p['hora_recoleccion'])): ?>
                            <span style="color:var(--verde);font-weight:700;">
                                <i class="bi bi-clock-fill me-1"></i><?php echo date('h:i A', strtotime($p['hora_recoleccion'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pe-3">
                        <button class="btn btn-sm" style="background:#f0ebe0;color:var(--verde);font-weight:700;font-size:.78rem;border-radius:6px;" onclick="verDetalle(<?php echo (int)$p['id_pedido']; ?>)">
                            <i class="bi bi-eye me-1"></i>Ver
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal detalle pedido -->
<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:14px;border:none;">
            <div class="modal-header" style="background:var(--verde);color:white;border-radius:14px 14px 0 0;border:none;">
                <h5 class="modal-title fw-bold"><i class="bi bi-bag me-2"></i>Detalle del Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detalleBody">
                <div class="text-center py-4"><div class="spinner-border" style="color:var(--verde);"></div></div>
            </div>
        </div>
    </div>
</div>

<?php /* ═══════════════════════════ MI CRÉDITO ════════════════════════════ */ ?>
<?php elseif ($tab === 'credito'): ?>

<div class="inner-tabs">
    <a href="cliente.php?tab=tienda"  class="inner-tab"><i class="bi bi-shop-window"></i> Tienda</a>
    <a href="cliente.php?tab=pedidos" class="inner-tab">
        <i class="bi bi-bag-check"></i> Mis Pedidos
        <?php if ($pendientes_count > 0): ?><span class="tab-badge"><?php echo $pendientes_count; ?></span><?php endif; ?>
    </a>
    <a href="cliente.php?tab=credito" class="inner-tab active"><i class="bi bi-credit-card"></i> Mi Crédito</a>
</div>

<div class="inner-page">
    <h5 class="fw-bold mb-4" style="color:var(--verde);"><i class="bi bi-credit-card me-2"></i>Mi Línea de Crédito</h5>

    <?php if ($mi_credito): ?>
    <?php
    $est = $mi_credito['estado'];
    if ($est === 'pendiente') {
        $accentBg='#fff8e1'; $accentColor='#795800'; $icon='bi-clock-history'; $estTxt='En revisión';
    } elseif ($est === 'aprobado') {
        $accentBg='#e8f5e9'; $accentColor='#1b5e20'; $icon='bi-check-circle-fill'; $estTxt='Aprobado';
    } else {
        $accentBg='#fdecea'; $accentColor='#c62828'; $icon='bi-x-circle-fill'; $estTxt='Rechazado';
    }
    ?>
    <div class="card-natural p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <p class="text-muted small mb-2">Estado de tu solicitud</p>
                <span style="background:<?php echo $accentBg; ?>;color:<?php echo $accentColor; ?>;font-weight:800;font-size:.88rem;padding:7px 16px;border-radius:20px;display:inline-flex;align-items:center;gap:7px;">
                    <i class="bi <?php echo $icon; ?>"></i><?php echo $estTxt; ?>
                </span>
            </div>
            <div class="text-end">
                <div class="small text-muted">Solicitado el</div>
                <div class="fw-bold"><?php echo date('d/m/Y', strtotime($mi_credito['fecha_solicitud'])); ?></div>
            </div>
        </div>

        <?php if ($est === 'pendiente'): ?>
            <div style="background:#f0f8f0;border-left:4px solid var(--verde-lt);border-radius:0 8px 8px 0;padding:14px 16px;">
                <p class="mb-0 small"><i class="bi bi-info-circle me-2" style="color:var(--verde);"></i>Tu solicitud está siendo revisada. Te notificaremos cuando haya una respuesta.</p>
                <?php $ms=(float)($mi_credito['monto_solicitado']??0); if($ms>0): ?>
                    <p class="mb-0 mt-1 small fw-bold" style="color:var(--verde);">Monto solicitado: $<?php echo number_format($ms,2); ?> MXN</p>
                <?php endif; ?>
            </div>

        <?php elseif ($est === 'aprobado'): ?>
            <div class="row g-3">
                <div class="col-6">
                    <div class="text-center p-3 rounded-3" style="background:#f7f3eb;">
                        <div class="small fw-bold text-muted mb-1">LÍMITE</div>
                        <div class="fw-bold" style="font-size:1.6rem;color:var(--verde);">$<?php echo number_format((float)$mi_credito['monto_total'],2); ?></div>
                        <div class="small text-muted">MXN</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="text-center p-3 rounded-3" style="background:#e8f5e9;">
                        <div class="small fw-bold text-muted mb-1">DISPONIBLE</div>
                        <div class="fw-bold" style="font-size:1.6rem;color:var(--verde-md);">$<?php echo number_format((float)$mi_credito['saldo_disponible'],2); ?></div>
                        <div class="small text-muted">MXN</div>
                    </div>
                </div>
                <?php $deuda = (float)$mi_credito['monto_total'] - (float)$mi_credito['saldo_disponible']; ?>
                <?php if ($deuda > 0.01): ?>
                <div class="col-12">
                    <div class="text-center p-3 rounded-3" style="background:#fdecea;border:1px solid #f5c6c6;">
                        <div class="small fw-bold text-muted mb-1">DEUDA ACTUAL</div>
                        <div class="fw-bold" style="font-size:1.3rem;color:#c62828;">$<?php echo number_format($deuda,2); ?> MXN</div>
                        <div class="small text-muted mt-1">Acércate a la farmacia para realizar un abono</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($mi_credito['comentario_revision'])): ?>
                <div class="mt-3 p-3 rounded-3" style="background:#f7f3eb;font-size:.85rem;color:#666;">
                    <i class="bi bi-chat-left-text me-1"></i><?php echo h($mi_credito['comentario_revision']); ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="background:#fdecea;border-left:4px solid #e57373;border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:20px;">
                <p class="mb-0 small fw-bold text-danger"><i class="bi bi-x-circle-fill me-2"></i>Tu solicitud fue rechazada.</p>
                <?php if (!empty($mi_credito['comentario_revision'])): ?>
                    <p class="mb-0 mt-1 small">Motivo: <?php echo h($mi_credito['comentario_revision']); ?></p>
                <?php endif; ?>
            </div>
            <p class="small text-muted mb-3">Puedes solicitar un nuevo crédito si tus condiciones cambian.</p>
            <form method="POST">
                <input type="hidden" name="accion" value="solicitar_credito">
                <div class="row g-3">
                    <div class="col-sm-5">
                        <label class="small fw-bold text-muted">MONTO A SOLICITAR (MXN) *</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_solicitado" class="form-control" min="100" step="100" placeholder="500.00" required>
                        </div>
                    </div>
                    <div class="col-sm-7">
                        <label class="small fw-bold text-muted">MOTIVO (OPCIONAL)</label>
                        <input type="text" name="motivo" class="form-control" placeholder="¿Para qué necesitas el crédito?">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn-verde" style="border-radius:6px;padding:9px 24px;font-size:.85rem;display:inline-block;border:none;cursor:pointer;">
                            <i class="bi bi-send me-1"></i>Solicitar Crédito
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="card-natural p-4">
        <div class="mb-1" style="font-size:2.5rem;color:var(--dorado);"><i class="bi bi-credit-card-2-front"></i></div>
        <h6 class="fw-bold mb-1">Solicitar Línea de Crédito</h6>
        <p class="text-muted small mb-4">Completa el formulario para solicitar crédito. Podrás comprar ahora y pagar en la farmacia.</p>
        <form method="POST">
            <input type="hidden" name="accion" value="solicitar_credito">
            <div class="row g-3">
                <div class="col-sm-5">
                    <label class="small fw-bold text-muted">MONTO A SOLICITAR (MXN) *</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text">$</span>
                        <input type="number" name="monto_solicitado" class="form-control fw-bold" min="100" step="100" placeholder="1,000.00" required>
                    </div>
                    <div class="small text-muted mt-1">Mínimo: $100 MXN</div>
                </div>
                <div class="col-sm-7">
                    <label class="small fw-bold text-muted">MOTIVO (OPCIONAL)</label>
                    <textarea name="motivo" class="form-control" rows="3" placeholder="Explica para qué necesitas el crédito…"></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn-verde" style="border-radius:8px;padding:11px 32px;font-size:.9rem;display:inline-block;border:none;cursor:pointer;">
                        <i class="bi bi-send-fill me-2"></i>Enviar Solicitud
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- ════════════════════ CARRITO FLOTANTE (solo en tienda) ════════════════════ -->
<?php if ($tab === 'tienda'): ?>
<button class="carrito-fab" onclick="abrirCarrito()" id="carritoFab" style="display:none;" title="Ver carrito">
    <i class="bi bi-cart3"></i>
    <span class="carrito-fab-badge" id="carritoCount">0</span>
</button>

<div class="carrito-overlay" id="carritoOverlay" onclick="cerrarCarrito()"></div>
<div class="carrito-panel" id="carritoPanel">
    <div class="carrito-header">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-cart3 me-2"></i>Mi Carrito</h5>
            <small style="opacity:.8;">Revisa tu pedido antes de enviarlo</small>
        </div>
        <button onclick="cerrarCarrito()" style="background:rgba(255,255,255,.15);border:none;color:white;width:34px;height:34px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="carrito-body" id="carritoBody">
        <div class="text-center py-5 text-muted">
            <i class="bi bi-basket" style="font-size:2.5rem;opacity:.3;"></i>
            <p class="mt-3 small">El carrito está vacío.<br>Agrega productos de la tienda.</p>
        </div>
    </div>
    <div class="carrito-footer" id="carritoFooter" style="display:none;">
        <div class="carrito-total">
            <span class="label">Total</span>
            <span class="amount" id="totalCarrito">$0.00</span>
        </div>
        <form method="POST" id="pedidoForm">
            <input type="hidden" name="accion" value="guardar_pedido">
            <input type="hidden" name="items" id="itemsInput">
            <input type="hidden" name="metodo_pago" id="metodoPagoInput" value="efectivo">
            <button type="button" class="btn-verde w-100 py-2" style="border-radius:8px;font-size:.88rem;cursor:pointer;border:none;" onclick="abrirModalPago()">
                <i class="bi bi-send-fill me-2"></i>ENVIAR PEDIDO
            </button>
        </form>
        <button class="w-100 mt-2 py-2" style="background:transparent;border:1.5px solid #ddd;color:#888;font-size:.8rem;font-weight:700;border-radius:8px;cursor:pointer;" onclick="limpiarCarrito()">
            <i class="bi bi-trash me-1"></i>Vaciar carrito
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════ MODAL: MÉTODO DE PAGO ════════════════ -->
<?php if ($tab === 'tienda'): ?>
<div class="modal fade" id="modalMetodoPago" tabindex="-1" aria-labelledby="modalPagoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;">
            <div class="modal-header border-0" style="background:var(--verde);color:white;padding:20px 24px;">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="modalPagoLabel">
                        <i class="bi bi-credit-card-2-front me-2"></i>¿Cómo pagarás tu pedido?
                    </h5>
                    <small style="opacity:.8;">Elige el método de pago al momento de recoger</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3" id="opcionesPago">
                    <div class="col-6">
                        <div class="pago-opcion" onclick="seleccionarPago('efectivo')" data-metodo="efectivo" style="border:2px solid #e0e0e0;border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;">
                            <div style="font-size:2rem;margin-bottom:8px;">💵</div>
                            <div style="font-weight:700;font-size:.9rem;color:#1e4d2b;">Efectivo</div>
                            <div style="font-size:.75rem;color:#888;margin-top:4px;">Pagas al recoger</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="pago-opcion" onclick="seleccionarPago('tarjeta')" data-metodo="tarjeta" style="border:2px solid #e0e0e0;border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;">
                            <div style="font-size:2rem;margin-bottom:8px;">💳</div>
                            <div style="font-weight:700;font-size:.9rem;color:#1e4d2b;">Tarjeta</div>
                            <div style="font-size:.75rem;color:#888;margin-top:4px;">Crédito o débito</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="pago-opcion" onclick="seleccionarPago('transferencia')" data-metodo="transferencia" style="border:2px solid #e0e0e0;border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;">
                            <div style="font-size:2rem;margin-bottom:8px;">🏦</div>
                            <div style="font-weight:700;font-size:.9rem;color:#1e4d2b;">Transferencia</div>
                            <div style="font-size:.75rem;color:#888;margin-top:4px;">SPEI / CoDi</div>
                        </div>
                    </div>
                    <?php if (!empty($mi_credito) && $mi_credito['estado'] === 'aprobado' && (float)$mi_credito['saldo_disponible'] > 0): ?>
                    <div class="col-6">
                        <div class="pago-opcion" onclick="seleccionarPago('credito')" data-metodo="credito" style="border:2px solid #e0e0e0;border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;">
                            <div style="font-size:2rem;margin-bottom:8px;">📋</div>
                            <div style="font-weight:700;font-size:.9rem;color:#1e4d2b;">Crédito</div>
                            <div style="font-size:.75rem;color:#888;margin-top:4px;">Disponible: $<?php echo number_format((float)$mi_credito['saldo_disponible'],2); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="pagoSeleccionadoMsg" style="display:none;margin-top:16px;padding:10px 14px;background:#e8f5e9;border-radius:8px;font-size:.88rem;color:#1b5e20;font-weight:700;">
                    <i class="bi bi-check-circle-fill me-1"></i><span id="pagoSeleccionadoTxt"></span>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn fw-bold px-4" id="btnConfirmarPedido"
                        style="background:var(--verde);color:white;border-radius:8px;" onclick="confirmarPedido()" disabled>
                    <i class="bi bi-send-fill me-2"></i>Confirmar Pedido
                </button>
            </div>
        </div>
    </div>
</div>
<style>
.pago-opcion.selected {
    border-color: var(--verde) !important;
    background: #e8f5e9;
}
.pago-opcion:hover { border-color: var(--verde-md) !important; background: #f0f8f0; }
</style>
<?php endif; ?>

</div><!-- /page-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Carrito ─────────────────────────────────────────── */
let carrito = {};

function agregarAlCarrito(prod) {
    if (!carrito[prod.id]) carrito[prod.id] = { ...prod, cantidad: 0 };
    if (carrito[prod.id].cantidad >= prod.stock) { alert('Stock máximo: ' + prod.stock); return; }
    carrito[prod.id].cantidad++;
    renderCarrito();
    abrirCarrito();
}

function cambiarCantidad(id, delta) {
    if (!carrito[id]) return;
    carrito[id].cantidad += delta;
    if (carrito[id].cantidad <= 0) delete carrito[id];
    renderCarrito();
}

function limpiarCarrito() {
    carrito = {};
    renderCarrito();
}

function renderCarrito() {
    const body   = document.getElementById('carritoBody');
    const footer = document.getElementById('carritoFooter');
    const count  = document.getElementById('carritoCount');
    const fab    = document.getElementById('carritoFab');
    const total  = document.getElementById('totalCarrito');
    const items  = Object.values(carrito);

    if (!body) return;

    let totalAmt = 0, totalItems = 0;

    if (items.length === 0) {
        body.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-basket" style="font-size:2.5rem;opacity:.3;"></i><p class="mt-3 small">El carrito está vacío.</p></div>';
        footer.style.display = 'none';
        fab.style.display    = 'none';
        return;
    }

    let html = '';
    items.forEach(item => {
        const sub  = item.precio * item.cantidad;
        totalAmt  += sub;
        totalItems+= item.cantidad;
        html += `
        <div class="carrito-item">
            <div class="carrito-item-icon"><i class="bi bi-capsule"></i></div>
            <div class="carrito-item-info">
                <div class="carrito-item-name">${item.nombre}</div>
                <div class="carrito-item-price">$${item.precio.toFixed(2)} MXN &times; ${item.cantidad} = $${sub.toFixed(2)}</div>
            </div>
            <div class="qty-ctrl">
                <button class="qty-btn" onclick="cambiarCantidad(${item.id}, -1)">−</button>
                <span class="qty-num">${item.cantidad}</span>
                <button class="qty-btn" onclick="cambiarCantidad(${item.id}, 1)">+</button>
            </div>
        </div>`;
    });

    body.innerHTML   = html;
    footer.style.display = 'block';
    fab.style.display    = 'flex';
    count.textContent    = totalItems;
    total.textContent    = '$' + totalAmt.toFixed(2);
}

function abrirCarrito() {
    document.getElementById('carritoPanel').classList.add('open');
    document.getElementById('carritoOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function cerrarCarrito() {
    document.getElementById('carritoPanel').classList.remove('open');
    document.getElementById('carritoOverlay').classList.remove('active');
    document.body.style.overflow = '';
}

let metodoPagoSeleccionado = '';

function abrirModalPago() {
    const items = Object.values(carrito);
    if (items.length === 0) { alert('El carrito está vacío.'); return; }
    // Reset selección
    metodoPagoSeleccionado = '';
    document.querySelectorAll('.pago-opcion').forEach(el => el.classList.remove('selected'));
    document.getElementById('pagoSeleccionadoMsg').style.display = 'none';
    document.getElementById('btnConfirmarPedido').disabled = true;
    new bootstrap.Modal(document.getElementById('modalMetodoPago')).show();
}

function seleccionarPago(metodo) {
    metodoPagoSeleccionado = metodo;
    document.querySelectorAll('.pago-opcion').forEach(el => {
        el.classList.toggle('selected', el.dataset.metodo === metodo);
    });
    const labels = { efectivo:'Efectivo', tarjeta:'Tarjeta (crédito/débito)', transferencia:'Transferencia bancaria (SPEI)', credito:'Crédito en cuenta' };
    document.getElementById('pagoSeleccionadoTxt').textContent = 'Método seleccionado: ' + (labels[metodo] || metodo);
    document.getElementById('pagoSeleccionadoMsg').style.display = 'block';
    document.getElementById('btnConfirmarPedido').disabled = false;
}

function confirmarPedido() {
    if (!metodoPagoSeleccionado) { alert('Selecciona un método de pago.'); return; }
    const items = Object.values(carrito).map(i => ({ id: i.id, cantidad: i.cantidad }));
    if (items.length === 0) { alert('El carrito está vacío.'); return; }
    document.getElementById('itemsInput').value = JSON.stringify(items);
    document.getElementById('metodoPagoInput').value = metodoPagoSeleccionado;
    document.getElementById('pedidoForm').submit();
}

/* ── Filtros ──────────────────────────────────────────── */
function filtrarProductos() {
    const q    = document.getElementById('buscarProd').value.toLowerCase().trim();
    const cols = document.querySelectorAll('.prod-col');
    let visible = 0;
    cols.forEach(c => {
        const match = !q
            || c.dataset.nombre.includes(q)
            || c.dataset.compuesto.includes(q);
        c.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noRes = document.getElementById('sinResultados');
    if (noRes) noRes.classList.toggle('d-none', visible > 0);
}

function filtrarCategoria(cat) {
    document.getElementById('buscarProd').value = '';
    const cols = document.querySelectorAll('.prod-col');
    cols.forEach(c => {
        c.style.display = (cat === 'todos' || c.dataset.cat === cat) ? '' : 'none';
    });
    document.getElementById('sinResultados')?.classList.add('d-none');
    // Actualizar estado visual de las cat cards
    document.querySelectorAll('.cat-card').forEach(card => {
        card.classList.remove('active-cat');
    });
    event.target.closest('.cat-card')?.classList.add('active-cat');
    document.getElementById('catalogo-grid').scrollIntoView({ behavior: 'smooth' });
}

/* ── Modal detalle pedido ─────────────────────────────── */
function verDetalle(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
    const body  = document.getElementById('detalleBody');
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:var(--verde);"></div></div>';
    modal.show();
    fetch('detalle_pedido_cliente.php?id=' + id)
        .then(r => r.text())
        .then(html => { body.innerHTML = html; })
        .catch(() => { body.innerHTML = '<p class="text-danger text-center">Error al cargar el detalle.</p>'; });
}
</script>
</body>
</html>
