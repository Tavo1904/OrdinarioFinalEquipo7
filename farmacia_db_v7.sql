-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 09-06-2026 a las 01:05:47
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12
-- Migración aplicada: TC-EX-07 — Restricción UNIQUE en proveedores.rfc

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `farmacia_db`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

CREATE TABLE `configuracion` (
  `clave` varchar(60) NOT NULL,
  `valor` varchar(255) NOT NULL DEFAULT '',
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`clave`, `valor`, `descripcion`) VALUES
('dias_alerta_cad', '90', 'Días antes de caducidad para alerta'),
('inactividad_min', '30', 'Minutos de inactividad para cerrar sesión'),
('intentos_max', '3', 'Intentos de login antes de bloquear'),
('limite_mayoreo_def', '50', 'Cantidad mínima para precio mayoreo'),
('nombre_farmacia', 'Populares Peñaloza', 'Nombre de la farmacia');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `creditos`
--

CREATE TABLE `creditos` (
  `id_credito` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `monto_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `saldo_disponible` decimal(10,2) NOT NULL DEFAULT 0.00,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `fecha_autorizacion` datetime DEFAULT NULL,
  `fecha_solicitud` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_resolucion` datetime DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `monto_solicitado` decimal(10,2) NOT NULL DEFAULT 0.00,
  `motivo_solicitud` varchar(500) DEFAULT NULL,
  `comentario_revision` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `creditos`
--

INSERT INTO `creditos` (`id_credito`, `id_usuario`, `monto_total`, `saldo_disponible`, `estado`, `fecha_autorizacion`, `fecha_solicitud`, `fecha_resolucion`, `observaciones`, `monto_solicitado`, `motivo_solicitud`, `comentario_revision`) VALUES
(1, 4, 2000.00, 2000.00, 'aprobado', '2026-06-04 15:45:51', '2026-06-04 15:45:51', NULL, NULL, 2000.00, NULL, 'Crédito aprobado.'),
(2, 5, 5000.00, 5000.00, 'aprobado', '2026-06-04 15:45:51', '2026-06-04 15:45:51', NULL, NULL, 5000.00, NULL, 'Crédito aprobado.'),
(3, 6, 1500.00, 1500.00, 'aprobado', '2026-06-08 16:20:31', '2026-06-08 16:18:54', NULL, NULL, 1000.00, 'Compras', 'Crédito aprobado.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empleados_permisos`
--

CREATE TABLE `empleados_permisos` (
  `id_usuario`    int(11) NOT NULL,
  `ventas`        tinyint(1) NOT NULL DEFAULT 0,
  `inventario`    tinyint(1) NOT NULL DEFAULT 0,
  `compras`       tinyint(1) NOT NULL DEFAULT 0,
  `clientes`      tinyint(1) NOT NULL DEFAULT 0,
  `creditos`      tinyint(1) NOT NULL DEFAULT 0,
  `reportes`      tinyint(1) NOT NULL DEFAULT 0,
  `configuracion` tinyint(1) NOT NULL DEFAULT 0,
  `empleados`     tinyint(1) NOT NULL DEFAULT 0,
  `pedidos_web`   tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Permiso para aceptar/rechazar pedidos de la plataforma web'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `empleados_permisos`
-- id 1 = Administrador        (rol admin   → todos los permisos = 1, incluyendo pedidos_web)
-- id 2 = Elizabeth Ramírez    (rol empleado → permisos = 0 hasta que admin los active)
-- id 3 = Jesús Hernández      (rol empleado → permisos = 0 hasta que admin los active)
--

INSERT INTO `empleados_permisos` (`id_usuario`, `ventas`, `inventario`, `compras`, `clientes`, `creditos`, `reportes`, `configuracion`, `empleados`, `pedidos_web`) VALUES
(1, 1, 1, 1, 1, 1, 1, 1, 1, 1),
(2, 0, 0, 0, 0, 0, 0, 0, 0, 0),
(3, 0, 0, 0, 0, 0, 0, 0, 0, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_pedido`
--

CREATE TABLE `detalle_pedido` (
  `id_detalle` int(11) NOT NULL,
  `id_pedido` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `precio_unitario` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `modalidad` varchar(20) NOT NULL DEFAULT 'menudeo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `detalle_pedido`
--

INSERT INTO `detalle_pedido` (`id_detalle`, `id_pedido`, `id_producto`, `cantidad`, `precio_unitario`, `subtotal`, `modalidad`) VALUES
(1, 1, 79, 2, 38.00, 76.00, 'menudeo'),
(2, 2, 79, 2, 38.00, 76.00, 'menudeo'),
(3, 3, 98, 1, 45.00, 45.00, 'menudeo'),
(4, 3, 94, 1, 32.00, 32.00, 'menudeo'),
(5, 4, 98, 1, 45.00, 45.00, 'menudeo'),
(6, 4, 94, 1, 32.00, 32.00, 'menudeo'),
(7, 5, 94, 1, 32.00, 32.00, 'menudeo'),
(8, 6, 79, 1, 38.00, 38.00, 'menudeo'),
(9, 6, 99, 1, 22.00, 22.00, 'menudeo'),
(10, 7, 64, 1, 72.00, 72.00, 'menudeo'),
(11, 7, 94, 1, 32.00, 32.00, 'menudeo'),
(12, 8, 1, 3, 25.00, 75.00, 'menudeo');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `facturas_compra`
--

CREATE TABLE `facturas_compra` (
  `id_factura` int(11) NOT NULL,
  `id_proveedor` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `costo_unitario` decimal(10,2) NOT NULL,
  `folio` varchar(80) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `facturas_compra`
--

INSERT INTO `facturas_compra` (`id_factura`, `id_proveedor`, `id_producto`, `cantidad`, `costo_unitario`, `folio`, `fecha`) VALUES
(2, 1, 7, 100, 18.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(3, 1, 9, 80, 22.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(4, 1, 14, 50, 42.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(5, 1, 17, 60, 33.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(6, 1, 20, 40, 47.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(7, 1, 98, 120, 22.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(8, 1, 101, 200, 14.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(9, 1, 103, 300, 4.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(17, 2, 42, 80, 49.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(18, 2, 46, 60, 72.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(19, 2, 48, 100, 19.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(20, 2, 52, 90, 31.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(21, 2, 53, 70, 39.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(22, 2, 57, 30, 105.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(23, 2, 75, 150, 24.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(24, 2, 76, 80, 42.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(25, 2, 70, 50, 46.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(26, 2, 74, 30, 72.00, 'FC-FNA-2025-001', '2025-03-15 10:30:00'),
(39, 1, 7, 100, 18.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(40, 1, 9, 80, 22.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00'),
(41, 1, 103, 300, 4.00, 'FC-DMS-2025-001', '2025-03-10 09:00:00');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_precios`
--

CREATE TABLE `historial_precios` (
  `id_historial` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `precio_menudeo_anterior` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_menudeo_nuevo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_mayoreo_anterior` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_mayoreo_nuevo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `movimientos_inventario`
--

CREATE TABLE `movimientos_inventario` (
  `id_movimiento` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo_movimiento` enum('entrada','salida','ajuste') NOT NULL,
  `cantidad` int(11) NOT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `origen` varchar(20) NOT NULL DEFAULT 'pos',
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `movimientos_inventario`
--

INSERT INTO `movimientos_inventario` (`id_movimiento`, `id_producto`, `id_usuario`, `tipo_movimiento`, `cantidad`, `observaciones`, `origen`, `fecha`) VALUES
(1, 79, 1, 'salida', 2, 'Venta POS Folio #1 (menudeo)', 'pos', '2026-06-04 16:03:31'),
(2, 79, 1, 'salida', 2, 'Venta POS Folio #2 (menudeo)', 'pos', '2026-06-04 16:14:32'),
(3, 98, 1, 'salida', 1, 'Venta POS Folio #000003 (menudeo) — $45.00 c/u', 'pos', '2026-06-05 08:06:34'),
(4, 94, 1, 'salida', 1, 'Venta POS Folio #000003 (menudeo) — $32.00 c/u', 'pos', '2026-06-05 08:06:34'),
(5, 98, 1, 'salida', 1, 'Venta POS Folio #000004 (menudeo) — $45.00 c/u', 'pos', '2026-06-05 08:07:14'),
(6, 94, 1, 'salida', 1, 'Venta POS Folio #000004 (menudeo) — $32.00 c/u', 'pos', '2026-06-05 08:07:14'),
(7, 94, 1, 'salida', 1, 'Venta POS Folio #000005 (menudeo) — $32.00 c/u', 'pos', '2026-06-05 08:44:47'),
(8, 79, 1, 'salida', 1, 'Pedido Web #000006 — Aceptado — Ácido Fólico 5mg', 'web', '2026-06-08 16:19:29'),
(9, 99, 1, 'salida', 1, 'Pedido Web #000006 — Aceptado — Agua Oxigenada 3%', 'web', '2026-06-08 16:19:29'),
(10, 64, 1, 'salida', 1, 'Pedido Web #000007 — Aceptado — Ambroxol 30mg', 'web', '2026-06-08 16:39:40'),
(11, 94, 1, 'salida', 1, 'Pedido Web #000007 — Aceptado — Albendazol 400mg', 'web', '2026-06-08 16:39:40'),
(12, 1, 1, 'salida', 3, 'Pedido Web #000008 — Aceptado — Paracetamol 500mg', 'web', '2026-06-08 16:52:39');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos_credito`
--

CREATE TABLE `pagos_credito` (
  `id_pago` int(11) NOT NULL,
  `id_credito` int(11) NOT NULL,
  `monto_pagado` decimal(10,2) NOT NULL,
  `observaciones` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `pagos_credito`
--

INSERT INTO `pagos_credito` (`id_pago`, `id_credito`, `monto_pagado`, `observaciones`, `fecha`) VALUES
(1, 2, 500.00, 'Abono inicial al crédito aprobado', '2026-06-04 15:45:51'),
(8, 2, 1577.00, 'Abono en efectivo — por Administrador', '2026-06-08 16:13:31'),
(9, 1, 77.00, 'Abono en efectivo — por Administrador', '2026-06-08 16:13:34'),
(10, 3, 104.00, 'Abono en efectivo — por Administrador', '2026-06-08 16:40:18'),
(11, 3, 75.00, 'Abono en efectivo — por Administrador', '2026-06-08 16:54:09');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pedidos`
--

CREATE TABLE `pedidos` (
  `id_pedido` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_cliente` int(11) DEFAULT NULL,
  `estado` enum('completado','cancelado','pendiente') NOT NULL DEFAULT 'completado',
  `estado_aprobacion` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'aprobado',
  `tipo_pago` varchar(50) NOT NULL DEFAULT 'efectivo',
  `origen` varchar(10) NOT NULL DEFAULT 'pos',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `notas_cliente` text DEFAULT NULL,
  `fecha_resolucion` datetime DEFAULT NULL,
  `hora_recoleccion` time DEFAULT NULL,
  `comentario_aprobacion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `pedidos`
--

INSERT INTO `pedidos` (`id_pedido`, `id_usuario`, `id_cliente`, `estado`, `estado_aprobacion`, `tipo_pago`, `origen`, `total`, `fecha`, `notas_cliente`, `fecha_resolucion`, `hora_recoleccion`, `comentario_aprobacion`) VALUES
(1, 1, 4, 'completado', 'aprobado', 'credito', 'pos', 76.00, '2026-06-04 16:03:31', NULL, NULL, NULL, NULL),
(2, 1, NULL, 'completado', 'aprobado', 'tarjeta', 'pos', 76.00, '2026-06-04 16:14:32', NULL, NULL, NULL, NULL),
(3, 1, 5, 'completado', 'aprobado', 'credito', 'pos', 77.00, '2026-06-05 08:06:34', NULL, NULL, NULL, NULL),
(4, 1, 4, 'completado', 'aprobado', 'credito', 'pos', 77.00, '2026-06-05 08:07:14', NULL, NULL, NULL, NULL),
(5, 1, NULL, 'completado', 'aprobado', 'efectivo', 'pos', 32.00, '2026-06-05 08:44:47', NULL, NULL, NULL, NULL),
(6, 6, 6, 'completado', 'aprobado', 'tarjeta', 'web', 60.00, '2026-06-08 16:18:43', NULL, '2026-06-08 16:19:29', '10:00:00', 'Pedido aceptado.'),
(7, 6, 6, 'completado', 'aprobado', 'credito', 'web', 104.00, '2026-06-08 16:38:58', NULL, '2026-06-08 16:39:40', '10:00:00', 'Pedido aceptado.'),
(8, 6, 6, 'completado', 'aprobado', 'credito', 'web', 75.00, '2026-06-08 16:52:03', NULL, '2026-06-08 16:52:39', '10:00:00', 'Pedido aceptado.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id_producto` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `compuesto` varchar(150) DEFAULT NULL,
  `numero_lote` varchar(60) DEFAULT NULL,
  `categoria` varchar(100) NOT NULL,
  `laboratorio` varchar(150) DEFAULT NULL,
  `presentacion` varchar(100) DEFAULT NULL,
  `fecha_caducidad` date DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_mayoreo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `costo_adquisicion` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `stock_minimo` int(11) NOT NULL DEFAULT 0,
  `descripcion` text DEFAULT NULL,
  `estado` enum('disponible','agotado','descontinuado') NOT NULL DEFAULT 'disponible',
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id_producto`, `nombre`, `compuesto`, `numero_lote`, `categoria`, `laboratorio`, `presentacion`, `fecha_caducidad`, `precio`, `precio_mayoreo`, `costo_adquisicion`, `stock`, `stock_minimo`, `descripcion`, `estado`, `fecha_registro`) VALUES
(1, 'Paracetamol 500mg', 'Paracetamol', NULL, 'Analgésico', 'Genérico', 'Tabletas c/20', '2027-06-01', 25.00, 20.00, 12.00, 97, 10, NULL, 'disponible', '2026-06-04 15:52:08'),
(2, 'Amoxicilina 500mg', 'Amoxicilina', NULL, 'Antibiótico', 'Genérico', 'Cápsulas c/12', '2026-12-31', 85.00, 70.00, 40.00, 60, 5, NULL, 'disponible', '2026-06-04 15:52:08'),
(3, 'Ibuprofeno 400mg', 'Ibuprofeno', NULL, 'Antiinflamatorio', 'Genérico', 'Tabletas c/10', '2027-03-15', 35.00, 28.00, 18.00, 80, 10, NULL, 'disponible', '2026-06-04 15:52:08'),
(4, 'Paracetamol 500mg', 'Paracetamol', NULL, 'Analgésico', 'Genérico', 'Tabletas c/20', '2027-06-01', 25.00, 20.00, 12.00, 100, 10, NULL, 'disponible', '2026-06-04 15:52:14'),
(5, 'Amoxicilina 500mg', 'Amoxicilina', NULL, 'Antibiótico', 'Genérico', 'Cápsulas c/12', '2026-12-31', 85.00, 70.00, 40.00, 60, 5, NULL, 'disponible', '2026-06-04 15:52:14'),
(6, 'Ibuprofeno 400mg', 'Ibuprofeno', NULL, 'Antiinflamatorio', 'Genérico', 'Tabletas c/10', '2027-03-15', 35.00, 28.00, 18.00, 80, 10, NULL, 'disponible', '2026-06-04 15:52:14'),
(7, 'Paracetamol 500mg', 'Paracetamol', 'ANA-001', 'Analgésicos', 'Genérico MX', 'Caja 20 tabletas', '2027-06-30', 35.00, 28.00, 18.00, 200, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(8, 'Paracetamol Infantil 160mg/5ml', 'Paracetamol', 'ANA-002', 'Analgésicos', 'Genérico MX', 'Frasco 120ml suspensión', '2027-03-31', 48.00, 38.00, 24.00, 120, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(9, 'Ibuprofeno 400mg', 'Ibuprofeno', 'ANA-003', 'Analgésicos', 'Rimsa', 'Caja 20 tabletas', '2027-01-31', 45.00, 36.00, 22.00, 180, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(10, 'Ibuprofeno 800mg', 'Ibuprofeno', 'ANA-004', 'Analgésicos', 'Rimsa', 'Caja 20 tabletas', '2026-11-30', 65.00, 52.00, 32.00, 100, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(11, 'Ibuprofeno Suspensión 100mg/5ml', 'Ibuprofeno', 'ANA-005', 'Analgésicos', 'Rimsa', 'Frasco 120ml', '2027-04-30', 62.00, 50.00, 30.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(12, 'Naproxeno 250mg', 'Naproxeno sódico', 'ANA-006', 'Analgésicos', 'Bayer', 'Caja 20 tabletas', '2027-02-28', 55.00, 44.00, 27.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(13, 'Naproxeno 500mg', 'Naproxeno sódico', 'ANA-007', 'Analgésicos', 'Bayer', 'Caja 20 tabletas', '2027-02-28', 75.00, 60.00, 37.00, 70, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(14, 'Ketorolaco 10mg', 'Ketorolaco trometamol', 'ANA-008', 'Analgésicos', 'Liomont', 'Caja 20 tabletas', '2026-09-30', 85.00, 68.00, 42.00, 60, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(15, 'Metamizol 500mg', 'Metamizol sódico', 'ANA-009', 'Analgésicos', 'Torrent', 'Caja 20 tabletas', '2027-05-31', 40.00, 32.00, 20.00, 150, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(16, 'Diclofenaco 50mg', 'Diclofenaco sódico', 'ANA-010', 'Analgésicos', 'Novartis', 'Caja 20 tabletas', '2027-01-31', 58.00, 46.00, 28.00, 100, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(17, 'Amoxicilina 500mg', 'Amoxicilina trihidrato', 'ATB-001', 'Antibióticos', 'Mavi', 'Caja 12 cápsulas', '2027-03-31', 68.00, 54.00, 33.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(18, 'Amoxicilina 250mg/5ml Suspensión', 'Amoxicilina trihidrato', 'ATB-002', 'Antibióticos', 'Mavi', 'Frasco 100ml', '2026-12-31', 85.00, 68.00, 42.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(19, 'Amoxicilina + Clavulanato 875/125mg', 'Amoxicilina/Ácido clavulánico', 'ATB-003', 'Antibióticos', 'GlaxoSmithKline', 'Caja 14 tabletas', '2027-05-31', 185.00, 148.00, 92.00, 50, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(20, 'Azitromicina 500mg', 'Azitromicina', 'ATB-004', 'Antibióticos', 'Pfizer', 'Caja 3 tabletas', '2027-02-28', 95.00, 76.00, 47.00, 70, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(21, 'Azitromicina 200mg/5ml Suspensión', 'Azitromicina', 'ATB-005', 'Antibióticos', 'Pfizer', 'Frasco 30ml', '2026-10-31', 110.00, 88.00, 55.00, 40, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(22, 'Claritromicina 500mg', 'Claritromicina', 'ATB-006', 'Antibióticos', 'Abbott', 'Caja 14 tabletas', '2027-04-30', 145.00, 116.00, 72.00, 45, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(23, 'Ciprofloxacino 500mg', 'Ciprofloxacino clorhidrato', 'ATB-007', 'Antibióticos', 'Bayer', 'Caja 14 tabletas', '2027-01-31', 120.00, 96.00, 60.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(24, 'Metronidazol 500mg', 'Metronidazol', 'ATB-008', 'Antibióticos', 'Baxter', 'Caja 20 tabletas', '2027-06-30', 52.00, 41.00, 25.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(25, 'Doxiciclina 100mg', 'Doxiciclina hiclatol', 'ATB-009', 'Antibióticos', 'Sigma', 'Caja 14 cápsulas', '2026-08-31', 88.00, 70.00, 44.00, 50, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(26, 'Trimetoprim/Sulfametoxazol 160/800mg', 'Trimetoprim + Sulfametoxazol', 'ATB-010', 'Antibióticos', 'Roche', 'Caja 20 tabletas', '2027-03-31', 72.00, 57.00, 35.00, 70, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(27, 'Meloxicam 15mg', 'Meloxicam', 'AIF-001', 'Antiinflamatorios', 'Boehringer', 'Caja 10 tabletas', '2027-02-28', 78.00, 62.00, 38.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(28, 'Celecoxib 200mg', 'Celecoxib', 'AIF-002', 'Antiinflamatorios', 'Pfizer', 'Caja 10 cápsulas', '2027-05-31', 145.00, 116.00, 72.00, 50, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(29, 'Piroxicam 20mg', 'Piroxicam', 'AIF-003', 'Antiinflamatorios', 'Pfizer', 'Caja 10 cápsulas', '2026-11-30', 60.00, 48.00, 30.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(30, 'Diclofenaco Gel 1%', 'Diclofenaco dietilamonio', 'AIF-004', 'Antiinflamatorios', 'Novartis', 'Tubo 50g', '2027-06-30', 95.00, 76.00, 47.00, 45, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(31, 'Betametasona 0.5mg', 'Betametasona', 'AIF-005', 'Antiinflamatorios', 'Schering', 'Caja 20 tabletas', '2027-01-31', 82.00, 65.00, 40.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(32, 'Omeprazol 20mg', 'Omeprazol', 'GAS-001', 'Gastrointestinales', 'AstraZeneca', 'Caja 14 cápsulas', '2027-04-30', 55.00, 44.00, 27.00, 160, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(33, 'Omeprazol 40mg', 'Omeprazol', 'GAS-002', 'Gastrointestinales', 'AstraZeneca', 'Caja 14 cápsulas', '2027-04-30', 75.00, 60.00, 37.00, 100, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(34, 'Pantoprazol 40mg', 'Pantoprazol sódico', 'GAS-003', 'Gastrointestinales', 'Takeda', 'Caja 14 tabletas', '2027-03-31', 85.00, 68.00, 42.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(35, 'Ranitidina 150mg', 'Ranitidina clorhidrato', 'GAS-004', 'Gastrointestinales', 'Glaxo', 'Caja 20 tabletas', '2026-12-31', 48.00, 38.00, 24.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(36, 'Metoclopramida 10mg', 'Metoclopramida', 'GAS-005', 'Gastrointestinales', 'Sanofi', 'Caja 20 tabletas', '2027-02-28', 42.00, 33.00, 20.00, 100, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(37, 'Loperamida 2mg', 'Loperamida clorhidrato', 'GAS-006', 'Gastrointestinales', 'Janssen', 'Caja 12 cápsulas', '2027-05-31', 58.00, 46.00, 28.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(38, 'Bismuto 262mg', 'Subsalicilato de bismuto', 'GAS-007', 'Gastrointestinales', 'P&G', 'Frasco 240ml suspensión', '2026-10-31', 72.00, 57.00, 36.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(39, 'Domperidona 10mg', 'Domperidona', 'GAS-008', 'Gastrointestinales', 'Janssen', 'Caja 30 tabletas', '2027-06-30', 65.00, 52.00, 32.00, 70, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(40, 'Simeticona 80mg', 'Simeticona', 'GAS-009', 'Gastrointestinales', 'Pfizer', 'Frasco 30ml gotas', '2027-01-31', 55.00, 44.00, 27.00, 75, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(41, 'Hidróxido de Magnesio Suspensión', 'Hidróxido de magnesio', 'GAS-010', 'Gastrointestinales', 'Genérico MX', 'Frasco 360ml', '2027-03-31', 48.00, 38.00, 24.00, 85, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(42, 'Losartán 50mg', 'Losartán potásico', 'CAR-001', 'Cardiovasculares', 'Merck', 'Caja 30 tabletas', '2027-06-30', 98.00, 78.00, 49.00, 120, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(43, 'Enalapril 10mg', 'Enalapril maleato', 'CAR-002', 'Cardiovasculares', 'Merck', 'Caja 30 tabletas', '2027-05-31', 72.00, 57.00, 36.00, 110, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(44, 'Amlodipino 5mg', 'Amlodipino besilato', 'CAR-003', 'Cardiovasculares', 'Pfizer', 'Caja 30 tabletas', '2027-04-30', 85.00, 68.00, 42.00, 100, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(45, 'Metoprolol 50mg', 'Metoprolol tartrato', 'CAR-004', 'Cardiovasculares', 'AstraZeneca', 'Caja 20 tabletas', '2027-03-31', 78.00, 62.00, 39.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(46, 'Atorvastatina 20mg', 'Atorvastatina cálcica', 'CAR-005', 'Cardiovasculares', 'Pfizer', 'Caja 30 tabletas', '2027-02-28', 145.00, 116.00, 72.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(47, 'Atorvastatina 40mg', 'Atorvastatina cálcica', 'CAR-006', 'Cardiovasculares', 'Pfizer', 'Caja 30 tabletas', '2027-02-28', 185.00, 148.00, 92.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(48, 'Aspirina 100mg', 'Ácido acetilsalicílico', 'CAR-007', 'Cardiovasculares', 'Bayer', 'Caja 30 tabletas', '2027-06-30', 38.00, 30.00, 19.00, 150, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(49, 'Clopidogrel 75mg', 'Clopidogrel bisulfato', 'CAR-008', 'Cardiovasculares', 'Sanofi', 'Caja 30 tabletas', '2027-01-31', 220.00, 176.00, 110.00, 50, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(50, 'Furosemida 40mg', 'Furosemida', 'CAR-009', 'Cardiovasculares', 'Sanofi', 'Caja 20 tabletas', '2026-11-30', 45.00, 36.00, 22.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(51, 'Digoxina 0.25mg', 'Digoxina', 'CAR-010', 'Cardiovasculares', 'Lanoxin', 'Caja 30 tabletas', '2026-09-30', 88.00, 70.00, 44.00, 40, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(52, 'Metformina 500mg', 'Metformina clorhidrato', 'DIA-001', 'Diabetes', 'Merck', 'Caja 30 tabletas', '2027-06-30', 62.00, 49.00, 31.00, 130, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(53, 'Metformina 850mg', 'Metformina clorhidrato', 'DIA-002', 'Diabetes', 'Merck', 'Caja 30 tabletas', '2027-06-30', 78.00, 62.00, 39.00, 110, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(54, 'Glibenclamida 5mg', 'Glibenclamida', 'DIA-003', 'Diabetes', 'Genérico MX', 'Caja 30 tabletas', '2027-05-31', 48.00, 38.00, 24.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(55, 'Glimepirida 2mg', 'Glimepirida', 'DIA-004', 'Diabetes', 'Sanofi', 'Caja 30 tabletas', '2027-04-30', 95.00, 76.00, 47.00, 70, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(56, 'Sitagliptina 100mg', 'Sitagliptina fosfato', 'DIA-005', 'Diabetes', 'MSD', 'Caja 30 tabletas', '2027-03-31', 380.00, 304.00, 190.00, 30, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(57, 'Insulina NPH 100UI/ml', 'Insulina isofánica humana', 'DIA-006', 'Diabetes', 'Novo Nordisk', 'Frasco 10ml', '2026-12-31', 210.00, 168.00, 105.00, 40, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(58, 'Insulina Regular 100UI/ml', 'Insulina humana', 'DIA-007', 'Diabetes', 'Novo Nordisk', 'Frasco 10ml', '2026-12-31', 200.00, 160.00, 100.00, 35, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(59, 'Salbutamol Inhalador 100mcg', 'Salbutamol sulfato', 'RES-001', 'Respiratorios', 'GSK', 'Aerosol 200 dosis', '2027-06-30', 185.00, 148.00, 92.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(60, 'Budesonida Inhalador 200mcg', 'Budesonida', 'RES-002', 'Respiratorios', 'AstraZeneca', 'Aerosol 200 dosis', '2027-05-31', 320.00, 256.00, 160.00, 35, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(61, 'Loratadina 10mg', 'Loratadina', 'RES-003', 'Respiratorios', 'Schering', 'Caja 10 tabletas', '2027-04-30', 42.00, 33.00, 21.00, 140, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(62, 'Cetirizina 10mg', 'Cetirizina dihidrocloruro', 'RES-004', 'Respiratorios', 'UCB', 'Caja 10 tabletas', '2027-03-31', 45.00, 36.00, 22.00, 120, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(63, 'Dextrometorfano 15mg/5ml', 'Dextrometorfano bromhidrato', 'RES-005', 'Respiratorios', 'Bayer', 'Frasco 120ml jarabe', '2026-11-30', 65.00, 52.00, 32.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(64, 'Ambroxol 30mg', 'Ambroxol clorhidrato', 'RES-006', 'Respiratorios', 'Boehringer', 'Frasco 120ml jarabe', '2027-02-28', 72.00, 57.00, 36.00, 74, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(65, 'Guaifenesina 100mg/5ml', 'Guaifenesina', 'RES-007', 'Respiratorios', 'Genérico MX', 'Frasco 120ml jarabe', '2026-10-31', 55.00, 44.00, 27.00, 70, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(66, 'Montelukast 10mg', 'Montelukast sódico', 'RES-008', 'Respiratorios', 'MSD', 'Caja 30 tabletas', '2027-06-30', 185.00, 148.00, 92.00, 45, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(67, 'Fexofenadina 120mg', 'Fexofenadina clorhidrato', 'RES-009', 'Respiratorios', 'Sanofi', 'Caja 10 tabletas', '2027-05-31', 88.00, 70.00, 44.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(68, 'Alprazolam 0.5mg', 'Alprazolam', 'NEU-001', 'Neurológicos', 'Upjohn', 'Caja 30 tabletas', '2027-01-31', 75.00, 60.00, 37.00, 50, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(69, 'Clonazepam 0.5mg', 'Clonazepam', 'NEU-002', 'Neurológicos', 'Roche', 'Caja 30 tabletas', '2027-02-28', 68.00, 54.00, 34.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(70, 'Fluoxetina 20mg', 'Fluoxetina clorhidrato', 'NEU-003', 'Neurológicos', 'Eli Lilly', 'Caja 14 cápsulas', '2027-03-31', 92.00, 73.00, 46.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(71, 'Sertralina 50mg', 'Sertralina clorhidrato', 'NEU-004', 'Neurológicos', 'Pfizer', 'Caja 14 tabletas', '2027-04-30', 105.00, 84.00, 52.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(72, 'Amitriptilina 25mg', 'Amitriptilina clorhidrato', 'NEU-005', 'Neurológicos', 'MSD', 'Caja 30 tabletas', '2027-05-31', 58.00, 46.00, 29.00, 45, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(73, 'Carbamazepina 200mg', 'Carbamazepina', 'NEU-006', 'Neurológicos', 'Novartis', 'Caja 30 tabletas', '2026-12-31', 88.00, 70.00, 44.00, 40, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(74, 'Gabapentina 300mg', 'Gabapentina', 'NEU-007', 'Neurológicos', 'Pfizer', 'Caja 15 cápsulas', '2027-01-31', 145.00, 116.00, 72.00, 35, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(75, 'Vitamina C 500mg', 'Ácido ascórbico', 'VIT-001', 'Vitaminas', 'Bayer', 'Caja 30 tabletas masticables', '2027-06-30', 48.00, 38.00, 24.00, 200, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(76, 'Vitamina D3 1000UI', 'Colecalciferol', 'VIT-002', 'Vitaminas', 'Merck', 'Caja 30 cápsulas', '2027-05-31', 85.00, 68.00, 42.00, 120, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(77, 'Complejo B', 'Vitaminas B1 B2 B6 B12', 'VIT-003', 'Vitaminas', 'Liomont', 'Caja 30 tabletas', '2027-04-30', 62.00, 49.00, 31.00, 150, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(78, 'Calcio + Vitamina D 600mg/400UI', 'Carbonato de calcio + Colecalciferol', 'VIT-004', 'Vitaminas', 'Pfizer', 'Caja 60 tabletas', '2027-03-31', 95.00, 76.00, 47.00, 100, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(79, 'Ácido Fólico 5mg', 'Ácido fólico', 'VIT-005', 'Vitaminas', 'Genérico MX', 'Caja 30 tabletas', '2027-02-28', 38.00, 30.00, 19.00, 125, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(80, 'Zinc 20mg', 'Sulfato de zinc', 'VIT-006', 'Vitaminas', 'Genérico MX', 'Caja 30 tabletas', '2027-01-31', 42.00, 33.00, 21.00, 110, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(81, 'Omega-3 1000mg', 'Ácidos grasos omega-3', 'VIT-007', 'Vitaminas', 'Bayer', 'Caja 30 cápsulas', '2027-06-30', 125.00, 100.00, 62.00, 90, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(82, 'Magnesio 400mg', 'Óxido de magnesio', 'VIT-008', 'Vitaminas', 'Genérico MX', 'Caja 30 tabletas', '2027-05-31', 65.00, 52.00, 32.00, 85, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(83, 'Hierro 65mg', 'Sulfato ferroso', 'VIT-009', 'Vitaminas', 'Genérico MX', 'Caja 30 tabletas', '2027-04-30', 45.00, 36.00, 22.00, 100, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(84, 'Hidrocortisona Crema 1%', 'Hidrocortisona', 'DER-001', 'Dermatológicos', 'Merck', 'Tubo 20g', '2027-06-30', 78.00, 62.00, 39.00, 70, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(85, 'Clotrimazol Crema 1%', 'Clotrimazol', 'Bayer', 'DER-002', 'Dermatológicos', 'Tubo 20g', '2027-05-31', 65.00, 52.00, 32.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(86, 'Mupirocina Ungüento 2%', 'Mupirocina', 'DER-003', 'Dermatológicos', 'GSK', 'Tubo 15g', '2027-03-31', 95.00, 76.00, 47.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(87, 'Ketoconazol Crema 2%', 'Ketoconazol', 'DER-004', 'Dermatológicos', 'Janssen', 'Tubo 30g', '2027-04-30', 88.00, 70.00, 44.00, 60, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(88, 'Tretinoína Crema 0.025%', 'Tretinoína', 'DER-005', 'Dermatológicos', 'Janssen', 'Tubo 20g', '2026-11-30', 145.00, 116.00, 72.00, 30, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(89, 'Permetrina Crema 5%', 'Permetrina', 'DER-006', 'Dermatológicos', 'Genérico MX', 'Tubo 60g', '2027-02-28', 118.00, 94.00, 59.00, 40, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(90, 'Tobramicina Colirio 0.3%', 'Tobramicina', 'OFT-001', 'Oftálmicos', 'Alcon', 'Frasco 5ml gotas', '2027-01-31', 95.00, 76.00, 47.00, 45, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(91, 'Lágrimas Artificiales', 'Carboximetilcelulosa 0.5%', 'OFT-002', 'Oftálmicos', 'Alcon', 'Frasco 15ml gotas', '2027-06-30', 78.00, 62.00, 39.00, 65, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(92, 'Ciprofloxacino Ótico 0.3%', 'Ciprofloxacino', 'OTI-001', 'Óticos', 'Bayer', 'Frasco 5ml gotas', '2027-05-31', 88.00, 70.00, 44.00, 40, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(93, 'Benzocaína + Propilenglicol Ótico', 'Benzocaína + Glicerina', 'OTI-002', 'Óticos', 'Genérico MX', 'Frasco 15ml gotas', '2026-12-31', 68.00, 54.00, 34.00, 35, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(94, 'Albendazol 400mg', 'Albendazol', 'PAR-001', 'Antiparasitarios', 'GSK', 'Caja 1 tableta', '2027-06-30', 32.00, 25.00, 16.00, 116, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(95, 'Mebendazol 500mg', 'Mebendazol', 'PAR-002', 'Antiparasitarios', 'Janssen', 'Caja 1 tableta', '2027-05-31', 28.00, 22.00, 14.00, 110, 15, NULL, 'disponible', '2026-06-04 15:58:58'),
(96, 'Ivermectina 6mg', 'Ivermectina', 'PAR-003', 'Antiparasitarios', 'MSD', 'Caja 4 tabletas', '2027-04-30', 85.00, 68.00, 42.00, 65, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(97, 'Metronidazol 250mg/5ml Suspensión', 'Metronidazol', 'PAR-004', 'Antiparasitarios', 'Baxter', 'Frasco 120ml', '2026-10-31', 62.00, 49.00, 31.00, 55, 8, NULL, 'disponible', '2026-06-04 15:58:58'),
(98, 'Alcohol Isopropílico 70%', 'Alcohol isopropílico', 'CUR-001', 'Material de curación', 'Genérico MX', 'Frasco 500ml', '2028-01-31', 45.00, 36.00, 22.00, 198, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(99, 'Agua Oxigenada 3%', 'Peróxido de hidrógeno', 'CUR-002', 'Material de curación', 'Genérico MX', 'Frasco 250ml', '2028-01-31', 22.00, 17.00, 11.00, 179, 20, NULL, 'disponible', '2026-06-04 15:58:58'),
(100, 'Vendas Elásticas 5cm x 4.5m', 'N/A', 'CUR-003', 'Material de curación', 'Bendi', 'Pieza individual', '2030-12-31', 18.00, 14.00, 9.00, 250, 30, NULL, 'disponible', '2026-06-04 15:58:58'),
(101, 'Gasas Estériles 10x10cm', 'N/A', 'CUR-004', 'Material de curación', 'Curapor', 'Paquete 10 piezas', '2030-12-31', 28.00, 22.00, 14.00, 300, 30, NULL, 'disponible', '2026-06-04 15:58:58'),
(102, 'Termómetro Digital', 'N/A', 'CUR-005', 'Material de curación', 'Omron', 'Pieza individual', '2035-12-31', 85.00, 68.00, 42.00, 50, 5, NULL, 'disponible', '2026-06-04 15:58:58'),
(103, 'Jeringas 3ml c/aguja', 'N/A', 'CUR-006', 'Material de curación', 'BD', 'Pieza o Caja', '2030-06-30', 8.00, 6.00, 4.00, 500, 50, NULL, 'disponible', '2026-06-04 15:58:58'),
(104, 'Guantes Látex Caja 100', 'N/A', 'CUR-007', 'Material de curación', 'Genérico MX', 'Caja 100 piezas', '2029-12-31', 95.00, 76.00, 47.00, 80, 10, NULL, 'disponible', '2026-06-04 15:58:58'),
(105, 'Cubrebocas Caja 50', 'N/A', 'CUR-008', 'Material de curación', 'Genérico MX', 'Caja 50 piezas', '2027-12-31', 65.00, 52.00, 32.00, 100, 10, NULL, 'disponible', '2026-06-04 15:58:58');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveedores`
--

CREATE TABLE `proveedores` (
  `id_proveedor` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `rfc` varchar(20) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `email` varchar(120) NOT NULL DEFAULT '',
  `productos` text DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'activo',
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `proveedores`
--

INSERT INTO `proveedores` (`id_proveedor`, `nombre`, `rfc`, `telefono`, `email`, `productos`, `estado`, `fecha_registro`) VALUES
(1, 'Distribuidora Médica del Sureste', 'DMS800415JK2', '2299101520', '', 'Analgésicos, antibióticos, antiinflamatorios, material de curación', 'activo', '2026-06-04 15:45:51'),
(2, 'Farmacéutica Nacional FANASA', 'FNA750920AB8', '5556781234', '', 'Cardiovasculares, diabetes, neurológicos, vitaminas y suplementos', 'activo', '2026-06-04 15:45:51'),
(3, 'Probiomed Distribuciones', 'PDI901105RT4', '5543219876', '', 'Respiratorios, dermatológicos, oftálmicos, antiparasitarios', 'activo', '2026-06-04 15:45:51'),
(4, 'Grupo Quimsa Salud', 'GQS850630MN3', '2228763300', '', 'Gastrointestinales, insulinas, inhaladores, material de curación', 'activo', '2026-06-04 15:45:51');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `contraseña` varchar(255) NOT NULL,
  `rol` enum('admin','empleado','cliente') NOT NULL DEFAULT 'empleado',
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `verificado` tinyint(1) NOT NULL DEFAULT 1,
  `token_verificacion` varchar(64) DEFAULT NULL,
  `token_expira` datetime DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `tipo_cliente` varchar(20) NOT NULL DEFAULT 'minorista',
  `limite_mayoreo` int(11) NOT NULL DEFAULT 50,
  `intentos_fallidos` int(11) NOT NULL DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `nombre`, `correo`, `contraseña`, `rol`, `estado`, `verificado`, `token_verificacion`, `token_expira`, `rfc`, `tipo_cliente`, `limite_mayoreo`, `intentos_fallidos`, `bloqueado_hasta`, `fecha_registro`) VALUES
(1, 'Administrador', 'admin@farmacia.com', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin', 'activo', 1, NULL, NULL, NULL, 'minorista', 50, 0, NULL, '2026-06-04 15:45:28'),
(2, 'Elizabeth Ramírez Torres', 'elizabeth.ramirez@farmacia.com', '$2y$10$7t1N1mKVl4VlLqBqL2sOUe0Zq3Gik8sBWfp9RsYcMxRJEqVk9L.26', 'empleado', 'activo', 1, NULL, NULL, NULL, 'minorista', 50, 0, NULL, '2026-06-04 15:45:51'),
(3, 'Jesús Hernández Mendoza', 'jesus.hernandez@farmacia.com', '$2y$10$7t1N1mKVl4VlLqBqL2sOUe0Zq3Gik8sBWfp9RsYcMxRJEqVk9L.26', 'empleado', 'activo', 1, NULL, NULL, NULL, 'minorista', 50, 0, NULL, '2026-06-04 15:45:51'),
(4, 'María Guadalupe Sánchez López', 'guadalupe.sanchez@gmail.com', '$2y$10$PX3pFqnTqFMjZyJMpHgY7uXQbLY8XNmVrJi1w4UGkOT0aWrPE7VQi', 'cliente', 'activo', 1, NULL, NULL, 'SALG850712HV8', 'minorista', 50, 0, NULL, '2026-06-04 15:45:51'),
(5, 'Roberto Carlos Flores Vega', 'roberto.flores@gmail.com', '$2y$10$PX3pFqnTqFMjZyJMpHgY7uXQbLY8XNmVrJi1w4UGkOT0aWrPE7VQi', 'cliente', 'activo', 1, NULL, NULL, 'FOVR780320QT5', 'mayorista', 100, 0, NULL, '2026-06-04 15:45:51'),
(6, 'Elizabeth peña', 'Luchas@gmail.com', '$2y$10$Y5nWBDctV1uhVYCuKnNWdu.IFY8arjw82vQapewRdDMs0SC5rfu1y', 'cliente', 'activo', 1, NULL, NULL, '', 'minorista', 50, 0, NULL, '2026-06-08 16:18:02');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`clave`);

--
-- Indices de la tabla `empleados_permisos`
--
ALTER TABLE `empleados_permisos`
  ADD PRIMARY KEY (`id_usuario`);

--
-- Indices de la tabla `creditos`
--
ALTER TABLE `creditos`
  ADD PRIMARY KEY (`id_credito`),
  ADD KEY `idx_creditos_usuario` (`id_usuario`);

--
-- Indices de la tabla `detalle_pedido`
--
ALTER TABLE `detalle_pedido`
  ADD PRIMARY KEY (`id_detalle`),
  ADD KEY `idx_detalle_pedido` (`id_pedido`),
  ADD KEY `idx_detalle_producto` (`id_producto`);

--
-- Indices de la tabla `facturas_compra`
--
ALTER TABLE `facturas_compra`
  ADD PRIMARY KEY (`id_factura`),
  ADD KEY `idx_fc_proveedor` (`id_proveedor`),
  ADD KEY `idx_factura_producto` (`id_producto`),
  ADD KEY `idx_fc_fecha` (`fecha`);

--
-- Indices de la tabla `historial_precios`
--
ALTER TABLE `historial_precios`
  ADD PRIMARY KEY (`id_historial`),
  ADD KEY `idx_historial_producto` (`id_producto`),
  ADD KEY `idx_historial_usuario` (`id_usuario`);

--
-- Indices de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD PRIMARY KEY (`id_movimiento`),
  ADD KEY `idx_mov_producto` (`id_producto`),
  ADD KEY `idx_mov_usuario` (`id_usuario`),
  ADD KEY `idx_mov_fecha` (`fecha`),
  ADD KEY `idx_mov_origen` (`origen`);

--
-- Indices de la tabla `pagos_credito`
--
ALTER TABLE `pagos_credito`
  ADD PRIMARY KEY (`id_pago`),
  ADD KEY `idx_pagos_credito` (`id_credito`);

--
-- Indices de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id_pedido`),
  ADD KEY `idx_pedidos_usuario` (`id_usuario`),
  ADD KEY `idx_pedidos_cliente` (`id_cliente`),
  ADD KEY `idx_pedidos_fecha` (`fecha`),
  ADD KEY `idx_pedidos_estado` (`estado`),
  ADD KEY `idx_pedidos_origen` (`origen`),
  ADD KEY `idx_pedidos_estado_aprobacion` (`estado_aprobacion`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id_producto`),
  ADD UNIQUE KEY `numero_lote` (`numero_lote`),
  ADD KEY `idx_productos_nombre` (`nombre`),
  ADD KEY `idx_productos_lote` (`numero_lote`),
  ADD KEY `idx_productos_caducidad` (`fecha_caducidad`),
  ADD KEY `idx_productos_categoria` (`categoria`),
  ADD KEY `idx_productos_estado` (`estado`);

--
-- Indices de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  ADD PRIMARY KEY (`id_proveedor`),
  ADD UNIQUE KEY `uq_proveedores_rfc` (`rfc`),
  ADD KEY `idx_proveedores_nombre` (`nombre`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD KEY `idx_usuarios_rol` (`rol`),
  ADD KEY `idx_usuarios_estado` (`estado`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `creditos`
--
ALTER TABLE `creditos`
  MODIFY `id_credito` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `detalle_pedido`
--
ALTER TABLE `detalle_pedido`
  MODIFY `id_detalle` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `facturas_compra`
--
ALTER TABLE `facturas_compra`
  MODIFY `id_factura` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT de la tabla `historial_precios`
--
ALTER TABLE `historial_precios`
  MODIFY `id_historial` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  MODIFY `id_movimiento` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `pagos_credito`
--
ALTER TABLE `pagos_credito`
  MODIFY `id_pago` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id_pedido` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id_producto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

--
-- AUTO_INCREMENT de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  MODIFY `id_proveedor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `empleados_permisos`
--
ALTER TABLE `empleados_permisos`
  ADD CONSTRAINT `fk_permisos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `creditos`
--
ALTER TABLE `creditos`
  ADD CONSTRAINT `fk_credito_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `detalle_pedido`
--
ALTER TABLE `detalle_pedido`
  ADD CONSTRAINT `fk_detalle_pedido` FOREIGN KEY (`id_pedido`) REFERENCES `pedidos` (`id_pedido`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_detalle_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `facturas_compra`
--
ALTER TABLE `facturas_compra`
  ADD CONSTRAINT `fk_factura_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_factura_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedores` (`id_proveedor`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `historial_precios`
--
ALTER TABLE `historial_precios`
  ADD CONSTRAINT `fk_historial_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD CONSTRAINT `fk_mov_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mov_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `pagos_credito`
--
ALTER TABLE `pagos_credito`
  ADD CONSTRAINT `fk_pago_credito` FOREIGN KEY (`id_credito`) REFERENCES `creditos` (`id_credito`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD CONSTRAINT `fk_pedidos_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pedidos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
