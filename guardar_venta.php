<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include 'db.php';

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['productos']) || !is_array($input['productos'])) {
    echo json_encode(['success' => false, 'message' => 'Datos invalidos']);
    exit;
}

try {
    $conexion->beginTransaction();

    $idClienteVenta   = !empty($input['id_cliente'])         ? (int)$input['id_cliente']         : null;
    $idClienteCredito = !empty($input['id_cliente_credito']) ? (int)$input['id_cliente_credito'] : null;
    $tipoPago         = $input['tipo_pago'] ?? 'efectivo';

    // Leer límite de mayoreo global desde configuración (fallback 50)
    $limiteMayoreoGlobal = 50;
    try {
        $stmtCfg = $conexion->query("SELECT valor FROM configuracion WHERE clave='limite_mayoreo_def' LIMIT 1");
        if ($stmtCfg) {
            $valCfg = $stmtCfg->fetchColumn();
            if ($valCfg !== false && (int)$valCfg > 0) $limiteMayoreoGlobal = (int)$valCfg;
        }
    } catch (Exception $e) {}

    // El cliente puede enviar su propio límite desde el POS (si la sesión lo conoce)
    $limiteDesdeCliente = !empty($input['limite_mayoreo']) && (int)$input['limite_mayoreo'] > 0
        ? (int)$input['limite_mayoreo']
        : $limiteMayoreoGlobal;

    // Validar cliente
    $cliente = null;
    $idClienteRef = $idClienteCredito ?? $idClienteVenta;
    if ($idClienteRef) {
        $stmtCliente = $conexion->prepare("SELECT id_usuario, nombre, tipo_cliente, limite_mayoreo FROM usuarios WHERE id_usuario = ?");
        $stmtCliente->execute([$idClienteRef]);
        $cliente = $stmtCliente->fetch();
    }

    // Si pago con credito, validar saldo ANTES de procesar
    $creditoRecord = null;
    if ($tipoPago === 'credito') {
        if (!$idClienteCredito) {
            throw new Exception('Debe seleccionar un cliente con credito para este tipo de pago.');
        }
        $stmtCred = $conexion->prepare("SELECT * FROM creditos WHERE id_usuario = ? AND estado = 'aprobado' FOR UPDATE");
        $stmtCred->execute([$idClienteCredito]);
        $creditoRecord = $stmtCred->fetch();
        if (!$creditoRecord) {
            throw new Exception('El cliente no tiene una linea de credito activa.');
        }
    }

    // Procesar productos
    $items = [];
    $total = 0.0;

    foreach ($input['productos'] as $prod) {
        $idProducto          = (int)($prod['id']       ?? 0);
        $cantidad            = (int)($prod['cantidad'] ?? 0);
        $modalidadSolicitada = ($prod['modalidad'] ?? 'menudeo') === 'mayoreo' ? 'mayoreo' : 'menudeo';

        if ($idProducto <= 0 || $cantidad <= 0) throw new Exception('Producto o cantidad invalida');

        $stmtCheck = $conexion->prepare("SELECT * FROM productos WHERE id_producto = ? FOR UPDATE");
        $stmtCheck->execute([$idProducto]);
        $producto = $stmtCheck->fetch();

        if (!$producto) throw new Exception('Producto no encontrado');
        if ((int)$producto['stock'] < $cantidad) throw new Exception('Stock insuficiente para: ' . $producto['nombre']);
        if (!can_sell_product($producto)) throw new Exception('Producto vencido — no puede venderse: ' . $producto['nombre']);

        // ─── CP-POS-02 FIX ────────────────────────────────────────────────────
        // El precio mayoreo aplica a CUALQUIER cliente (no sólo mayoristas)
        // si la cantidad supera el umbral configurado.
        // El umbral a usar: límite del cliente en DB > límite enviado por POS > global config
        $limiteMayoreoCliente = $cliente ? (int)$cliente['limite_mayoreo'] : 0;
        $limiteMayoreoUsado   = $limiteMayoreoCliente > 0 ? $limiteMayoreoCliente : $limiteDesdeCliente;

        $modalidad = $modalidadSolicitada;

        // Sólo permitir mayoreo si la cantidad alcanza el umbral
        if ($modalidad === 'mayoreo' && $cantidad < $limiteMayoreoUsado) {
            $modalidad = 'menudeo'; // cantidad insuficiente para el descuento
        }
        // ─────────────────────────────────────────────────────────────────────

        $precioUnitario = $modalidad === 'mayoreo' ? (float)$producto['precio_mayoreo'] : (float)$producto['precio'];
        if ($precioUnitario <= 0) { $precioUnitario = (float)$producto['precio']; $modalidad = 'menudeo'; }

        $subtotal = $precioUnitario * $cantidad;
        $total   += $subtotal;
        $items[] = [
            'producto'       => $producto,
            'cantidad'       => $cantidad,
            'precio_unitario'=> $precioUnitario,
            'modalidad'      => $modalidad,
            'subtotal'       => $subtotal
        ];
    }

    // Validar saldo de credito vs total real
    if ($tipoPago === 'credito' && $creditoRecord) {
        if ((float)$creditoRecord['saldo_disponible'] < $total) {
            throw new Exception('Credito insuficiente. Saldo: $' . number_format((float)$creditoRecord['saldo_disponible'], 2) . ' — Total: $' . number_format($total, 2));
        }
    }

    // Insertar pedido
    $idCliente = $idClienteCredito ?? $idClienteVenta;
    $stmtPedido = $conexion->prepare("INSERT INTO pedidos (id_usuario, id_cliente, estado, tipo_pago, total, fecha) VALUES (?, ?, 'completado', ?, ?, NOW())");
    $stmtPedido->execute([$_SESSION['id_usuario'], $idCliente, $tipoPago, $total]);
    $idPedido = $conexion->lastInsertId();

    $stmtDetalle = $conexion->prepare("INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad, subtotal, precio_unitario, modalidad) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtStock   = $conexion->prepare("UPDATE productos SET stock = stock - ? WHERE id_producto = ?");
    $stmtMov     = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo_movimiento, cantidad, observaciones, origen) VALUES (?, ?, 'salida', ?, ?, 'pos')");

    foreach ($items as $item) {
        $prod = $item['producto'];
        $stmtDetalle->execute([$idPedido, $prod['id_producto'], $item['cantidad'], $item['subtotal'], $item['precio_unitario'], $item['modalidad']]);
        $stmtStock->execute([$item['cantidad'], $prod['id_producto']]);
        $stmtMov->execute([
            $prod['id_producto'],
            $_SESSION['id_usuario'],
            $item['cantidad'],
            'Venta POS Folio #' . str_pad($idPedido, 6, '0', STR_PAD_LEFT) . ' (' . $item['modalidad'] . ') — $' . number_format($item['precio_unitario'], 2) . ' c/u'
        ]);
    }

    // Descontar del credito si aplica
    if ($tipoPago === 'credito' && $creditoRecord) {
        $nuevoSaldo = (float)$creditoRecord['saldo_disponible'] - $total;
        $conexion->prepare("UPDATE creditos SET saldo_disponible = ? WHERE id_credito = ?")
                 ->execute([$nuevoSaldo, $creditoRecord['id_credito']]);
    }

    $conexion->commit();
    echo json_encode(['success' => true, 'id_pedido' => $idPedido, 'total' => $total]);

} catch (Exception $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
