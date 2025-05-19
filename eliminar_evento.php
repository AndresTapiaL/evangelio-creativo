<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try{
  session_start();
  if (empty($_SESSION['id_usuario'])) throw new Exception('No autorizado');

  $id = (int)($_POST['id_evento'] ?? 0);
  if ($id <= 0) throw new Exception('ID de evento inválido');

  // Basta un DELETE; los FKs cascada harán el resto
  $stmt = $pdo->prepare('DELETE FROM eventos WHERE id_evento = ?');
  $stmt->execute([$id]);

  echo json_encode(['mensaje'=>'OK']);
}catch(Exception $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
