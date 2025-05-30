<?php
// ───── CONFIG ───────────────────────────────────────────────
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

// ───── PARÁMETROS ───────────────────────────────────────────
$type    = $_GET['type']    ?? '';        // integrantes | eventos | equipos | graf-event
$periodo = (int)($_GET['periodo'] ?? 0);  // id_periodo existente
$teamId  = isset($_GET['team']) && ctype_digit($_GET['team'])
           ? (int)$_GET['team']
           : 0;                           // 0 ⇒ “Todos”

if (!$periodo || !$type) {
    http_response_code(400);
    echo json_encode(['error'=>'params']);
    exit;
}

/* util para sumar columnas con zeros */
function pct($num,$den){ return $den?round(100*$num/$den,2):0; }

// ───── CONSULTAS SEGÚN $type ────────────────────────────────
switch ($type) {

  /* ═════════ 1) Justificaciones por integrantes ═══════════ */
  case 'integrantes':

    $teamWhere = $teamId ? " AND iep.id_equipo_proyecto = :team " : "";

    $sql = "
      SELECT
        u.id_usuario,
        CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno)
            AS nombre_completo,
        ji.nombre_justificacion_inasistencia,
        rjip.total,
        rjip.porcentaje
      FROM resumen_justificaciones_integrantes_periodo rjip
      JOIN usuarios                       u   ON u.id_usuario = rjip.id_usuario
      JOIN justificacion_inasistencia     ji  ON ji.id_justificacion_inasistencia =
                                                rjip.id_justificacion_inasistencia
      JOIN integrantes_equipos_proyectos  iep ON iep.id_usuario = u.id_usuario
      WHERE rjip.id_periodo = :p
        $teamWhere
      GROUP BY u.id_usuario,
               ji.nombre_justificacion_inasistencia";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':p', $periodo, PDO::PARAM_INT);
    if ($teamId) $stmt->bindValue(':team', $teamId, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

  /* ═════════ 2) Justificaciones por eventos ═══════════════ */
  case 'eventos':

    $teamJoin  = $teamId ? "JOIN equipos_proyectos_eventos epe ON epe.id_evento = rjep.id_evento" : "";
    $teamWhere = $teamId ? " AND epe.id_equipo_proyecto = :team " : "";

    $sql = "
      SELECT
        ev.id_evento,
        ev.nombre_evento,
        DATE(ev.fecha_hora_inicio)                 AS fecha_evento,
        ji.nombre_justificacion_inasistencia,
        rjep.total,
        rjep.porcentaje
      FROM resumen_justificaciones_eventos_periodo rjep
      JOIN eventos                     ev  ON ev.id_evento = rjep.id_evento
      JOIN justificacion_inasistencia  ji  ON ji.id_justificacion_inasistencia =
                                             rjep.id_justificacion_inasistencia
      $teamJoin
      WHERE rjep.id_periodo = :p
        $teamWhere";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':p', $periodo, PDO::PARAM_INT);
    if ($teamId) $stmt->bindValue(':team', $teamId, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

  /* ═════════ 3) Cuadro de estados por equipo  ═════════════ */
  case 'equipos':

    /* ───────── Filtro opcional por equipo ───────── */
    $teamWhere = $teamId ? " WHERE ep.id_equipo_proyecto = :team " : "";

    /* ── 1) Último estado de actividad de cada integrante dentro del período ── */
    $sql = "
      WITH ult AS (
        SELECT
          h.id_integrante_equipo_proyecto,
          -- último estado del periodo seleccionado
          SUBSTRING_INDEX(
            GROUP_CONCAT(h.id_tipo_estado_actividad
                         ORDER BY h.fecha_estado_actividad DESC), ',', 1) AS id_estado
        FROM historial_estados_actividad h
        WHERE get_period_id(h.fecha_estado_actividad) = :p
        GROUP BY h.id_integrante_equipo_proyecto
      )

      SELECT
        ep.nombre_equipo_proyecto,

        /* total de integrantes CON registro en el período */
        COUNT(*)                                         AS total_integrantes,

        SUM(ult.id_estado = 1)                           AS activos,
        SUM(ult.id_estado = 2)                           AS semiactivos,
        SUM(ult.id_estado = 5)                           AS nuevos,
        SUM(ult.id_estado = 3)                           AS inactivos,
        SUM(ult.id_estado = 4)                           AS en_espera,
        SUM(ult.id_estado = 6)                           AS retirados,
        SUM(ult.id_estado = 7)                           AS cambios

      FROM ult
      JOIN integrantes_equipos_proyectos iep
                 ON iep.id_integrante_equipo_proyecto = ult.id_integrante_equipo_proyecto
      JOIN equipos_proyectos ep USING(id_equipo_proyecto)
      $teamWhere
      GROUP BY ep.id_equipo_proyecto
      ORDER BY ep.nombre_equipo_proyecto
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':p', $periodo, PDO::PARAM_INT);
    if ($teamId) $stmt->bindValue(':team', $teamId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

  /* ═════════ 4) Datos crudos para gráfico de eventos ══════ */
  case 'eventos_estado':
    $sql = "
      SELECT
        ep.nombre_equipo_proyecto,
        ev.id_estado_final,
        COUNT(*)        AS total
      FROM eventos ev
      JOIN equipos_proyectos_eventos epe ON epe.id_evento = ev.id_evento
      JOIN equipos_proyectos          ep  ON ep.id_equipo_proyecto = epe.id_equipo_proyecto
      WHERE get_period_id(DATE(ev.fecha_hora_inicio)) = :p
      GROUP BY ep.id_equipo_proyecto, ev.id_estado_final";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':p',$periodo,PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
