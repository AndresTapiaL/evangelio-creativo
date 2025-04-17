<?php
require 'conexion.php';
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
if (!$token || !isset($_FILES['foto'])) {
  echo json_encode(['error' => 'Token o archivo no recibido']);
  exit;
}

$stmt = $pdo->prepare("SELECT id_usuario FROM tokens_usuarios WHERE token = :token AND expira_en > NOW()");
$stmt->execute(['token' => $token]);
$id_usuario = $stmt->fetchColumn();

if (!$id_usuario) {
  echo json_encode(['error' => 'Token inválido o expirado']);
  exit;
}

$archivo = $_FILES['foto'];
$extensiones_validas = ['jpg', 'jpeg', 'png', 'gif'];
$nombre_original = $archivo['name'];
$tmp = $archivo['tmp_name'];
$ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

if (!in_array($ext, $extensiones_validas)) {
  echo json_encode(['error' => 'Formato de imagen no válido']);
  exit;
}

$nombre_final = 'foto_' . $id_usuario . '_' . time() . '.' . $ext;
$ruta_destino = 'uploads/fotos/' . $nombre_final;

// Asegurar carpeta
if (!file_exists('uploads/fotos')) {
  mkdir('uploads/fotos', 0777, true);
}

if (move_uploaded_file($tmp, $ruta_destino)) {
  $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = :ruta WHERE id_usuario = :id");
  $stmt->execute(['ruta' => $ruta_destino, 'id' => $id_usuario]);
  echo json_encode(['mensaje' => 'Foto actualizada', 'ruta' => $ruta_destino]);
} else {
  echo json_encode(['error' => 'No se pudo mover el archivo']);
}
?>
