<?php
/**
 * Elimina la foto de perfil de un usuario:
 *  • Borra el archivo del disco (si existe y no es default)
 *  • Pone NULL en usuarios.foto_perfil
 */
require 'conexion.php';
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
if (!$token) {
  echo json_encode(['error' => 'Token no recibido']);
  exit;
}

$stmt = $pdo->prepare("
  SELECT u.id_usuario, u.foto_perfil
  FROM tokens_usuarios t
  JOIN usuarios u ON u.id_usuario = t.id_usuario
  WHERE t.token = :token AND t.expira_en > NOW()
");
$stmt->execute(['token' => $token]);
$datos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos) {
  echo json_encode(['error' => 'Token inválido o expirado']);
  exit;
}

$foto_actual = $datos['foto_perfil'];
if ($foto_actual && $foto_actual !== 'uploads/fotos/default.png' && file_exists($foto_actual)) {
  @unlink($foto_actual);
}

$pdo->prepare("UPDATE usuarios SET foto_perfil = NULL WHERE id_usuario = :id")
    ->execute(['id' => $datos['id_usuario']]);

echo json_encode(['mensaje' => 'Foto eliminada']);
?>
