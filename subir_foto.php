<?php
/**
 * Sube una nueva foto de perfil o reemplaza la existente.
 *  • Guarda el archivo en uploads/fotos/
 *  • Actualiza la columna foto_perfil en la tabla usuarios
 *  • Elimina del disco la foto anterior (si existe y no es la de “default”)
 */
require 'conexion.php';
header('Content-Type: application/json');

// 1. Validar token y archivo ⇢ obtener $id_usuario
$token = $_POST['token'] ?? '';
if (!$token) {
  echo json_encode(['error' => 'Token no recibido.']);
  exit;
}
if (!isset($_FILES['foto'])) {
  echo json_encode(['error' => 'Archivo no recibido.']);
  exit;
}

$stmt = $pdo->prepare(
  "SELECT u.id_usuario
   FROM tokens_usuarios t
   JOIN usuarios u ON u.id_usuario = t.id_usuario
   WHERE t.token = :token AND t.expira_en >  NOW()"
);
$stmt->execute(['token' => $token]);
$id_usuario = $stmt->fetchColumn();

if (!$id_usuario) {
  echo json_encode(['error' => 'Token inválido o expirado.']);
  exit;
}

// 2. Validar archivo (extensión, tamaño, MIME)
$archivo = $_FILES['foto'];
$tmp      = $archivo['tmp_name'];
$ext      = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
$permitidosExt = ['jpg', 'jpeg', 'png', 'gif'];
$permitidosMime = ['image/jpeg', 'image/png', 'image/gif'];

$mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp);
if (!in_array($ext, $permitidosExt) || !in_array($mime, $permitidosMime)) {
  echo json_encode(['error' => 'Formato de imagen no válido.']);
  exit;
}
if ($archivo['size'] > 5 * 1024 * 1024) { // 5 MB
  echo json_encode(['error' => 'La imagen supera el tamaño máximo de 5 MB.']);
  exit;
}

// 3. Ruta final + creación de carpeta si no existe
$carpeta = 'uploads/fotos/';
if (!is_dir($carpeta) && !mkdir($carpeta, 0777, true)) {
  echo json_encode(['error' => 'No se pudo crear la carpeta de destino.']);
  exit;
}
$nombre_final  = 'foto_' . $id_usuario . '_' . time() . '.' . $ext;
$ruta_destino  = $carpeta . $nombre_final;

// 4. Mover archivo
if (!move_uploaded_file($tmp, $ruta_destino)) {
  echo json_encode(['error' => 'No se pudo guardar la imagen.']);
  exit;
}

// 5. Eliminar foto anterior (del disco y BD)
$oldPath = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id_usuario = :id");
$oldPath->execute(['id' => $id_usuario]);
$anterior = $oldPath->fetchColumn();

$pdo->prepare("UPDATE usuarios SET foto_perfil = :ruta WHERE id_usuario = :id")
    ->execute(['ruta' => $ruta_destino, 'id' => $id_usuario]);

if ($anterior && $anterior !== 'uploads/fotos/default.png' && file_exists($anterior)) {
  @unlink($anterior); // silencioso
}

echo json_encode([
  'mensaje' => 'Foto de perfil actualizada.',
  'ruta'    => $ruta_destino
]);
?>
