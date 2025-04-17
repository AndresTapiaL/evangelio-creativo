<?php
require 'conexion.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['error' => 'Método no permitido']);
  exit;
}

$token = $_POST['token'] ?? '';
if (!$token) {
  echo json_encode(['error' => 'Token no recibido']);
  exit;
}

// Obtener el ID del usuario desde el token
$stmt = $pdo->prepare("SELECT id_usuario FROM tokens_usuarios WHERE token = :token AND expira_en > NOW()");
$stmt->execute(['token' => $token]);
$id_usuario = $stmt->fetchColumn();

if (!$id_usuario) {
  echo json_encode(['error' => 'Token inválido o expirado']);
  exit;
}

// Recibir datos
$campos = [
  'fecha_nacimiento', 'rut_dni', 'id_pais', 'id_region_estado', 'id_ciudad_comuna',
  'direccion', 'iglesia_ministerio', 'profesion_oficio_estudio', 'id_ocupacion'
];

$update = [];
$valores = [];

foreach ($campos as $campo) {
  if (isset($_POST[$campo])) {
    $update[] = "$campo = :$campo";
    $valores[$campo] = $_POST[$campo];
  }
}

if (!empty($update)) {
  $valores['id_usuario'] = $id_usuario;
  $sql = "UPDATE usuarios SET " . implode(', ', $update) . ", ultima_actualizacion = NOW() WHERE id_usuario = :id_usuario";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($valores);
}

// Actualizar correo
if (isset($_POST['correo'])) {
  $correo = $_POST['correo'];
  $stmt = $pdo->prepare("UPDATE correos_electronicos SET correo_electronico = :correo WHERE id_usuario = :id");
  $stmt->execute(['correo' => $correo, 'id' => $id_usuario]);
}

// Actualizar boletín
$boletin = isset($_POST['boletin']) ? 1 : 0;
$stmt = $pdo->prepare("UPDATE usuarios SET boletin = :boletin WHERE id_usuario = :id");
$stmt->execute(['boletin' => $boletin, 'id' => $id_usuario]);

echo json_encode(['mensaje' => 'Datos actualizados correctamente']);
?>
