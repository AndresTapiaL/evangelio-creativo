<?php
ob_start();
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
  session_start();
  if (empty($_SESSION['id_usuario'])) throw new Exception('No autorizado');

  /* ───── Datos recibidos ───── */
  $idEvento  = $_POST['id_evento'] ?? null;
  $projArr   = $_POST['id_equipo_proyecto'] ?? [];       // ← viene del form
  $esGeneral = in_array('', $projArr, true);             // checkbox “General”

  /* ───── Validaciones simples ───── */
  $ini = $_POST['fecha_hora_inicio']  ?? '';
  $fin = $_POST['fecha_hora_termino'] ?? '';
  if ($fin && strtotime($fin) < strtotime($ini)) {
      throw new Exception('La fecha y hora de término no puede ser anterior a la de inicio.');
  }

  // convertir "" a null para el encargado
if (isset($_POST['encargado']) && $_POST['encargado'] !== '') {
    $encargado = (int) $_POST['encargado'];
} else {
    $encargado = null;
}

  /* ───── 1) Actualizar tabla eventos ───── */
  $f = fn($k) => ($_POST[$k] ?? null) !== '' ? $_POST[$k] : null;
  $stmt = $pdo->prepare("
    UPDATE eventos SET
      nombre_evento      = :nombre,
      lugar              = :lugar,
      descripcion        = :desc,
      observacion        = :obs,
      fecha_hora_inicio  = :ini,
      fecha_hora_termino = :fin,
      id_estado_previo   = :prev,
      id_tipo            = :tipo,
      id_estado_final    = :finest,
      encargado          = :enc,
      es_general         = :gen
    WHERE id_evento = :id
  ");
  $stmt->execute([
    ':nombre'   => $f('nombre_evento'),
    ':lugar'    => $f('lugar'),
    ':desc'     => $f('descripcion'),
    ':obs'      => $f('observacion'),
    ':ini'      => $f('fecha_hora_inicio'),
    ':fin'      => $f('fecha_hora_termino'),
    ':prev'     => $f('id_estado_previo'),
    ':tipo'     => $f('id_tipo'),
    ':finest'   => $f('id_estado_final'),
    ':enc'      => $encargado,
    ':gen'      => $esGeneral ? 1 : 0,
    ':id'       => $idEvento
  ]);

  /* ───── 2) Actualizar relación N-a-N ───── */
  $pdo->prepare("DELETE FROM equipos_proyectos_eventos WHERE id_evento = ?")
      ->execute([$idEvento]);

  if (!$esGeneral) {                       // solo si NO es general
    $ins = $pdo->prepare("
      INSERT INTO equipos_proyectos_eventos (id_evento, id_equipo_proyecto)
      VALUES (?, ?)
    ");
    foreach ($projArr as $eid) {
      if ($eid === '') continue;           // ignoro casilla vacía por seguridad
      $ins->execute([$idEvento, $eid]);
    }
  }

  ob_clean();
  echo json_encode(['mensaje' => 'OK']);
} catch (Exception $e) {
  http_response_code(400);
  ob_clean();
  echo json_encode(['error' => $e->getMessage()]);
}
