<?php
// marcar_asistencia_pasados.php
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// 1) Validar sesión
if (empty($_SESSION['id_usuario'])) {
    header('Content-Type: application/json', true, 403);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

// 2) Leer y validar inputs
$id_evento  = filter_input(INPUT_POST, 'id_evento', FILTER_VALIDATE_INT);
$estado     = filter_input(INPUT_POST, 'id_estado_asistencia', FILTER_VALIDATE_INT);
$just       = filter_input(INPUT_POST, 'id_justificacion_inasistencia', FILTER_VALIDATE_INT);
$otros      = filter_input(INPUT_POST, 'descripcion_otros', FILTER_SANITIZE_STRING);

if (!$id_evento || !in_array($estado, [1,2,3], true)) {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['ok'=>false,'error'=>'Datos inválidos']);
    exit;
}
// si eligió "Justificado" debe venir justificación
if ($estado === 3 && !$just) {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['ok'=>false,'error'=>'Debe elegir una justificación']);
    exit;
}
// si eligió "Otros" (id=9) debe venir descripción
if ($just === 9 && trim($otros)==='') {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['ok'=>false,'error'=>'Debe describir la justificación “Otros”']);
    exit;
}

// 3) Verificar permiso sobre el evento (mismo join que en marcar_asistencia)
$chk = $pdo->prepare("
  SELECT 1
    FROM eventos e
    LEFT JOIN equipos_proyectos_eventos epe
      ON e.id_evento = epe.id_evento
    LEFT JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     AND iep.id_usuario = ?
   WHERE e.id_evento = ?
     AND e.fecha_hora_termino < NOW()
     AND e.id_estado_previo = 1
     AND e.id_estado_final NOT IN (5,6)
     AND (e.es_general = 1 OR iep.id_usuario IS NOT NULL)
   LIMIT 1
");
$chk->execute([$id_usuario, $id_evento]);
if (!$chk->fetchColumn()) {
    header('Content-Type: application/json', true, 403);
    echo json_encode(['ok'=>false,'error'=>'Sin permiso para este evento']);
    exit;
}

// 4) Insert / Update en asistencias
$stmt = $pdo->prepare("
  INSERT INTO asistencias
    (id_usuario, id_evento, id_estado_asistencia, id_justificacion_inasistencia, descripcion_otros)
  VALUES (?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    id_estado_asistencia        = VALUES(id_estado_asistencia),
    id_justificacion_inasistencia = VALUES(id_justificacion_inasistencia),
    descripcion_otros           = VALUES(descripcion_otros)
");
$stmt->execute([
    $id_usuario,
    $id_evento,
    $estado,
    $just?: null,
    $otros?: null
]);

// 5) Responder éxito
header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
exit;
