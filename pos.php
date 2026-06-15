<?php
  include 'db.php';
  include 'header.php';
  require_perm('ventas'); // MEJORA 2: Solo empleados con permiso de ventas

  
$stmt = $conexion->query("
    SELECT *
    FROM productos
    WHERE stock > 0 AND estado='disponible'
    ORDER BY nombre ASC, fecha_caducidad ASC
    LIMIT 120
");
$productos = array_values(array_filter($stmt->fetchAll(), 'can_sell_product'));

// Clientes para selector
$clientes = $conexion->query("
    SELECT id_usuario, nombre, rfc, tipo_cliente, limite_mayoreo
    FROM usuarios
    WHERE rol = 'cliente' OR tipo_cliente = 'mayorista'
    ORDER BY nombre ASC
")->fetchAll();

// Clientes con credito aperturado y saldo disponible
$clientesCredito = $conexion->query("
    SELECT u.id_usuario, u.nombre, c.id_credito, c.saldo_disponible, c.monto_total
    FROM creditos c
    INNER JOIN usuarios u ON c.id_usuario = u.id_usuario
    WHERE c.estado = 'aprobado' AND c.saldo_disponible > 0
    ORDER BY u.nombre ASC
")->fetchAll();

// ─── Leer límite mayoreo global desde configuración ─────────────────
$limiteMayoreoGlobal = 50;
try {
    $stmtCfg = $conexion->query("SELECT valor FROM configuracion WHERE clave='limite_mayoreo_def' LIMIT 1");
    if ($stmtCfg) {
        $valCfg = $stmtCfg->fetchColumn();
        if ($valCfg !== false && (int)$valCfg > 0) $limiteMayoreoGlobal = (int)$valCfg;
    }
} catch (Exception $e) {}
?>

<style>
    .product-card-btn { transition: all .2s ease-in-out; border: 1px solid transparent; }
    .product-card-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,.08) !important; border-color: #0d6efd !important; }
    .btn-icon-danger { color:#dc3545; border-radius:50%; width:30px; height:30px; display:flex; align-items:center; justify-content:center; }
    .btn-icon-danger:hover { background-color:#ffeaea; }
    .scroll-clean::-webkit-scrollbar { width:6px; }
    .scroll-clean::-webkit-scrollbar-thumb { background-color:#dee2e6; border-radius:10px; }
    .search-empty { display:none; }
    #ticketPanel { position: sticky; top: 20px; max-height: calc(100vh - 80px); display: flex; flex-direction: column; }
    #ticketPanel .card { max-height: calc(100vh - 80px); display: flex; flex-direction: column; }
    #ticketPanel .card-body { overflow-y: auto; flex: 1 1 0; min-height: 80px; }
    #ticketPanel .card-footer { flex-shrink: 0; }
    #ticketPanel .card-header { flex-shrink: 0; }
    #ticketPanel .p-3.border-bottom { flex-shrink: 0; }
    @media(max-width:767px){
        #ticketPanel{position:static;max-height:none;}
        #ticketPanel .card{max-height:none;}
    }
</style>

<div class="row g-0">
    <!-- LADO IZQUIERDO: Productos -->
    <div class="col-md-8 pe-3">
        <div class="d-flex justify-content-between align-items-center mb-3 pt-2 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold text-dark mb-0">Punto de Venta</h4>
                <small class="text-muted">Mayoreo automatico desde <strong id="lblLimiteMayoreo"><?php echo $limiteMayoreoGlobal; ?></strong> uds por producto</small>
            </div>
            <div class="input-group w-auto flex-grow-1 shadow-sm" style="max-width:320px;">
                <span class="input-group-text bg-white border-end-0 ps-3"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="buscador" class="form-control border-start-0 py-2" placeholder="Buscar medicamento o lote..." autocomplete="off">
            </div>
        </div>

        <div id="blockedLots" class="alert alert-warning py-2 d-none"></div>
        <div id="sinResultados" class="alert alert-light border search-empty">No se encontraron productos con ese termino.</div>

        <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-3 scroll-clean pb-5" id="gridProductos" style="overflow-y:auto;max-height:calc(100vh - 200px);">
            <?php foreach ($productos as $p):
                $expiry = expiry_status($p['fecha_caducidad'] ?? null);
            ?>
            <div class="col item-card">
                <div class="card h-100 card-custom border-0 shadow-sm position-relative overflow-hidden product-card-btn"
                     style="cursor:pointer;"
                     onclick='agregarAlCarrito(<?php echo json_encode([
                         'id'           => (int)$p['id_producto'],
                         'nombre'       => $p['nombre'],
                         'lote'         => $p['numero_lote'] ?? '',
                         'precio'       => (float)$p['precio'],
                         'precio_mayoreo'=> (float)$p['precio_mayoreo'],
                         'stock'        => (int)$p['stock'],
                         'caducidad'    => $p['fecha_caducidad'] ?? ''
                     ]); ?>)'>
                    <div class="card-body text-center p-3 d-flex flex-column justify-content-center">
                        <div class="mb-2"><div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex p-3"><i class="bi bi-capsule fs-3"></i></div></div>
                        <h6 class="fw-bold mb-1 text-truncate text-dark small" title="<?php echo h($p['nombre']); ?>"><?php echo h($p['nombre']); ?></h6>
                        <div class="small text-muted">Lote <?php echo h($p['numero_lote'] ?? ''); ?></div>
                        <h5 class="text-success fw-bold mb-0">$<?php echo number_format((float)$p['precio'], 2); ?></h5>
                        <?php if ($expiry['status'] === 'warning'): ?>
                            <span class="badge bg-warning-subtle text-warning border mt-2"><?php echo h($expiry['message']); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-white text-secondary border position-absolute top-0 end-0 m-2 shadow-sm">Stock: <?php echo (int)$p['stock']; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- LADO DERECHO: Ticket fijo -->
    <div class="col-md-4" id="ticketPanel">
        <div class="card card-custom border-0 shadow-lg overflow-hidden">
            <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="bi bi-receipt me-2"></i> Ticket</h5>
                <span class="badge bg-white text-primary rounded-pill" id="badgeCount">0 items</span>
            </div>

            <div class="p-3 border-bottom bg-light">
                <label class="small fw-bold text-muted">Cliente</label>
                <select class="form-select" id="clienteSelect" onchange="actualizarCliente()">
                    <option value="" data-tipo="minorista" data-limite="<?php echo $limiteMayoreoGlobal; ?>">Venta de mostrador</option>
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?php echo (int)$c['id_usuario']; ?>"
                                data-tipo="<?php echo h($c['tipo_cliente']); ?>"
                                data-limite="<?php echo (int)($c['limite_mayoreo'] ?: $limiteMayoreoGlobal); ?>">
                            <?php echo h($c['nombre']); ?> — <?php echo h($c['tipo_cliente']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Carrito scrollable -->
            <div class="card-body p-0 bg-white scroll-clean">
                <table class="table mb-0"><tbody id="tablaCarrito"></tbody></table>
                <div id="msgVacio" class="text-center text-muted mt-4 pb-4">
                    <i class="bi bi-basket display-1 opacity-25"></i>
                    <p class="mt-3 fw-bold">El carrito esta vacio</p>
                    <small>Selecciona productos de la izquierda</small>
                </div>
            </div>

            <!-- Footer -->
            <div class="card-footer bg-light border-top p-3">
                <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Subtotal</span><span class="fw-bold text-dark" id="lblSubtotal">$0.00</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="fs-5 fw-bold text-dark">Total</span><span class="fs-3 fw-bold text-success" id="lblTotal">$0.00</span></div>

                <div id="mayoreoMsg" class="alert alert-info py-2 small d-none mb-2"></div>
                <div id="alertaCredito" class="alert alert-danger py-2 small d-none mb-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="alertaCreditoMsg"></span>
                </div>

                <div class="mb-2">
                    <select class="form-select form-select-sm border-0 bg-white shadow-sm" id="tipoPago" onchange="onTipoPagoChange()">
                        <option value="efectivo">Pago en Efectivo</option>
                        <option value="tarjeta">Pago con Tarjeta</option>
                        <option value="credito">Nota de Credito</option>
                    </select>
                </div>

                <div id="panelCredito" class="d-none mb-2">
                    <label class="small fw-bold text-muted">Seleccionar cliente con credito</label>
                    <select class="form-select form-select-sm" id="clienteCreditoSelect" onchange="onCreditoClienteChange()">
                        <option value="">-- Elegir cliente --</option>
                        <?php foreach ($clientesCredito as $cc): ?>
                            <option value="<?php echo (int)$cc['id_usuario']; ?>"
                                    data-saldo="<?php echo (float)$cc['saldo_disponible']; ?>"
                                    data-total="<?php echo (float)$cc['monto_total']; ?>"
                                    data-credito="<?php echo (int)$cc['id_credito']; ?>">
                                <?php echo h($cc['nombre']); ?> — Saldo: $<?php echo number_format((float)$cc['saldo_disponible'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($clientesCredito)): ?>
                        <div class="alert alert-warning py-1 mt-1 small mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>No hay clientes con credito disponible.
                        </div>
                    <?php endif; ?>
                    <div id="infoCreditoCliente" class="mt-1 small text-muted d-none">
                        Saldo disponible: <strong id="saldoCreditoDisp">$0.00</strong>
                    </div>
                </div>

                <button class="btn btn-primary w-100 btn-lg fw-bold py-2 rounded-3 shadow-sm" onclick="cobrar()">
                    COBRAR AHORA <i class="bi bi-arrow-right ms-2"></i>
                </button>
            </div>
        </div>
    </div>
</div>

</div></div>

<script>
// ─── Límite de mayoreo global (viene del servidor) ───────────────────
const LIMITE_MAYOREO_DEFAULT = <?php echo (int)$limiteMayoreoGlobal; ?>;

let carrito = [];
let clienteActual = { id: null, tipo: 'minorista', limite: LIMITE_MAYOREO_DEFAULT };

function precioAplicable(item) {
    return item.modalidad === 'mayoreo' ? item.precio_mayoreo : item.precio;
}

function agregarAlCarrito(prod) {
    if (prod.stock < 1) { alert('Producto sin existencias.'); return; }
    let item = carrito.find(i => i.id === prod.id);
    if (item) { cambiarCant(carrito.indexOf(item), 1); return; }

    // Al agregar, siempre empieza en menudeo (qty=1 nunca alcanza el mínimo mayoreo)
    carrito.push({
        id: prod.id, nombre: prod.nombre, lote: prod.lote,
        precio: Number(prod.precio),
        precio_mayoreo: Number(prod.precio_mayoreo || prod.precio),
        cantidad: 1, stockMax: Number(prod.stock),
        modalidad: 'menudeo'
    });
    aplicarReglasMayoreo();
    renderizarCarrito();
}

/**
 * CP-POS-02 FIX:
 * La regla de mayoreo se aplica a CUALQUIER cliente (incluyendo mostrador)
 * cuando la cantidad supera el umbral (clienteActual.limite).
 * No requiere tipo_cliente = 'mayorista'.
 */
function aplicarReglasMayoreo() {
    carrito.forEach(item => {
        // Si la cantidad alcanza el umbral, cambiar a mayoreo
        if (item.cantidad >= clienteActual.limite && item.precio_mayoreo > 0 && item.precio_mayoreo < item.precio) {
            item.modalidad = 'mayoreo';
        }
        // Si la cantidad cae por debajo, volver a menudeo (sólo si no fue cambiado manualmente a mayoreo con <limite)
        else if (item.modalidad === 'mayoreo' && item.cantidad < clienteActual.limite) {
            item.modalidad = 'menudeo';
        }
    });
}

function renderizarCarrito() {
    const tbody      = document.getElementById('tablaCarrito');
    const msgVacio   = document.getElementById('msgVacio');
    const badgeCount = document.getElementById('badgeCount');
    const mayoreoMsg = document.getElementById('mayoreoMsg');
    tbody.innerHTML = '';
    let total = 0, itemsTotal = 0, mensajesMayoreo = [];

    msgVacio.style.display = carrito.length === 0 ? 'block' : 'none';

    carrito.forEach((item, index) => {
        const unitario = precioAplicable(item);
        const subtotal = unitario * item.cantidad;
        total += subtotal;
        itemsTotal += item.cantidad;

        // Mensaje informativo de mayoreo
        if (item.modalidad === 'mayoreo') {
            mensajesMayoreo.push(`${item.nombre}: precio mayoreo $${item.precio_mayoreo.toFixed(2)} (x${item.cantidad} uds)`);
        } else if (item.precio_mayoreo > 0 && item.precio_mayoreo < item.precio) {
            const faltan = clienteActual.limite - item.cantidad;
            if (faltan > 0) mensajesMayoreo.push(`${item.nombre}: agrega ${faltan} mas para precio mayoreo $${item.precio_mayoreo.toFixed(2)}`);
        }

        tbody.innerHTML += `
            <tr>
                <td class="ps-3 align-middle py-2">
                    <div class="fw-bold text-dark text-truncate" style="max-width:135px;">${item.nombre}</div>
                    <div class="small text-muted">Lote ${item.lote || ''}</div>
                    <div class="d-flex align-items-center gap-1 mt-1">
                        <span class="badge ${item.modalidad==='mayoreo' ? 'bg-success' : 'bg-light text-muted border'} px-2">
                            ${item.modalidad==='mayoreo' ? '★ Mayoreo' : 'Menudeo'}
                        </span>
                        <small class="text-muted">$${unitario.toFixed(2)} c/u</small>
                    </div>
                </td>
                <td class="text-center align-middle">
                    <div class="d-flex align-items-center justify-content-center bg-light rounded-pill border py-1 px-1" style="width:fit-content;margin:0 auto;">
                        <button class="btn btn-sm text-danger rounded-circle p-0" style="width:24px;height:24px;" onclick="cambiarCant(${index}, -1)"><i class="bi bi-dash"></i></button>
                        <span class="mx-2 fw-bold" style="min-width:20px;">${item.cantidad}</span>
                        <button class="btn btn-sm btn-primary rounded-circle p-0" style="width:24px;height:24px;" onclick="cambiarCant(${index}, 1)"><i class="bi bi-plus text-white"></i></button>
                    </div>
                </td>
                <td class="text-end pe-2 align-middle">
                    <div class="fw-bold ${item.modalidad==='mayoreo'?'text-success':''}">$${subtotal.toFixed(2)}</div>
                </td>
                <td class="align-middle text-center pe-2">
                    <button class="btn btn-icon-danger p-0" onclick="eliminar(${index})"><i class="bi bi-x-lg"></i></button>
                </td>
            </tr>`;
    });

    document.getElementById('lblSubtotal').innerText = '$' + total.toFixed(2);
    document.getElementById('lblTotal').innerText    = '$' + total.toFixed(2);
    badgeCount.innerText = itemsTotal + (itemsTotal === 1 ? ' item' : ' items');

    if (mensajesMayoreo.length > 0) {
        mayoreoMsg.classList.remove('d-none');
        mayoreoMsg.innerHTML = '<i class="bi bi-tags-fill me-1"></i><strong>Precio mayoreo:</strong> ' + mensajesMayoreo.join(' &nbsp;|&nbsp; ');
    } else {
        mayoreoMsg.classList.add('d-none');
    }

    if (document.getElementById('tipoPago').value === 'credito') {
        validarCreditoEnTiempoReal(total);
    } else {
        document.getElementById('alertaCredito').classList.add('d-none');
    }
}

function cambiarModalidad(index, modalidad) { carrito[index].modalidad = modalidad; renderizarCarrito(); }

function cambiarCant(index, delta) {
    const item = carrito[index];
    const nuevaCant = item.cantidad + delta;
    if (nuevaCant > item.stockMax) { alert('No hay mas stock disponible.'); return; }
    if (nuevaCant <= 0) { eliminar(index); } else {
        item.cantidad = nuevaCant;
        aplicarReglasMayoreo();
        renderizarCarrito();
    }
}

function eliminar(index) { carrito.splice(index, 1); renderizarCarrito(); }

function actualizarCliente() {
    const option = document.getElementById('clienteSelect').selectedOptions[0];
    const limite = Number(option.dataset.limite) || LIMITE_MAYOREO_DEFAULT;
    clienteActual = {
        id:     option.value || null,
        tipo:   option.dataset.tipo || 'minorista',
        limite: limite
    };
    document.getElementById('lblLimiteMayoreo').textContent = limite;
    aplicarReglasMayoreo();
    renderizarCarrito();
}

function onTipoPagoChange() {
    const tipo  = document.getElementById('tipoPago').value;
    const panel = document.getElementById('panelCredito');
    const alerta= document.getElementById('alertaCredito');
    if (tipo === 'credito') {
        panel.classList.remove('d-none');
        const total = carrito.reduce((s, i) => s + precioAplicable(i) * i.cantidad, 0);
        validarCreditoEnTiempoReal(total);
    } else {
        panel.classList.add('d-none');
        alerta.classList.add('d-none');
    }
}

function onCreditoClienteChange() {
    const sel  = document.getElementById('clienteCreditoSelect');
    const info = document.getElementById('infoCreditoCliente');
    if (sel.value) {
        document.getElementById('saldoCreditoDisp').innerText = '$' + Number(sel.selectedOptions[0].dataset.saldo).toFixed(2);
        info.classList.remove('d-none');
        const total = carrito.reduce((s, i) => s + precioAplicable(i) * i.cantidad, 0);
        validarCreditoEnTiempoReal(total);
    } else {
        info.classList.add('d-none');
    }
}

function validarCreditoEnTiempoReal(total) {
    const alerta = document.getElementById('alertaCredito');
    const msg    = document.getElementById('alertaCreditoMsg');
    const sel    = document.getElementById('clienteCreditoSelect');
    if (!sel || !sel.value) { alerta.classList.add('d-none'); return; }
    const saldo = Number(sel.selectedOptions[0].dataset.saldo);
    if (total > saldo) {
        msg.innerText = `Credito insuficiente. Disponible: $${saldo.toFixed(2)} — Total: $${total.toFixed(2)}`;
        alerta.classList.remove('d-none');
    } else {
        alerta.classList.add('d-none');
    }
}

async function cobrar() {
    if (carrito.length === 0) return alert('El carrito esta vacio');
    const tipoPago = document.getElementById('tipoPago').value;
    const total    = carrito.reduce((s, i) => s + precioAplicable(i) * i.cantidad, 0);
    let idClienteCredito = null;

    if (tipoPago === 'credito') {
        const sel = document.getElementById('clienteCreditoSelect');
        if (!sel || !sel.value) { alert('Selecciona un cliente con credito disponible.'); return; }
        idClienteCredito = parseInt(sel.value);
        const saldo = Number(sel.selectedOptions[0].dataset.saldo);
        if (total > saldo) {
            alert('Credito insuficiente. Saldo: $' + saldo.toFixed(2) + '\nTotal: $' + total.toFixed(2));
            return;
        }
    }

    if (!confirm('Confirmar venta por $' + total.toFixed(2) + '?')) return;

    const ventaData = {
        productos: carrito.map(i => ({ id: i.id, cantidad: i.cantidad, modalidad: i.modalidad })),
        id_cliente: idClienteCredito || clienteActual.id,
        tipo_pago: tipoPago,
        id_cliente_credito: idClienteCredito,
        limite_mayoreo: clienteActual.limite
    };

    try {
        const response = await fetch('guardar_venta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(ventaData)
        });
        const data = await response.json();
        if (data.success) {
            window.open('ver_ticket.php?id=' + data.id_pedido, '_blank');
            carrito = [];
            renderizarCarrito();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (err) {
        alert('Error de conexion');
    }
}

let timer = null;
document.getElementById('buscador').addEventListener('input', function() {
    clearTimeout(timer);
    timer = setTimeout(() => buscarProductos(this.value), 180);
});

async function buscarProductos(q) {
    const blocked = document.getElementById('blockedLots');
    const empty   = document.getElementById('sinResultados');
    if (q.trim().length === 0) { location.reload(); return; }
    const res  = await fetch('buscar_productos.php?q=' + encodeURIComponent(q) + '&context=pos');
    const data = await res.json();
    const productos = data.productos || [];
    const grid = document.getElementById('gridProductos');
    grid.innerHTML = '';
    productos.forEach(p => {
        const col  = document.createElement('div');
        col.className = 'col item-card';
        const warn = p.expiry_status === 'warning' ? `<span class="badge bg-warning-subtle text-warning border mt-2">${p.expiry_message}</span>` : '';
        col.innerHTML = `
            <div class="card h-100 card-custom border-0 shadow-sm position-relative overflow-hidden product-card-btn" style="cursor:pointer;">
                <div class="card-body text-center p-3 d-flex flex-column justify-content-center">
                    <div class="mb-2"><div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex p-3"><i class="bi bi-capsule fs-3"></i></div></div>
                    <h6 class="fw-bold mb-1 text-truncate text-dark small" title="${p.nombre}">${p.nombre}</h6>
                    <div class="small text-muted">Lote ${p.numero_lote || ''}</div>
                    <h5 class="text-success fw-bold mb-0">$${Number(p.precio).toFixed(2)}</h5>
                    ${warn}
                    <span class="badge bg-white text-secondary border position-absolute top-0 end-0 m-2 shadow-sm">Stock: ${p.stock}</span>
                </div>
            </div>`;
        col.querySelector('.card').addEventListener('click', () => agregarAlCarrito({
            id: p.id_producto, nombre: p.nombre, lote: p.numero_lote,
            precio: Number(p.precio), precio_mayoreo: Number(p.precio_mayoreo), stock: Number(p.stock)
        }));
        grid.appendChild(col);
    });
    empty.style.display = productos.length === 0 ? 'block' : 'none';
    blocked.classList.toggle('d-none', !(data.blocked_lots || []).length);
    blocked.innerText = (data.blocked_messages || []).length
        ? data.blocked_messages.join(' | ')
        : ((data.blocked_lots || []).length ? 'Lotes no disponibles por caducidad: ' + data.blocked_lots.join(', ') : '');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
