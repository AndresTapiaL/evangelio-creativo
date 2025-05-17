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

// 2.4) Validar: solo “calendario”, “general” o un ID en tu lista
if ($filtro !== 'calendario' && $filtro !== 'general') {
    $fid = (int)$filtro;
    if (!in_array($fid, $userTeamIds, true)) {
        header('Location: eventos.php?filtro=calendario');
        exit;
    }
    $filtro = $fid;
}

// ─── 1.2) Calcular mes anterior y siguiente para el nav
$ts        = strtotime("$year-$month-01");
$prevMonth = date('Y-m', strtotime('-1 month', $ts));
$nextMonth = date('Y-m', strtotime('+1 month', $ts));

// ─── 1.3) Mapeos de meses y días
$meses = [
  '01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
  '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
  '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'
];
$dias = ['0'=>'Domingo','1'=>'Lunes','2'=>'Martes','3'=>'Miércoles',
         '4'=>'Jueves','5'=>'Viernes','6'=>'Sábado'];

// 3.1) Base del WHERE (ya tienes $where y $params)
$where[]          = "YEAR(e.fecha_hora_inicio) = :year";
$where[]          = "MONTH(e.fecha_hora_inicio) = :month";
$params['year']   = $year;
$params['month']  = $month;

// Calendario: todo, no agregamos condición
if ($filtro === 'calendario') {
    // => no hacemos nada, trae todos los eventos
}
// General: solo eventos con es_general = 1
elseif ($filtro === 'general') {
    $where[] = "e.es_general = 1";
}
// Equipo específico: solo eventos asociados a ese equipo
else {  // ya validaste arriba que sea un ID de equipo válido
    $where[]        = "ep.id_equipo_proyecto = :filtro";
    $params['filtro'] = $filtro;
}

// 3.2) Query con joins + recuento de asistencia y total de integrantes
$sql = "
  SELECT
    e.id_evento,
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
    COALESCE(tu.total_integrantes, 0) AS total_integrantes

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

  ".(count($where) ? 'WHERE '.implode(' AND ', $where) : '')."

  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
";
$stmtEv = $pdo->prepare($sql);
$stmtEv->execute($params);

$rows = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

/* ─── Líderes y coordinadores disponibles ─── */
/* ─── líderes y coordinadores ─── */
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
  <nav>
    <div class="menu">
      <a href="home.php">Inicio</a>
      <a href="eventos.php">Eventos</a>
      <a href="integrantes.php">Integrantes</a>
      <a href="ver_mis_datos.php">Mis datos</a>
      <a href="reportes.php">Reportes</a>
      <a href="admision.php">Admisión</a>
      <a href="#"><i class="fas fa-bell"></i></a>
    </div>
    <div class="perfil">
      <span id="nombre-usuario">
        <?= htmlspecialchars($navUser['nombres']) ?>
      </span>
      <img
        id="foto-perfil-nav"
        src="<?= htmlspecialchars($navUser['foto_perfil']) ?>"
        alt="Foto de <?= htmlspecialchars($navUser['nombres']) ?>">
      <a href="#" id="logout" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <!-- ░░░░ CONTENIDO PRINCIPAL ░░░░ -->
  <main>
    <div class="container" style="display:grid; grid-template-columns:200px 1fr; gap:1rem">
    <!-- 4.1) Panel izquierdo -->
    <aside style="background:#fafafa; padding:1rem; border-radius:6px">
      <!-- General wrapper para todos los forms -->
      <?php foreach ([
          'calendario' => 'Calendario',
          'general'    => 'Eventos Generales'
        ] + array_column($userTeams, 'nombre_equipo_proyecto', 'id_equipo_proyecto')
          as $key => $label): ?>

        <form method="POST" style="margin:0 0 .5rem">
          <!-- conserva el mes actual -->
          <input type="hidden" name="mes"   value="<?= htmlspecialchars($mesParam) ?>">
          <button 
            name="filtro" 
            value="<?= htmlspecialchars($key) ?>"
            style="width:100%; text-align:left; 
                  background:<?= $filtro===$key?'#e0e0e0':'transparent' ?>;
                  font-weight:<?= $filtro===$key?'bold':'normal' ?>;
                  border:none; padding:.5rem; cursor:pointer;">
            <?= htmlspecialchars($label) ?>
          </button>
        </form>

      <?php endforeach; ?>
    </aside>

    <?php
    // Calcula mes anterior y siguiente
    $ts        = strtotime("$year-$month-01");
    $prevMonth = date('Y-m', strtotime('-1 month', $ts));
    $nextMonth = date('Y-m', strtotime('+1 month', $ts));
    $meses     = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
                  '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
                  '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
    ?>

    <div>
      <nav class="month-nav">
        <!-- Mes anterior -->
        <form method="POST" style="display:inline-block">
          <input type="hidden" name="filtro" value="<?=htmlspecialchars($filtro)?>">
          <input type="hidden" name="mes"    value="<?= $prevMonth ?>">
          <button type="submit" class="nav-arrow">&larr;</button>
        </form>

        <span class="nav-title"><?= $meses[$month] ?> <?= $year ?></span>

        <!-- Mes siguiente -->
        <form method="POST" style="display:inline-block">
          <input type="hidden" name="filtro" value="<?=htmlspecialchars($filtro)?>">
          <input type="hidden" name="mes"    value="<?= $nextMonth ?>">
          <button type="submit" class="nav-arrow">&rarr;</button>
        </form>
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
                    <!-- 1) Ver detalles -->
                    <button
                      title="Ver detalles"
                      class="action-btn detail-btn"
                      data-fi="<?= $dias[date('w',$si)] . ' ' . date('d',$si) . ' | ' . date('H.i',$si) . ' horas' ?>"
                      data-ft="<?= $dias[date('w',$si)] . ' ' . date('d',$si) . ' | ' . date('H.i',$si) . ' horas' ?>"
                      data-nombre="<?= htmlspecialchars($e['nombre_evento']) ?>"
                      data-lugar="<?= htmlspecialchars($e['lugar'] ?? '') ?>"
                      data-encargado="<?= htmlspecialchars($e['encargado_nombre_completo'] ?? '') ?>"
                      data-descripcion="<?= htmlspecialchars($e['descripcion'] ?? '') ?>"
                      data-equipos="<?= htmlspecialchars($e['equipos'] ?: 'General') ?>"
                      data-previo="<?= htmlspecialchars($e['nombre_estado_previo'] ?? '') ?>"
                      data-tipo="<?= htmlspecialchars($e['nombre_tipo'] ?? '') ?>"
                      data-asist="<?= (int)$e['cnt_presente'] . ' de ' . (int)$e['total_integrantes'] ?>"
                      data-observacion="<?= htmlspecialchars($e['observacion'] ?? '') ?>"
                      data-final="<?= htmlspecialchars($e['nombre_estado_final'] ?? '') ?>"
                    >
                      <i class="fas fa-eye"></i>
                    </button>

                    <!-- 2) Editar -->
                    <button
                      title="Editar"
                      class="action-btn edit-btn"
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
                    <button title="Duplicar" class="action-btn copy-btn">
                      <i class="fas fa-copy"></i>
                    </button>

                    <!-- 4) Eliminar -->
                    <button title="Eliminar" class="action-btn delete-btn">
                      <i class="fas fa-trash"></i>
                    </button>

                    <!-- 5) Notificar -->
                    <button title="Notificar" class="action-btn notify-btn">
                      <i class="fas fa-bell"></i>
                    </button>
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
    document.getElementById('logout').addEventListener('click', e => {
      e.preventDefault();
      const t = localStorage.getItem('token');
      fetch('cerrar_sesion.php', {
        headers: { 'Authorization': 'Bearer ' + t }
      }).finally(() => {
        localStorage.clear();
        location.replace('login.html');
      });
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
          <div class="detail-item">
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
        <form id="form-edit-evento">
          <input type="hidden" name="id_evento" id="edit-id">
          <div class="form-group">
            <label>Nombre:</label>
            <input type="text" name="nombre_evento" id="edit-nombre" required>
            <small id="err-required-nombre" class="err-inline">* obligatorio</small>
            <small id="err-regex-nombre"   class="err-inline">* solo letras, números, espacios y . , ( ) -</small>
          </div>
          <div class="form-group">
            <label>Lugar:</label>
            <input type="text" name="lugar" id="edit-lugar">
            <small id="err-regex-lugar" class="err-inline">* solo letras, números, espacios y . , ( ) -</small>
          </div>
          <div class="form-group">
            <label>Descripción:</label>
            <textarea name="descripcion" id="edit-descripcion"></textarea>
            <small id="err-regex-descripcion" class="err-inline">* solo letras, números, espacios y . , ( ) -</small>
          </div>
          <div class="form-group">
            <label>Observación:</label>
            <textarea name="observacion" id="edit-observacion"></textarea>
            <small id="err-regex-observacion" class="err-inline">* solo letras, números, espacios y . , ( ) -</small>
          </div>
          <div class="form-group">
            <label>Fecha y hora inicio:</label>
            <input type="datetime-local" name="fecha_hora_inicio" id="edit-start" required>
            <small id="err-required-start" class="err-inline">* fecha y hora requeridas</small>
          </div>
          <div class="form-group">
            <label>Fecha y hora término:</label>
            <input type="datetime-local" name="fecha_hora_termino" id="edit-end">
            <small id="err-required-end" class="err-inline">* fecha y hora requeridas</small>
              <!-- Mensaje de error inline -->
              <div id="end-error" class="input-error" style="display:none; color:#dc3545; font-size:0.875rem; margin-top:0.25rem;">
                * La fecha y hora de término debe ser igual o posterior al inicio.
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
        <button id="btn-save-evento" class="btn btn-primary">Guardar cambios</button>
      </footer>
    </div>
  </div>

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script src="eventos.js"></script>
</body>
</html>
