<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try{
  session_start();
  if (empty($_SESSION['id_usuario'])) throw new Exception('No autorizado');

  /* ───── Datos recibidos ───── */
  $projArr   = $_POST['id_equipo_proyecto'] ?? [];                 // check-boxes
  $esGeneral = in_array('', $projArr, true) ? 1 : 0;               // "" ⇒ general
  $projArr   = array_filter($projArr, fn($v) => $v !== '');        // limpia los ""

  // ── convertir "" a null ──
if (isset($_POST['encargado']) && $_POST['encargado'] !== '') {
    $encargado = (int) $_POST['encargado'];
} else {
    $encargado = null;
}

  /* 1. Insertar evento */
  $stmt = $pdo->prepare("
    INSERT INTO eventos(
      nombre_evento,lugar,descripcion,observacion,
      fecha_hora_inicio,fecha_hora_termino,
      id_estado_previo,id_tipo,id_estado_final,
      encargado,es_general
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmt->execute([
    $_POST['nombre_evento']      ?? null,
    $_POST['lugar']              ?? null,
    $_POST['descripcion']        ?? null,
    $_POST['observacion']        ?? null,
    $_POST['fecha_hora_inicio']  ?? null,
    $_POST['fecha_hora_termino'] ?? null,
    $_POST['id_estado_previo']   ?? null,
    $_POST['id_tipo']            ?? null,
    $_POST['id_estado_final']    ?? null,
    $encargado,
    $esGeneral                          // ← ahora correcto
  ]);
  $newId = $pdo->lastInsertId();

  /* 2. Insertar equipos/proyectos (solo si NO es general) */
  if ($esGeneral === 0 && !empty($projArr)) {
    $ins = $pdo->prepare("
      INSERT INTO equipos_proyectos_eventos(id_evento,id_equipo_proyecto)
      VALUES (?,?)
    ");
    foreach ($projArr as $ep) {
      $ins->execute([$newId, $ep]);
    }
  }

  echo json_encode(['mensaje' => 'OK']);
}catch(Exception $e){
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
