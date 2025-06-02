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

    /* ——— Validación ——— */
    if (!$teamId) {
        echo json_encode(['ok'=>false,'error'=>'team-required']);
        exit;
    }

    /* 1-a)  Información del período seleccionado  */
    $per = $pdo->prepare("
        SELECT fecha_inicio, fecha_termino , es_historico
          FROM periodos
         WHERE id_periodo = ?
    ");
    $per->execute([$periodo]);
    $perInfo = $per->fetch(PDO::FETCH_ASSOC);

    if (!$perInfo) {                         // id-periodo inexistente
        echo json_encode(['ok'=>false,'error'=>'bad-period']);
        exit;
    }

    /* 1-b)  Miembros vigentes del equipo en ese período
             (al menos un estado de actividad dentro del rango)      */
    /* 1-b)  universo de integrantes del equipo  ─────────────── */
    /* ---  (sin historial ni rango de fechas)  ---------------- */

    $uidList = $pdo->query("
        SELECT id_usuario
        FROM   integrantes_equipos_proyectos
        WHERE  id_equipo_proyecto = $teamId
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($uidList)) {
        /* no hay integrantes todavía ⇒ usamos 0 para que el IN sea válido   */
        $uidList = [0];
    }

    /*  ─── 3) genera la lista para el IN de forma segura ───  */
    $in = implode(',', $uidList);          // ahora nunca queda vacío

    /* 1-c)  Catálogo de justificaciones (para pivot)                */
    $just = $pdo->query("
        SELECT id_justificacion_inasistencia   AS jid,
               nombre_justificacion_inasistencia
          FROM justificacion_inasistencia
      ORDER BY jid
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* 1-d)  Estadísticas reales (sólo eventos REALIZADOS = 4)       */
    $sqlStats = "
        WITH rango AS (
              SELECT :ini AS ini , :fin AS fin
        ),
        universe AS (
              SELECT id_usuario
                FROM integrantes_equipos_proyectos
               WHERE id_equipo_proyecto = :team_u
        ),
        eventos_ok AS (                      /* eventos del equipo, estado = 4   */
              SELECT e.id_evento , e.fecha_hora_inicio
                FROM eventos e
                JOIN equipos_proyectos_eventos epe ON epe.id_evento = e.id_evento
               WHERE epe.id_equipo_proyecto = :team_e
                 AND e.id_estado_final = 4
                 AND DATE(e.fecha_hora_inicio) BETWEEN :ini AND :fin
        ),
        asist_ok AS (                        /* asistencias válidas               */
              SELECT  a.id_usuario,
                      COALESCE(a.id_justificacion_inasistencia,11) AS jid
                FROM asistencias a
                JOIN eventos_ok  eo  ON eo.id_evento = a.id_evento
                JOIN integrantes_equipos_proyectos iep
                     ON iep.id_usuario = a.id_usuario
                    AND iep.id_equipo_proyecto = :team_a
                WHERE a.id_usuario IN (%s)   /* <- lista miembros */
        ),
        totales AS (
              SELECT id_usuario , COUNT(*) AS denom
                FROM asist_ok
            GROUP BY id_usuario
        )
        SELECT
            u.id_usuario,
            j.jid                               AS id_justificacion_inasistencia,
            IFNULL(s.cnt , 0)                   AS total,
            COALESCE(
                ROUND(100 * IFNULL(s.cnt,0) / NULLIF(t.denom,0), 2),
                0
            ) AS porcentaje
        FROM (SELECT id_usuario FROM universe) u     /* mantiene el universo */
        CROSS JOIN (
            SELECT id_justificacion_inasistencia AS jid
              FROM justificacion_inasistencia
        ) j
        LEFT  JOIN (SELECT id_usuario , jid , COUNT(*) cnt
                      FROM asist_ok
                  GROUP BY id_usuario , jid) s
               ON s.id_usuario = u.id_usuario
              AND s.jid        = j.jid
        LEFT  JOIN totales t  ON t.id_usuario = u.id_usuario
        ORDER BY u.id_usuario , j.jid
    ";

    /* —— reemplazamos %s por la lista bind-param ───────────────── */
    $in = implode(',', $uidList);            // (todos son INT, seguro)
    $sqlStats = sprintf($sqlStats, $in);

    $stmS = $pdo->prepare($sqlStats);
    $stmS->bindValue(':team_u', $teamId, PDO::PARAM_INT);
    $stmS->bindValue(':team_e', $teamId, PDO::PARAM_INT);
    $stmS->bindValue(':team_a', $teamId, PDO::PARAM_INT);
    $stmS->bindValue(':ini',   $perInfo['fecha_inicio'] ?: '1000-01-01');
    $stmS->bindValue(':fin',   $perInfo['fecha_termino'] ?: '9999-12-31');
    $stmS->execute();
    $rows = $stmS->fetchAll(PDO::FETCH_ASSOC);

    /* 1-e) Nombres de los usuarios --------------------------------- */
    $nombres = $pdo->query("
        SELECT id_usuario,
              CONCAT(nombres,' ',apellido_paterno,' ',apellido_materno) AS nombre
          FROM usuarios
        WHERE id_usuario IN ($in)
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    /* 1-e-bis) Mapa de justificaciones id ⇒ nombre ------------------ */
    $mapJust = array_column(
                  $just,                       // array fuente
                  'nombre_justificacion_inasistencia', // value
                  'jid'                        // key
              );

    /* 1-f)  Formato de salida (sin avisos) -------------------------- */
    $out = [];
    foreach ($rows as $r){
        $out[] = [
          'id_usuario'        => $r['id_usuario'],
          'nombre_completo'   => $nombres[$r['id_usuario']]  ?? '—',
          'nombre_justificacion_inasistencia'
                                => $mapJust[$r['id_justificacion_inasistencia']] ?? '',
          'porcentaje'        => $r['porcentaje'] ?? 0
        ];
    }

    echo json_encode(['ok'=>true,'data'=>$out]);
    exit;


  /* ═════════ 2) Justificaciones por eventos ═══════════════ */
  case 'eventos':

    /* Si $teamId = 0 → traer todos los equipos; 
       si no, filtrar con AND epe.id_equipo_proyecto = :team */
    $teamFilter = $teamId ? 'AND epe.id_equipo_proyecto = :team' : '';

    /* ——— Seleccionamos todas las combinaciones evento ↔ justificación ———
       (así obtenemos filas con total=0 si no hubo participantes de ese tipo) */
    $sql = "
      SELECT
        ev.id_evento,
        ev.nombre_evento,
        DATE(ev.fecha_hora_inicio) AS fecha_evento,
        ji.nombre_justificacion_inasistencia,
        COALESCE(r.total,0)     AS total,
        COALESCE(r.porcentaje,0) AS porcentaje
      FROM eventos ev

      /* 1) equipo asociado al evento */
      JOIN equipos_proyectos_eventos epe
        ON epe.id_evento = ev.id_evento

      /* 2) “cruzamos” con todas las justificaciones posibles */
      CROSS JOIN justificacion_inasistencia ji

      /* 3) Unimos la tabla resumen, PERO TOMAMOS el id_periodo generado por get_period_id(fecha) */
      LEFT JOIN resumen_justificaciones_eventos_periodo r
        ON r.id_evento = ev.id_evento
       AND r.id_periodo = get_period_id(DATE(ev.fecha_hora_inicio))
       AND r.id_justificacion_inasistencia = ji.id_justificacion_inasistencia

      /* 4) Unimos periodos para saber el rango (o si es histórico) */
      JOIN periodos per
        ON per.id_periodo = :p

      WHERE ev.id_estado_final = 4
        /* Si es histórico, entramos con per.es_historico = 1 */
        AND (
            per.es_historico = 1
            OR DATE(ev.fecha_hora_inicio) BETWEEN per.fecha_inicio AND per.fecha_termino
        )
        $teamFilter

      ORDER BY ev.fecha_hora_inicio, ev.id_evento, ji.id_justificacion_inasistencia
    ";

    $st = $pdo->prepare($sql);
    // 1) ligamos :p (el período del botón que pulsó el usuario)
    $st->bindValue(':p', $periodo, PDO::PARAM_INT);
    // 2) Si hay filtro de equipo, lo ligamos también
    if ($teamId) {
        $st->bindValue(':team', $teamId, PDO::PARAM_INT);
    }
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    /* Si no hay ningún evento “Realizado” según el filtro, devolvemos mensaje */
    if (empty($rows)) {
        echo json_encode([
            'ok'   => true,
            'data' => [['mensaje' => 'Sin eventos aún.']]
        ]);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $rows]);
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
