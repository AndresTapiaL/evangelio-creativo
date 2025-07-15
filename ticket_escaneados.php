<?php
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
// renombramos la variable para que sea $id_usuario
$id_usuario = $_SESSION['id_usuario'];

// 1) ¿Eres Liderazgo nacional? (equipo 1)
$stmtLiderNacional = $pdo->prepare("
    SELECT 1
      FROM integrantes_equipos_proyectos
     WHERE id_usuario = ?
       AND id_equipo_proyecto = 1
     LIMIT 1
");
$stmtLiderNacional->execute([$id_usuario]);
$isLiderNacional = (bool) $stmtLiderNacional->fetchColumn();

// 2) ¿Eres Líder o Coordinador en alguno de los equipos de este evento?
$obsStmt = $pdo->prepare("
    SELECT DISTINCT epe.id_evento
      FROM equipos_proyectos_eventos epe
      JOIN integrantes_equipos_proyectos iep
        ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     WHERE iep.id_usuario = ?
       AND iep.id_rol IN (4,6)
");
$obsStmt->execute([$id_usuario]);
$obsEventIds = $obsStmt->fetchAll(PDO::FETCH_COLUMN);

/* ─── evento solicitado ─── */
$idEvento = isset($_GET['evt']) ? (int)$_GET['evt'] : 0;
if (!$idEvento) {
    http_response_code(400);
    exit('Falta parámetro ?evt');
}

// — Trae nombre y foto para el menú —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
// cambiamos 'id' => $id  por 'id' => $id_usuario
$stmt->execute(['id'=>$id_usuario]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$sqlEsc = "
    SELECT  ts.id_ticket_usuario             AS id,
            tu.nombre_completo               AS nombre,
            th.nombre_horario                AS horario,
            ts.es_ingreso                    AS es_ingreso,
            tu.alimentacion                  AS alimentacion,
            DATE_FORMAT(ts.scan_at,'%d-%m-%Y %H:%i:%s') AS scan_at
      FROM  ticket_scans   ts
      JOIN  ticket_horarios th ON th.id_ticket_horario = ts.id_ticket_horario
      JOIN  ticket_usuario  tu ON tu.id_ticket_usuario = ts.id_ticket_usuario
      JOIN  eventos_tickets et ON et.id_evento_ticket  = tu.id_evento_ticket
     WHERE  et.id_evento = :evt
  ORDER BY  ts.scan_at DESC";

/* ─── salida JSON si se llama con ?ajax=1 ─── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $stmt = $pdo->prepare($sqlEsc);
    $stmt->execute(['evt' => $idEvento]);

    /*  ⬇️  DataTables quiere un objeto con llave “data”  */
    echo json_encode(
        ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$evtTitle = $pdo->prepare("SELECT nombre_evento FROM eventos WHERE id_evento=?");
$evtTitle->execute([$idEvento]);
$evtTitle = $evtTitle->fetchColumn() ?: 'Evento sin título';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Escaneados – <?=htmlspecialchars($evtTitle)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- DataTables + jQuery -->
  <link rel="stylesheet"
      href="https://cdn.datatables.net/1.13.10/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.10/js/jquery.dataTables.min.js"></script>
  <style>
    /* ===== Reset + body idénticos a ticket_detalle.php ===== */
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html{scroll-behavior:smooth}

    body{
      font:400 15px/1.55 "Poppins",system-ui,sans-serif;
      background:var(--bg-main);
      color:var(--negro);
      margin:0;      /* sin márgenes */
      padding:0;     /* ❗ quita los 2 rem que empujaban el nav */
      min-height:100vh;
    }
    .container {
      max-width: 800px;
      margin: auto;
      background: #fff;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 0 12px rgba(0,0,0,.1);
    }
    h1 {
      margin-top: 0;
    }

    /* Contenedor horizontal */
    .attendance-options {
      display: flex;
      gap: 2rem;
      justify-content: center;
      margin-top: 1rem;
    }

    /* Cada opción (“pill + circle”) */
    .att-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      cursor: pointer;
    }

    /* Estilo de la “pill” con texto */
    .att-item .pill {
      padding: .4rem 1rem;
      border-radius: 9999px;
      color: #fff;
      font-weight: bold;
      margin-bottom: .4rem;
      font-size: .9rem;
      white-space: nowrap;
    }

    /* Colores según valor */
    .att-1 .pill { background: #4CAF50; }   /* Presente → verde */
    .att-2 .pill { background: #F44336; }   /* Ausente → rojo   */
    .att-3 .pill { background: #FFEB3B; color: #222; } /* No sé → amarillo */

    /* Círculo vacío */
    .att-item .circle {
      width: 16px; height: 16px;
      border: 2px solid currentColor;
      border-radius: 50%;
      box-sizing: border-box;
    }

    /* Círculo relleno cuando está “selected” */
    .att-item.selected .circle {
      background: currentColor;
    }

    /* Overlay semi-transparente */
    .modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.5);
      display: none;               /* oculto por defecto */
      align-items: center;
      justify-content: center;
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
      background: #fafafa;
      padding: 1rem;
      border-bottom: 1px solid #ddd;
      position: relative;
    }

    /* Título y botón de cierre */
    .card-title { margin: 0; font-size: 1.25rem; color: #333; }
    .modal-close {
      position: absolute; top: 1rem; right: 1rem;
      background: none; border: none; font-size: 1.25rem;
      color: #666; cursor: pointer; transition: color .2s;
    }
    .modal-close:hover { color: #333; }

    /* Cuerpo con scroll interno si hace falta */
    .card-body {
      padding: 1.5rem;
      overflow-y: auto;
      flex: 1;
    }

    /* Lista de definiciones */
    .vertical-list { margin: 0; padding: 0; list-style: none; }
    .detail-item {
      margin-bottom: 1rem;
      padding-bottom: .5rem;
      border-bottom: 1px solid #eee;
    }
    .detail-item:last-child { border-bottom: none; }
    .detail-item dt {
      font-weight: 600; color: #333; margin: 0 0 .25rem; font-size: 1rem;
    }
    .detail-item dd {
      margin: 0; padding-left: 1rem; color: #555; font-size: .95rem; line-height: 1.4;
    }

    /* Alinea a la izquierda la lista de equipos */
    .equipos-list {
      margin: 0;
      padding-left: 1rem;
      list-style: disc outside;
      text-align: left; /* fuerza alineación izquierda */
    }

    .past-attendance {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .past-attendance .attendance-options {
      display: flex;
      gap: 0.5rem;
    }
    .past-attendance .extras {
      display: none;
      align-items: center;
      gap: 0.5rem;
    }
    .past-attendance .past-other {
      max-width: 200px;
    }
    .past-attendance .error-msg {
      color: #E53935;
      font-size: 0.85rem;
      display: none;
    }

    /* scroll horizontal si la tabla es ancha */
    div.dataTables_wrapper{overflow-x:auto;}
    table.dataTable thead th,
    table.dataTable tbody td{white-space:nowrap;}
    .i-ok{color:#198754}   /* ✅ */
    .i-bad{color:#d62828}  /* ❌ */

    /* —— botón & caja de búsqueda —— */
    .dataTables_filter{
    display:flex;                /* input + botón en línea */
    align-items:center;
    gap:.6rem;
    }
    .dataTables_filter input{
    height:36px;                 /* que coincida con los otros buttons */
    border:1px solid #ffd4b8;
    background:#fff2e9;
    color:var(--primary);
    border-radius:8px;
    padding:.45rem .8rem;
    }
    .dataTables_filter .action-btn{
    background:var(--primary);
    color:#fff;
    border:0;
    border-radius:8px;
    padding:.45rem .9rem;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    transition:background .2s ease;
    }
    .dataTables_filter .action-btn:hover{
    background:var(--primary-hover);
    }

    /* —— BOTONES action‑btn (mismo look que ticket_detalle.php) —— */
    .action-btn,
    td a,
    td button{
    background:#f5f6fa;
    border:1px solid #dfe1ea;
    color:var(--primary);
    border-radius:8px;
    padding:.35rem .7rem;
    font-size:.78rem;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    transition:all .2s ease;
    }
    .action-btn:hover,
    td a:hover,
    td button:hover{
    background:var(--primary);      /* naranja corporativo */
    color:#fff;
    border-color:var(--primary);
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

    <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin:2rem 0 .5rem">
    <a href="ticket_detalle.php?evt=<?=$idEvento?>" class="action-btn" style="font-size:.85rem">
        <i class="fa-solid fa-arrow-left"></i> Volver
    </a>
    <h1 style="margin:0;color:#ff4200"><?=htmlspecialchars($evtTitle)?></h1>
    </div>

    <section style="max-width:95vw;margin:0 auto 3rem;background:#fff;
                    padding:1.6rem;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08);">
    <table id="tblEsc" class="display" style="width:100%">
        <thead>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Horario</th>
            <th>Ingreso / Salida</th>
            <th>Alimentación</th>
            <th>Horario de escaneo</th>
        </tr>
        </thead>
        <tbody></tbody>
    </table>
    </section>

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
    const tabla = $('#tblEsc').DataTable({
    ajax:  'ticket_escaneados.php?evt=<?=$idEvento?>&ajax=1',
    columns:[
        {data:'id'},
        {data:'nombre'},
        {data:'horario'},
        {
        data   : 'es_ingreso',
        render : function (d, type) {
            /* 0 = Salida ┊ 1 = Ingreso */
            if (type === 'display') {
                return d == 1
                    ? '<i class="fa-solid fa-circle-arrow-up i-ok" title="Ingreso"></i>'
                    : '<i class="fa-solid fa-circle-arrow-down i-bad" title="Salida"></i>';
            }
            /* para ordenar, buscar y exportar devolvemos texto plano */
            return d == 1 ? 'Ingreso' : 'Salida';
        },
        className : 'dt-center'
        },
        {data:'alimentacion'},
        {data:'scan_at'}
    ],
    pageLength:100,
    order:[[5,'desc']],
    language:{
        url:'https://cdn.datatables.net/plug-ins/1.13.10/i18n/es-CL.json',
        emptyTable:'No hay escaneados en este evento'
    },
    scrollX:true
    });

    /* —— inyecta botón “Buscar” con la misma clase action‑btn —— */
    $('#tblEsc_filter').append(
    '<button id="dtBuscar" class="action-btn">' +
        '<i class="fa-solid fa-search"></i> Buscar' +
    '</button>'
    );

    /* al hacer click aplicamos el filtro —sin regex para evitar patrones raros— */
    $('#dtBuscar').on('click', () => {
    const term = $('#tblEsc_filter input').val();
    tabla.search(term, false, false).draw();   // regex=false, smart=false
    });

    /* refresco automático cada 5 s */
    setInterval(()=>tabla.ajax.reload(null,false), 5000);
    </script>

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script>
</body>
</html>
