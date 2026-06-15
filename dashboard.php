<?php 
include 'db.php'; 
include 'header.php'; 

// --- KPIS ---
$total_prod = $conexion->query("SELECT COUNT(*) FROM productos")->fetchColumn();
$stock_bajo = $conexion->query("SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo")->fetchColumn();
$ventas_hoy = $conexion->query("SELECT SUM(total) FROM pedidos WHERE DATE(fecha) = CURDATE()")->fetchColumn();

// --- ÚLTIMOS 5 MOVIMIENTOS (Solo resumen) ---
$sql = "SELECT p.*, u.nombre as vendedor 
        FROM pedidos p 
        INNER JOIN usuarios u ON p.id_usuario = u.id_usuario 
        ORDER BY p.fecha DESC 
        LIMIT 5"; // <--- ESTO ES LO IMPORTANTE
$ultimos_movimientos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-dark mb-0">Panel Principal</h2>
    <span class="badge bg-white text-secondary border p-2"><?php echo date("d/m/Y"); ?></span>
</div>

<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card card-custom p-4 border-0 shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted text-uppercase small fw-bold">Inventario</h6>
                    <h2 class="fw-bold text-primary mb-0"><?php echo $total_prod; ?> Items</h2>
                </div>
                <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                    <i class="bi bi-box-seam fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card card-custom p-4 border-0 shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted text-uppercase small fw-bold">Ventas de Hoy</h6>
                    <h2 class="fw-bold text-success mb-0">$<?php echo number_format($ventas_hoy, 2); ?></h2>
                </div>
                <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                    <i class="bi bi-cash-stack fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card card-custom p-4 border-0 shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted text-uppercase small fw-bold">Alertas Stock</h6>
                    <h2 class="fw-bold text-danger mb-0"><?php echo $stock_bajo; ?></h2>
                </div>
                <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                    <i class="bi bi-exclamation-circle fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card card-custom border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-activity me-2 text-primary"></i> Actividad Reciente
        </h5>
        <a href="movimientos.php" class="btn btn-sm btn-light text-primary fw-bold">
            Ver Historial Completo <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Folio</th>
                    <th>Hora</th>
                    <th>Vendedor</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($ultimos_movimientos)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-4 opacity-25"></i>
                            <p class="mt-2">Sin actividad reciente.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($ultimos_movimientos as $m): ?>
                    <tr>
                        <td class="ps-4 text-muted small">#<?php echo str_pad($m['id_pedido'], 5, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo date("h:i A", strtotime($m['fecha'])); ?></td>
                        <td><span class="fw-bold text-dark"><?php echo $m['vendedor']; ?></span></td>
                        <td class="fw-bold text-success">$<?php echo number_format($m['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>