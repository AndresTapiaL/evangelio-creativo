<?php
require 'conexion.php';

if (!isset($_GET['token'])) {
    echo "Token no proporcionado.";
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

if ($registro) {
    $ahora = date('Y-m-d H:i:s');
    if ($registro['expira_en'] > $ahora) {
        echo "Token válido. ID usuario: " . $registro['id_usuario'];
    } else {
        $pdo->prepare("DELETE FROM tokens_usuarios WHERE token = :token")->execute(['token' => $token]);
        echo "Token expirado.";
    }
} else {
    echo "Token no válido.";
}
?>