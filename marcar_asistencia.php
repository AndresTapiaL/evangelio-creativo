<?php
require 'conexion.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autorizado']);
  exit;
}
$id_usuario = $_SESSION['id_usuario'];

// casteamos a entero para que in_array lo reconozca
$id_evento = isset($_POST['id_evento'])
    ? (int) $_POST['id_evento']
    : 0;
$estado = isset($_POST['id_estado_previo_asistencia'])
    ? (int) $_POST['id_estado_previo_asistencia']
    : 0;

// validamos valores numéricos
if ($id_evento <= 0 || ! in_array($estado, [1,2,3], true)) {
  echo json_encode(['ok'=>false,'error'=>'Datos inválidos']);
  exit;
}

// Insert o update
$stmt = $pdo->prepare("
  INSERT INTO asistencias (id_evento, id_usuario, id_estado_previo_asistencia)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE
    id_estado_previo_asistencia = VALUES(id_estado_previo_asistencia)
");
$stmt->execute([$id_evento, $id_usuario, $estado]);

// Recontar “Presente”
$stmt2 = $pdo->prepare("
  SELECT COUNT(*) FROM asistencias
   WHERE id_evento = ? AND id_estado_previo_asistencia = 1
");
$stmt2->execute([$id_evento]);
$cnt_presente = (int)$stmt2->fetchColumn();

// Recontar integrantes
$stmt3 = $pdo->prepare("
  SELECT COUNT(DISTINCT iep.id_usuario)
    FROM equipos_proyectos_eventos epe
    JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
   WHERE epe.id_evento = ?
");
$stmt3->execute([$id_evento]);
$total_integrantes = (int)$stmt3->fetchColumn();

echo json_encode([
  'ok'                => true,
  'cnt_presente'      => $cnt_presente,
  'total_integrantes' => $total_integrantes
]);
