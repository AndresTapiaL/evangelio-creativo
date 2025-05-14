<?php
ini_set('display_errors',        0);
ini_set('display_startup_errors',0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
// subir_foto.php
require 'conexion.php';

// 1) Validar token y obtener usuario
$token = $_POST['token'] ?? '';
if (!$token) {
    echo json_encode(['error' => 'Token no recibido.']);
    exit;
}
$stmt = $pdo->prepare("
  SELECT u.id_usuario 
    FROM tokens_usuarios t
    JOIN usuarios u ON u.id_usuario = t.id_usuario
   WHERE t.token = :token 
     AND t.expira_en > NOW()
");
$stmt->execute(['token' => $token]);
$id_usuario = $stmt->fetchColumn();
if (!$id_usuario) {
    echo json_encode(['error' => 'Token inválido o expirado.']);
    exit;
}

// 2) Manejar eliminación de foto si se solicitó
$delete = $_POST['delete_foto'] ?? '0';
if ($delete === '1') {
    // 2.1) Obtener la ruta actual de la foto en BD
    $stmt = $pdo->prepare("
      SELECT foto_perfil 
        FROM usuarios 
       WHERE id_usuario = :id
    ");
    $stmt->execute(['id' => $id_usuario]);
    $prev = $stmt->fetchColumn();

    // 2.2) Actualizar en la BD para que apunte a la default
    $pdo->prepare("
      UPDATE usuarios 
         SET foto_perfil = 'uploads/fotos/default.png' 
       WHERE id_usuario = :id
    ")->execute(['id' => $id_usuario]);

    // 2.3) Borrar el archivo anterior del disco (si existía y no era la default)
    if ($prev 
        && $prev !== 'uploads/fotos/default.png' 
        && file_exists(__DIR__ . '/' . $prev)
    ) {
        @unlink(__DIR__ . '/' . $prev);
    }

    // 2.4) Responder al cliente
    echo json_encode([
      'mensaje' => 'Foto eliminada.',
      'ruta'    => 'uploads/fotos/default.png'
    ]);
    exit;
}


// Límite de peso: 5 MB
$maxSize = 5 * 1024 * 1024;  // en bytes

// 3) Si no hay archivo, error
if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No se recibió ninguna imagen.']);
    exit;
}

if ($_FILES['foto']['size'] > $maxSize) {
    echo json_encode(['error' => 'El archivo excede el tamaño máximo de 2 MB.']);
    exit;
}

// 4) Validar tipo y guardar nuevo archivo
$allowed = ['image/jpeg','image/png','image/gif','image/webp','image/jpg'];
if (!in_array($_FILES['foto']['type'], $allowed)) {
    echo json_encode(['error' => 'Formato de imagen no permitido.']);
    exit;
}
$ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
$ruta = 'uploads/fotos/' . uniqid('f_', true) . "." . $ext;
if (!move_uploaded_file($_FILES['foto']['tmp_name'], $ruta)) {
    echo json_encode(['error' => 'No se pudo guardar la imagen.']);
    exit;
}

// 5) Actualizar BD y borrar anterior
$old = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id_usuario = :id");
$old->execute(['id' => $id_usuario]);
$prev = $old->fetchColumn();

$pdo->prepare("
  UPDATE usuarios 
     SET foto_perfil = :ruta 
   WHERE id_usuario = :id
")->execute(['ruta' => $ruta, 'id' => $id_usuario]);

if ($prev && $prev !== 'uploads/fotos/default.png' && file_exists($prev)) {
    @unlink($prev);
}

echo json_encode([
  'mensaje' => 'Foto actualizada.',
  'ruta'    => $ruta
]);
