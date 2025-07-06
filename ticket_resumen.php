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

// — Trae nombre y foto para el menú —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
// cambiamos 'id' => $id  por 'id' => $id_usuario
$stmt->execute(['id'=>$id_usuario]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* ────────────────── NUEVO: evento + permisos ────────────────── */

/* ① parámetro evt */
$idEvento = isset($_GET['evt']) ? (int)$_GET['evt'] : 0;
if (!$idEvento) {
    http_response_code(400);
    exit('Evento no especificado');
}

/* ② autorización:
      - Liderazgo Nacional  (ya calculado)
      - ó Líder / Coordinador de algún equipo vinculado al evento */
if (!$isLiderNacional && !in_array($idEvento, $obsEventIds, true)) {
    http_response_code(403);
    exit('Acceso restringido');
}

/* ③ datos básicos del evento */
$evtStmt = $pdo->prepare("
    SELECT nombre_evento
      FROM eventos
     WHERE id_evento = ?
     LIMIT 1");
$evtStmt->execute([$idEvento]);
$eventoNombre = $evtStmt->fetchColumn() ?: 'Evento sin nombre';

/* ④ catálogo de tickets y horarios */
$tickets = $pdo->prepare("
    SELECT id_evento_ticket, nombre_ticket
      FROM eventos_tickets
     WHERE id_evento = ?
  ORDER BY id_evento_ticket");
$tickets->execute([$idEvento]);
$tickets = $tickets->fetchAll(PDO::FETCH_KEY_PAIR);        // [id ⇒ nombre]

$horarios = $pdo->prepare("
    SELECT id_ticket_horario, nombre_horario
      FROM ticket_horarios
     WHERE id_evento = ?
  ORDER BY fecha_inicio");
$horarios->execute([$idEvento]);
$horarios = $horarios->fetchAll(PDO::FETCH_KEY_PAIR);      // [id ⇒ nombre]

if (!$tickets) {                         // evento sin tickets
    $tablaMsg = 'Sin resultados para este evento';
} else {

    /* ⑤ total inscritos por ticket (una sola cifra) */
    $totPorTicket = $pdo->prepare("
        SELECT id_evento_ticket, COUNT(*) AS total
          FROM ticket_usuario
         WHERE id_evento_ticket IN (" . implode(',',array_keys($tickets)) . ")
      GROUP BY id_evento_ticket");
    $totPorTicket->execute();
    $totPorTicket = $totPorTicket->fetchAll(PDO::FETCH_KEY_PAIR);

    /* ⑥ total global del evento */
    $totalEvento = (int)$pdo->query("
        SELECT COUNT(*)
          FROM ticket_usuario tu
          JOIN eventos_tickets et USING(id_evento_ticket)
         WHERE et.id_evento = $idEvento")->fetchColumn();

    /* ⑦ ingresos / salidas por horario  (SOLO el ÚLTIMO scan del usuario) */
    $ing = $sal = array_fill_keys(array_keys($horarios), 0);

    if ($horarios) {
        $scan = $pdo->prepare("
            SELECT   s.id_ticket_horario,
                     SUM(s.es_ingreso = 1) AS ingresos,
                     SUM(s.es_ingreso = 0) AS salidas
              FROM   ticket_scans      s
              JOIN  (                  /* último scan de cada usuario-horario */
                     SELECT id_ticket_horario,
                            id_ticket_usuario,
                            MAX(scan_at) AS last_scan
                       FROM ticket_scans
                   GROUP BY id_ticket_horario, id_ticket_usuario
                    ) last
                    ON  last.id_ticket_horario = s.id_ticket_horario
                    AND last.id_ticket_usuario = s.id_ticket_usuario
                    AND last.last_scan         = s.scan_at
              JOIN   ticket_usuario     tu ON tu.id_ticket_usuario = s.id_ticket_usuario
              JOIN   eventos_tickets    et USING(id_evento_ticket)
             WHERE   et.id_evento = ?
               AND   s.id_ticket_horario IN (" . implode(',', array_keys($horarios)) . ")
          GROUP BY   s.id_ticket_horario");
        $scan->execute([$idEvento]);

        foreach ($scan as $r) {
            $ing[$r['id_ticket_horario']] = (int)$r['ingresos'];
            $sal[$r['id_ticket_horario']] = (int)$r['salidas'];
        }
    }

    /* ⑧ sin acreditar = total - (ingresos+salidas) por horario */
    $sin = [];
    foreach ($horarios as $hid => $n) {
        $sin[$hid] = $totalEvento - ($ing[$hid] + $sal[$hid]);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Resumen – <?=htmlspecialchars($eventoNombre)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* --------------- Botones y paleta igual que ticket_detalle.php --------------- */
    :root{
    --negro:#2e292c;
    --naranjo:#ff4200;
    --naranjo-dark:#d63800;
    --blanco:#ffffff;
    --gris:#6d7280;
    --verde:#198754;
    --rojo:#d62828;

    --bg-main:#f6f7fb;
    --bg-card:#ffffff;

    --primary:var(--naranjo);
    --primary-hover:var(--naranjo-dark);
    --success:var(--verde);
    --danger:var(--rojo);

    --radius:12px;
    --shadow:0 6px 20px rgba(0,0,0,.08);
    --transition:.2s ease;
    --w-fijo: 180px;
    }

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

    /* ─── tabla de resumen ─── */
    section{max-width:1200px;margin:0 auto 2rem;padding:2rem;background:#fff;
            border-radius:10px;box-shadow:0 0 12px rgba(0,0,0,.08);}
    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th,td{
    padding:.6rem 1rem;
    text-align:center;
    border:1px solid #e5e7f0;
    white-space:nowrap;          /* ← NUEVO: evita saltos de línea */
    }
    thead{background:#f8f9fe}
    tbody tr:nth-child(odd){background:#fcfcff}

    /* ░░░ tabla con scroll horizontal + 1.ª columna sticky ░░░ */
    .tbl-scroll{
    overflow-x:auto;       /* aparece scroll sólo cuando haga falta */
    position:relative;     /* referencia para el sticky              */
    }

    /* la tabla puede crecer indefinidamente en ancho */
    .tbl-scroll table{min-width:100%;}

    /* 1.ª columna fijada, sin efectos visuales extra */
    .tbl-scroll th:first-child,
    .tbl-scroll td:first-child{
    position:sticky;
    left:0;                        /* queda exactamente donde nace      */
    width:var(--w-fijo);
    min-width:var(--w-fijo);
    background:inherit;            /* mismo fondo que el resto de la fila */
    z-index:2;                     /* queda por encima de las demás celdas */
    }

    /* ── Mantiene el color original de cada fila en la columna fija ── */

    /* cabecera */
    .tbl-scroll thead th:first-child{
    background:#f8f9fe;        /* mismo fondo que <thead> */
    }

    /* filas IMPARES (ya venían con #fcfcff) */
    .tbl-scroll tbody tr:nth-child(odd) td:first-child{
    background:#fcfcff;
    }

    /* filas PARES (blancas) */
    .tbl-scroll tbody tr:nth-child(even) td:first-child{
    background:#fff;
    }

    /* ── estilo genérico de botones ─────────────────────────────────────────────── */
    button,
    a.btn,
    .btn-prim{
    display:inline-flex;
    align-items:center;
    gap:.4rem;
    padding:.45rem .9rem;
    border:0;
    border-radius:8px;
    background:var(--primary);
    color:#fff;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    transition:background var(--transition);
    }
    button:hover,
    a.btn:hover,
    .btn-prim:hover{
    background:var(--primary-hover);
    }

    /* ── botones de acción, incluido el “Volver” ────────────────────────────────── */
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
    transition:all var(--transition);
    }
    .action-btn:hover,
    td a:hover,
    td button:hover{
    background:var(--primary);
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

    <div style="display:flex;gap:1rem;justify-content:center;align-items:center;margin-top:1.6rem">
    <a href="ticket_detalle.php?evt=<?=$idEvento?>" 
        class="action-btn" style="font-size:.85rem">
        <i class="fa-solid fa-arrow-left"></i> Volver
    </a>
    <h1 style="margin:0"><?=htmlspecialchars($eventoNombre)?></h1>
    </div>

    <section>
    <?php if(isset($tablaMsg)): ?>
    <p style="text-align:center;font-weight:600"><?=$tablaMsg?></p>
    <?php else: ?>
    <div class="tbl-scroll">
        <table>
        <thead>
            <tr>
            <th>Tickets</th>
            <?php foreach($horarios as $nom): ?>
                <th><?=htmlspecialchars($nom)?></th>
            <?php endforeach ?>
            </tr>
        </thead>
        <tbody>

        <!-- 1) filas por tipo de ticket -->
        <?php foreach($tickets as $tid=>$nom): ?>
            <tr>
            <td style="text-align:left;font-weight:600"><?=htmlspecialchars($nom)?></td>
            <td colspan="<?=count($horarios)?>">
                <?= $totPorTicket[$tid] ?? '-' ?>
            </td>
            </tr>
        <?php endforeach ?>

        <!-- separación -->
        <tr><td colspan="<?=count($horarios)+1?>" 
                style="background:#e5e7f0;height:4px;padding:0"></td></tr>

        <!-- 2) Ingresos / Salidas / Sin acreditar -->
        <?php
            $bloques = [
            'Ingresos'      => $ing,
            'Salidas'       => $sal,
            'Sin acreditar' => $sin
            ];
            foreach($bloques as $lbl=>$arr): ?>
            <tr>
                <td style="text-align:left;font-weight:600"><?=$lbl?></td>
                <?php foreach($horarios as $hid=>$n): ?>
                <td><?= $arr[$hid] ?: '-' ?></td>
                <?php endforeach ?>
            </tr>
        <?php endforeach ?>

        <!-- separación -->
        <tr><td colspan="<?=count($horarios)+1?>" 
                style="background:#e5e7f0;height:4px;padding:0"></td></tr>

        <!-- 3) Total global -->
        <tr>
            <td style="text-align:left;font-weight:600">Total</td>
            <td colspan="<?=count($horarios)?>"><?= $totalEvento ?: '-' ?></td>
        </tr>

        </tbody>
        </table>
    </div>
    <?php endif; ?>
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

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script>
</body>
</html>
