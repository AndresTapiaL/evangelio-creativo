<?php
date_default_timezone_set('UTC');
require 'conexion.php';
require_once 'lib_auth.php';

session_start();
$uid = $_SESSION['id_usuario'] ?? 0;

/* ── 1. ¿Sesión iniciada? ─────────────────────────────── */
if (!$uid) {
    header('Location: login.html');
    exit;
}

/* ── 2. ¿Tiene derecho a usar reportes? ───────────────── */
if (!user_can_use_reports($pdo, $uid)) {
    http_response_code(403);
    exit('403 – Acceso denegado');
}

// — Trae nombre y foto para el menú —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
$stmt->execute(['id'=>$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$isLN  = user_is_lider_nac($pdo,$uid);
$myTeams = user_allowed_teams($pdo,$uid);   // lista de IDs permitidos
//  objeto JS que usará reportes.js
echo "<script>
        window.REP_AUTH = {
            isLN : ".($isLN?'true':'false').",
            allowed : ".json_encode(array_map('intval',$myTeams))."
        };
      </script>";

/* ─── Equipos que el usuario puede ver ─── */
if ($isLN) {
    $teams = $pdo->query("
        SELECT id_equipo_proyecto, nombre_equipo_proyecto
          FROM equipos_proyectos
      ORDER BY nombre_equipo_proyecto")
      ->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!$myTeams) $myTeams = [0];                // evita IN ()
    $in = implode(',', array_map('intval',$myTeams));
    $teams = $pdo->query("
        SELECT id_equipo_proyecto, nombre_equipo_proyecto
          FROM equipos_proyectos
         WHERE id_equipo_proyecto IN ($in)
      ORDER BY nombre_equipo_proyecto")
      ->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reportes</title> 
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* ====== BASE ====== */
    h1{ margin:0 0 1rem; font:600 1.55rem/1.2 "Poppins",sans-serif; color:var(--negro); }

    /* Scrollbars (igual que eventos.php) */
    ::-webkit-scrollbar{ width:8px; height:8px; }
    ::-webkit-scrollbar-thumb{ background:#c5c9d6; border-radius:8px; }
    ::-webkit-scrollbar-thumb:hover{ background:#a9afc4; }
    html{ scroll-behavior:smooth; }

    /* ====== LAYOUT ====== */
    .layout{ display:flex; }
    .sidebar{
      position:fixed; top:var(--nav-h); left:0; bottom:0; width:240px;
      background:var(--bg-sidebar); color:#fff; padding:1rem .75rem 2rem;
      overflow-y:auto; border-radius:0 var(--radius) var(--radius) 0;
      box-shadow:var(--shadow); overflow-x:hidden;
    }
    .sb-title{ margin:0 0 1rem; font:600 .95rem/1 "Poppins",sans-serif; color:#fff; }
    .sb-list{ list-style:none; padding:0; margin:0; }
    .sb-btn{
      all:unset; display:block; width:100%; padding:.55rem .85rem;
      font:500 .9rem/1.25 "Poppins",sans-serif; border-radius:8px; cursor:pointer;
      color:#e5e7eb; transition:background .18s,color .18s; white-space:normal;
      box-sizing:border-box;      /* asegura que el fondo no se “corte” */
      overflow-wrap:anywhere;     /* para que quiebre en cualquier punto si es largo */
      word-break:break-word;
    }
    .sb-btn:hover{ background:rgba(255,255,255,.12); color:#fff; }
    .sb-btn.active{ background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.25); }

    #reportes-main{
      margin-left:240px; padding:2rem 2.2rem;
      min-height:calc(100vh - var(--nav-h));
    }

    #reportes-card{
      background:#fff; border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:1.25rem 1.4rem 1.8rem;
    }

    /* ====== Toolbar + Tabs ====== */
    #reportes-toolbar{
      display:flex; flex-direction:column; gap:1rem; margin-bottom:1.2rem;
    }
    .tabs{
      display:flex; flex-wrap:wrap; gap:.5rem;
    }
    .btn-tab{
      border:0; border-radius:999px; padding:.5rem .9rem;
      background:#f3f4f6; color:#374151; cursor:pointer;
      font:500 .85rem/1 "Poppins",sans-serif; transition:all .18s;
    }
    .btn-tab:hover{ background:#e5e7eb; }
    .btn-tab.active{
      background:var(--primary); color:#fff; box-shadow:0 3px 8px rgba(0,0,0,.16);
    }

    /* Barra de periodos (con flechas) */
    .period-bar{
      display:flex; align-items:center; justify-content:center;
      flex-wrap:wrap; gap:.5rem; margin-bottom:.5rem;
    }
    .period-bar .anio-label{
      font-weight:600; color:#222;
    }
    .period-bar .arrow-btn{
      font-size:1.1rem; padding:.35rem .6rem;
      border:1px solid #d6d9e2; border-radius:8px; background:#fafafa;
      cursor:pointer; transition:.15s;
    }
    .period-bar .arrow-btn:hover{ background:#fff; }
    .btn-periodo{
      border:1px solid #bbb; border-radius:999px;
      background:#fafafa; cursor:pointer; transition:.15s;
      padding:.45rem .8rem; font:500 .8rem/1 "Poppins",sans-serif;
    }
    .btn-periodo:hover{ background:#fff; }
    .btn-periodo.active{ background:var(--amarillo); border-color:#e6b800; font-weight:600; }

    /* ====== Tabla ====== */
    .table-responsive{
      -webkit-overflow-scrolling:touch;
      margin-bottom:2rem; border:1px solid #ddd; border-radius:6px;
      overflow-x:auto;
      overflow-y:visible;
    }
    .table-responsive table{
      width:100%; table-layout:fixed; border-collapse:collapse;
      min-width:720px;
    }
    .table-responsive thead th{
      background:#f9fafb; color:#4b5563; font:600 .72rem/1.2 "Poppins",sans-serif;
      text-transform:uppercase; letter-spacing:.5px; position:sticky; top:0; z-index:2;
      padding:.65rem .75rem; border-bottom:1px solid #e5e7eb;
    }
    .table-responsive th, .table-responsive td{
      border:1px solid #eee; padding:.55rem .65rem; text-align:left; white-space:normal;
      word-wrap:break-word; overflow-wrap:break-word; font:.82rem/1.35 "Poppins",sans-serif;
    }
    .table-responsive tbody tr:nth-child(even){ background:#fafafa; }

    /* ====== Botones sistema (reusados) ====== */
    .btn-sys{
      border:0; border-radius:8px; font:500 .85rem/1 "Poppins",sans-serif;
      cursor:pointer; padding:.6rem 1rem; display:inline-flex; align-items:center; gap:.35rem;
      background:var(--primary); color:#fff; transition:background .2s, transform .15s;
    }
    .btn-sys:hover{ background:var(--primary-dark); }
    .btn-sidebar-toggle{
      display:none;
    }

    /* ====== RESPONSIVE ====== */
    @media (max-width:768px){
      .sidebar{
        transform:translateX(-100%);
        transition:transform .3s ease;
        width:220px; z-index:11000;
      }
      .sidebar.open{ transform:translateX(0); }
      .btn-sidebar-toggle{
        display:inline-flex; position:fixed; top:calc(var(--nav-h) + .5rem); left:.75rem;
        padding:.55rem .75rem; background:var(--primary); color:#fff; border-radius:8px;
        box-shadow:0 4px 10px rgba(0,0,0,.15); z-index:12000;
      }
      #reportes-main{
        margin-left:0; padding:1rem 1rem 2rem;
      }
      .table-responsive table{
        min-width:600px;
      }
    }
    @media (max-width:600px){
      /* modo “tarjetas” cuando renderizas tablas grandes */
      .table-responsive thead{ display:none; }
      .table-responsive table{ min-width:100%; border:0; }
      .table-responsive tbody tr{
        display:block; margin-bottom:1rem; background:#fff;
        border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,.05);
      }
      .table-responsive tbody td{
        display:grid; grid-template-columns:1fr; gap:.25rem;
        border:none; border-top:1px solid #f4f4f6;
        font-size:.80rem; line-height:1.35;
      }
      .table-responsive tbody td:first-child{ border-top:0; }
      .table-responsive tbody td::before{
        content:attr(data-label);
        font-weight:600; color:#374151; text-transform:uppercase;
        font-size:.68rem; letter-spacing:.4px; margin-bottom:.15rem;
      }
    }

    /* Tabla compacta específica para Justificaciones */
    .table-compact th,
    .table-compact td{
      font-size:.75rem;
      line-height:1.2;
    }

    /* Para que el nav de periodos quede fijo arriba cuando haya scroll
      (punto 4 también lo pide para esas dos secciones) */
    .period-bar{
      position:sticky;
      top:calc(var(--nav-h) + .5rem);
      z-index:5;
      background:#fff;
      padding:.5rem 0;
    }

    #reportes-card,
    #reportes-main,
    .layout{
      position:relative;
      z-index:0;   /* asegura que quede por debajo del nav principal */
    }

    /* Cuando ocultamos el sidebar y queremos ocupar todo el ancho */
    #reportes-main.fullwidth{
      margin-left:0 !important;
      width:100%;
    }

    /* El card ocupa todo el ancho disponible y se centra.
      Le quitamos el padding para dárselo al interior (.pad-host). */
    #reportes-card.fullwidth{
      width:100%;
      margin:0 auto;
      padding:0;                 /* el padding lo maneja .pad-host */
    }

    /* Cuando queremos que el contenido NO quede pegado a los bordes
      (solo para Equipos y Eventos · Estados) */
    #reportes-card.pad-host #reportes-toolbar{
      padding:1.25rem 2rem 0;    /* padding separado para la toolbar */
    }
    #reportes-card.pad-host section{
      padding:0 2rem 2rem;       /* padding para el contenido */
    }

    .chart-block{
      margin:2rem 0;
      padding-bottom:1.25rem;
      border-bottom:1px solid #eee;
    }

    /* ========= NEW TABLE LOOK ========= */
    .table-shell{
      overflow:auto;                     /* horizontal + vertical si hace falta */
      max-height:65vh;
      border:1px solid #e5e7eb;
      border-radius:12px;
      box-shadow:0 1px 2px rgba(0,0,0,.04);
      background:#fff;
    }

    /* Base */
    .dt{
      width:100%;
      border-collapse:separate;          /* necesario para los radios */
      border-spacing:0;
      min-width:900px;                   /* ajusta si quieres */
      table-layout:auto;
      font-family:"Poppins",sans-serif;
    }
    .dt thead th{
      position:sticky;
      top:0;
      z-index:4;
      background:#f9fafb;
      color:#374151;
      font:600 .75rem/1.2 "Poppins",sans-serif;
      text-transform:uppercase;
      letter-spacing:.4px;
      padding:.6rem .75rem;
      border-bottom:1px solid #e5e7eb;
      white-space:normal;
      overflow-wrap:anywhere;
    }
    .dt tbody td{
      padding:.55rem .75rem;
      border-bottom:1px solid #f1f5f9;
      font:.82rem/1.35 "Poppins",sans-serif;
      white-space:normal;
      overflow-wrap:anywhere;
    }
    .dt tbody tr:nth-child(even){ background:#fafafa; }
    .dt tbody tr:hover{ background:#f1f5f9; }

    /* Alineación: todo lo que no es la primera columna centrado (por tus %) */
    .dt thead th:not(:first-child),
    .dt tbody td:not(:first-child){
      text-align:center;
    }

    /* Primera (y siguientes bloqueadas) columna fija con sombra sutil */
    .dt thead th:first-child,
    .dt tbody td:first-child{
      position:sticky;
      left:0;
      z-index:5;
      background:#fff;
      box-shadow: 1px 0 0 #e5e7eb, 4px 0 8px rgba(0,0,0,.02);
    }

    /* Si fijas 2 columnas, el script agrega la clase .locked-col a la 1ª, 2ª, etc.
      Estas reglas aseguran el fondo, z-index y la sombrita de separación. */
    .locked-col{
      position:sticky;
      z-index:6;
      background:#fff;
      box-shadow: 1px 0 0 #e5e7eb, 4px 0 8px rgba(0,0,0,.02);
      white-space:normal !important;
      word-break:break-word;
      overflow-wrap:anywhere;
    }

    /* Cabeceras de columnas bloqueadas por encima del resto */
    thead .locked-col{
      z-index:7;
    }

    /* Chips para los textos largos de header: más respiración */
    .dt thead th{
      min-height:42px;
      hyphens:auto;
    }

    /* Mini utilidades */
    .dt.-compact thead th,
    .dt.-compact tbody td{
      font-size:.75rem;
      line-height:1.25;
      padding:.45rem .6rem;
    }
    /* ========= /NEW TABLE LOOK ========= */

    /* ===== Encabezados pegajosos (viewport) para tablas de Justificaciones ===== */
    .table-sticky thead th{
      position:sticky;
      /* queda pegado respecto al viewport, por encima del nav y la toolbar */
      top:calc(var(--nav-h) + 96px);
      z-index:5;
      background:#f9fafb;
    }

    /* Anchos controlados + mejor quiebre para las tablas de Justificaciones */
    .table-just th,
    .table-just td{
      min-width:120px;                 /* todas las columnas con ancho base */
      hyphens:auto;                    /* hifenación automática donde sea posible */
      overflow-wrap:anywhere;          /* permite romper palabras muy largas */
      word-break:normal;               /* no fuerces el break por caracter */
      white-space:normal;
    }
    .table-just th:first-child,
    .table-just td:first-child{
      min-width:220px;                 /* Nombre / Nombre evento */
    }
    /* Fecha (2ª columna) – dale ancho fijo y evita cortes */
    .table-just th:nth-child(2),
    .table-just td:nth-child(2){
      min-width: 120px;
      width: 120px;        /* fuerza el ancho calculado para el sticky */
      max-width: 120px;
      white-space: nowrap; /* no permitas que FE-CHA baje de línea */
      hyphens: none;       /* desactiva guiones automáticos */
      word-break: keep-all;
      text-align: center;  /* opcional, para que la fecha quede centrada */
    }

    /* Mensaje cuando no hay datos pero queremos mantener la tabla (anchos) */
    .table-empty td{
      text-align:center;
      color:#777;
      font-style:italic;
    }

    /* (opcional) mejora visual: fija el alto mínimo del header para que no “salte” */
    .table-just thead th{
      min-height:42px;
    }

    /* ====== OVERRIDES & EXTRAS (solo look & feel) ====== */

    /* Altura máxima un poco más inteligente para que no tape la toolbar */
    .table-shell{
      max-height: calc(100vh - (var(--nav-h, 64px) + 220px));
    }

    /* Mejora la legibilidad y los espacios en tablas muy densas */
    .dt.-compact thead th,
    .dt.-compact tbody td{
      font-size: .78rem;
      line-height: 1.28;
      padding: .5rem .6rem;
    }

    /* Quita el “salto duro” por caracteres poco usuales pero permite romper si es extremadamente largo */
    .dt thead th,
    .dt tbody td{
      overflow-wrap: anywhere;
      word-break: normal;
    }

    /* Sombras más suaves para las columnas bloqueadas (fijas) */
    .locked-col{
      background:#fff;
      box-shadow: 1px 0 0 #e5e7eb, 6px 0 12px rgba(0,0,0,.025);
    }

    /* Hover un poco más notorio */
    .dt tbody tr:hover{
      background:#eef2f7;
    }

    /* ====== Barra visual para porcentajes ====== */
    .pct{
      --p: 0; /* 0–100 */
      position: relative;
      display:inline-block;
      min-width: 46px;
      padding: .05rem .35rem;
      border-radius: 4px;
      background:
        linear-gradient(90deg,
          var(--primary, #4f46e5) calc(var(--p)*1%),
          #e5e7eb 0);
      color:#111827;
      font-variant-numeric: tabular-nums;
      font-weight: 600;
    }
    .pct[data-low="1"]{
      background:
        linear-gradient(90deg,
          #F44336 calc(var(--p)*1%),
          #e5e7eb 0);
      color:#fff;
    }
    .pct[data-mid="1"]{
      background:
        linear-gradient(90deg,
          #FF9800 calc(var(--p)*1%),
          #e5e7eb 0);
      color:#111827;
    }
    .pct[data-high="1"]{
      background:
        linear-gradient(90deg,
          #8BC34A calc(var(--p)*1%),
          #e5e7eb 0);
      color:#111827;
    }

    /* Ajuste del sticky del header dentro del wrapper con sombra */
    .table-shell thead th{
      top:0;               /* pegado al tope del contenedor con scroll */
    }

    /* Mensaje vacío centrado, manteniendo anchos */
    .table-empty td{
      text-align:center;
      color:#6b7280;
      font-style:italic;
      padding: 2rem 0;
    }

    /* ====== HEADERS COMPACTOS (abreviatura + tooltip) ====== */
    .dt thead th{
      white-space:nowrap;                 /* no rompas en múltiples líneas */
      text-overflow:ellipsis;
      overflow:hidden;
    }

    .dt thead th[data-full]{
      position:relative;
      cursor:pointer;                     /* quitamos el “?” del cursor help */
    }
    .dt thead th[data-full]:hover{
      overflow:visible;                   /* para que el tooltip no se corte */
    }

    /* el texto corto que se ve siempre */
    .dt thead th .hshort{
      display:block;
      font-size:.72rem;
      font-weight:600;
      letter-spacing:.3px;
      text-transform:uppercase;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    /* Reafirma el sticky del header dentro del wrapper con scroll */
    .table-shell thead,
    .table-shell thead th{
      position: sticky;
      top: 0;
      z-index: 8;               /* por encima del contenido */
      background:#f9fafb;       /* para tapar filas al hacer scroll */
    }

    /* Las columnas bloqueadas (locked) aún más arriba */
    .table-shell thead th.locked-col{
      z-index: 9;
    }

    /* No rompas el sticky por overflow del th */
    .dt thead th{
      overflow: hidden;         /* vuelve a hidden por defecto */
    }
    .dt thead th[data-full]:hover{
      overflow: visible;        /* solo visible en hover para el tooltip */
    }

    /* ===== Tooltip global para headers (fuera de la tabla) ===== */
    #th-tooltip{
      position: fixed;
      z-index: 999999;         /* por encima de todo */
      max-width: 320px;
      background: #111827;
      color: #fff;
      font: 500 .72rem/1.35 "Poppins",sans-serif;
      padding: .5rem .65rem;
      border-radius: 6px;
      box-shadow: 0 10px 24px rgba(0,0,0,.22);
      pointer-events: none;     /* no captura el mouse */
      display: none;
      text-align: left;
      white-space: normal;
    }
    #th-tooltip strong{
      display:block;
      margin-bottom:.15rem;
    }
    #th-tooltip small{
      opacity:.85;
      font-weight:400;
      display:block;
      margin-top:.15rem;
    }

    /* Para que el sticky del header no se pierda */
    .table-shell{
      position: relative;   /* reafirma el contexto */
    }
    .table-shell thead,
    .table-shell thead th{
      position: sticky;
      top: 0;
      z-index: 8;
      background:#f9fafb;
    }
    .table-shell thead th.locked-col{
      z-index: 9;
    }

    /* ====== BEAUTY EXTRAS for Equipos & Eventos · Estados ====== */

    /* KPIs (tarjetitas arriba de la tabla Equipos) */
    .kpis-grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap:.75rem 1rem;
      margin:.5rem 0 1.25rem;
    }
    .kpi-card{
      background:#f9fafb;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:.7rem .9rem .8rem;
      box-shadow:0 1px 2px rgba(0,0,0,.03);
    }
    .kpi-card h4{
      margin:0 0 .15rem;
      font:600 .78rem/1.1 "Poppins",sans-serif;
      color:#6b7280;
    }
    .kpi-card span{
      display:block;
      font:700 1.15rem/1 "Poppins",sans-serif;
      color:#111827;
    }

    /* Chips de estado para leyendas */
    .legend{
      display:flex; flex-wrap:wrap; gap:.4rem .55rem; margin:.6rem 0 .2rem;
    }
    .legend-chip{
      display:inline-flex; align-items:center; gap:.35rem;
      font:500 .75rem/1 "Poppins",sans-serif; color:#111827;
      background:#f3f4f6; border-radius:999px; padding:.18rem .5rem .18rem .18rem;
      border:1px solid #e5e7eb;
    }
    .legend-dot{
      width:.7rem; height:.7rem; border-radius:50%;
    }

    /* Cards + grid para gráficos de Eventos · Estados */
    .chart-grid{
      display:grid;
      grid-template-columns:1fr;
      gap:1.25rem;
    }
    @media (min-width: 1024px){
      .chart-grid{ grid-template-columns:1fr 1fr; }
    }
    .chart-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      box-shadow:0 1px 3px rgba(0,0,0,.04);
      padding:1rem 1.2rem 1.1rem;
    }
    .chart-card h3{
      margin:.15rem 0 .25rem;
      font:600 1rem/1.2 "Poppins",sans-serif;
    }
    .chart-sub{
      margin:0 0 .9rem;
      color:#6b7280;
      font:400 .8rem/1.25 "Poppins",sans-serif;
    }

    /* Empty state bonito para esos dos reportes */
    .empty-state{
      display:flex; align-items:center; justify-content:center;
      min-height:160px;
      background:#fafafa;
      border:1px dashed #d1d5db;
      border-radius:12px;
      color:#6b7280;
      font:italic 500 .9rem/1.2 "Poppins",sans-serif;
    }

    /* === PERIOD BAR: año encima de los botones (solo Equipos / Eventos·Estados) === */
    .period-bar.with-year-nav{
      flex-direction: column;
      gap: .4rem;
      align-items: center;
    }
    .period-bar .year-nav{
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      margin-bottom: .15rem;
    }
    .period-bar .year-nav .anio-label{
      font: 600 .9rem/1 "Poppins",sans-serif;
      color:#111827;
    }
    .period-bar .periodos-del-anio{
      display:flex;
      flex-wrap:wrap;
      gap:.4rem .5rem;
      justify-content:center;
    }

    /* Botones de periodo un poco más compactos para que quepan mejor */
    .period-bar.with-year-nav .btn-periodo{
      padding:.35rem .7rem;
      font-size:.78rem;
    }

    /* ====== Ajustes visuales para gráficos “más pequeños” ====== */
    .chart-grid{
      display:grid;
      grid-template-columns:1fr;
      gap:1rem;
    }
    @media (min-width: 1024px){
      .chart-grid{
        grid-template-columns:1fr 1fr;
      }
    }
    .chart-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      box-shadow:0 1px 3px rgba(0,0,0,.04);
      padding: .8rem 1rem 1rem;
    }
    .chart-card h3{
      margin:.15rem 0 .2rem;
      font:600 .95rem/1.2 "Poppins",sans-serif;
    }
    .chart-sub{
      margin:0 0 .7rem;
      color:#6b7280;
      font:400 .78rem/1.25 "Poppins",sans-serif;
    }

    /* Altura/control de los canvas para que "quepan" mejor */
    .chart-card canvas{
      max-height: 280px;        /* altura “bonita” por defecto */
    }

    /* Leyenda abajo, más compacta */
    .chart-legend-bottom{
      margin-top:.4rem;
      display:flex;
      flex-wrap:wrap;
      gap:.25rem .5rem;
      justify-content:flex-start;
    }
    .chart-legend-bottom .legend-chip{
      display:inline-flex; align-items:center; gap:.3rem;
      font:500 .75rem/1 "Poppins",sans-serif; color:#111827;
      background:#f3f4f6; border-radius:999px; padding:.12rem .45rem .12rem .32rem;
      border:1px solid #e5e7eb;
    }
    .chart-legend-bottom .legend-dot{
      width:.6rem; height:.6rem; border-radius:50%;
    }

    /* ====== PAGINACIÓN DE TABLAS ====== */
    .pager{
      display:flex;
      flex-wrap:wrap;
      gap:.35rem;
      align-items:center;
      justify-content:flex-end;
      margin:.5rem 0 0;
    }
    .pager button{
      border:1px solid #d1d5db;
      background:#fff;
      border-radius:6px;
      padding:.25rem .55rem;
      font:.8rem/1 "Poppins",sans-serif;
      cursor:pointer;
      transition:.15s;
    }
    .pager button:hover:not([disabled]){
      background:#f3f4f6;
    }
    .pager button[disabled]{
      opacity:.4;
      cursor:default;
    }
    .pager .page-info{
      font:.8rem/1 "Poppins",sans-serif;
      color:#4b5563;
      margin:0 .35rem;
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

  <!-- === BOTÓN TOGGLE SIDEBAR (móvil) === -->
  <button id="toggle-sidebar"
          class="btn-sys btn-sidebar-toggle"
          aria-label="Menú">
    <i class="fa-solid fa-bars"></i>
  </button>

  <!-- ░░░░ LAYOUT (igual a eventos.php) ░░░░ -->
  <div class="layout">

    <!-- ░░░░ SIDEBAR ░░░░ -->
    <aside class="sidebar" id="sidebar-reportes">
      <ul id="team-list" class="sb-list">
        <?php foreach($teams as $ix=>$t): ?>
          <li>
            <button class="sb-btn team-btn<?= $ix==0?' active':'' ?>"
                    data-id="<?= $t['id_equipo_proyecto'] ?>">
              <?= htmlspecialchars($t['nombre_equipo_proyecto']) ?>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <!-- ░░░░ MAIN ░░░░ -->
    <main id="reportes-main">
      <h1>Reportes</h1>

      <div id="reportes-card">
        <!-- Toolbar superior -->
        <div id="reportes-toolbar">
          <!-- Pestañas -->
          <nav id="tabs" class="tabs">
            <button data-report="integrantes"   id="tab-integrantes"    class="btn-tab">Justificaciones · Integrantes</button>
            <button data-report="eventos"       id="tab-eventos"        class="btn-tab">Justificaciones · Eventos</button>
            <button data-report="equipos"       id="tab-equipos"        class="btn-tab">Equipos</button>
            <button data-report="eventos_estado" id="tab-eventos_estado" class="btn-tab">Eventos · Estados</button>
          </nav>

          <!-- Periodos -->
          <div id="period-buttons" class="period-bar"></div>
        </div>

        <!-- Contenido -->
        <section>
          <div id="report-container"></div>
        </section>
      </div><!-- /reportes-card -->
    </main>
  </div><!-- /layout -->

  <!-- ═════════ utilidades ═════════ -->
  <script>
    document.getElementById('logout').addEventListener('click', async e => {
      e.preventDefault();
      const token = localStorage.getItem('token');
      if (!token) {
        localStorage.clear();
        return location.replace('login.html');
      }
      try {
        const res = await fetch('cerrar_sesion.php', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token }
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
        localStorage.clear();
        location.replace('login.html');
      }
    });

    // === Toggle sidebar móvil (idéntico a eventos.php) ===
    (function(){
      const btn  = document.getElementById('toggle-sidebar');
      const side = document.getElementById('sidebar-reportes');
      if(btn && side){
        btn.addEventListener('click', ()=> side.classList.toggle('open'));
      }
    })();
  </script>

  <!-- Heartbeat + tu JS -->
  <script src="heartbeat.js"></script>
  <script defer src="reportes.js"></script>
  <div id="th-tooltip" role="tooltip" aria-hidden="true"></div>
</body>
</html>
