<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include 'db.php';

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
$context = $_GET['context'] ?? 'pos';
$started = microtime(true);

if (mb_strlen($q, 'UTF-8') < 1) {
    echo json_encode([]);
    exit;
}

$like = '%' . $q . '%';
$stmt = $conexion->prepare("
    SELECT *
    FROM productos
    WHERE nombre LIKE ? OR compuesto LIKE ? OR numero_lote LIKE ?
    ORDER BY nombre ASC, fecha_caducidad ASC
    LIMIT 25
");
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll();

$normalizedQ = normalize_text($q);
$results = [];
$blockedLots = [];
$blockedMessages = [];

foreach ($rows as $p) {
    $haystack = normalize_text(($p['nombre'] ?? '') . ' ' . ($p['compuesto'] ?? '') . ' ' . ($p['numero_lote'] ?? ''));
    if (strpos($haystack, $normalizedQ) === false) {
        continue;
    }

    $expiry = expiry_status($p['fecha_caducidad'] ?? null);
    if ($context === 'pos' && !can_sell_product($p)) {
        $lot = $p['numero_lote'] ?: $p['nombre'];
        $blockedLots[] = $lot;
        $blockedMessages[] = 'Lote ' . $lot . ': ' . $expiry['message'];
        continue;
    }

    $results[] = [
        'id_producto' => (int)$p['id_producto'],
        'nombre' => $p['nombre'],
        'compuesto' => $p['compuesto'] ?? '',
        'numero_lote' => $p['numero_lote'] ?? '',
        'fecha_caducidad' => $p['fecha_caducidad'] ?? '',
        'precio' => (float)$p['precio'],
        'precio_mayoreo' => (float)$p['precio_mayoreo'],
        'stock' => (int)$p['stock'],
        'expiry_status' => $expiry['status'],
        'expiry_message' => $expiry['message'],
    ];
}

echo json_encode([
    'productos' => $results,
    'blocked_lots' => $blockedLots,
    'blocked_messages' => $blockedMessages,
    'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
    'message' => empty($results) ? 'No se encontraron productos con ese termino' : ''
], JSON_UNESCAPED_UNICODE);
?>
