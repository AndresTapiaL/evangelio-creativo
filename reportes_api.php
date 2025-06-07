<?php
// ───── CONFIG ───────────────────────────────────────────────
require 'conexion.php';
require_once 'lib_auth.php';
session_start();
$uid = $_SESSION['id_usuario'] ?? 0;
if (!user_can_use_reports($pdo,$uid)) {
    http_response_code(403);
    echo json_encode(['error'=>'forbidden']); exit;
}
$isLN   = user_is_lider_nac($pdo,$uid);
$myTeams = user_allowed_teams($pdo,$uid);     // array

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
    assert_team_allowed($teamId,$myTeams,$isLN);

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
    if (!$isLN && $teamId===0){
        // no se permite "todos" salvo Lider Nac
        http_response_code(403); echo json_encode(['error'=>'team-forbidden']); exit;
    }
    $teamFilter = $teamId ? 'AND epe.id_equipo_proyecto = :team' : '';
    if (!$isLN && $teamId){
        assert_team_allowed($teamId,$myTeams,false);
    }

    /* ——— Seleccionamos todas las combinaciones evento ↔ justificación ———
       (así obtenemos filas con total=0 si no hubo participantes de ese tipo) */
    $sql = "
      SELECT
        ev.id_evento,
        ev.nombre_evento,
        DATE_FORMAT(ev.fecha_hora_inicio, '%d-%m-%Y') AS fecha_evento,
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
    if (!$isLN && !$myTeams){
        http_response_code(403);
        echo json_encode(['error'=>'forbidden']); exit;
    }
    // ==== 1) CTE para obtener “último estado” de cada integrante en este período ====
    $sql = "
      WITH ult AS (
        SELECT
          h.id_integrante_equipo_proyecto,
          SUBSTRING_INDEX(
            GROUP_CONCAT(h.id_tipo_estado_actividad
                         ORDER BY h.fecha_estado_actividad DESC),
            ',', 1
          ) AS id_estado
        FROM historial_estados_actividad h
        WHERE get_period_id(h.fecha_estado_actividad) = :p
        GROUP BY h.id_integrante_equipo_proyecto
      )
      SELECT
        ep.id_equipo_proyecto,
        ep.es_equipo,
        ep.nombre_equipo_proyecto,

        /* Total de integrantes (solo si su último estado NO es 6 ni 7) */
        COUNT(
          DISTINCT
          CASE 
            WHEN ult.id_estado NOT IN (6,7) THEN iep.id_usuario
            ELSE NULL
          END
        ) AS total_integrantes,

        /* Conteos por cada estado concreto */
        SUM( IF( ult.id_estado = 1, 1, 0 ) ) AS activos,
        SUM( IF( ult.id_estado = 2, 1, 0 ) ) AS semiactivos,
        SUM( IF( ult.id_estado = 5, 1, 0 ) ) AS nuevos,
        SUM( IF( ult.id_estado = 3, 1, 0 ) ) AS inactivos,
        SUM( IF( ult.id_estado = 4, 1, 0 ) ) AS en_espera,
        SUM( IF( ult.id_estado = 6, 1, 0 ) ) AS retirados,
        SUM( IF( ult.id_estado = 7, 1, 0 ) ) AS cambios,
        SUM( IF( ult.id_estado = 8, 1, 0 ) ) AS sin_estado

      FROM equipos_proyectos ep
      LEFT JOIN integrantes_equipos_proyectos iep
        ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
      LEFT JOIN ult
        ON ult.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto

      GROUP BY ep.id_equipo_proyecto, ep.es_equipo, ep.nombre_equipo_proyecto
      ORDER BY ep.es_equipo DESC, ep.nombre_equipo_proyecto ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':p', $periodo, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==== 2) Nueva lógica para “Total” según el nuevo criterio solicitado ====
    //
    // Queremos contar “cada usuario sólo una vez” si en ese período
    // tiene al menos UN registro en historial_estados_actividad con id_tipo_estado_actividad ≠ 6 ni 7.
    //
    // Para ello, nos basta con hacer:
    //   SELECT COUNT(DISTINCT iep.id_usuario)
    //   FROM historial_estados_actividad h
    //   JOIN integrantes_equipos_proyectos iep
    //     ON iep.id_integrante_equipo_proyecto = h.id_integrante_equipo_proyecto
    //   WHERE get_period_id(h.fecha_estado_actividad) = :p
    //     AND h.id_tipo_estado_actividad NOT IN (6,7)
    //
    // Así, “DISTINCT iep.id_usuario” garantiza que si un usuario aparece en varios equipos
    // o con varias filas de historial, sólo cuente una vez, y solo se cuenta si existe
    // al menos un h.id_tipo_estado_actividad ≠ 6,7.
    //

    $sqlTotal = "
      SELECT
        COUNT(DISTINCT iep.id_usuario) AS total_usuarios
      FROM historial_estados_actividad h
      JOIN integrantes_equipos_proyectos iep
        ON iep.id_integrante_equipo_proyecto = h.id_integrante_equipo_proyecto
      WHERE get_period_id(h.fecha_estado_actividad) = :p
        AND h.id_tipo_estado_actividad NOT IN (6,7)
    ";
    $stTotal = $pdo->prepare($sqlTotal);
    $stTotal->bindValue(':p', $periodo, PDO::PARAM_INT);
    $stTotal->execute();
    $resTot = $stTotal->fetch(PDO::FETCH_ASSOC);
    $totalIntegrantesAll = $resTot['total_usuarios'] ?? 0;

    // ==== 3) CALCULAR TOTALES “column-wise” recorriendo $filas (sin contar la fila Total) ====
    // Inicializamos las variables acumuladoras en cero:
    $sumActivos      = 0;
    $sumSemiactivos  = 0;
    $sumNuevos       = 0;
    $sumInactivos    = 0;
    $sumEnEspera     = 0;
    $sumRetirados    = 0;
    $sumCambios      = 0;
    $sumSinEstado    = 0;

    // Recorremos cada fila existente (cada equipo) y sumamos sus columnas:
    foreach ($filas as $fila) {
        // Por seguridad, casteamos a entero (si viniera NULL, lo tratamos como 0):
        $sumActivos     += (int) $fila['activos'];
        $sumSemiactivos += (int) $fila['semiactivos'];
        $sumNuevos      += (int) $fila['nuevos'];
        $sumInactivos   += (int) $fila['inactivos'];
        $sumEnEspera    += (int) $fila['en_espera'];
        $sumRetirados   += (int) $fila['retirados'];
        $sumCambios     += (int) $fila['cambios'];
        $sumSinEstado   += (int) $fila['sin_estado'];
    }

    // ==== 4) Ahora sí agregamos la fila “Total” con esos acumulados ====
    $filas[] = [
      'id_equipo_proyecto'     => 0,
      'es_equipo'              => 0,
      'nombre_equipo_proyecto' => 'Total',
      'total_integrantes'      => $totalIntegrantesAll,
      'activos'                => $sumActivos,
      'semiactivos'            => $sumSemiactivos,
      'nuevos'                 => $sumNuevos,
      'inactivos'              => $sumInactivos,
      'en_espera'              => $sumEnEspera,
      'retirados'              => $sumRetirados,
      'cambios'                => $sumCambios,
      'sin_estado'             => $sumSinEstado,
    ];

    echo json_encode(['ok'=>true,'data'=>$filas]);
    exit;

  /* ═════════ 4)  Eventos · Estados  ═════════════════════════════ */
  case 'eventos_estado':
    /* ── ¿el período elegido es “Anual AAAA” ? ───────────────── */
    $nomPer = $pdo->prepare("SELECT nombre_periodo
                              FROM periodos
                              WHERE id_periodo = ?");
    $nomPer->execute([$periodo]);
    $nomPer = $nomPer->fetchColumn() ?: '';
    $isAnnual = str_ends_with($nomPer, '-Anual');   // PHP 8; para PHP 7 usa substr/strpos
    $yearSel  = $isAnnual ? (int)substr($nomPer, 0, 4) : 0;

    if (!$isLN && !$myTeams){
        http_response_code(403);
        echo json_encode(['error'=>'forbidden']); exit;
    }

    /* 0)  Catálogos de estados y equipos/proyectos ────────────── */
    $estados = $pdo->query("
        SELECT id_estado_final, nombre_estado_final
          FROM estados_finales_eventos
      ORDER BY id_estado_final
    ")->fetchAll(PDO::FETCH_ASSOC);

    $catEquipos = $pdo->query("
        SELECT id_equipo_proyecto,
               nombre_equipo_proyecto,
               es_equipo
          FROM equipos_proyectos
      ORDER BY nombre_equipo_proyecto
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* helper → matriz equipo×estado inicializada a 0 ------------- */
    $makeMatrix = function (array $subset) use ($estados) {
        $M = [];
        foreach ($subset as $eq) {
            foreach ($estados as $es) {
                $M[] = [
                    'id_equipo_proyecto'     => (int)$eq['id_equipo_proyecto'],
                    'nombre_equipo_proyecto' => $eq['nombre_equipo_proyecto'],
                    'id_estado_final'        => (int)$es['id_estado_final'],
                    'nombre_estado_final'    => $es['nombre_estado_final'],
                    'total'                  => 0
                ];
            }
        }
        return $M;
    };

    /* 1)  Conteo real por (equipo_proyecto , estado_final) ─────── */
    if ($isAnnual) {
        /* —— Anual: filtrar por AÑO —— */
        $q = $pdo->prepare("
            SELECT epe.id_equipo_proyecto,
                  ev.id_estado_final,
                  COUNT(*) AS tot
              FROM equipos_proyectos_eventos epe
              JOIN eventos ev ON ev.id_evento = epe.id_evento
            WHERE YEAR(ev.fecha_hora_inicio) = :y
              AND ev.id_estado_final IS NOT NULL
              AND ev.id_estado_previo  = 1
          GROUP BY epe.id_equipo_proyecto, ev.id_estado_final
        ");
        $q->bindValue(':y', $yearSel, PDO::PARAM_INT);

    } else {
        /* —— Cuatrimestre / Histórico (código original) —— */
        $q = $pdo->prepare("
            SELECT epe.id_equipo_proyecto,
                  ev.id_estado_final,
                  COUNT(*) AS tot
              FROM equipos_proyectos_eventos epe
              JOIN eventos ev ON ev.id_evento = epe.id_evento
            WHERE get_period_id(DATE(ev.fecha_hora_inicio)) = :p
              AND ev.id_estado_final IS NOT NULL
              AND ev.id_estado_previo  = 1
          GROUP BY epe.id_equipo_proyecto, ev.id_estado_final
        ");
        $q->bindValue(':p', $periodo, PDO::PARAM_INT);
    }
    $q->execute();

    /* Pasamos el resultado a un mapa bidimensional para lookup rápido */
    $map = [];   // [id_equipo][id_estado] → tot
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['id_equipo_proyecto']][(int)$r['id_estado_final']] = (int)$r['tot'];
    }

    /* 2)  Separar catálogo y armar matrices de salida ──────────── */
    $soloEquipos   = array_filter($catEquipos, fn($e) => $e['es_equipo'] == 1);
    $soloProyectos = array_filter($catEquipos, fn($e) => $e['es_equipo'] == 0);

    $general = $makeMatrix($soloEquipos);      // es_equipo = 1
    foreach ($general as &$g) {
        $g['total'] = $map[$g['id_equipo_proyecto']][$g['id_estado_final']] ?? 0;
    }
    unset($g);

    $otros = $makeMatrix($soloProyectos);      // es_equipo = 0
    foreach ($otros as &$o) {
        $o['total'] = $map[$o['id_equipo_proyecto']][$o['id_estado_final']] ?? 0;
    }
    unset($o);

    echo json_encode([
        'ok'      => true,
        'general' => $general,   //  ← solo equipos (es_equipo = 1)
        'otros'   => $otros      //  ← solo proyectos (es_equipo = 0)
    ]);
    exit;
}
