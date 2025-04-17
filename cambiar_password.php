<?php
require 'conexion.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['error' => 'Método no permitido']);
  exit;
}

$token = $_POST['token'] ?? '';
$clave_actual = $_POST['clave_actual'] ?? '';
$clave_nueva = $_POST['clave_nueva'] ?? '';

if (!$token || !$clave_actual || !$clave_nueva) {
  echo json_encode(['error' => 'Faltan datos']);
  exit;
}

$stmt = $pdo->prepare("SELECT u.id_usuario, u.password FROM tokens_usuarios t JOIN usuarios u ON t.id_usuario = u.id_usuario WHERE t.token = :token AND t.expira_en > NOW()");
$stmt->execute(['token' => $token]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
  echo json_encode(['error' => 'Token inválido']);
  exit;
}

if (!password_verify($clave_actual, $usuario['password'])) {
  echo json_encode(['error' => 'Contraseña actual incorrecta']);
  exit;
}

$nueva_hash = password_hash($clave_nueva, PASSWORD_DEFAULT);
$update = $pdo->prepare("UPDATE usuarios SET password = :nueva WHERE id_usuario = :id");
$update->execute([
  'nueva' => $nueva_hash,
  'id' => $usuario['id_usuario']
]);

echo json_encode(['mensaje' => 'Contraseña actualizada correctamente']);
?>
