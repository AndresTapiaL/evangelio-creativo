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
    nav {
      background: #f0f0f0;
      padding: 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
    }
    nav .menu {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: center;
    }
    nav a {
      text-decoration: none;
      color: #222;
      font-weight: bold;
    }
    .perfil {
      display: flex;
      align-items: center;
      gap: .5rem;
    }
    .perfil img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      object-fit: cover;
    }
    body {
      font-family: sans-serif;
      background: #f6f6f6;
      margin: 0;
      padding: 2rem;
    }
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
      overflow-wrap: anywhere;    /* apoyo en navegadores modernos */
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

    /* Scrollbar personalizado suave (WebKit) */
    .table-responsive::-webkit-scrollbar {
    height: 8px;
    }
    .table-responsive::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.2);
    border-radius: 4px;
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

    .month-nav {
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 1.5rem 0;
      font-size: 1.1rem;
    }
    .month-nav .nav-arrow {
      text-decoration: none;
      color: #444;
      font-weight: bold;
      padding: 0 .75rem;
      font-size: 1.4rem;
    }
    .month-nav .nav-title {
      flex: 1;
      text-align: center;
      font-weight: bold;
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

    .table-responsive th:nth-child(1),
    .table-responsive td:nth-child(1) { /* “Evento” es la 3ª columna */
      width: 9%;
    }

    .table-responsive th:nth-child(2),
    .table-responsive td:nth-child(2) {
      width: 9%;
    }

    .table-responsive th:nth-child(3),
    .table-responsive td:nth-child(3) {
      width: 14%;
    }

    .table-responsive th:nth-child(4),
    .table-responsive td:nth-child(4) {
      width: 12.5%;
    }

    .table-responsive th:nth-child(5),
    .table-responsive td:nth-child(5) {
      width: 10.5%;
    }

    .table-responsive th:nth-child(6),
    .table-responsive td:nth-child(6) {
      width: 10%;
    }

    .table-responsive th:nth-child(7),
    .table-responsive td:nth-child(7) {
      width: 9%;
    }

    .table-responsive th:nth-child(8),
    .table-responsive td:nth-child(8) {
      width: 13.5%;
    }

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

  <!-- ░░░░ CONTENIDO PRINCIPAL ░░░░ -->
  <main>
    <div class="container" style="display:grid; grid-template-columns:200px 1fr; gap:1rem">
    <!-- 4.1) Panel izquierdo -->
    <?php
      // ─── Armar lista de filtros para el sidebar ───
      $sidebarOpts = [
        'calendario' => 'Calendario',
        'general'    => 'Eventos Generales',
      ];

      if ($isLiderNacional) {
          // Liderazgo nacional ve TODOS los proyectos
          $projectsForSidebar = $allProjects;
      } else {
          // Usuarios “normales” solo ven sus equipos/proyectos
          $projectsForSidebar = $userTeams;
      }

      // Convertir a [id => nombre]
      $sidebarOpts += array_column(
        $projectsForSidebar,
        'nombre_equipo_proyecto',
        'id_equipo_proyecto'
      );
    ?>
    <aside style="background:#fafafa; padding:1rem; border-radius:6px">
      <?php foreach ($sidebarOpts as $key => $label): ?>
        <form method="POST" style="margin:0 0 .5rem">
          <input type="hidden" name="mes"      value="<?=htmlspecialchars($mesParam)?>">
          <input type="hidden" name="busqueda" value="<?=htmlspecialchars($busqueda)?>">
          <button
            name="filtro"
            value="<?=htmlspecialchars($key)?>"
            style="
              width:100%;
              text-align:left;
              background:<?= $filtro===$key ? '#e0e0e0':'transparent'?>;
              font-weight:<?= $filtro===$key ? 'bold':'normal'?>;
              border:none; padding:.5rem; cursor:pointer;
            "
          >
            <?=htmlspecialchars($label)?>
          </button>
        </form>
      <?php endforeach; ?>
    </aside>

    <div>
      <div class="d-flex justify-content-end mb-2">
        <form class="d-flex me-auto" method="POST" action="eventos.php" id="form-search">
          <input
            type="text"
            name="busqueda"
            id="search-input"
            maxlength="200"
            placeholder="Buscar..."
            value="<?= htmlspecialchars($busqueda) ?>"
            style="padding:.4rem .6rem; border:1px solid #ccc; border-radius:4px;"
          >
          <button type="submit" id="btn-search" class="btn btn-outline-secondary ms-2">
            🔍 Buscar
          </button>
        </form>
        <small id="search-error" class="err-inline" style="display:none;">
          * Solo letras, números, espacios, saltos de línea y . , # ¿ ¡ ! ? ( ) / -
        </small>

        <?php
          // Rango de meses por defecto: mismo mes en start y end
          $mesStart = $_REQUEST['mesStart'] ?? $mesParam;
          $mesEnd   = $_REQUEST['mesEnd']   ?? $mesParam;
        ?>

        <form id="form-download" method="GET" action="export.php" class="d-flex align-items-center me-2">
          <input type="hidden" name="filtro"    value="<?= htmlspecialchars($filtro) ?>">
          <input type="hidden" name="busqueda"  value="<?= htmlspecialchars($busqueda) ?>">
          <input type="hidden" name="aprobados" value="<?= $showAprob ? '1' : '0' ?>">

          <div style="position:relative; margin-right:.5rem">
            <input 
              type="month" 
              name="mesStart" 
              id="mesStart"
              min="1970-01"
              max="2037-12"
              pattern="\d{4}-\d{2}"
              title="AAAA-MM entre 1970-01 y 2037-12"
              value="<?= htmlspecialchars($mesStart) ?>"
              class="form-control form-control-sm"
            >
            <small id="mesStart-error" class="err-inline" style="display:none; position:absolute; top:100%; left:0;">
              * Fecha de inicio requerida
            </small>
          </div>

          <div style="position:relative; margin-right:.5rem">
            <input 
              type="month" 
              name="mesEnd" 
              id="mesEnd"
              min="1970-01"
              max="2037-12"
              pattern="\d{4}-\d{2}"
              title="AAAA-MM entre 1970-01 y 2037-12"
              value="<?= htmlspecialchars($mesEnd) ?>"
              class="form-control form-control-sm"
            >
            <small id="mesEnd-error" class="err-inline" style="display:none; position:absolute; top:100%; left:0;">
              * Fecha de término requerida
            </small>
          </div>

          <small id="dateOrder-error" class="err-inline me-2" style="display:none;">
            * La fecha de término debe ser mayor o igual a la de inicio
          </small>
          <small id="dateRange-error" class="err-inline me-2" style="display:none;">
            * El rango no puede exceder 2 años
          </small>

          <select name="format" class="form-select form-select-sm me-2">
            <option value="excel">Excel</option>
            <option value="pdf">PDF</option>
          </select>

          <button type="submit" id="btn-download" class="btn btn-success btn-sm">
            ↓ Descargar
          </button>
        </form>

        <?php if ($canCreate): ?>
          <button id="btn-new-event" class="btn btn-success">
            <i class="fas fa-plus"></i> Crear evento
          </button>
        <?php endif; ?>

        <?php if ($canRequest): ?>
          <button id="btn-request-event"
                  class="btn btn-primary <?= $canCreate ? 'ms-2' : '' ?>">
            <i class="fas fa-envelope-open-text"></i> Solicitar evento
          </button>
        <?php endif; ?>
      </div>

      <?php if ($isLiderNacional): ?>
        <button
          id="btn-aprobar-eventos"
          class="btn btn-warning <?= $showAprob ? 'active' : '' ?>"
          style="position: relative; margin-right: .5rem;"
        >
          Aprobar eventos
          <?php if ($pendingCount > 0): ?>
            <span
              style="
                position: absolute;
                top: -6px; right: -6px;
                background: red;
                color: white;
                border-radius: 50%;
                padding: 2px 6px;
                font-size: 0.75rem;
              "
            >
              <?= $pendingCount ?>
            </span>
          <?php endif; ?>
        </button>
      <?php endif; ?>

      <nav class="month-nav">
        <?php if ($prevMonth !== null): ?>
          <form method="POST" style="display:inline-block">
            <input type="hidden" name="filtro"   value="<?= htmlspecialchars($filtro) ?>">
            <input type="hidden" name="mes"      value="<?= $prevMonth ?>">
            <input type="hidden" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>">
            <button type="submit" class="nav-arrow">&larr;</button>
          </form>
        <?php endif; ?>

        <span class="nav-title"><?= $meses[$month] ?> <?= $year ?></span>

        <?php if ($nextMonth !== null): ?>
          <form method="POST" style="display:inline-block">
            <input type="hidden" name="filtro"   value="<?= htmlspecialchars($filtro) ?>">
            <input type="hidden" name="mes"      value="<?= $nextMonth ?>">
            <input type="hidden" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>">
            <button type="submit" class="nav-arrow">&rarr;</button>
          </form>
        <?php endif; ?>
      </nav>

      <!-- 4.2) Tabla de este mes -->
      <section>
        <?php if (empty($rows)): ?>
          <p>No hay eventos en <?= $meses[$month] ?> <?= $year ?>.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Inicio</th><th>Término</th><th>Evento</th>
                  <th>Equipo/Proyecto</th><th>Estado previo</th>
                  <th>Asist. previa</th><th>Estado final</th><th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($rows as $e):
                  $si = strtotime($e['fecha_hora_inicio']);
                  $st = strtotime($e['fecha_hora_termino']);
                ?>
                <?php
                  /* ── Puede gestionar (Editar / Duplicar / Eliminar) ── */
                  $canManage = $isLiderNacional;
                ?>
                <tr>
                  <td>
                    <?= $dias[date('w',$si)] . ' ' . date('d',$si) ?><br>
                    <?= date('H.i',$si) . ' horas' ?>
                  </td>
                  <td>
                    <?= $dias[date('w',$st)] . ' ' . date('d',$st) ?><br>
                    <?= date('H.i',$st) . ' horas' ?>
                  </td>
                  <td><?= htmlspecialchars($e['nombre_evento']) ?></td>
                  <td>
                    <?php
                      // 1) Preparamos siempre un array, sustituyendo cadena vacía por 'General'
                      $raw   = $e['equipos'] ?: 'General';
                      $teams = array_filter(array_map('trim', explode(',', $raw)));
                    ?>
                    <ul class="equipos-list">
                      <?php foreach ($teams as $team): ?>
                        <li><?= htmlspecialchars($team) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </td>
                  <td><?= htmlspecialchars($e['nombre_estado_previo']) ?></td>
                  <td>
                    <?= (int)$e['cnt_presente'] ?> de <?= (int)$e['total_integrantes'] ?>
                  </td>
                  <td><?= htmlspecialchars($e['nombre_estado_final']) ?></td>
                  <td class="actions" style="white-space:nowrap">

                    <!-- Ver detalles: siempre -->
                    <button
                      title="Ver detalles"
                      class="action-btn detail-btn"
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
                      data-observacion="<?= 
                          htmlspecialchars($e['observacion'] ?? '', ENT_QUOTES)
                      ?>"
                      data-can-see-observacion="<?= $e['show_observacion'] ? '1' : '0' ?>"
                      data-final="<?= htmlspecialchars($e['nombre_estado_final'] ?? '') ?>"
                    >
                      <i class="fas fa-eye"></i>
                    </button>

                    <!-- Notificar: siempre -->
                    <button title="Notificar" class="action-btn notify-btn"
                            data-id="<?= $e['id_evento'] ?>">
                      <i class="fas fa-bell"></i>
                    </button>

                    <?php if ($canManage): ?>
                      <!-- Editar -->
                      <button
                        title="Editar" class="action-btn edit-btn"
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
                      >
                        <i class="fas fa-pen"></i>
                      </button>

                      <!-- 3) Duplicar -->
                      <button
                        title="Duplicar"
                        class="action-btn copy-btn"
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
                      >
                        <i class="fas fa-copy"></i>
                      </button>

                      <!-- 4) Eliminar -->
                      <button
                        class="action-btn delete-btn"
                        data-id="<?= $e['id_evento'] ?>"
                        title="Eliminar">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>
    </div>
  </main>

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

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script src="eventos.js"></script>
</body>
</html>
