<?php
// cerrar_sesion.php
session_start();

// 1) Vaciar $_SESSION
$_SESSION = [];
$where  = [];
$params = [];

// 2) Borrar la cookie de sesión (si existe)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(), '', time() - 42000,
      $params['path'], $params['domain'],
      $params['secure'], $params['httponly']
    );
}

$token = null;
$hdrs  = apache_request_headers() ?: [];
if (! empty($hdrs['Authorization'])) {
    if (preg_match('/Bearer\s+([A-Fa-f0-9]{64})/', $hdrs['Authorization'], $m)) {
        $token = $m[1];
    }
}

if ($token) {
    require 'conexion.php';
    $stmt = $pdo->prepare("DELETE FROM tokens_usuarios WHERE token = ?");
    $stmt->execute([$token]);
}

// 3) Destruir la sesión
session_destroy();

// 4) Si llega un token por GET, también lo borramos de la tabla
if (isset($_GET['token']) && preg_match('/^[A-Fa-f0-9]{64}$/', $_GET['token'])) {
    require 'conexion.php';
    $stmt = $pdo->prepare("DELETE FROM tokens_usuarios WHERE token = ?");
    $stmt->execute([ $_GET['token'] ]);
}

// 5) Redirigir al login (o devolver JSON si lo prefieres)
header('Location: login.html');
exit;
