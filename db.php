<?php
date_default_timezone_set('America/Mexico_City');

$host = 'localhost';
$dbname = 'farmacia_db';
$user = 'root';
$pass = '';

try {
   $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    require_once __DIR__ . '/app_helpers.php';
    ensure_schema($conexion);
} catch (PDOException $e) {
    die("Error de conexion: " . $e->getMessage());
}
?>
