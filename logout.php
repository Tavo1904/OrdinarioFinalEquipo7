<?php
// 1. Iniciar la sesión para poder acceder a ella
session_start();

// 2. Eliminar todas las variables de sesión (limpia $_SESSION)
session_unset();

// 3. Destruir la sesión completamente del servidor
session_destroy();

// 4. Redirigir al usuario al Login (index.php)
header("Location: index.php");
exit;
?>