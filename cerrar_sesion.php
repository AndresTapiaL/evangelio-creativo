<?php
require 'conexion.php';

if (!isset($_GET['token'])) {
    echo "Token no proporcionado.";
    exit;
}

$token = $_GET['token'];

$stmt = $pdo->prepare("DELETE FROM tokens_usuarios WHERE token = :token");
$stmt->execute(['token' => $token]);

if ($stmt->rowCount()) {
    echo "Sesión cerrada exitosamente.";
} else {
    echo "Token no encontrado o ya eliminado.";
}
?>