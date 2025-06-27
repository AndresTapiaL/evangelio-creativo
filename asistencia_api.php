<?php
/*  asistencia_api.php
 *  ────────────────
 *  GET  action=list    →   devuelve JSON con los integrantes + estados
 *  POST action=update  →   actualiza el estado (previo o definitivo)
 *  © Evangelio Creativo – 2025
 ---------------------------------------------------------------*/
require 'conexion.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

/* ── 1) Seguridad de sesión ───────────────────────────────── */
if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    exit(json_encode(['error'=>'No autorizado']));
}
$uid = (int)$_SESSION['id_usuario'];

/* ── 2) Parámetros básicos ────────────────────────────────── */
$action    = $_REQUEST['action']    ?? 'list';
$idEvento  = (int)($_REQUEST['id_evento'] ?? 0);
$idUsuario = (int)($_POST['id_usuario'] ?? 0);   // sólo para update
$valor     = $_POST['valor'] ?? null;            // sólo para update
$esPrevio  = isset($_POST['esPrevio']) ? (bool)$_POST['esPrevio'] : null;

try {
  /* ────────────────────────────────────────────────────────── */
  switch ($action) {

  /* ═════ LIST ═════ */
  case 'list': {
    if (!$idEvento) throw new Exception('id_evento requerido');

    /* 2-a) Datos del evento + si es previo/definitivo */
    $stEvt = $pdo->prepare("
        SELECT e.es_general,
               e.fecha_hora_inicio,
               NOW()  < e.fecha_hora_inicio         AS es_previo,
               EXISTS (
                  SELECT 1
                    FROM equipos_proyectos_eventos
                   WHERE id_evento = e.id_evento
               )                                     AS tiene_equipos
          FROM eventos e
         WHERE id_evento = ?
    ");
    $stEvt->execute([$idEvento]);
    $evt = $stEvt->fetch(PDO::FETCH_ASSOC);
    if (!$evt) throw new Exception('Evento inexistente');

    $esPrevio = (bool)$evt['es_previo'];

    /* 2-b) Autorización del solicitante:
            – Liderazgo nacional o
            – Pertenece a ≥1 equipo del evento                                   */
    $ok = false;
    if ($uid) {
        /* ¿liderazgo nacional? */
        $ok = (bool)$pdo->query("
            SELECT 1
              FROM integrantes_equipos_proyectos
             WHERE id_usuario = $uid
               AND id_equipo_proyecto = 1            /* liderazgo nacional */
             LIMIT 1")->fetchColumn();

        if (!$ok) {                                  /* ¿equipo del evento? */
            $ok = (bool)$pdo->query("
                SELECT 1
                  FROM equipos_proyectos_eventos epe
                  JOIN integrantes_equipos_proyectos iep
                        ON iep.id_equipo_proyecto = epe.id_equipo_proyecto
                 WHERE epe.id_evento       = $idEvento
                   AND iep.id_usuario      = $uid
                   AND iep.habilitado      = 1
                 LIMIT 1")->fetchColumn();
        }
    }
    if (!$ok) { http_response_code(403);
                exit(json_encode(['error'=>'Sin permiso'])); }

    /* 2-c) Sub-lista de integrantes elegibles (ya hay fila en “asistencias”) */
    $sqlIntegrantes = "
      SELECT
           a.id_usuario,
           CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno) AS nombre,
           IFNULL(a.id_estado_previo_asistencia,3)  AS estado_previo,
           IFNULL(a.id_estado_asistencia,2)         AS estado_def,
           GROUP_CONCAT(DISTINCT ep.id_equipo_proyecto) AS equipos_ids,
           GROUP_CONCAT(DISTINCT ep.nombre_equipo_proyecto
                        ORDER BY ep.nombre_equipo_proyecto SEPARATOR ', ') AS equipos_nombres
      FROM asistencias                        a
      JOIN usuarios                           u ON u.id_usuario = a.id_usuario
      LEFT JOIN integrantes_equipos_proyectos iep
             ON iep.id_usuario = a.id_usuario
      LEFT JOIN equipos_proyectos             ep ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
      LEFT JOIN equipos_proyectos_eventos     epe
             ON epe.id_evento = a.id_evento
      WHERE a.id_evento = :evt
        AND (
              :esGen = 1
           OR iep.id_equipo_proyecto = epe.id_equipo_proyecto
        )
        AND iep.habilitado = 1
      GROUP BY a.id_usuario
    ";
    $rs = $pdo->prepare($sqlIntegrantes);
    $rs->execute([
      ':evt'   => $idEvento,
      ':esGen' => $evt['es_general']
    ]);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);

    /* 2-d) Re-organizar por equipo ─────────────────────────── */
    $equipos = [];
    foreach ($rows as $r) {
        $listaIds   = array_filter(array_map('trim', explode(',',$r['equipos_ids'])));
        $listaNoms  = array_filter(array_map('trim', explode(',',$r['equipos_nombres'])));
        $pairs      = array_combine($listaIds, $listaNoms);
        /* Evento GENERAL sin equipos asignados → usamos id=0 “General” */
        if (!$evt['tiene_equipos']) $pairs = [0 => 'General'] + $pairs;

        foreach ($pairs as $idEq=>$nomEq) {
            if (!isset($equipos[$idEq])) $equipos[$idEq] = [
              'id'   => (int)$idEq,
              'nom'  => $nomEq,
              'ints' => []
            ];
            $equipos[$idEq]['ints'][] = [
              'id'   => (int)$r['id_usuario'],
              'nom'  => $r['nombre'],
              'prev' => (int)$r['estado_previo'],
              'def'  => (int)$r['estado_def']
            ];
        }
    }
    ksort($equipos);                // orden por id_equipo

    echo json_encode([
      'ok'        => true,
      'esPrevio'  => $esPrevio,
      'equipos'   => array_values($equipos)
    ]);
    break;
  }

  /* ═════ UPDATE ═════ */
  case 'update': {
    if (!$idEvento || !$idUsuario || $valor===null || $esPrevio===null)
        throw new Exception('Parámetros incompletos');

    /* 1) ¿fila existente? */
    $stChk = $pdo->prepare("
        SELECT 1 FROM asistencias
         WHERE id_evento = ? AND id_usuario = ? LIMIT 1");
    $stChk->execute([$idEvento,$idUsuario]);
    if (!$stChk->fetchColumn()) throw new Exception('Integrante no vinculado');

    /* 2) Validar valor */
    if ($esPrevio) {
        $ok = (bool)$pdo->prepare("
              SELECT 1 FROM estados_previos_asistencia
               WHERE id_estado_previo_asistencia = ?")
              ->execute([$valor]);
        $col = 'id_estado_previo_asistencia';
    } else {
        $ok = (bool)$pdo->prepare("
              SELECT 1 FROM estados_asistencia
               WHERE id_estado_asistencia = ?")
              ->execute([$valor]);
        $col = 'id_estado_asistencia';
    }
    if (!$ok) throw new Exception('Estado inválido');

    /* 3) Update seguro */
    $up = $pdo->prepare("
        UPDATE asistencias
           SET $col = :val
         WHERE id_evento  = :evt
           AND id_usuario = :usr");
    $up->execute([
        ':val'=>$valor, ':evt'=>$idEvento, ':usr'=>$idUsuario
    ]);

    echo json_encode(['ok'=>true]);
    break;
  }

  default:
      throw new Exception('Acción desconocida');
  }

} catch (Exception $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
