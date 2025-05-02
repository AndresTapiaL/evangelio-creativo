<?php
require 'conexion.php';
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
if (!$token) {
  echo json_encode(['error' => 'Token no recibido']);
  exit;
}

$stmt = $pdo->prepare("SELECT id_usuario FROM tokens_usuarios WHERE token = :token AND expira_en > NOW()");
$stmt->execute(['token' => $token]);
$id_usuario = $stmt->fetchColumn();

if (!$id_usuario) {
  echo json_encode(['error' => 'Token inválido']);
  exit;
}

// Eliminar teléfonos anteriores
$pdo->prepare("DELETE FROM telefonos WHERE id_usuario = :id")->execute(['id' => $id_usuario]);

// Insertar nuevos teléfonos
for ($i = 1; $i <= 3; $i++) {
  $numero = $_POST["telefono_$i"] ?? '';
  $tipo = $_POST["tipo_telefono_$i"] ?? '';
  $es_principal = ($i == 1) ? 1 : 0;

  if (!empty($numero) && !empty($tipo)) {
    $stmt = $pdo->prepare("INSERT INTO telefonos (id_usuario, telefono, es_principal, id_descripcion_telefono)
                           VALUES (:id_usuario, :telefono, :es_principal, :id_tipo)");
    $stmt->execute([
      'id_usuario' => $id_usuario,
      'telefono' => $numero,
      'es_principal' => $es_principal,
      'id_tipo' => $tipo
    ]);
  }
}

echo json_encode(['mensaje' => 'Teléfonos actualizados correctamente']);
?>
