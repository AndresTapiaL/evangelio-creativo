<?php
require 'conexion.php';

if (!isset($_GET['token'])) {
    http_response_code(401);
    echo "Acceso denegado: token requerido.";
    exit;
}

$token = $_GET['token'];

$stmt = $pdo->prepare("
    SELECT id_usuario, expira_en 
    FROM tokens_usuarios 
    WHERE token = :token
");
$stmt->execute(['token' => $token]);
$registro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$registro || $registro['expira_en'] <= date('Y-m-d H:i:s')) {
    http_response_code(401);
    echo "Token inválido o expirado.";
    exit;
}

// Si se desea usar $id_usuario después:
$id_usuario = $registro['id_usuario'];
?>
