<?php
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('column_exists')) {
    function column_exists(PDO $conexion, $table, $column) {
        $stmt = $conexion->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('table_exists')) {
    function table_exists(PDO $conexion, $table) {
        $stmt = $conexion->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $conexion, $table, $column, $definition) {
        if (!column_exists($conexion, $table, $column)) {
            $conexion->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

if (!function_exists('index_exists')) {
    function index_exists(PDO $conexion, $table, $index) {
        $stmt = $conexion->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        ");
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('ensure_index')) {
    function ensure_index(PDO $conexion, $table, $index, $column) {
        if (!index_exists($conexion, $table, $index)) {
            $conexion->exec("ALTER TABLE `$table` ADD INDEX `$index` (`$column`)");
        }
    }
}

if (!function_exists('ensure_schema')) {
    function ensure_schema(PDO $conexion) {
        ensure_column($conexion, 'productos', 'compuesto',        "VARCHAR(150) NULL");
        ensure_column($conexion, 'productos', 'numero_lote',      "VARCHAR(60) NULL");
        ensure_column($conexion, 'productos', 'precio_mayoreo',   "DECIMAL(10,2) NOT NULL DEFAULT 0");
        ensure_column($conexion, 'productos', 'costo_adquisicion',"DECIMAL(10,2) NOT NULL DEFAULT 0");

        ensure_column($conexion, 'usuarios', 'intentos_fallidos', "INT NOT NULL DEFAULT 0");
        ensure_column($conexion, 'usuarios', 'bloqueado_hasta',   "DATETIME NULL");
        ensure_column($conexion, 'usuarios', 'rfc',               "VARCHAR(20) NULL");
        ensure_column($conexion, 'usuarios', 'tipo_cliente',      "VARCHAR(20) NOT NULL DEFAULT 'minorista'");
        ensure_column($conexion, 'usuarios', 'limite_mayoreo',    "INT NOT NULL DEFAULT 50");
        ensure_column($conexion, 'usuarios', 'verificado',          "TINYINT(1) NOT NULL DEFAULT 1");
        ensure_column($conexion, 'usuarios', 'token_verificacion',  "VARCHAR(64) NULL");
        ensure_column($conexion, 'usuarios', 'token_expira',        "DATETIME NULL");

        ensure_column($conexion, 'pedidos', 'id_cliente',             "INT NULL");
        ensure_column($conexion, 'pedidos', 'estado_aprobacion',      "VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
        ensure_column($conexion, 'pedidos', 'hora_recoleccion',       "TIME NULL");
        ensure_column($conexion, 'pedidos', 'comentario_aprobacion',  "VARCHAR(255) NULL");
        ensure_column($conexion, 'pedidos', 'tipo_pago',              "VARCHAR(20) NOT NULL DEFAULT 'efectivo'");
        ensure_column($conexion, 'pedidos', 'origen',                 "VARCHAR(10) NOT NULL DEFAULT 'pos'");
        ensure_column($conexion, 'pedidos', 'fecha_resolucion',       "DATETIME NULL");

        ensure_column($conexion, 'detalle_pedido', 'precio_unitario', "DECIMAL(10,2) NOT NULL DEFAULT 0");
        ensure_column($conexion, 'detalle_pedido', 'modalidad',       "VARCHAR(20) NOT NULL DEFAULT 'menudeo'");

        if (!table_exists($conexion, 'proveedores')) {
            $conexion->exec("CREATE TABLE proveedores (
                id_proveedor INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(150) NOT NULL,
                rfc VARCHAR(20) NOT NULL,
                telefono VARCHAR(20) NOT NULL,
                productos TEXT NULL,
                fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        if (!table_exists($conexion, 'facturas_compra')) {
            $conexion->exec("CREATE TABLE facturas_compra (
                id_factura INT AUTO_INCREMENT PRIMARY KEY,
                id_proveedor INT NOT NULL,
                id_producto INT NOT NULL,
                cantidad INT NOT NULL,
                costo_unitario DECIMAL(10,2) NOT NULL,
                folio VARCHAR(80) NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        if (!table_exists($conexion, 'historial_precios')) {
            $conexion->exec("CREATE TABLE historial_precios (
                id_historial INT AUTO_INCREMENT PRIMARY KEY,
                id_producto INT NOT NULL,
                id_usuario INT NOT NULL,
                precio_menudeo_anterior DECIMAL(10,2) NOT NULL DEFAULT 0,
                precio_menudeo_nuevo DECIMAL(10,2) NOT NULL DEFAULT 0,
                precio_mayoreo_anterior DECIMAL(10,2) NOT NULL DEFAULT 0,
                precio_mayoreo_nuevo DECIMAL(10,2) NOT NULL DEFAULT 0,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        if (!table_exists($conexion, 'creditos')) {
            $conexion->exec("CREATE TABLE creditos (
                id_credito         INT AUTO_INCREMENT PRIMARY KEY,
                id_usuario         INT NOT NULL,
                monto_total        DECIMAL(10,2) NOT NULL DEFAULT 0,
                saldo_disponible   DECIMAL(10,2) NOT NULL DEFAULT 0,
                estado             VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                monto_solicitado   DECIMAL(10,2) NOT NULL DEFAULT 0,
                motivo_solicitud   VARCHAR(500) NULL,
                comentario_revision VARCHAR(255) NULL,
                fecha_solicitud    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_autorizacion DATETIME NULL,
                fecha_resolucion   DATETIME NULL,
                INDEX idx_creditos_usuario (id_usuario)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        ensure_column($conexion, 'creditos', 'monto_solicitado',    "DECIMAL(10,2) NOT NULL DEFAULT 0");
        ensure_column($conexion, 'creditos', 'motivo_solicitud',    "VARCHAR(500) NULL");
        ensure_column($conexion, 'creditos', 'comentario_revision', "VARCHAR(255) NULL");
        ensure_column($conexion, 'creditos', 'fecha_solicitud',     "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        ensure_column($conexion, 'creditos', 'fecha_autorizacion',  "DATETIME NULL");
        ensure_column($conexion, 'creditos', 'fecha_resolucion',    "DATETIME NULL");

        try {
            $ukCheck = $conexion->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'creditos'
                  AND INDEX_NAME = 'id_usuario' AND NON_UNIQUE = 0
            ");
            $ukCheck->execute();
            if ((int)$ukCheck->fetchColumn() > 0) {
                $conexion->exec("ALTER TABLE `creditos` DROP INDEX `id_usuario`");
                ensure_index($conexion, 'creditos', 'idx_creditos_usuario', 'id_usuario');
            }
        } catch (Exception $e) { }

        try {
            $enumCheck = $conexion->prepare("
                SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'creditos' AND COLUMN_NAME = 'estado'
            ");
            $enumCheck->execute();
            if ($enumCheck->fetchColumn() === 'enum') {
                $conexion->exec("ALTER TABLE `creditos` MODIFY `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
            }
        } catch (Exception $e) { }

        if (!table_exists($conexion, 'pagos_credito')) {
            $conexion->exec("CREATE TABLE pagos_credito (
                id_pago INT AUTO_INCREMENT PRIMARY KEY,
                id_credito INT NOT NULL,
                monto_pagado DECIMAL(10,2) NOT NULL,
                observaciones VARCHAR(255) NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        if (!table_exists($conexion, 'configuracion')) {
            $conexion->exec("CREATE TABLE configuracion (
                clave       VARCHAR(60)  NOT NULL,
                valor       VARCHAR(255) NOT NULL DEFAULT '',
                descripcion VARCHAR(255) NULL,
                PRIMARY KEY (clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $conexion->exec("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
                ('nombre_farmacia',   'Populares Peñaloza', 'Nombre de la farmacia'),
                ('intentos_max',      '3',                  'Intentos de login antes de bloquear'),
                ('inactividad_min',   '30',                 'Minutos de inactividad para cerrar sesión'),
                ('limite_mayoreo_def','50',                 'Cantidad mínima para precio mayoreo'),
                ('dias_alerta_cad',   '90',                 'Días antes de caducidad para alerta')");
        }

        ensure_index($conexion, 'productos', 'idx_productos_nombre',    'nombre');
        ensure_index($conexion, 'productos', 'idx_productos_lote',      'numero_lote');
        ensure_index($conexion, 'productos', 'idx_productos_caducidad', 'fecha_caducidad');
        ensure_index($conexion, 'pedidos',   'idx_pedidos_origen',      'origen');

        ensure_column($conexion, 'movimientos_inventario', 'origen',
            "VARCHAR(20) NOT NULL DEFAULT 'desconocido'");
        ensure_index($conexion, 'movimientos_inventario', 'idx_mov_origen', 'origen');

        ensure_column($conexion, 'proveedores', 'email',  "VARCHAR(120) NOT NULL DEFAULT ''");
        ensure_column($conexion, 'proveedores', 'estado', "VARCHAR(20)  NOT NULL DEFAULT 'activo'");
        ensure_index($conexion,  'proveedores', 'idx_proveedores_nombre', 'nombre');

        ensure_index($conexion, 'pedidos', 'idx_pedidos_fecha',   'fecha');
        ensure_index($conexion, 'pedidos', 'idx_pedidos_estado',  'estado');
        ensure_index($conexion, 'facturas_compra', 'idx_fc_proveedor', 'id_proveedor');
        ensure_index($conexion, 'facturas_compra', 'idx_fc_fecha',     'fecha');
        ensure_index($conexion, 'facturas_compra', 'idx_factura_producto', 'id_producto');

        try {
            $conexion->exec("
                UPDATE movimientos_inventario
                SET origen = CASE
                    WHEN observaciones LIKE 'Venta POS%'       THEN 'pos'
                    WHEN observaciones LIKE 'Pedido Web%'      THEN 'web'
                    WHEN observaciones LIKE 'Inventario inicial%' THEN 'entrada'
                    WHEN tipo_movimiento = 'entrada'            THEN 'entrada'
                    WHEN tipo_movimiento = 'ajuste'             THEN 'ajuste'
                    ELSE 'pos'
                END
                WHERE origen = 'desconocido'
            ");
        } catch (Exception $e) { }

        // ── Módulo de permisos por empleado ──────────────────────────────────
        if (!table_exists($conexion, 'empleados_permisos')) {
            $conexion->exec("CREATE TABLE empleados_permisos (
                id_usuario     INT NOT NULL PRIMARY KEY,
                ventas         TINYINT(1) NOT NULL DEFAULT 0,
                inventario     TINYINT(1) NOT NULL DEFAULT 0,
                compras        TINYINT(1) NOT NULL DEFAULT 0,
                clientes       TINYINT(1) NOT NULL DEFAULT 0,
                creditos       TINYINT(1) NOT NULL DEFAULT 0,
                reportes       TINYINT(1) NOT NULL DEFAULT 0,
                configuracion  TINYINT(1) NOT NULL DEFAULT 0,
                empleados      TINYINT(1) NOT NULL DEFAULT 0,
                pedidos_web    TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        // ── MEJORA 1: Agregar columna pedidos_web si no existe (migraciones automáticas) ─
        ensure_column($conexion, 'empleados_permisos', 'pedidos_web', "TINYINT(1) NOT NULL DEFAULT 0");

        // ── Asegurar fila de permisos para todos los empleados/admins existentes
        try {
            $conexion->exec("
                INSERT IGNORE INTO empleados_permisos (id_usuario)
                SELECT id_usuario FROM usuarios WHERE rol IN ('admin','empleado','vendedor')
            ");
            // Admins: todos los permisos en 1, incluyendo pedidos_web
            $conexion->exec("
                UPDATE empleados_permisos ep
                INNER JOIN usuarios u ON u.id_usuario = ep.id_usuario
                SET ep.ventas=1, ep.inventario=1, ep.compras=1, ep.clientes=1,
                    ep.creditos=1, ep.reportes=1, ep.configuracion=1, ep.empleados=1,
                    ep.pedidos_web=1
                WHERE u.rol = 'admin'
            ");
        } catch (Exception $e) { }
    }
}

if (!function_exists('password_column')) {
    function password_column(PDO $conexion) {
        foreach (['contraseña', 'contrasena', 'contrasea'] as $column) {
            if (column_exists($conexion, 'usuarios', $column)) return $column;
        }
        ensure_column($conexion, 'usuarios', 'contrasena', "VARCHAR(255) NULL");
        return 'contrasena';
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
    }
}

if (!function_exists('require_admin')) {
    function require_admin($message = 'Permisos insuficientes') {
        if (!is_admin()) {
            http_response_code(403);
            echo "<div class='alert alert-danger fw-bold'>$message</div>";
            echo "<a href='dashboard.php' class='btn btn-primary'>Volver al panel</a>";
            echo "</div></div></body></html>";
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ── MEJORA 1 & 4: Gestión y recarga dinámica de permisos ─────────────────────
// ══════════════════════════════════════════════════════════════════════════════

if (!function_exists('load_permisos_session')) {
    /**
     * Carga los permisos del empleado desde la BD hacia la sesión.
     * Se llama al iniciar sesión (Mejora 1) y en cada página (Mejora 4).
     */
    function load_permisos_session(PDO $conexion, int $id_usuario) {
        try {
            $stmt = $conexion->prepare("
                SELECT * FROM empleados_permisos WHERE id_usuario = ? LIMIT 1
            ");
            $stmt->execute([$id_usuario]);
            $perms = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($perms) {
                $_SESSION['permisos'] = $perms;
            } else {
                $_SESSION['permisos'] = [
                    'ventas' => 0, 'inventario' => 0, 'compras' => 0,
                    'clientes' => 0, 'creditos' => 0, 'reportes' => 0,
                    'configuracion' => 0, 'empleados' => 0,
                    'pedidos_web' => 0,
                ];
            }
        } catch (Exception $e) {
            $_SESSION['permisos'] = [];
        }
    }
}

if (!function_exists('refresh_permisos')) {
    /**
     * Mejora 4: Recarga permisos desde la BD en cada petición.
     * Garantiza que los cambios del admin se reflejen de inmediato.
     */
    function refresh_permisos(PDO $conexion) {
        if (!isset($_SESSION['id_usuario'])) return;
        if (($_SESSION['rol'] ?? '') === 'admin') return;
        load_permisos_session($conexion, (int)$_SESSION['id_usuario']);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ── MEJORA 2: Control de acceso basado en roles ───────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════

if (!function_exists('has_perm')) {
    /**
     * Devuelve true si el usuario actual tiene el permiso indicado.
     * Los administradores siempre tienen acceso completo.
     */
    function has_perm(string $perm): bool {
        if (($_SESSION['rol'] ?? '') === 'admin') return true;
        return (int)($_SESSION['permisos'][$perm] ?? 0) === 1;
    }
}

if (!function_exists('require_perm')) {
    /**
     * Bloquea el acceso si el usuario no tiene el permiso requerido.
     * Redirige a dashboard con mensaje de acceso denegado.
     */
    function require_perm(string $perm) {
        if (!has_perm($perm)) {
            if (!headers_sent()) {
                header("Location: dashboard.php?acceso_denegado=1");
            } else {
                echo "<script>window.location='dashboard.php?acceso_denegado=1';</script>";
            }
            exit;
        }
    }
}

if (!function_exists('require_perm_raw')) {
    /**
     * Variante para archivos sin header.php (reportes/facturas/movimientos).
     * Redirige limpiamente sin HTML previo.
     */
    function require_perm_raw(string $perm) {
        if (!has_perm($perm)) {
            header("Location: dashboard.php?acceso_denegado=1");
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ── MEJORA 3: Obtener permisos de un usuario para visualización ───────────────
// ══════════════════════════════════════════════════════════════════════════════

if (!function_exists('get_permisos_usuario')) {
    /**
     * Retorna el array de permisos de un usuario desde la BD.
     * Útil para mostrar los permisos en el perfil del empleado.
     */
    function get_permisos_usuario(PDO $conexion, int $id_usuario): array {
        try {
            $stmt = $conexion->prepare("
                SELECT * FROM empleados_permisos WHERE id_usuario = ? LIMIT 1
            ");
            $stmt->execute([$id_usuario]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: [];
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('normalize_text')) {
    function normalize_text($text) {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
        $text = str_replace(
            ["\xC3\xA1","\xC3\xA9","\xC3\xAD","\xC3\xB3","\xC3\xBA","\xC3\xBC","\xC3\xB1"],
            ['a','e','i','o','u','u','n'], $text
        );
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) return str_replace(["'", '`', '^', '~'], '', strtolower($converted));
        }
        $from = ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'];
        $to   = ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'];
        return str_replace(["'", '`', '^', '~'], '', str_replace($from, $to, $text));
    }
}

if (!function_exists('expiry_status')) {
    function expiry_status($fecha) {
        if (empty($fecha)) return ['status' => 'ok', 'days' => null, 'message' => ''];
        $today  = new DateTimeImmutable(date('Y-m-d'));
        $expiry = new DateTimeImmutable($fecha);
        $days   = (int)$today->diff($expiry)->format('%r%a');
        if ($days < 0)   return ['status' => 'expired',       'days' => $days, 'message' => 'Producto vencido - no se puede vender'];
        if ($days === 0) return ['status' => 'expired_today', 'days' => 0,     'message' => 'Producto vencido el dia de hoy - no es apto para comercializacion'];
        if ($days <= 90) return ['status' => 'warning',       'days' => $days, 'message' => 'Proximo a vencer - ' . $days . ' dias'];
        return ['status' => 'ok', 'days' => $days, 'message' => ''];
    }
}

if (!function_exists('can_sell_product')) {
    function can_sell_product(array $producto) {
        $status = expiry_status($producto['fecha_caducidad'] ?? null);
        return $status['status'] !== 'expired' && $status['status'] !== 'expired_today';
    }
}
?>
