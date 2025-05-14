<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
  // Validar token/session como en actualizar_usuario.php
  session_start();
  if (empty($_SESSION['id_usuario'])) throw new Exception('No autorizado');

  // convertir a DateTime para comparar
  $ini = $_POST['fecha_hora_inicio']   ?? '';
  $fin = $_POST['fecha_hora_termino']  ?? '';

  if ($fin && strtotime($fin) < strtotime($ini)) {
  throw new Exception('La fecha y hora de término no puede ser anterior a la de inicio.');
  }

  // Obtener campos
  $id   = $_POST['id_evento'];
  $f    = fn($k)=>($_POST[$k]??null) !== '' ? $_POST[$k] : null;

  // 1) Actualizar tabla eventos
  $stmt = $pdo->prepare("
  UPDATE eventos SET
      nombre_evento     = :nombre,
      lugar             = :lugar,
      descripcion       = :descripcion,
      observacion       = :observacion,
      fecha_hora_inicio = :start,
      fecha_hora_termino= :end,
      id_estado_previo  = :previo,
      id_tipo           = :tipo,
      id_estado_final   = :final,
      encargado         = :enc
  WHERE id_evento = :id
  ");
  $stmt->execute([
  ':nombre'      => $f('nombre_evento'),
  ':lugar'       => $f('lugar'),
  ':descripcion' => $f('descripcion'),
  ':observacion' => $f('observacion'),
  ':start'       => $f('fecha_hora_inicio'),
  ':end'         => $f('fecha_hora_termino'),
  ':previo'      => $f('id_estado_previo'),
  ':tipo'        => $f('id_tipo'),
  ':final'       => $f('id_estado_final'),
  ':enc'         => $f('encargado'),
  ':id'          => $id
  ]);

  // 2) Actualizar relación muchos-a-muchos
  $pdo->prepare("DELETE FROM equipos_proyectos_eventos WHERE id_evento=?")
      ->execute([$id]);
  if (!empty($_POST['equipo_ids']) && is_array($_POST['equipo_ids'])) {
    $ins = $pdo->prepare("
      INSERT INTO equipos_proyectos_eventos(id_evento,id_equipo_proyecto)
      VALUES(?,?)
    ");
    foreach ($_POST['equipo_ids'] as $eid) {
      $ins->execute([$id, $eid]);
    }
  }

  echo json_encode(['mensaje'=>'OK']);
} catch(Exception $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
