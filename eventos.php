<?php
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();

// 1.1) Validar sesión igual que en ver_mis_datos.php
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

/* ─── Permisos para mostrar los botones ────────────────────────── */
$canCreate  = false;   // botón “Crear evento”
$canRequest = false;   // botón “Solicitar evento”

// (a) ¿Pertenece al Liderazgo nacional?  (equipo 1)
$stmt = $pdo->prepare("
    SELECT 1
    FROM integrantes_equipos_proyectos
    WHERE id_usuario = ? AND id_equipo_proyecto = 1
    LIMIT 1
");
$stmt->execute([$id_usuario]);
$canCreate = (bool)$stmt->fetchColumn();

// (b) ¿Tiene rol Líder (4) o Coordinador/a (6) en cualquier equipo?
$stmt = $pdo->prepare("
    SELECT 1
    FROM integrantes_equipos_proyectos
    WHERE id_usuario = ?
      AND id_rol IN (4,6)
    LIMIT 1
");
$stmt->execute([$id_usuario]);
$canRequest = (bool)$stmt->fetchColumn();

/* ── Permisos globales ─────────────────────────────────────────────── */
$stmtLiderNacional = $pdo->prepare(
  'SELECT 1 FROM integrantes_equipos_proyectos
   WHERE id_usuario = ? AND id_equipo_proyecto = 1 LIMIT 1'
);
$stmtLiderNacional->execute([$id_usuario]);
$isLiderNacional = (bool)$stmtLiderNacional->fetchColumn();

// ─── Equipos donde el usuario es Líder (4) o Coordinador/a (6) ───
$leadStmt = $pdo->prepare("
  SELECT DISTINCT id_equipo_proyecto
    FROM integrantes_equipos_proyectos
   WHERE id_usuario = :uid
     AND id_rol IN (4,6)
");
$leadStmt->execute(['uid'=>$id_usuario]);
$myLeadTeams = $leadStmt->fetchAll(PDO::FETCH_COLUMN);

// evento_buscar: término de búsqueda (puede venir por GET o POST)
$busqueda = trim($_REQUEST['busqueda'] ?? '');

// ¿Está activo el filtro “Aprobar eventos”?
$showAprob = ($_REQUEST['aprobados'] ?? '') === '1';

// 1.2) Traer para el nav: nombre + foto
$stmtNav = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :uid
");
$stmtNav->execute(['uid'=>$id_usuario]);
$navUser = $stmtNav->fetch(PDO::FETCH_ASSOC);

// 2.1) Obtener equipos/proyectos a los que perteneces
$qEq = $pdo->prepare("
  SELECT ep.id_equipo_proyecto, ep.nombre_equipo_proyecto
    FROM integrantes_equipos_proyectos iep
    JOIN equipos_proyectos ep
      ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
   WHERE iep.id_usuario = :uid
   ORDER BY ep.nombre_equipo_proyecto
");
$qEq->execute(['uid'=>$id_usuario]);
$userTeams = $qEq->fetchAll(PDO::FETCH_ASSOC);

$allProjects = $pdo
  ->query("SELECT id_equipo_proyecto, nombre_equipo_proyecto
            FROM equipos_proyectos
           ORDER BY nombre_equipo_proyecto")
  ->fetchAll(PDO::FETCH_ASSOC);

// 2.2) Extraer solamente los IDs para validar
$userTeamIds = array_column($userTeams, 'id_equipo_proyecto');

// 1.1) Leer filtro y mes por POST (si no hay POST, usar defecto)
$filtro   = $_POST['filtro']  ?? 'calendario';
$mesParam = $_POST['mes']     ?? date('Y-m');

list($year, $month) = explode('-', $mesParam);

// 2.4) Validar: solo “calendario”, “general” o un ID válido
if ($filtro !== 'calendario' && $filtro !== 'general') {
    $fid = (int)$filtro;
    // Si NO eres Liderazgo nacional y no estás en ese equipo, redirige
    if (!$isLiderNacional && !in_array($fid, $userTeamIds, true)) {
        header('Location: eventos.php?filtro=calendario');
        exit;
    }
    // Si eres Liderazgo nacional, aceptas cualquier $fid
    $filtro = $fid;
}

// ─── 1.2) Calcular mes anterior y siguiente para el nav
$ts        = strtotime("$year-$month-01");

$prevMonth = ($year > 1970 || ($year == 1970 && $month > 1))
           ? date('Y-m', strtotime('-1 month', $ts))
           : null;

$nextMonth = ($year < 2037 || ($year == 2037 && $month < 12))
           ? date('Y-m', strtotime('+1 month', $ts))
           : null;

// ─── 1.3) Mapeos de meses y días
$meses = [
  '01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
  '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
  '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'
];
$dias = ['0'=>'Domingo','1'=>'Lunes','2'=>'Martes','3'=>'Miércoles',
         '4'=>'Jueves','5'=>'Viernes','6'=>'Sábado'];

$where  = [];
$params = [];

// 3.1) Base del WHERE (ya tienes $where y $params)
$where[]          = "YEAR(e.fecha_hora_inicio) = :year";
$where[]          = "MONTH(e.fecha_hora_inicio) = :month";
$params['year']   = $year;
$params['month']  = $month;

if ($busqueda !== '') {
    $where[] = "(
        e.nombre_evento LIKE :busqueda
     OR CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno) LIKE :busqueda
     OR e.lugar LIKE :busqueda
     OR e.descripcion LIKE :busqueda
     OR e.observacion LIKE :busqueda
     OR tipe.nombre_tipo LIKE :busqueda
     OR prev.nombre_estado_previo LIKE :busqueda
     OR fin.nombre_estado_final LIKE :busqueda
     OR epj.nombre_equipo_proyecto LIKE :busqueda
    )";
    $params['busqueda'] = "%{$busqueda}%";
}

// ─── Filtrar por id_estado_previo según rol ───
if (!$isLiderNacional) {
    if (!empty($myLeadTeams)) {
        $teamList = implode(',', array_map('intval',$myLeadTeams));
        $where[] = "(
          e.id_estado_previo = 1
          OR (
              e.id_estado_previo IN (2,3)
            AND e.es_general = 0
            AND e.id_evento IN (
                SELECT id_evento
                  FROM equipos_proyectos_eventos
                  WHERE id_equipo_proyecto IN ($teamList)
            )
          )
        )";
    } else {
        // Sin rol 4/6 y no nacional: solo Aprobados
        $where[] = "e.id_estado_previo = 1";
    }
}
// si $isLiderNacional === true, no agregamos nada: ve todos los estados

// ─── Calendario: todo
if ($filtro === 'calendario') {
    // nada
}
// ─── General: sólo generales
elseif ($filtro === 'general') {
    $where[] = "e.es_general = 1";
}
// ─── Equipo específico: sólo eventos de ese equipo
else {
    // filtramos sólo eventos, no restringimos el JOIN
    $where[] = "
      e.id_evento IN (
        SELECT id_evento
          FROM equipos_proyectos_eventos
         WHERE id_equipo_proyecto = :filtro
      )
    ";
    $params['filtro'] = (int)$filtro;
}

// 3.2) Query con joins + recuento de asistencia y total de integrantes
$sql = "
  SELECT
    e.id_evento,
    e.es_general,
    e.encargado       AS encargado_id,
    e.nombre_evento,
    e.lugar,
    e.descripcion,
    e.observacion,
    e.fecha_hora_inicio,
    e.fecha_hora_termino,
    e.id_estado_previo,
    e.id_tipo,
    e.id_estado_final,
    CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno)
      AS encargado_nombre_completo,
    prev.nombre_estado_previo,
    fin.nombre_estado_final,
    tipe.nombre_tipo,
    GROUP_CONCAT(DISTINCT epj.nombre_equipo_proyecto SEPARATOR ', ') AS equipos,
    GROUP_CONCAT(DISTINCT ep.id_equipo_proyecto)             AS equipo_ids,

    -- 1) Cantidad de asistentes “Presente” (id_estado_previo_asistencia = 1)
    COALESCE(ap.cnt_presente, 0) AS cnt_presente,

    -- 2) Total de usuarios únicos en los equipos/proyectos de este evento
    COALESCE(
      CASE
        WHEN e.es_general = 1                 -- evento General
            THEN allu.cnt_all                -- ⇒ todos los usuarios únicos
        ELSE tu.total_integrantes             -- ⇒ solo los de sus equipos
      END,
      0
    ) AS total_integrantes

  FROM eventos e

  LEFT JOIN usuarios u
    ON e.encargado = u.id_usuario

  LEFT JOIN estados_previos_eventos prev
    ON e.id_estado_previo = prev.id_estado_previo

  LEFT JOIN estados_finales_eventos fin
    ON e.id_estado_final  = fin.id_estado_final

  LEFT JOIN tipos_evento tipe
    ON e.id_tipo          = tipe.id_tipo

  LEFT JOIN equipos_proyectos_eventos ep
    ON e.id_evento = ep.id_evento

  LEFT JOIN equipos_proyectos epj
    ON ep.id_equipo_proyecto = epj.id_equipo_proyecto

  /* —— Subconsulta 1: recuento de “Presente” —— */
  LEFT JOIN (
    SELECT 
      id_evento, 
      COUNT(*) AS cnt_presente
    FROM asistencias
    WHERE id_estado_previo_asistencia = 1
    GROUP BY id_evento
  ) ap ON ap.id_evento = e.id_evento

  /* —— Subconsulta 2: total de integrantes únicos por evento —— */
  LEFT JOIN (
    SELECT 
      epe.id_evento,
      COUNT(DISTINCT iep.id_usuario) AS total_integrantes
    FROM equipos_proyectos_eventos epe
    JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
    GROUP BY epe.id_evento
  ) tu ON tu.id_evento = e.id_evento

  /* —— Subconsulta 3: total global de usuarios con ≥1 equipo —— */
  LEFT JOIN (
    SELECT COUNT(DISTINCT id_usuario) AS cnt_all
    FROM integrantes_equipos_proyectos
  ) allu ON 1 = 1            -- se une siempre, devuelve 1 fila fijo

  ".(count($where) ? 'WHERE '.implode(' AND ', $where) : '')."

  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
";
$stmtEv = $pdo->prepare($sql);
$stmtEv->execute($params);

$rows = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

// ─── 1) Contar globalmente todos los eventos “En espera” (estado_previo = 3)
$pendingCount = 0;
if ($isLiderNacional) {
    $cntStmt = $pdo->query("
      SELECT COUNT(*) 
        FROM eventos 
       WHERE id_estado_previo = 3
    ");
    $pendingCount = (int)$cntStmt->fetchColumn();
}

// ─── 2) Si soy Liderazgo nacional y activo “Aprobar”, filtrar solo estado_previo = 3
if ($isLiderNacional && $showAprob) {
    $rows = array_filter($rows, fn($e)=> (int)$e['id_estado_previo'] === 3);
}

// ─── Determinar eventos asociados a equipos donde el usuario es Líder (4) o Coordinador/a (6)
$obsStmt = $pdo->prepare("
  SELECT DISTINCT epe.id_evento
    FROM equipos_proyectos_eventos epe
    JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
   WHERE iep.id_usuario = :uid
     AND iep.id_rol IN (4,6)
");
$obsStmt->execute(['uid'=>$id_usuario]);
$obsEventIds = $obsStmt->fetchAll(PDO::FETCH_COLUMN);

// ─── Anotar en cada fila si mostrar observación
foreach ($rows as &$e) {
    $e['show_observacion'] =
        $isLiderNacional                                // siempre si es Liderazgo nacional
     || in_array($e['id_evento'], $obsEventIds, true);  // o si es líder/coordinador de ese equipo
}
unset($e);

/* ─── Líderes y coordinadores disponibles ─── */
/* ─── líderes y coordinadores ─── */
$ldrStmt = $pdo->prepare("
  SELECT 
    u.id_usuario,
    CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno)               AS full_name,

    /*  lista de proyectos SÓLO si tiene alguno → '' en caso contrario   */
    COALESCE(
      GROUP_CONCAT(
        DISTINCT iep.id_equipo_proyecto
        ORDER BY iep.id_equipo_proyecto
        SEPARATOR ','
      ),
      ''
    )                                                                            AS project_ids

  FROM usuarios               u
  LEFT JOIN integrantes_equipos_proyectos iep
         ON iep.id_usuario = u.id_usuario

  WHERE iep.id_rol IN (4,6)                       -- Líder / Coordinador
     OR iep.id_equipo_proyecto = 1                -- Liderazgo nacional (id 1)
     OR iep.id_equipo_proyecto IS NULL            -- Líder “general” sin proyectos

  GROUP BY u.id_usuario
");
$ldrStmt->execute();
$leaders = $ldrStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Eventos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    h1 {
      margin-top: 0;
    }
    /* tablas limpias */
    section table {
        width:100%; border-collapse:collapse; margin-top:.5rem;
    }
    section th, section td {
        border:1px solid #ddd; padding:.5rem; text-align:left;
    }
    section th { background:#f3f3f3; }
    /* sidebar */
    aside a { text-decoration:none; color:#222; }
    /* resaltado de filtro activo */
    aside a[style*="font-weight:bold"] { background:#e0e0e0; }
    /* ─── Table Responsive ─── */
    .table-responsive {
    -webkit-overflow-scrolling: touch;     /* smoother en móviles */
    margin-bottom: 2rem;                   /* separa meses */
    border: 1px solid #ddd;                /* marco suave */
    border-radius: 6px;
    }

    /* Que la table pueda ser más ancha que su contenedor */
    .table-responsive table {
      width: 100%;
      table-layout: fixed;    /* fija la distribución de columnas */
      border-collapse: collapse;
    }

    .table-responsive th,
    .table-responsive td {
      white-space: normal;        /* permite salto de línea */
      word-wrap: break-word;      /* rompe palabras largas */
      overflow-wrap: break-word;  /* evita que se rompa cada carácter */
    }

    /* Zebra stripes para filas pares */
    .table-responsive tbody tr:nth-child(even) {
    background-color: #fafafa;
    }

    /* Cabecera fija al hacer scroll vertical */
    .table-responsive thead th {
    position: sticky;
    top: 0;
    background: #f3f3f3;
    z-index: 2;
    }

    /* Padding y líneas */
    .table-responsive th,
    .table-responsive td {
    padding: .5rem;
    border: 1px solid #ddd;
    text-align: left;
    }

    /* Acciones al final de cada fila: iconos claros */
    .table-responsive td.actions button {
    background: none;
    border: none;
    cursor: pointer;
    margin-right: .4rem;
    }
    .table-responsive td.actions button img {
    width: 16px;
    height: 16px;
    }

    /* botón transparente con padding uniforme */
    .actions .action-btn {
      background: none;
      border: none;
      padding: 0.4rem;
      cursor: pointer;
      font-size: 1.2rem;
      color: #555;
      transition: color .2s;
    }
    .actions .action-btn:hover {
      color: #000;
    }

    /* espaciado entre botones */
    .actions .action-btn + .action-btn {
      margin-left: -1rem;
    }

    /* Overlay */
    .modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.6);
      display: flex; align-items:center; justify-content:center;
      z-index: 10000;
    }
    /* Modal box */
    .modal-content {
      background: #fff;
      padding: 1.5rem;
      border-radius: 8px;
      max-width: 500px;
      width: 90%;
      max-height: 90%;
      overflow-y: auto;
      position: relative;
    }
    /* Close button */
    .modal-close {
      position: absolute;
      top: .5rem; right: .75rem;
      background: none; border: none;
      font-size: 1.5rem; cursor: pointer;
    }
    /* Definition list styling */
    .modal-content dl {
      margin: 0;
    }
    .modal-content dt {
      font-weight: bold;
      margin-top: .75rem;
    }
    .modal-content dd {
      margin: .25rem 0 0 0;
      padding-left: .5rem;
    }

    /* Overlay semi-transparente */
    .modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.5);
      display: flex; align-items: center; justify-content: center;
      padding: 1rem;
      z-index: 9999;
    }

    /* Contenedor tipo “card” */
    .modal-content.card {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.2);
      max-width: 600px;
      width: 100%;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    /* Header con fondo ligero */
    .card-header {
      display: flex; align-items: center; justify-content: space-between;
      background: #f5f5f5;
      padding: 1rem;
      border-bottom: 1px solid #ddd;
    }
    .card-title {
      margin: 0;
      font-size: 1.25rem;
      color: #333;
    }
    .modal-close {
      background: none;         /* sin fondo */
      border: none;             /* sin borde */
      padding: 0;               /* sin padding extra */
      font-size: 1.25rem;       /* tamaño ligero */
      color: #666;              /* gris tenue */
      cursor: pointer;
      transition: color .2s;
    }
    .modal-close:hover {
      color: #333;              /* gris oscuro al hover */
    }

    /* Cuerpo con scroll interno si hace falta */
    .card-body {
      padding: 1rem;
      overflow-y: auto;
      flex: 1;
    }

    /* Botón secundario (si quieres estilo más suave) */
    .btn-secondary {
      background: #6c757d;
    }
    .btn-secondary:hover {
      background: #5a6268;
    }

    /* 1) Lista vertical del modal */
    .vertical-list {
      margin: 0;
      padding: 0;
      list-style: none;
    }
    .detail-item {
      margin-bottom: 1rem;
      padding-bottom: .5rem;
      border-bottom: 1px solid #eee;
    }
    .detail-item:last-child {
      border-bottom: none;
      margin-bottom: 0;
      padding-bottom: 0;
    }

    /* 2) Estilos de dt/dd */
    .detail-item dt {
      font-weight: 600;
      color: #333;
      margin: 0 0 .25rem 0;
      font-size: 1rem;
    }
    .detail-item dd {
      margin: 0;
      color: #555;
      padding-left: 1rem;
      font-size: .95rem;
      line-height: 1.4;
    }

    /* 3) Ajustes generales de la tarjeta/modal */
    .modal-content.card {
      border-radius: 8px;
      overflow: hidden;
    }
    .card-header {
      padding: 1rem 1.5rem;
      background: #fafafa;
      border-bottom: 1px solid #ddd;
    }
    .card-body {
      padding: 1.5rem;
    }
    .modal-close {
      font-size: 1.25rem;
      color: #666;
    }
    .btn-secondary {
      background: #007bff;
      color: #fff;
    }
    .btn-secondary:hover {
      background: #0056b3;
    }
    .card-header {
      position: relative;
    }
    .modal-close {
      position: absolute;
      top: 1rem;
      right: 1rem;
    }
    /* 1) El contenedor principal siempre al 100% de la segunda columna */
    .main-content {
      grid-column: 2;  /* ya debería estar */
      width: 100%;
    }
    .main-content > nav,
    .main-content > section {
      width: 100%;
    }

    /* ─── Anchos de columnas — versión revisada ─── */
    .table-responsive th:nth-child(1),
    .table-responsive td:nth-child(1),          /* Inicio   */
    .table-responsive th:nth-child(2),
    .table-responsive td:nth-child(2)           /* Término  */
    {
        min-width: 130px;           /* cabe “Jueves 17 | 17.15” sin romperse */
        width:      14%;
        white-space: nowrap;        /* una sola línea por celda */
    }

    .table-responsive th:nth-child(3),
    .table-responsive td:nth-child(3) { width: 14%; }     /* Evento */
    .table-responsive th:nth-child(4),
    .table-responsive td:nth-child(4) { width: 15%; }     /* Equipo / Proyecto */
    .table-responsive th:nth-child(5),
    .table-responsive td:nth-child(5) { width: 11%; }     /* Estado previo */
    .table-responsive th:nth-child(6),
    .table-responsive td:nth-child(6) { width: 11%; }     /* Asistencia previa */
    .table-responsive th:nth-child(7),
    .table-responsive td:nth-child(7) { width: 10%; }     /* Estado final */
    .table-responsive th:nth-child(8),
    .table-responsive td:nth-child(8) { width: 16%; }     /* Acciones */

    /* ─── Formulario en modal ─── */
    .modal-content .form-group {
      margin-bottom: 1rem;
      display: flex;
      flex-direction: column;
    }
    .modal-content .form-group label {
      font-weight: 600;
      margin-bottom: .5rem;
      color: #333;
    }
    .modal-content .form-group input[type="text"],
    .modal-content .form-group input[type="datetime-local"],
    .modal-content .form-group textarea,
    .modal-content .form-group select {
      padding: .5rem .75rem;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: .95rem;
      background: #fafafa;
      width: 100%;
      box-sizing: border-box;
      transition: border .2s, box-shadow .2s;
    }
    .modal-content .form-group input:focus,
    .modal-content .form-group textarea:focus,
    .modal-content .form-group select:focus {
      outline: none;
      border-color: #007bff;
      box-shadow: 0 0 0 2px rgba(0,123,255,.2);
    }

    /* ─── Checkbox‐pill group ─── */
    .checkbox-list {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
    }
    .checkbox-item {
      display: flex;
      align-items: center;
      background: #f0f0f0;
      border-radius: 20px;
      padding: .4rem .8rem;
      cursor: pointer;
      transition: background .2s;
      user-select: none;
    }
    .checkbox-item input {
      margin-right: .5rem;
      accent-color: #007bff;
    }
    .checkbox-item:hover {
      background: #e2e6ea;
    }
    .checkbox-item input:checked + span {
      color: #007bff;
      font-weight: 600;
    }

    /* ─── Botón Guardar ─── */
    #btn-save-evento {
      width: 50%;
      padding: .75rem;
      background:rgb(255, 102, 32);
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 1rem;
      font-weight: bold;
      cursor: pointer;
      transition: background .2s;
    }
    #btn-save-evento:disabled {
      background:rgb(211, 163, 148);
      cursor: not-allowed;
    }
    #btn-save-evento:hover:not(:disabled) {
      background:rgb(219, 122, 42);
    }

        /* ─── Botón Duplicar ─── */
    #btn-create-evento {
      width: 50%;
      padding: .75rem;
      background:rgb(255, 102, 32);
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 1rem;
      font-weight: bold;
      cursor: pointer;
      transition: background .2s;
    }
    #btn-create-evento:disabled {
      background:rgb(211, 163, 148);
      cursor: not-allowed;
    }
    #btn-create-evento:hover:not(:disabled) {
      background:rgb(219, 122, 42);
    }

    /* Lista de Equipos/Proyectos en la tabla */
    .equipos-list {
      margin: 0;
      padding-left: 1rem;
      list-style: disc outside;
    }
    .equipos-list li {
      margin-bottom: 0.25rem;
      line-height: 1.3;
    }

    .input-error {
      color: #dc3545;
      font-size: 0.875rem;
      margin-top: 0.25rem;
      display: none;
    }

    .err-inline{
      display:none;
      color: #dc3545;
      margin-top: 0.25rem;
      font-size: 0.875rem;
    }

    .checkboxes-grid {
      display: grid;
      grid-template-columns: 1fr 1fr; /* dos columnas */
      gap: .75rem .5rem;              /* 0.75rem fila, 0.5rem columna */
    }

    .checkbox-item {
      display: inline-flex;
      align-items: center;
      padding: .4rem .6rem;
      background: #f0f0f0;
      border-radius: 4px;
      cursor: pointer;
      user-select: none;
    }

    .checkbox-item input {
      margin-right: .5rem;
    }

    #modal-edit .card-footer{
      background: transparent !important;
      border-top: none !important;
      padding: 0 1rem 1rem;
    }

    /* —— Modal Editar: quitar franja negra ————————————— */
    #modal-copy .card-footer{
      background: transparent !important;  /* elimina el negro               */
      border-top: none !important;         /* quita línea divisora (opcional) */
      padding: 0 1rem 1rem;                /* mismo padding que el card-body  */
    }

    /* —— contenedor de proyectos en modo grid ——————————— */
    #grp-projects{
      display:grid;                         /* ↩ cambia de flex a grid        */
      grid-template-columns: repeat(2, 1fr);/* 2 columnas de igual ancho       */
      gap:.5rem;                            /* espacio entre casillas          */
    }

    #grp-projects label{
      display:block;                        /* cada label ocupa 1 columna      */
    }

    #grp-projects .proj-error{              /* mensaje de error                */
      grid-column:1 / -1;                   /* abarca de la col 1 a la última  */
      color:#e74c3c;
      margin-top:.25rem;
    }

    /* 2 columnas iguales + mensaje que “ocupa” la fila completa */
    .checkboxes-grid{
      display:grid;                       /* ← cambia a GRID                 */
      grid-template-columns:repeat(2,1fr);/* 2 columnas del mismo ancho       */
      gap:.5rem;                           /* mismo espacio que antes          */
    }

    .checkboxes-grid .checkbox-item{
      display:block;                      /* cada label = 1 celda             */
    }

    .checkboxes-grid .proj-error{         /* mensaje de error                 */
      grid-column:1 / -1;                 /* se estira por las 2 columnas      */
      color:#e74c3c;
      margin-top:.25rem;
      font-size:.875rem;
    }

    .btn-warning.active{
      background:#e69500;
    }

    /* === SCROLLBAR (copiado desde integrantes.php) === */
    /* Chrome / Edge / WebKit */
    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }
    ::-webkit-scrollbar-thumb {
      background: #c5c9d6;
      border-radius: 8px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #a9afc4;
    }

    /* Comportamiento de desplazamiento suave global (igual que integrantes.php) */
    html {
      scroll-behavior: smooth;
    }

    /* ====== LAYOUT ESTILO «integrantes» APLICADO A EVENTOS ====== */

    /* Contenedor general (sidebar + contenido) */
    .layout{
      display:flex;
    }

    /* Sidebar fija (reutiliza variables globales de navegador.php) */
    .sidebar{
      position:fixed;
      top:var(--nav-h);
      left:0;
      bottom:0;
      width:240px;
      background:var(--bg-sidebar);
      color:#fff;
      padding:1rem .75rem 2rem;
      overflow-y:auto;
      overflow-x:hidden;           /* ← evita scroll horizontal */
      border-radius:0 var(--radius) var(--radius) 0;
      box-shadow:var(--shadow);
      scrollbar-gutter: stable;    /* opcional: evita “saltos” al aparecer scroll */
    }

    /* Botones de filtro (los <button> dentro de cada <form>) */
    .sidebar form{
      margin:0 0 .35rem;
    }
    .sidebar form button{
      all:unset;
      display:block;
      width:100%;
      padding:.55rem .85rem;
      font:500 .9rem/1.25 "Poppins",sans-serif;
      border-radius:8px;
      cursor:pointer;
      background:transparent;
      color:#e5e7eb;
      transition:background .18s,color .18s;
      position:relative;
      white-space:normal;          /* permite salto */
      word-break:break-word;       /* rompe palabras largas */
      overflow-wrap:anywhere;      /* soporte amplio */
      max-width:100%;
      box-sizing:border-box;
    }
    .sidebar form button:hover{
      background:rgba(255,255,255,.12);
      color:#fff;
    }
    .sidebar form button.active{
      background:var(--primary);
      color:#fff;
      box-shadow:0 4px 12px rgba(0,0,0,.25);
    }

    /* Contenido principal (margen desplazado) */
    #eventos-main{
      margin-left:240px;
      padding:2rem 2.2rem;
      min-height:calc(100vh - var(--nav-h));
      display:block;
    }

    /* Título principal */
    #eventos-main h1{
      margin:0 0 1rem;
      font:600 1.55rem/1.2 "Poppins",sans-serif;
      letter-spacing:.5px;
      color:var(--negro);
    }

    /* Contenedor “card” para controles y tabla */
    #eventos-card{
      background:#fff;
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:1.25rem 1.4rem 1.8rem;
      position:relative;
      overflow:hidden;
    }

    /* Barra de acciones superior (buscador + fechas + etc.) */
    #eventos-toolbar{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem 1rem;
      align-items:flex-end;
      margin-bottom:1.2rem;
    }

    #eventos-toolbar form,
    #eventos-toolbar > div{
      display:flex;
      align-items:center;
      gap:.5rem;
    }

    /* Inputs / selects coherentes */
    #eventos-toolbar input[type="text"],
    #eventos-toolbar input[type="month"],
    #eventos-toolbar select{
      padding:.5rem .7rem;
      border:1px solid #d6d9e2;
      border-radius:8px;
      font:400 .85rem/1 "Poppins",sans-serif;
      background:#fff;
      min-width:150px;
      transition:border-color .18s, box-shadow .18s;
    }
    #eventos-toolbar input:focus,
    #eventos-toolbar select:focus{
      outline:none;
      border-color:var(--primary);
      box-shadow:0 0 0 2px rgba(255,54,0,.25);
    }

    /* Botones genéricos tipo sistema */
    .btn-sys{
      border:0;
      border-radius:8px;
      font:500 .85rem/1 "Poppins",sans-serif;
      cursor:pointer;
      padding:.6rem 1rem;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      background:var(--primary);
      color:#fff;
      transition:background .2s, transform .15s;
    }
    .btn-sys:hover{background:var(--primary-dark);}
    .btn-sys:active{transform:translateY(1px);}

    /* Variante secundaria */
    .btn-sec{
      background:#e5e7eb;
      color:#111827;
    }
    .btn-sec:hover{background:#d1d5db;}

    /* Botón advertencia (Aprobar eventos) */
    .btn-warning{
      background:var(--amarillo);
      color:#444;
    }
    .btn-warning:hover{background:#f7d600;}
    .btn-warning.active{
      background:#f4c400;
      color:#222;
    }

    /* Badges numéricos dentro de botones */
    .btn-sys .badge{
      background:#d60000;
      color:#fff;
      border-radius:12px;
      padding:2px 6px;
      font-size:.65rem;
      font-weight:600;
      line-height:1;
      position:relative;
      top:-1px;
    }

    /* Month navigation alineada y estilizada */
    .month-nav{
      position:relative;
      z-index:1;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:1.25rem;
      margin:0 0 1.4rem;
      font:600 1rem/1 "Poppins",sans-serif;
      color:var(--negro);
    }

    .month-nav .nav-title{
      background:#ffffff;
      border:1px solid #e3e6ec;
      padding:.55rem 1.25rem;
      border-radius:14px;
      box-shadow:0 2px 4px rgba(0,0,0,.06);
      letter-spacing:.5px;
    }

    .month-nav button.nav-arrow{
      all:unset;
      width:44px;
      height:44px;
      display:flex;
      align-items:center;
      justify-content:center;
      border-radius:14px;
      cursor:pointer;
      background:linear-gradient(135deg,#f4f6f9,#e9edf3);
      border:1px solid #d9dde3;
      box-shadow:0 2px 4px rgba(0,0,0,.08), inset 0 0 0 1px #fff;
      font-size:1.05rem;
      color:#374151;
      transition:box-shadow .25s, transform .18s, background .25s, color .25s;
    }

    .month-nav button.nav-arrow:hover{
      background:#fff;
      color:#111827;
      box-shadow:0 4px 10px rgba(0,0,0,.12);
    }

    .month-nav button.nav-arrow:active{
      transform:translateY(2px);
      box-shadow:0 2px 5px rgba(0,0,0,.16);
    }

    @media (max-width:600px){
      .month-nav{
        gap:.75rem;
      }
      .month-nav button.nav-arrow{
        width:40px;
        height:40px;
      }
      .month-nav .nav-title{
        padding:.5rem .9rem;
        font-size:.9rem;
      }
    }

    /* Tabla dentro del card */
    #eventos-card .table-responsive{
      border:1px solid #e6e8ef;
      border-radius:10px;
      overflow:auto;
      box-shadow:inset 0 0 0 1px #fff;
      background:#fff;
      max-width:100%;
    }

    #eventos-card table{
      width:100%;
      border-collapse:collapse;
      font:400 .8rem/1.4 "Poppins",sans-serif;
      min-width:920px;
    }

    #eventos-card thead th{
      background:#f9fafb;
      color:#4b5563;
      font:600 .72rem/1.2 "Poppins",sans-serif;
      text-transform:uppercase;
      letter-spacing:.5px;
      position:sticky;
      top:0;
      z-index:10;
      padding:.65rem .75rem;
      border-bottom:1px solid #e5e7eb;
    }

    #eventos-card tbody td{
      padding:.65rem .75rem;
      border-bottom:1px solid #f0f2f5;
      vertical-align:top;
      background:#fff;
    }

    #eventos-card tbody tr:nth-child(odd) td{
      background:#fcfdfe;
    }

    #eventos-card tbody tr:hover td{ /* sin tono celeste */
      background:#ffffff; /* o #f7f8fa si prefieres un gris muy suave */
    }

    /* === Ajuste responsive para “Equipo o Proyecto” === */
    .equipos-list{
      margin:.2rem 0 0;
      padding:0;
      list-style:none;
      counter-reset:eqnum;
      display:flex;
      flex-wrap:wrap;                 /* ahora las pills fluyen en varias filas */
      gap:.35rem .45rem;
      max-width:100%;                  /* nunca exceder la celda */
    }

    .equipos-list li{
      position:relative;
      counter-increment:eqnum;
      font:500 .7rem/1.15 "Poppins",sans-serif;
      background:linear-gradient(135deg,#f5f7fa,#eceff4);
      border:1px solid #d9dee5;
      padding:.4rem .55rem .4rem 2.1rem;
      border-radius:10px;
      color:#374151;
      box-shadow:0 1px 2px rgba(0,0,0,.05);
      white-space:normal;              /* permite varias líneas */
      max-width:calc(100% - 0.25rem);  /* cada pill no excede ancho de la fila */
      line-height:1.2;
      word-break:break-word;
      overflow:visible;                /* quitamos el corte forzado */
      text-overflow:unset;
    }

    .equipos-list li::before{
      content:counter(eqnum);
      position:absolute;
      left:.55rem;
      top:50%;
      transform:translateY(-50%);
      width:1.1rem;
      height:1.1rem;
      border-radius:6px;
      background:var(--primary);
      color:#fff;
      font:600 .65rem/1.1 "Poppins",sans-serif;
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 1px 2px rgba(0,0,0,.25);
    }

    /* Opcional: limitar altura de la celda de equipos y hacer scroll interno */
    td:nth-child(4) .equipos-list{
      max-height:80px;
      overflow:auto;
      scrollbar-width:thin;
    }

    /* Columna acciones */
    .actions .action-btn{
      background:transparent;
      border:0;
      cursor:pointer;
      font-size:1rem;
      padding:.35rem;
      color:#6b7280;
      border-radius:6px;
      transition:background .18s,color .18s;
    }
    .actions .action-btn:hover{
      background:#eef2ff;
      color:#374151;
    }

    /* Ajustes de errores inline reutilizados */
    .err-inline{
      font:500 .65rem/1 "Poppins",sans-serif;
      margin-top:.25rem;
    }

    /* Adaptar buscador (emoji) */
    #form-search input[type="text"]{
      min-width:180px;
    }

    /* Espacio antes de la tabla principal */
    #eventos-main section{margin-top:0;}

    /* ==== Zona de descarga agrupada ==== */
    #form-download{
      position:relative;
      display:flex;
      align-items:flex-end;
      gap:.6rem;
      background:#f8f9fb;
      border:1px solid #e2e6ef;
      padding:.65rem .8rem .8rem;
      border-radius:10px;
      box-shadow:0 1px 2px rgba(0,0,0,.04);
    }

    #form-download .range-group{
      display:flex;
      gap:.75rem;
      align-items:flex-start;
    }

    #form-download .range-field{
      display:flex;
      flex-direction:column;
      font:500 .65rem/1 "Poppins",sans-serif;
      text-transform:uppercase;
      letter-spacing:.5px;
      color:#555;
    }

    #form-download .range-field label{
      margin:0 0 .25rem;
    }

    #form-download select{
      min-width:90px;
    }

    #form-download #btn-download{
      align-self:flex-end;
    }

    @media (max-width:1050px){
      #form-download{
        flex-wrap:wrap;
      }
      #form-download .range-group{
        width:100%;
        justify-content:space-between;
      }
    }

    /* ===== Modales unificados mejorados ===== */
    .modal-overlay{
      position:fixed;
      inset:0;
      background:rgba(17,24,39,.55);
      backdrop-filter:blur(3px);
      display:flex;
      align-items:center;
      justify-content:center;
      padding:1.5rem;
      z-index:10000;
      animation:fadeIn .25s ease;
    }

    @keyframes fadeIn{
      from{opacity:0;}
      to{opacity:1;}
    }

    .modal-content.card{
      background:linear-gradient(145deg,#ffffff 0%,#f5f7fb 100%);
      border:1px solid #e3e8ef;
      border-radius:18px;
      box-shadow:0 10px 30px -5px rgba(0,0,0,.25), 0 4px 10px -3px rgba(0,0,0,.15);
      overflow:hidden;
      animation:popIn .28s cubic-bezier(.16,.8,.3,1);
    }

    @keyframes popIn{
      0%{transform:translateY(12px) scale(.96); opacity:0;}
      100%{transform:translateY(0) scale(1); opacity:1;}
    }

    .card-header{
      background:linear-gradient(90deg,var(--primary) 0%, #ff7a33 60%, #ff934d 100%);
      color:#fff;
      padding:1rem 1.25rem;
      display:flex;
      align-items:center;
      gap:.75rem;
      position:relative;
    }

    .card-header .card-title{
      margin:0;
      font:600 1.05rem/1.1 "Poppins",sans-serif;
      letter-spacing:.5px;
    }

    .card-header .modal-close{
      position:absolute;
      top:50%;
      right:1rem;
      transform:translateY(-50%);
      color:#fff;
      background:rgba(255,255,255,.15);
      width:34px;
      height:34px;
      border-radius:10px;
      display:flex;
      align-items:center;
      justify-content:center;
      border:0;
      cursor:pointer;
      transition:background .2s, transform .15s;
    }
    .card-header .modal-close:hover{
      background:rgba(255,255,255,.28);
    }
    .card-header .modal-close:active{
      transform:translateY(-50%) scale(.95);
    }

    .card-body{
      padding:1.25rem 1.3rem 1.4rem;
      overflow-y:auto;
      max-height:70vh;
    }

    .card-footer{
      padding:1rem 1.3rem 1.3rem;
      background:#f1f4f9;
      border-top:1px solid #e0e6ef;
      display:flex;
      justify-content:flex-end;
      gap:.75rem;
    }

    /* Botones dentro de modales coherentes */
    .card-footer .btn,
    .modal-content.card button.btn-primary,
    .modal-content.card button.btn-success{
      background:var(--primary);
      color:#fff;
      border:0;
      border-radius:10px;
      padding:.7rem 1.1rem;
      font:500 .85rem/1 "Poppins",sans-serif;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      transition:background .2s, transform .15s;
    }

    .card-footer .btn:hover,
    .modal-content.card button.btn-primary:hover,
    .modal-content.card button.btn-success:hover{
      background:var(--primary-dark);
    }

    .card-footer .btn:active,
    .modal-content.card button.btn-primary:active,
    .modal-content.card button.btn-success:active{
      transform:translateY(1px);
    }

    /* Inputs en modales mismos estilos (ya tenías base, se refuerza) */
    .modal-content.card .form-group input,
    .modal-content.card .form-group textarea,
    .modal-content.card .form-group select{
      background:#fff;
      border:1px solid #d8dde5;
      font:.8rem "Poppins",sans-serif;
    }

    .modal-content.card .form-group input:focus,
    .modal-content.card .form-group textarea:focus,
    .modal-content.card .form-group select:focus{
      border-color:var(--primary);
      box-shadow:0 0 0 2px rgba(255,86,20,.25);
    }

    /* Pills de checkboxes uniformes */
    .modal-content.card .checkbox-item{
      background:#eef1f6;
      border-radius:24px;
      font:.7rem/1 "Poppins",sans-serif;
      padding:.45rem .75rem;
      transition:background .18s, color .18s;
    }
    .modal-content.card .checkbox-item:hover{
      background:#e2e6ed;
    }
    .modal-content.card .checkbox-item input:checked + span,
    .modal-content.card .checkbox-item input:checked{
      font-weight:600;
      color:var(--primary);
    }

    /* Lista de detalles (modal ver) */
    .vertical-list .detail-item{
      border:1px solid #e5e9f0;
      background:#fff;
      padding:.7rem .85rem .6rem;
      border-radius:10px;
      margin:0 0 .8rem;
      box-shadow:0 1px 2px rgba(0,0,0,.05);
    }
    .vertical-list .detail-item dt{
      font:600 .7rem/1 "Poppins",sans-serif;
      text-transform:uppercase;
      letter-spacing:.7px;
      margin:0 0 .35rem;
      color:#374151;
    }
    .vertical-list .detail-item dd{
      margin:0;
      font:.78rem/1.35 "Poppins",sans-serif;
      color:#4b5563;
      white-space:pre-wrap;
      word-break:break-word;
    }

    /* Botones de acción en celdas refinados */
    .actions .action-btn{
      border:0;
      background:#f3f4f6;
      width:28px;
      height:28px;
      border-radius:8px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      margin:0 .1rem;
      font-size:.8rem;
      color:#565e6b;
      line-height:1;
      padding:0;
      box-shadow:0 1px 2px rgba(0,0,0,.05);
      transition:background .18s, color .18s, transform .15s;
    }
    .actions .action-btn:hover{
      background:#ffffff;
      color:#111827;
    }
    .actions .action-btn:active{
      transform:translateY(1px);
    }

    /* Ajuste móvil todavía menor si hace falta */
    @media (max-width:900px){
      .actions .action-btn{
        width:26px;
        height:26px;
        font-size:.7rem;
      }
    }

    /* Para mantener celdas limpias en móviles */
    @media (max-width:900px){
      .actions .action-btn{
        width:30px;
        height:30px;
        font-size:.8rem;
      }
    }

    /* ===== Reordenado vertical de la toolbar ===== */
    #eventos-toolbar{
      display:flex;
      flex-direction:column;
      align-items:stretch;
      gap:1rem;
      margin-bottom:1.4rem;
    }

    #form-download{
      width:100%;
      box-sizing:border-box;
    }

    #toolbar-actions{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem;
    }

    #toolbar-search-wrapper{
      display:flex;
      flex-direction:column;
      width:100%;
    }

    #form-search{
      display:flex;
      gap:.5rem;
      width:100%;
    }

    #form-search input[type="text"]{
      flex:1;
    }

    #toolbar-search-wrapper #search-error{
      margin-top:.3rem;
    }

    /* Altura mínima de la tabla para mantener layout consistente */
    #eventos-card .table-responsive{
      min-height:340px;              /* ajusta si quieres más/menos */
      display:flex;
      flex-direction:column;
    }

    #eventos-card .table-responsive table{
      flex:1;
    }

    /* Fila vacía centrada */
    .row-empty td.empty-cell{
      padding:2.5rem 1rem;
      text-align:center;
      background:#f9fafb;
      font:500 .9rem/1.4 "Poppins",sans-serif;
      color:#555;
    }

    .row-empty .empty-msg{
      display:inline-flex;
      align-items:center;
      gap:.6rem;
      background:#fff;
      border:1px dashed #d1d5db;
      padding:.9rem 1.25rem;
      border-radius:12px;
      box-shadow:0 1px 2px rgba(0,0,0,.05);
    }

    .row-empty .empty-msg i{
      font-size:1.15rem;
      color:var(--primary);
    }

    /* ════════════════════════════════════════════════════════
      MODAL ASISTENCIA – Mejora visual (solo CSS)
      Sin cambiar JS / HTML existente.
      Pegar al final del <style>.
      ════════════════════════════════════════════════════════ */

    #modal-asist .modal-content.card {
      width: clamp(560px, 72vw, 780px);
      max-height: 90vh;
      display: flex;
      flex-direction: column;
    }

    #modal-asist .card-body#asist-body {
      padding: 1.05rem 1.2rem 1.4rem;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: #c5c9d6 transparent;
      background: linear-gradient(180deg,#ffffff 0%,#f7f9fc 100%);
      border-top: 1px solid #f0f3f7;
    }

    /* Scrollbar WebKit */
    #modal-asist .card-body#asist-body::-webkit-scrollbar {
      width: 8px;
    }
    #modal-asist .card-body#asist-body::-webkit-scrollbar-thumb {
      background:#c5c9d6;
      border-radius:8px;
    }
    #modal-asist .card-body#asist-body::-webkit-scrollbar-thumb:hover {
      background:#a9afc4;
    }

    /* Paleta / tokens */
    #asist-body {
      --asist-border: #e1e6ef;
      --asist-border-hover: #d2d9e3;
      --asist-bg: #fff;
      --asist-bg-alt: #f6f8fb;
      --asist-hover: #f1f6ff;
      --asist-accent: #ff681e;
      --asist-text: #1f2937;
      --asist-muted: #5a6474;
      --asist-pill-bg: linear-gradient(135deg,#f5f7fa,#edf1f6);
      --asist-pill-border: #d3dae2;
    }

    /* =======================================================
      1) MODO CON CLASES (ideal) 
      Estructura esperada por fila:
      <div class="asist-row">
          <div class="asist-nombre">
            (opcional <img class="avatar">) Nombre
          </div>
          <div class="asist-opciones">
            <button class="pill active">Presente</button> ...
          </div>
      </div>
      ======================================================= */
    #asist-body .asist-row {
      display: grid;
      grid-template-columns: minmax(220px,1fr) minmax(260px,340px);
      align-items: center;
      gap: .9rem;
      padding: .60rem .85rem .55rem;
      background: var(--asist-bg);
      border: 1px solid var(--asist-border);
      border-radius: 14px;
      box-shadow: 0 1px 2px rgba(0,0,0,.05);
      transition: background .18s, border-color .18s, box-shadow .18s;
      position: relative;
    }

    #asist-body .asist-row:nth-child(odd) {
      background: var(--asist-bg-alt);
    }

    #asist-body .asist-row:hover {
      background: var(--asist-hover);
      border-color: var(--asist-border-hover);
      box-shadow: 0 3px 10px -2px rgba(0,0,0,.10);
    }

    #asist-body .asist-row:focus-within {
      border-color: var(--asist-accent);
      box-shadow: 0 0 0 2px rgba(255,104,30,.23);
    }

    #asist-body .asist-row .asist-nombre {
      font: 600 .84rem/1.25 "Poppins",sans-serif;
      color: var(--asist-text);
      display: flex;
      align-items: center;
      gap: .55rem;
      min-width: 0;
      word-break: break-word;
    }

    #asist-body .asist-row .asist-nombre .avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      object-fit: cover;
      flex-shrink: 0;
      box-shadow: 0 0 0 2px #fff, 0 2px 6px rgba(0,0,0,.20);
    }

    #asist-body .asist-row .asist-opciones {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem .55rem;
      align-items: center;
      justify-content: flex-start;
      padding:.15rem 0;
    }

    /* Botones / pills (pueden ser <button>, <span>, <label>, etc.) */
    #asist-body .asist-row .asist-opciones .pill,
    #asist-body .asist-row .asist-opciones button,
    #asist-body .asist-row .asist-opciones label {
      cursor: pointer;
      user-select: none;
      background: var(--asist-pill-bg);
      border: 1px solid var(--asist-pill-border);
      color: var(--asist-muted);
      font: 500 .66rem/1 "Poppins",sans-serif;
      letter-spacing:.4px;
      padding: .50rem .85rem .45rem;
      border-radius: 999px;
      min-width: 70px;
      text-align: center;
      position: relative;
      transition: background .18s, border-color .18s, color .18s, box-shadow .18s, transform .15s;
    }

    #asist-body .asist-row .asist-opciones .pill:hover,
    #asist-body .asist-row .asist-opciones button:hover,
    #asist-body .asist-row .asist-opciones label:hover {
      background:#fff;
      border-color:#c8d0da;
      color:#374151;
      box-shadow:0 2px 4px rgba(0,0,0,.08);
    }

    #asist-body .asist-row .asist-opciones .active,
    #asist-body .asist-row .asist-opciones .pill.active {
      background: var(--asist-accent);
      border-color: var(--asist-accent);
      color:#fff !important;
      box-shadow:0 3px 10px -2px rgba(255,104,30,.55);
    }

    #asist-body .asist-row .asist-opciones .active::after {
      content:"";
      position:absolute;
      inset:0;
      pointer-events:none;
      border-radius:999px;
      box-shadow:0 0 0 2px rgba(255,255,255,.55) inset;
    }

    #asist-body .asist-row .asist-opciones input[type="radio"],
    #asist-body .asist-row .asist-opciones input[type="checkbox"] {
      /* Si existen, los escondemos y usamos el label/pill */
      position:absolute;
      opacity:0;
      width:0;
      height:0;
      pointer-events:none;
    }

    /* Estado seleccionado vía input + label */
    #asist-body .asist-row .asist-opciones input:checked + .pill,
    #asist-body .asist-row .asist-opciones input:checked + label {
      background: var(--asist-accent);
      border-color: var(--asist-accent);
      color:#fff;
      box-shadow:0 3px 10px -2px rgba(255,104,30,.55);
    }

    /* Etiqueta pequeña dentro del nombre (ej. rol) */
    #asist-body .asist-row .asist-nombre .tag {
      background:#ffe4d5;
      color:#b44200;
      font:600 .55rem/1 "Poppins",sans-serif;
      padding:.25rem .45rem .22rem;
      border-radius:6px;
      letter-spacing:.5px;
    }

    /* =======================================================
      2) MODO AUTOMÁTICO (fallback):
      Si NO tienes clases, se asume que cada hijo directo de
      #asist-body es una “fila” y dentro el primer hijo es
      “nombre” y lo demás “opciones”.
      ======================================================= */

    /* Fila genérica */
    #asist-body > div:not(.asist-row) {
      display:grid;
      grid-template-columns: minmax(220px,1fr) minmax(260px,340px);
      align-items: center;
      gap:.9rem;
      padding:.58rem .8rem .53rem;
      background: var(--asist-bg);
      border:1px solid var(--asist-border);
      border-radius:14px;
      box-shadow:0 1px 2px rgba(0,0,0,.05);
      transition: background .18s, border-color .18s, box-shadow .18s;
      position:relative;
    }

    #asist-body > div:not(.asist-row):nth-child(odd){
      background: var(--asist-bg-alt);
    }

    #asist-body > div:not(.asist-row):hover {
      background: var(--asist-hover);
      border-color: var(--asist-border-hover);
      box-shadow:0 3px 10px -2px rgba(0,0,0,.10);
    }

    /* Primera “columna” (nombre) */
    #asist-body > div:not(.asist-row) > :first-child {
      font:600 .84rem/1.25 "Poppins",sans-serif;
      color:var(--asist-text);
      display:flex;
      gap:.55rem;
      align-items:center;
      min-width:0;
      word-break:break-word;
    }

    /* Opciones (resto de hijos) */
    #asist-body > div:not(.asist-row) > :not(:first-child) {
      display:flex;
      flex-wrap:wrap;
      gap:.5rem .55rem;
      align-items:center;
      justify-content:flex-start;
      padding:.15rem 0;
    }

    /* Botoncitos genéricos dentro de las opciones */
    #asist-body > div:not(.asist-row) > :not(:first-child) > * {
      background: var(--asist-pill-bg);
      border:1px solid var(--asist-pill-border);
      border-radius:999px;
      padding:.50rem .85rem .45rem;
      font:500 .66rem/1 "Poppins",sans-serif;
      letter-spacing:.4px;
      color:var(--asist-muted);
      min-width:70px;
      text-align:center;
      cursor:pointer;
      user-select:none;
      transition: background .18s, border-color .18s, color .18s, box-shadow .18s;
    }

    #asist-body > div:not(.asist-row) > :not(:first-child) > *:hover {
      background:#fff;
      border-color:#c8d0da;
      color:#374151;
      box-shadow:0 2px 4px rgba(0,0,0,.08);
    }

    /* Marcar activo si el JS aplica .active */
    #asist-body > div:not(.asist-row) > :not(:first-child) > *.active {
      background:var(--asist-accent);
      border-color:var(--asist-accent);
      color:#fff;
      box-shadow:0 3px 10px -2px rgba(255,104,30,.55);
      position:relative;
    }
    #asist-body > div:not(.asist-row) > :not(:first-child) > *.active::after {
      content:"";
      position:absolute;
      inset:0;
      border-radius:999px;
      box-shadow:0 0 0 2px rgba(255,255,255,.5) inset;
      pointer-events:none;
    }

    /* Si por alguna razón hay <select> */
    #asist-body select {
      font:500 .7rem/1 "Poppins",sans-serif;
      border:1px solid #cfd5df;
      border-radius:10px;
      background:#fff;
      padding:.45rem .65rem;
      cursor:pointer;
      transition:border-color .18s, box-shadow .18s;
    }
    #asist-body select:focus {
      outline:none;
      border-color:var(--asist-accent);
      box-shadow:0 0 0 2px rgba(255,104,30,.25);
    }

    /* Estado de carga o mensaje vacío (si el JS agrega algo así) */
    #asist-body .asist-empty,
    #asist-body .asist-loading {
      border:1px dashed #d5dae2;
      background:#ffffff;
      font:500 .75rem/1.3 "Poppins",sans-serif;
      padding:1.5rem 1rem;
      text-align:center;
      border-radius:14px;
      color:#5f6b7a;
      letter-spacing:.4px;
    }

    /* Pequeño título de columna simulado opcional (si JS añade .asist-header) */
    #asist-body .asist-header {
      background:linear-gradient(90deg,#ffffff,#f0f4f9);
      border:1px solid #d8dee7;
      font:600 .62rem/1 "Poppins",sans-serif;
      letter-spacing:1.1px;
      text-transform:uppercase;
      color:#4d5a67;
      padding:.55rem .8rem;
      border-radius:12px;
      margin:0 0 .3rem;
      display:grid;
      grid-template-columns:minmax(220px,1fr) minmax(260px,340px);
      gap:.9rem;
    }

    /* Responsivo */
    @media (max-width: 640px) {
      #modal-asist .modal-content.card {
        width:90vw;
      }
      #asist-body .asist-row,
      #asist-body > div:not(.asist-row),
      #asist-body .asist-header {
        grid-template-columns: 1fr;
      }
      #asist-body .asist-row .asist-opciones,
      #asist-body > div:not(.asist-row) > :not(:first-child) {
        padding-top:.35rem;
      }
    }

    /* === Alinear opciones del modal asistencia totalmente a la derecha === */

    /* Modo con clases (.asist-row / .asist-opciones) */
    #asist-body .asist-row {
      /* opcional: si quieres un poquito menos de padding derecho para que se vea más pegado */
      --pad-x: .85rem;            /* valor actual */
      padding-right: var(--pad-x);
      position: relative;
    }

    #asist-body .asist-row .asist-opciones {
      justify-content: flex-end;  /* empuja las pills a la derecha */
      margin-left: auto;          /* asegura que todo el bloque se vaya a la derecha */
      /* Quita espacio interno que las separa del borde si deseas pegarlas del todo: */
      margin-right: calc(-1 * var(--pad-x));  /* “come” el padding derecho del row */
      padding-right: 0;
    }

    /* Si quieres que cada línea (cuando hace wrap) también esté alineada a la derecha */
    #asist-body .asist-row .asist-opciones {
      text-align: right;
    }

    /* Modo fallback (cuando NO hay clases y cada hijo de #asist-body es una fila) */
    #asist-body > div:not(.asist-row) {
      --pad-x: .85rem;
      padding-right: var(--pad-x);
      position: relative;
    }

    #asist-body > div:not(.asist-row) > :not(:first-child) {
      justify-content: flex-end;
      margin-left: auto;
      margin-right: calc(-1 * var(--pad-x));
      padding-right: 0;
      text-align: right;
    }

    /* (Opcional) Ajustar el gap para que visualmente se “pegue” más */
    #asist-body .asist-row .asist-opciones,
    #asist-body > div:not(.asist-row) > :not(:first-child) {
      gap: .45rem .45rem;  /* reduce horizontal si quieres */
    }

    /* ----------  BOTÓN HAMBURGER (oculto por defecto) ---------- */
    .btn-sidebar-toggle{
      display:none;                 /* sólo se mostrará en el @media */
    }

    /* ----------  VISTA ≤ 768 px (teléfonos) ---------- */
    @media (max-width:768px){

      /* sidebar fuera de pantalla hasta que se abra */
      .sidebar{
        transform:translateX(-100%);
        transition:transform .3s ease;
        width:220px;
        z-index:11000;
      }
      .sidebar.open{                /* se aplica vía JS */
        transform:translateX(0);
      }

      /* botón visible y flotante */
      .btn-sidebar-toggle{
        display:inline-flex;
        position:fixed;
        top:calc(var(--nav-h) + .5rem);
        left:.75rem;
        padding:.55rem .75rem;
        background:var(--primary);
        color:#fff;
        border-radius:8px;
        box-shadow:0 4px 10px rgba(0,0,0,.15);
        z-index:12000;
      }

      /* layout en columna y main sin margen izquierdo */
      .layout{flex-direction:column;}
      #eventos-main{
        margin-left:0;
        padding:1rem 1rem 2rem;
      }

      /* toolbar y buscador ocupan ancho completo */
      #eventos-toolbar form,
      #eventos-toolbar > div{
        flex-wrap:wrap;
        width:100%;
      }

      /* navegación de mes con salto de línea */
      .month-nav{
        flex-wrap:wrap;
        gap:.6rem;
      }

      /* tabla: permitir scroll horizontal en pantallas pequeñas */
      #eventos-card table{
        min-width:600px;      /* ancho máximo que se verterá con scroll */
      }
    }

    /*════════  AJUSTES EXTRA PARA ≤ 480 px  ════════*/
    @media (max-width:480px){

      /* 1. Card y separaciones más compactas */
      #eventos-card{
        padding:1rem .9rem 1.3rem;
      }

      /* 2. Toolbar 100 % de ancho, todo en columna */
      #eventos-toolbar{
        gap:1.1rem;
      }
      #eventos-toolbar form,
      #eventos-toolbar > div{
        width:100%;
        flex-wrap:wrap;
      }

      /* 3. Botones principales ocupan toda la fila */
      #toolbar-actions{
        width:100%;
        justify-content:stretch;
      }
      #toolbar-actions .btn-sys{
        flex:1 1 100%;
        justify-content:center;
      }

      /* 4. Agrupador de descarga pasa a columna */
      #form-download{
        flex-direction:column;
        align-items:stretch;
        gap:.9rem;
      }
      #form-download .range-group{
        flex-direction:column;
        gap:.6rem;
      }
      #form-download .range-field{
        width:100%;
      }
      #form-download #btn-download{
        width:100%;
        justify-content:center;
      }

      /* 5. Navegación de mes: título arriba, flechas debajo */
      .month-nav{
        flex-direction:column;
        gap:.55rem;
      }

      /* 6. Tabla: letra levemente menor y scroll horizontal asegurado */
      #eventos-card table{
        font-size:.75rem;
        min-width:520px;          /* el usuario hará scroll si hace falta */
      }

      /* 7. Equipos / proyectos: pills a 100 % */
      .equipos-list{
        gap:.35rem .35rem;
      }

      /* 8. Dropdowns e inputs ocupan toda la línea cuando sea posible */
      #eventos-toolbar input[type="text"],
      #eventos-toolbar input[type="month"],
      #eventos-toolbar select{
        width:100%;
        min-width:0;
      }
    }

    /*════════  TABLA COMO TARJETAS  ≤ 600 px  ════════*/
    @media (max-width:600px){

      /* Ocultamos cabecera y eliminamos ancho mínimo */
      #eventos-card thead{display:none;}
      #eventos-card table{min-width:100%; border:0;}

      /* Cada fila = tarjeta */
      #eventos-card tbody tr{
        display:block;
        margin-bottom:1rem;
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:12px;
        box-shadow:0 2px 6px rgba(0,0,0,.05);
        overflow:hidden;
      }

      /* Celdas en dos columnas: etiqueta + valor */
      #eventos-card tbody td{
        display:grid;
        grid-template-columns:110px 1fr;
        gap:.35rem .75rem;
        padding:.75rem .95rem;
        border:none;
        border-top:1px solid #f4f4f6;
        font-size:.80rem;
        line-height:1.35;
      }
      #eventos-card tbody td:first-child{border-top:0;}

      /* Etiqueta (“Inicio”, …) */
      #eventos-card tbody td::before{
        content:attr(data-label);
        font-weight:600;
        color:#374151;
        text-transform:uppercase;
        font-size:.68rem;
        letter-spacing:.4px;
      }

      /* Columna Acciones: se muestra en una sola línea */
      #eventos-card tbody td.actions{
        display:flex;
        align-items:center;
        gap:.4rem;
        justify-content:flex-start;
      }
      #eventos-card tbody td.actions::before{content:'';} /* sin etiqueta */

      /* Pills de equipos: ajustamos márgenes cuando van en tarjetas */
      #eventos-card tbody td:nth-child(4) .equipos-list{
        margin:.3rem 0 0;
      }

      /* ======= Ajustes extra para optimizar la tarjeta móvil ======= */

      /* (a) Etiqueta arriba y valor ocupando todo el ancho --------- */
      #eventos-card tbody td{
        /* pasamos de dos columnas fijas (110px 1fr) a una sola         */
        grid-template-columns: 1fr !important;
      }
      #eventos-card tbody td::before{
        grid-column: 1 / -1;          /* la etiqueta abarca la fila entera  */
        margin-bottom: .25rem;        /* pequeño espacio bajo la etiqueta   */
      }

      /* (b) Lista de Equipos/Proyectos bien visible ----------------- */
      #eventos-card tbody td[data-label="Equipo / Proyecto"] .equipos-list{
        display: block;               /* pila vertical                      */
      }
      #eventos-card tbody td[data-label="Equipo / Proyecto"] .equipos-list li{
        display: block;
        width: 100%;
        max-width: 100%;              /* aprovecha todo el ancho            */
      }
    }

    /* ─── Mobile fix específico para “Inicio” y “Término” ─── */
    @media (max-width:600px){
      #eventos-card tbody td[data-label="Inicio"],
      #eventos-card tbody td[data-label="Término"]{
          white-space: nowrap;
          word-break: normal;
          overflow-wrap: normal;
      }
    }

    /*════════  FIXES MÓVILES  (≤600 px)  ════════*/
    @media (max-width:600px){

      /* A) Cada tarjeta usa el 100 % del ancho disponible */
      #eventos-card tbody tr{
        width:100%;
      }

      /* B) Nos aseguramos de que no quede ningún fondo celeste */
      #eventos-card tbody tr,
      #eventos-card tbody td{
        background:#fff !important;
      }

      /* C) Más separación entre los iconos de acción */
      #eventos-card tbody td.actions{
        gap:.6rem;                 /* flex‑gap > anterior .4 rem */
      }
      .actions .action-btn + .action-btn{
        margin-left:0;             /* anula el –1 rem global     */
      }
    }

    /* █████  RESET de anchos en versión “tarjeta”  █████ */
    @media (max-width:600px){
      /* 1) cada celda ocupa todo el ancho ¡y sin mínimos! */
      #eventos-card tbody td{
        width:100%       !important;
        min-width:0      !important;
        max-width:100%   !important;
      }

      /* 2) los anchos asignados por nth‑child ya no aplican */
      .table-responsive td:nth-child(n),
      .table-responsive th:nth-child(n){
        width:auto       !important;
        min-width:0      !important;
      }
    }
  </style>

  <!-- ═════════ Validación única al cargar la página ═════════ -->
  <script>
  (() => {
    const token = localStorage.getItem('token');
    if (!token) { location.replace('login.html'); return; }
    const ctrl = new AbortController();
    window.addEventListener('beforeunload', ()=> ctrl.abort());

    validarToken(ctrl.signal)
      .catch(err => {
        if (err.message === 'TokenNoValido') {
          localStorage.clear();
          location.replace('login.html');
        }
      });

    async function validarToken(signal) {
      let res;
      try {
        res = await fetch('validar_token.php', {
          headers: { 'Authorization': 'Bearer ' + token },
          signal
        });
      } catch(e) {
        if (e.name === 'AbortError') throw e;
        throw new Error('NetworkFail');
      }
      if (res.status === 401) throw new Error('TokenNoValido');
      const data = await res.json();
      if (!data.ok) throw new Error('TokenNoValido');
    }
  })();
  </script>
  <!-- ═══════════════════════════════════════════════════════ -->
</head>

<body>
  <!-- ░░░░ NAV ░░░░ -->
  <?php require_once 'navegador.php'; ?>

  <!-- === BOTÓN TOGGLE SIDEBAR (solo se verá en móviles) === -->
  <button id="toggle-sidebar"
          class="btn-sys btn-sidebar-toggle"
          aria-label="Menú">
    <i class="fa-solid fa-bars"></i>
  </button>

  <!-- ░░░░ CONTENIDO PRINCIPAL (layout estilo integrantes) ░░░░ -->
  <div class="layout">

    <!-- ░░░░ SIDEBAR FILTROS ░░░░ -->
    <?php
      // ─── Armar lista de filtros para el sidebar (igual que antes) ───
      $sidebarOpts = [
        'calendario' => 'Calendario',
        'general'    => 'Eventos Generales',
      ];
      if ($isLiderNacional) {
          $projectsForSidebar = $allProjects;
      } else {
          $projectsForSidebar = $userTeams;
      }
      $sidebarOpts += array_column(
        $projectsForSidebar,
        'nombre_equipo_proyecto',
        'id_equipo_proyecto'
      );
    ?>
    <aside class="sidebar" id="sidebar-eventos">
      <?php foreach ($sidebarOpts as $key => $label): ?>
        <form method="POST">
          <input type="hidden" name="mes"      value="<?=htmlspecialchars($mesParam)?>">
          <input type="hidden" name="busqueda" value="<?=htmlspecialchars($busqueda)?>">
          <button
            name="filtro"
            value="<?=htmlspecialchars($key)?>"
            class="<?= $filtro===$key ? 'active' : '' ?>"
          >
            <?=htmlspecialchars($label)?>
          </button>
        </form>
      <?php endforeach; ?>
    </aside>

    <!-- ░░░░ MAIN ░░░░ -->
    <main id="eventos-main">
      <h1>Eventos</h1>

      <!-- Card global -->
      <div id="eventos-card">

        <!-- Barra / toolbar -->
        <div id="eventos-toolbar">

          <?php
            $mesStart = $_REQUEST['mesStart'] ?? $mesParam;
            $mesEnd   = $_REQUEST['mesEnd']   ?? $mesParam;
          ?>

          <!-- (Fila 1) Zona agrupada Descarga -->
          <form id="form-download" method="GET" action="export.php">
            <input type="hidden" name="filtro"    value="<?= htmlspecialchars($filtro) ?>">
            <input type="hidden" name="busqueda"  value="<?= htmlspecialchars($busqueda) ?>">
            <input type="hidden" name="aprobados" value="<?= $showAprob ? '1' : '0' ?>">

            <div class="range-group">
              <div class="range-field">
                <label for="mesStart">Inicio</label>
                <input
                  type="month"
                  name="mesStart"
                  id="mesStart"
                  min="1970-01" max="2037-12"
                  pattern="\d{4}-\d{2}"
                  value="<?= htmlspecialchars($mesStart) ?>">
              </div>

              <div class="range-field">
                <label for="mesEnd">Fin</label>
                <input
                  type="month"
                  name="mesEnd"
                  id="mesEnd"
                  min="1970-01" max="2037-12"
                  pattern="\d{4}-\d{2}"
                  value="<?= htmlspecialchars($mesEnd) ?>">
              </div>

              <div class="range-field">
                <label for="format">Formato</label>
                <select name="format" id="format">
                  <option value="excel">Excel</option>
                  <option value="pdf">PDF</option>
                </select>
              </div>
            </div>

            <div id="download-errors" style="display:flex;flex-direction:column;gap:.15rem;margin-top:.4rem">
              <small id="mesStart-error"  class="err-inline">* Selecciona el mes de inicio.</small>
              <small id="mesEnd-error"    class="err-inline">* Selecciona el mes de fin.</small>

              <small id="dateOrder-error" class="err-inline">
                * El mes de fin debe ser igual o posterior al de inicio.
              </small>

              <small id="dateRange-error" class="err-inline">
                * El rango máximo permitido es de 24&nbsp;meses.
              </small>
            </div>

            <button type="submit" id="btn-download" class="btn-sys btn-sec">
              <i class="fa-solid fa-file-arrow-down"></i> Descargar
            </button>
          </form>

          <!-- (Fila 2) Botones principales -->
          <div id="toolbar-actions">
            <?php if ($canCreate): ?>
              <button id="btn-new-event" class="btn-sys">
                <i class="fa-solid fa-calendar-plus"></i> Crear evento
              </button>
            <?php endif; ?>

            <?php if ($canRequest): ?>
              <button id="btn-request-event" class="btn-sys btn-sec">
                <i class="fa-solid fa-paper-plane"></i> Solicitar evento
              </button>
            <?php endif; ?>

            <?php if ($isLiderNacional): ?>
              <button
                id="btn-aprobar-eventos"
                class="btn-sys btn-warning <?= $showAprob ? 'active' : '' ?>"
                style="position:relative"
              >
                <i class="fa-solid fa-circle-check"></i> Aprobar
                <?php if ($pendingCount > 0): ?>
                  <span class="badge"><?= $pendingCount ?></span>
                <?php endif; ?>
              </button>
            <?php endif; ?>
          </div>

          <!-- (Fila 3) Buscador -->
          <div id="toolbar-search-wrapper">
            <form method="POST" action="eventos.php" id="form-search">
              <input
                type="text"
                name="busqueda"
                id="search-input"
                maxlength="200"
                placeholder="Buscar..."
                value="<?= htmlspecialchars($busqueda) ?>">
              <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
              <input type="hidden" name="mes"    value="<?= htmlspecialchars($mesParam) ?>">
              <button type="submit" id="btn-search" class="btn-sys btn-sec">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
              </button>
            </form>
            <small id="search-error" class="err-inline" style="display:none;">
              * Solo letras, números, espacios y . , # ¿ ¡ ! ? ( ) / -
            </small>
          </div>

        </div>

        <!-- Navegación de mes -->
        <nav class="month-nav">
          <?php if ($prevMonth !== null): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="filtro"   value="<?= htmlspecialchars($filtro) ?>">
              <input type="hidden" name="mes"      value="<?= $prevMonth ?>">
              <input type="hidden" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>">
              <button type="submit" class="nav-arrow" aria-label="Mes anterior">
                <i class="fa-solid fa-chevron-left"></i>
              </button>
            </form>
          <?php endif; ?>

          <span class="nav-title"><?= $meses[$month] ?> <?= $year ?></span>

          <?php if ($nextMonth !== null): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="filtro"   value="<?= htmlspecialchars($filtro) ?>">
              <input type="hidden" name="mes"      value="<?= $nextMonth ?>">
              <input type="hidden" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>">
              <button type="submit" class="nav-arrow" aria-label="Mes siguiente">
                <i class="fa-solid fa-chevron-right"></i>
              </button>
            </form>
          <?php endif; ?>
        </nav>

        <!-- Tabla / Resultados -->
        <section>
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Inicio</th><th>Término</th><th>Evento</th>
                  <th>Equipo o Proyecto</th><th>Estado previo</th>
                  <th>Asistencia previa</th><th>Estado final</th><th>Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($rows)): ?>
                <tr class="row-empty">
                  <td colspan="8" class="empty-cell">
                    <div class="empty-msg">
                      <i class="fa-regular fa-calendar-xmark"></i>
                      No hay eventos en <?= $meses[$month] ?> <?= $year ?>.
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach($rows as $e):
                  $si = strtotime($e['fecha_hora_inicio']);
                  $st = strtotime($e['fecha_hora_termino']);
                  $canManage = $isLiderNacional;
                ?>
                  <tr>
                    <td data-label="Inicio">
                      <?= $dias[date('w',$si)] . ' ' . date('d',$si) ?><br>
                      <?= date('H.i',$si) . ' horas' ?>
                    </td>
                    <td data-label="Término">
                      <?= $dias[date('w',$st)] . ' ' . date('d',$st) ?><br>
                      <?= date('H.i',$st) . ' horas' ?>
                    </td>
                    <td data-label="Evento"><?= htmlspecialchars($e['nombre_evento']) ?></td>
                    <td data-label="Equipo / Proyecto">
                      <?php
                        $raw   = $e['equipos'] ?: 'General';
                        $teams = array_filter(array_map('trim', explode(',', $raw)));
                      ?>
                      <ul class="equipos-list">
                        <?php foreach ($teams as $team): ?>
                          <li><?= htmlspecialchars($team) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </td>
                    <td data-label="Estado previo"><?= htmlspecialchars($e['nombre_estado_previo']) ?></td>
                    <td data-label="Asistencia"><?= (int)$e['cnt_presente'] ?> de <?= (int)$e['total_integrantes'] ?></td>
                    <td data-label="Estado final"><?= htmlspecialchars($e['nombre_estado_final']) ?></td>
                    <td class="actions" data-label="Acciones" style="white-space:nowrap">
                      <button title="Ver detalles" class="action-btn detail-btn"
                        data-fi="<?= $dias[date('w',$si)].' '.date('d',$si).' | '.date('H.i',$si).' horas' ?>"
                        data-ft="<?= $dias[date('w',$st)].' '.date('d',$st).' | '.date('H.i',$st).' horas' ?>"
                        data-nombre="<?= htmlspecialchars($e['nombre_evento']) ?>"
                        data-lugar="<?= htmlspecialchars($e['lugar'] ?? '') ?>"
                        data-encargado="<?= htmlspecialchars($e['encargado_nombre_completo'] ?? '') ?>"
                        data-descripcion="<?= htmlspecialchars($e['descripcion'] ?? '') ?>"
                        data-equipos="<?= htmlspecialchars($e['equipos'] ?: 'General') ?>"
                        data-previo="<?= htmlspecialchars($e['nombre_estado_previo'] ?? '') ?>"
                        data-tipo="<?= htmlspecialchars($e['nombre_tipo'] ?? '') ?>"
                        data-asist="<?= (int)$e['cnt_presente'].' de '.(int)$e['total_integrantes'] ?>"
                        data-observacion="<?= htmlspecialchars($e['observacion'] ?? '', ENT_QUOTES) ?>"
                        data-can-see-observacion="<?= $e['show_observacion'] ? '1' : '0' ?>"
                        data-final="<?= htmlspecialchars($e['nombre_estado_final'] ?? '') ?>"
                      ><i class="fas fa-circle-info"></i></button>

                      <button title="Asistencia" class="action-btn assist-btn"
                        data-id="<?= $e['id_evento'] ?>"
                        data-start="<?= $e['fecha_hora_inicio'] ?>">
                        <i class="fas fa-user-check"></i>
                      </button>

                      <?php if ($canManage): ?>
                        <button title="Editar" class="action-btn edit-btn"
                          data-id="<?= $e['id_evento'] ?>"
                          data-nombre="<?= htmlspecialchars($e['nombre_evento'] ?? '') ?>"
                          data-lugar="<?= htmlspecialchars($e['lugar'] ?? '') ?>"
                          data-encargado="<?= (int)$e['encargado_id'] ?>"
                          data-descripcion="<?= htmlspecialchars($e['descripcion'] ?? '') ?>"
                          data-observacion="<?= htmlspecialchars($e['observacion'] ?? '') ?>"
                          data-start="<?= $e['fecha_hora_inicio'] ?>"
                          data-end="<?= $e['fecha_hora_termino'] ?>"
                          data-previo="<?= $e['id_estado_previo'] ?>"
                          data-tipo="<?= $e['id_tipo'] ?>"
                          data-final="<?= $e['id_estado_final'] ?>"
                          data-equipos="<?= htmlspecialchars($e['equipo_ids']) ?>"
                        ><i class="fas fa-pen-to-square"></i></button>

                        <button title="Duplicar" class="action-btn copy-btn"
                          data-id="<?= $e['id_evento'] ?>"
                          data-nombre="<?= htmlspecialchars($e['nombre_evento'] ?? '') ?>"
                          data-lugar="<?= htmlspecialchars($e['lugar'] ?? '') ?>"
                          data-encargado="<?= (int)$e['encargado_id'] ?>"
                          data-descripcion="<?= htmlspecialchars($e['descripcion'] ?? '') ?>"
                          data-observacion="<?= htmlspecialchars($e['observacion'] ?? '') ?>"
                          data-start="<?= $e['fecha_hora_inicio'] ?>"
                          data-end="<?= $e['fecha_hora_termino'] ?>"
                          data-previo="<?= $e['id_estado_previo'] ?>"
                          data-tipo="<?= $e['id_tipo'] ?>"
                          data-final="<?= $e['id_estado_final'] ?>"
                          data-equipos="<?= htmlspecialchars($e['equipo_ids']) ?>"
                        ><i class="fas fa-clone"></i></button>

                        <button class="action-btn delete-btn"
                          data-id="<?= $e['id_evento'] ?>"
                          title="Eliminar">
                          <i class="fas fa-trash-can"></i>
                        </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div><!-- /eventos-card -->
    </main>
  </div><!-- /layout -->

  <!-- ═════════ utilidades ═════════ -->
  <script>
  document.getElementById('logout').addEventListener('click', async e => {
    e.preventDefault();
    const token = localStorage.getItem('token');
    if (!token) {
      // si no hay token, basta con redirigir
      localStorage.clear();
      return location.replace('login.html');
    }
    try {
      const res = await fetch('cerrar_sesion.php', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + token
        }
      });
      const data = await res.json();
      if (data.ok) {
        localStorage.clear();
        location.replace('login.html');
      } else {
        alert('No se pudo cerrar sesión: ' + (data.error||''));
      }
    } catch (err) {
      console.error(err);
      // aunque falle, limpiamos localStorage y redirigimos
      localStorage.clear();
      location.replace('login.html');
    }
  });
  </script>

  <script>
  /* === Toggle de sidebar en móviles === */
  (function(){
    const btn  = document.getElementById('toggle-sidebar');
    const side = document.getElementById('sidebar-eventos');
    if(btn && side){
      btn.addEventListener('click', ()=> side.classList.toggle('open'));
    }
  })();
  </script>

  <!-- Modal Detalles Evento -->
  <div id="modal-detalles" class="modal-overlay" style="display:none">
    <div class="modal-content card">
      <header class="card-header">
        <h2 id="md-nombre" class="card-title"></h2>
        <button class="modal-close"><i class="fas fa-times"></i></button>
      </header>
      <div class="card-body">
        <dl class="vertical-list">
          <div class="detail-item">
            <dt>Inicio</dt><dd id="md-fi"></dd>
          </div>
          <div class="detail-item">
            <dt>Término</dt><dd id="md-ft"></dd>
          </div>
          <div class="detail-item">
            <dt>Evento</dt><dd id="md-nombre2"></dd>
          </div>
          <div class="detail-item">
            <dt>Lugar</dt><dd id="md-lugar"></dd>
          </div>
          <div class="detail-item">
            <dt>Encargado</dt>
            <dd id="md-encargado"></dd>
          </div>
          <div class="detail-item">
            <dt>Descripción</dt><dd id="md-descripcion"></dd>
          </div>
          <div class="detail-item">
            <dt>Equipo/Proyecto</dt><dd id="md-equipos"></dd>
          </div>
          <div class="detail-item">
            <dt>Estado previo</dt><dd id="md-previo"></dd>
          </div>
          <div class="detail-item">
            <dt>Tipo</dt><dd id="md-tipo"></dd>
          </div>
          <div class="detail-item">
            <dt>Asistencia previa</dt><dd id="md-asist"></dd>
          </div>
          <div class="detail-item" id="row-observacion">
            <dt>Observación</dt><dd id="md-observacion"></dd>
          </div>
          <div class="detail-item">
            <dt>Estado final</dt><dd id="md-final"></dd>
          </div>
        </dl>
      </div>
    </div>
  </div>

  <?php
  // Pre-carga listas estáticas
  $estPrev = $pdo->query("SELECT id_estado_previo, nombre_estado_previo FROM estados_previos_eventos")->fetchAll();
  $tipos   = $pdo->query("SELECT id_tipo, nombre_tipo FROM tipos_evento")->fetchAll();
  $estFin  = $pdo->query("SELECT id_estado_final, nombre_estado_final FROM estados_finales_eventos")->fetchAll();
  // Tus propios equipos/proyectos (puedes usar $userTeams si quieres limitar)
  $allEq   = $pdo->query("SELECT id_equipo_proyecto, nombre_equipo_proyecto FROM equipos_proyectos")->fetchAll();
  ?>

  <?php
    $prevAsist = $pdo->query("
        SELECT id_estado_previo_asistencia id, nombre_estado_previo_asistencia nom
          FROM estados_previos_asistencia")->fetchAll(PDO::FETCH_ASSOC);
    $estAsist  = $pdo->query("
        SELECT id_estado_asistencia id, nombre_estado_asistencia nom
          FROM estados_asistencia")->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <script>
    const EST_PREV_AS = <?= json_encode($prevAsist) ?>;
    const EST_DEF_AS  = <?= json_encode($estAsist) ?>;
  </script>

  <div id="modal-edit" class="modal-overlay" style="display:none">
    <div class="modal-content card">
      <header class="card-header">
        <h2 class="card-title">Editar Evento</h2>
        <button class="modal-close"><i class="fas fa-times"></i></button>
      </header>
      <div class="card-body">
        <form id="form-edit-evento" novalidate>
          <input type="hidden" name="id_evento" id="edit-id">
          <div class="form-group">
            <label>Nombre:</label>
            <input type="text" name="nombre_evento" maxlength="100" id="edit-nombre" required>
            <small id="err-required-nombre" class="err-inline">* obligatorio</small>
            <small id="err-regex-nombre"   class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>
          <div class="form-group">
            <label>Lugar:</label>
            <input type="text" name="lugar" maxlength="100" id="edit-lugar">
            <small id="err-regex-lugar" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>
          <div class="form-group">
            <label>Descripción:</label>
            <textarea name="descripcion" maxlength="500" id="edit-descripcion"></textarea>
            <small id="err-regex-descripcion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>
          <div class="form-group">
            <label>Observación:</label>
            <textarea name="observacion" maxlength="500" id="edit-observacion"></textarea>
            <small id="err-regex-observacion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>
          <div class="form-group">
            <label>Fecha y hora inicio:</label>
            <input type="datetime-local" name="fecha_hora_inicio" id="edit-start" required min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="err-required-start" class="err-inline">* fecha y hora requeridas</small>
            <small id="err-range-start" class="err-inline">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
          </div>
          <div class="form-group">
            <label>Fecha y hora término:</label>
            <input type="datetime-local" name="fecha_hora_termino" id="edit-end"  min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="err-required-end" class="err-inline">* fecha y hora requeridas</small>
            <small id="err-range-end" class="err-inline">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
              <!-- Mensaje de error inline -->
              <div id="end-error" class="input-error" style="display:none; color:#dc3545; font-size:0.875rem; margin-top:0.25rem;">
                * La fecha y hora de término debe ser igual o posterior al inicio.
              </div>
              <div id="edit-mindiff-error"  class="input-error" style="display:none">
                * Debe haber al menos 15 min entre inicio y término.
              </div>
          </div>
          <div class="form-group">
            <label>Estado previo:</label>
            <select name="id_estado_previo" id="edit-previo">
              <?php foreach($estPrev as $v): ?>
                <option value="<?= $v['id_estado_previo'] ?>"><?= htmlspecialchars($v['nombre_estado_previo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Tipo:</label>
            <select name="id_tipo" id="edit-tipo">
              <?php foreach($tipos as $t): ?>
                <option value="<?= $t['id_tipo'] ?>"><?= htmlspecialchars($t['nombre_tipo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Estado final:</label>
            <select name="id_estado_final" id="edit-final">
              <?php foreach($estFin as $f): ?>
                <option value="<?= $f['id_estado_final'] ?>"><?= htmlspecialchars($f['nombre_estado_final']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Equipos/Proyectos:</label>
            <div class="checkboxes-grid">
              <!-- Opción General -->
              <label class="checkbox-item">
                <input 
                  type="checkbox" 
                  id="edit-general" 
                  name="id_equipo_proyecto[]" 
                  value=""
                >
                General
              </label>

              <!-- Tus proyectos -->
              <?php foreach($allProjects as $p): ?>
                <label class="checkbox-item">
                  <input 
                    type="checkbox"
                    class="edit-project-chk"
                    name="id_equipo_proyecto[]"
                    value="<?= $p['id_equipo_proyecto'] ?>"
                  >
                  <?= htmlspecialchars($p['nombre_equipo_proyecto']) ?>
                </label>
              <?php endforeach; ?>
              <small id="projects-error" class="err-inline proj-error">
                * Debes marcar al menos “General” o un equipo/proyecto.
              </small>
            </div>
          </div>
          <div class="form-group">
            <label>Encargado:</label>
            <select id="edit-encargado" name="encargado">
              <option value="">— sin encargado —</option>
              <?php foreach ($leaders as $ldr): ?>
                <option
                  value="<?= $ldr['id_usuario'] ?>"
                  data-projects="<?= trim($ldr['project_ids'], ',') ?>"
                >
                  <?= htmlspecialchars($ldr['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <footer class="card-footer">
        <button type="button" id="btn-save-evento" class="btn btn-primary">Guardar cambios</button>
      </footer>
    </div>
  </div>

  <!-- ═════════ Modal Duplicar Evento ═════════ -->
  <div id="modal-copy" class="modal-overlay" style="display:none">
    <div class="modal-content card">
      <header class="card-header">
        <h2 class="card-title">Duplicar Evento</h2>
        <button class="modal-close"><i class="fas fa-times"></i></button>
      </header>

      <div class="card-body">
        <form id="form-copy-evento" novalidate>
          <!-- Id oculto por si algún día lo necesitas (vacío) -->
          <input type="hidden" name="id_evento_original" id="copy-orig-id">

          <!-- 1) Nombre -->
          <div class="form-group">
            <label>Nombre:</label>
            <input type="text" name="nombre_evento" maxlength="100" id="copy-nombre" required>
            <small id="copy-err-required-nombre" class="err-inline">* obligatorio</small>
            <small id="copy-err-regex-nombre"   class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 2) Lugar -->
          <div class="form-group">
            <label>Lugar:</label>
            <input type="text" name="lugar" maxlength="100" id="copy-lugar">
            <small id="copy-err-regex-lugar" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 3) Descripción -->
          <div class="form-group">
            <label>Descripción:</label>
            <textarea name="descripcion" maxlength="500" id="copy-descripcion"></textarea>
            <small id="copy-err-regex-descripcion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 4) Observación -->
          <div class="form-group">
            <label>Observación:</label>
            <textarea name="observacion" maxlength="500" id="copy-observacion"></textarea>
            <small id="copy-err-regex-observacion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 5) Fechas -->
          <div class="form-group">
            <label>Fecha y hora inicio:</label>
            <input type="datetime-local" name="fecha_hora_inicio" id="copy-start" required  min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="copy-err-required-start" class="err-inline">* fecha y hora requeridas</small>
            <small id="copy-err-range-start" class="err-inline" style="display:none">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
          </div>
          <div class="form-group">
            <label>Fecha y hora término:</label>
            <input type="datetime-local" name="fecha_hora_termino" id="copy-end" min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="copy-err-required-end" class="err-inline">* fecha y hora requeridas</small>
            <small id="copy-err-range-end" class="err-inline" style="display:none">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
            <div id="copy-end-error" class="input-error">
              * La fecha y hora de término debe ser igual o posterior al inicio.
            </div>
            <div id="copy-mindiff-error"  class="input-error" style="display:none">
              * Debe haber al menos 15 min entre inicio y término.
            </div>
          </div>

          <!-- 6) Selects Estado previo / Tipo / Estado final -->
          <div class="form-group">
            <label>Estado previo:</label>
            <select name="id_estado_previo" id="copy-previo">
              <?php foreach($estPrev as $v): ?>
                <option value="<?= $v['id_estado_previo'] ?>"><?= htmlspecialchars($v['nombre_estado_previo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Tipo:</label>
            <select name="id_tipo" id="copy-tipo">
              <?php foreach($tipos as $t): ?>
                <option value="<?= $t['id_tipo'] ?>"><?= htmlspecialchars($t['nombre_tipo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Estado final:</label>
            <select name="id_estado_final" id="copy-final">
              <?php foreach($estFin as $f): ?>
                <option value="<?= $f['id_estado_final'] ?>"><?= htmlspecialchars($f['nombre_estado_final']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 7) Equipos / Proyectos + “General” -->
          <div class="form-group">
            <label>Equipos/Proyectos:</label>
            <div class="checkboxes-grid">
              <!-- General -->
              <label class="checkbox-item">
                <input type="checkbox" id="copy-general"
                      name="id_equipo_proyecto[]" value="">
                General
              </label>
              <!-- Listado dinámico -->
              <?php foreach($allEq as $p): ?>
                <label class="checkbox-item">
                  <input type="checkbox"
                        class="copy-project-chk"
                        name="id_equipo_proyecto[]"
                        value="<?= $p['id_equipo_proyecto'] ?>">
                  <?= htmlspecialchars($p['nombre_equipo_proyecto']) ?>
                </label>
              <?php endforeach; ?>
              <small id="copy-projects-error" class="err-inline proj-error">
                * Debes marcar “General” o al menos un equipo/proyecto.
              </small>
            </div>
          </div>

          <!-- 8) Encargado -->
          <div class="form-group">
            <label>Encargado:</label>
            <select id="copy-encargado" name="encargado">
              <option value="">— sin encargado —</option>
              <?php foreach ($leaders as $ldr): ?>
                <option value="<?= $ldr['id_usuario'] ?>"
                        data-projects="<?= trim($ldr['project_ids'], ',') ?>">
                  <?= htmlspecialchars($ldr['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <footer class="card-footer bg-transparent border-0 p-0 mt-3">
        <button type="button" id="btn-create-evento" class="btn btn-primary">
          Crear evento
        </button>
      </footer>
    </div>
  </div>

  <!-- ═════════ Modal Crear Evento ═════════ -->
  <div id="modal-create" class="modal-overlay" style="display:none">
    <div class="modal-content card">
      <header class="card-header">
        <h2 class="card-title">Crear Evento</h2>
        <button class="modal-close"><i class="fas fa-times"></i></button>
      </header>

      <div class="card-body">
        <form id="form-create-evento" novalidate>
          <!-- 1) Nombre -->
          <div class="form-group">
            <label>Nombre:</label>
            <input type="text" name="nombre_evento" maxlength="100" id="create-nombre" required>
            <small id="create-err-required-nombre" class="err-inline">* obligatorio</small>
            <small id="create-err-regex-nombre"   class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 2) Lugar -->
          <div class="form-group">
            <label>Lugar:</label>
            <input type="text" name="lugar" maxlength="100" id="create-lugar">
            <small id="create-err-regex-lugar" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 3) Descripción -->
          <div class="form-group">
            <label>Descripción:</label>
            <textarea name="descripcion" maxlength="500" id="create-descripcion"></textarea>
            <small id="create-err-regex-descripcion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 4) Observación -->
          <div class="form-group">
            <label>Observación:</label>
            <textarea name="observacion" maxlength="500" id="create-observacion"></textarea>
            <small id="create-err-regex-observacion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 5) Fechas -->
          <div class="form-group">
            <label>Fecha y hora inicio:</label>
            <input type="datetime-local" name="fecha_hora_inicio" id="create-start" required min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="create-err-required-start" class="err-inline">* fecha y hora requeridas</small>
            <div id="create-start-error" class="input-error" style="display:none">
              * La fecha de inicio debe estar entre 1970 y 2037.
            </div>
          </div>
          <div class="form-group">
            <label>Fecha y hora término:</label>
            <input type="datetime-local" name="fecha_hora_termino" id="create-end" min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="create-err-required-end" class="err-inline">* fecha y hora requeridas</small>
            <div id="create-end-error" class="input-error" style="display:none">
              * La fecha y hora de término debe ser igual o posterior al inicio.
            </div>
            <div id="create-mindiff-error"  class="input-error" style="display:none">
              * Debe haber al menos 15 min entre inicio y término.
            </div>
          </div>

          <!-- 6) Selects Estado previo / Tipo / Estado final -->
          <div class="form-group">
            <label>Estado previo:</label>
            <select name="id_estado_previo" id="create-previo">
              <?php foreach($estPrev as $v): ?>
                <option value="<?= $v['id_estado_previo'] ?>"><?= htmlspecialchars($v['nombre_estado_previo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Tipo:</label>
            <select name="id_tipo" id="create-tipo">
              <?php foreach($tipos as $t): ?>
                <option value="<?= $t['id_tipo'] ?>"><?= htmlspecialchars($t['nombre_tipo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Estado final:</label>
            <select name="id_estado_final" id="create-final">
              <?php foreach($estFin as $f): ?>
                <option value="<?= $f['id_estado_final'] ?>"><?= htmlspecialchars($f['nombre_estado_final']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 7) Equipos / Proyectos + “General” -->
          <div class="form-group">
            <label>Equipos/Proyectos:</label>
            <div class="checkboxes-grid">
              <!-- General -->
              <label class="checkbox-item">
                <input type="checkbox" id="create-general"
                      name="id_equipo_proyecto[]" value="">
                General
              </label>
              <!-- Listado dinámico -->
              <?php foreach($allEq as $p): ?>
                <label class="checkbox-item">
                  <input type="checkbox"
                        class="create-project-chk"
                        name="id_equipo_proyecto[]"
                        value="<?= $p['id_equipo_proyecto'] ?>">
                  <?= htmlspecialchars($p['nombre_equipo_proyecto']) ?>
                </label>
              <?php endforeach; ?>
              <small id="create-projects-error" class="err-inline proj-error">
                * Debes marcar “General” o al menos un equipo/proyecto.
              </small>
            </div>
          </div>

          <!-- 8) Encargado -->
          <div class="form-group">
            <label>Encargado:</label>
            <select id="create-encargado" name="encargado">
              <option value="">— sin encargado —</option>
              <?php foreach ($leaders as $ldr): ?>
                <option value="<?= $ldr['id_usuario'] ?>"
                        data-projects="<?= trim($ldr['project_ids'], ',') ?>">
                  <?= htmlspecialchars($ldr['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <footer class="card-footer bg-transparent border-0 p-0 mt-3">
        <button type="button" id="btn-store-evento" class="btn btn-success w-100">
          Crear evento
        </button>
      </footer>
    </div>
  </div>

  <!-- ═════════ Modal Solicitar Evento ═════════ -->
  <div id="modal-request" class="modal-overlay" style="display:none">
    <div class="modal-content card">
      <header class="card-header">
        <h2 class="card-title">Solicitar Evento</h2>
        <button class="modal-close"><i class="fas fa-times"></i></button>
      </header>

      <div class="card-body">
        <form id="form-request-evento" novalidate>
          <!-- 0) Hidden defaults -->
          <input type="hidden" name="id_estado_previo" value="3"><!-- En espera -->
          <input type="hidden" name="id_estado_final"  value="3"><!-- En pausa  -->

          <!-- 1) Nombre -->
          <div class="form-group">
            <label>Nombre:</label>
            <input type="text" maxlength="100" name="nombre_evento" id="req-nombre" required>
            <small id="req-err-required-nombre" class="err-inline">* obligatorio</small>
            <small id="req-err-regex-nombre"   class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 2) Lugar -->
          <div class="form-group">
            <label>Lugar:</label>
            <input type="text" maxlength="100" name="lugar" id="req-lugar">
            <small id="req-err-regex-lugar" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 3) Descripción -->
          <div class="form-group">
            <label>Descripción:</label>
            <textarea name="descripcion" maxlength="500" id="req-descripcion"></textarea>
            <small id="req-err-regex-descripcion" class="err-inline">* Solo letras, números, espacios, y . , # ¿ ¡ ! ? ( ) / -</small>
          </div>

          <!-- 4) Fechas -->
          <div class="form-group">
            <label>Fecha y hora inicio:</label>
            <input type="datetime-local" name="fecha_hora_inicio" id="req-start" required min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="req-err-required-start" class="err-inline">* fecha y hora requeridas</small>
            <small id="req-err-range-start" class="err-inline">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
          </div>
          <div class="form-group">
            <label>Fecha y hora término:</label>
            <input type="datetime-local" name="fecha_hora_termino" id="req-end" min="1970-01-01T00:00" max="2037-12-31T23:59" pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}" title="YYYY-MM-DDTHH:MM (entre 1970 y 2037)">
            <small id="req-err-required-end" class="err-inline">* fecha y hora requeridas</small>
            <small id="req-err-range-end" class="err-inline">
              * Fecha fuera del rango permitido (1970-2037)
            </small>
            <div id="req-end-error" class="input-error" style="display:none">
              * La fecha y hora de término debe ser igual o posterior al inicio.
            </div>
            <div id="req-mindiff-error"  class="input-error" style="display:none">
              * Debe haber al menos 15 min entre inicio y término.
            </div>
          </div>

          <!-- 5) Tipo -->
          <div class="form-group">
            <label>Tipo:</label>
            <select name="id_tipo" id="req-tipo">
              <?php foreach($tipos as $t): ?>
                <option value="<?= $t['id_tipo'] ?>"><?= htmlspecialchars($t['nombre_tipo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 6) Equipos / Proyectos + “General” -->
          <div class="form-group">
            <label>Equipos/Proyectos:</label>
            <div class="checkboxes-grid">
              <label class="checkbox-item">
                <input type="checkbox" id="req-general"
                      name="id_equipo_proyecto[]" value="">
                General
              </label>
              <?php foreach($allEq as $p): ?>
                <label class="checkbox-item">
                  <input type="checkbox"
                        class="req-project-chk"
                        name="id_equipo_proyecto[]"
                        value="<?= $p['id_equipo_proyecto'] ?>">
                  <?= htmlspecialchars($p['nombre_equipo_proyecto']) ?>
                </label>
              <?php endforeach; ?>
              <small id="req-projects-error" class="err-inline proj-error">
                * Debes marcar “General” o al menos un equipo/proyecto.
              </small>
            </div>
          </div>

          <!-- 7) Encargado -->
          <div class="form-group">
            <label>Encargado:</label>
            <select id="req-encargado" name="encargado">
              <option value="">— sin encargado —</option>
              <?php foreach ($leaders as $ldr): ?>
                <option value="<?= $ldr['id_usuario'] ?>"
                        data-projects="<?= trim($ldr['project_ids'], ',') ?>">
                  <?= htmlspecialchars($ldr['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <footer class="card-footer bg-transparent border-0 p-0 mt-3">
        <button type="button" id="btn-send-request" class="btn btn-primary w-100">
          Solicitar evento
        </button>
      </footer>
    </div>
  </div>

  <div id="modal-asist" class="modal-overlay" style="display:none">
    <div class="modal-content card" style="width:700px;max-height:90vh;overflow:hidden">
        <header class="card-header">
          <h2 class="card-title">Asistencia</h2>
          <button class="modal-close"><i class="fas fa-times"></i></button>
        </header>
        <div class="card-body" id="asist-body" style="overflow-y:auto"></div>
    </div>
  </div>

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script src="eventos.js"></script>
</body>
</html>
