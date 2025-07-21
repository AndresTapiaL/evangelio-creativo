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

// ── Nuevo: obtener próximos 5 eventos de mis equipos ──

// 1. Equipos/proyectos del usuario
$stmtTeams = $pdo->prepare("
  SELECT id_equipo_proyecto
    FROM integrantes_equipos_proyectos
   WHERE id_usuario = ?
");
$stmtTeams->execute([$id_usuario]);
$userTeamIds = $stmtTeams->fetchAll(PDO::FETCH_COLUMN);

if (empty($userTeamIds)) {
    // evita IN ()
    $userTeamIds = [0];
}

// 2. Query de próximos 3 eventos (tus equipos + generales) con estado previo = 1
$placeholders = implode(',', array_fill(0, count($userTeamIds), '?'));
$sqlUp = "
  SELECT
    e.id_evento,
    e.nombre_evento,
    e.lugar,
    e.descripcion,
    e.observacion,
    e.fecha_hora_inicio,
    e.fecha_hora_termino,
    e.encargado                      AS encargado_id,
    CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno)
      AS encargado_nombre_completo,
    tipe.nombre_tipo,
    prev.nombre_estado_previo,
    fin.nombre_estado_final,
    GROUP_CONCAT(DISTINCT epj.nombre_equipo_proyecto SEPARATOR ', ') AS equipos,
    COALESCE(ap.cnt_presente, 0)      AS cnt_presente,

    /* 2) Total de usuarios únicos en los equipos/proyectos de este evento */
    COALESCE(
      CASE
        WHEN e.es_general = 1
          THEN allu.cnt_all
        ELSE tu.total_integrantes
      END,
      0
    ) AS total_integrantes

  FROM eventos e

  /* 1) Filtrar tus equipos (LEFT para incluir generales) */
  LEFT JOIN equipos_proyectos_eventos AS user_epe
    ON e.id_evento = user_epe.id_evento
   AND user_epe.id_equipo_proyecto IN ($placeholders)

  /* 2) Encargado, tipo, estados… */
  LEFT JOIN usuarios u        ON e.encargado = u.id_usuario
  LEFT JOIN tipos_evento tipe ON e.id_tipo    = tipe.id_tipo
  LEFT JOIN estados_previos_eventos prev
           ON e.id_estado_previo = prev.id_estado_previo
  LEFT JOIN estados_finales_eventos fin
           ON e.id_estado_final  = fin.id_estado_final

  /* 3) Para agrupar todos los equipos del evento */
  LEFT JOIN equipos_proyectos_eventos AS all_epe
    ON e.id_evento = all_epe.id_evento
  LEFT JOIN equipos_proyectos AS epj
    ON all_epe.id_equipo_proyecto = epj.id_equipo_proyecto

  /* 4) Conteo de asistentes “Presente” */
  LEFT JOIN (
    SELECT id_evento, COUNT(*) AS cnt_presente
      FROM asistencias
     WHERE id_estado_previo_asistencia = 1
     GROUP BY id_evento
  ) ap ON ap.id_evento = e.id_evento

  /* 5) Total de integrantes únicos por evento */
  LEFT JOIN (
    SELECT epe.id_evento,
           COUNT(DISTINCT iep.id_usuario) AS total_integrantes
      FROM equipos_proyectos_eventos epe
      JOIN integrantes_equipos_proyectos iep
        ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     GROUP BY epe.id_evento
  ) tu ON tu.id_evento = e.id_evento

  /* 6) Conteo global de usuarios con ≥1 equipo (para generales) */
  LEFT JOIN (
    SELECT COUNT(DISTINCT id_usuario) AS cnt_all
      FROM integrantes_equipos_proyectos
  ) allu ON 1 = 1

  WHERE
    e.fecha_hora_inicio >= NOW()
    AND e.fecha_hora_inicio <= DATE_ADD(NOW(), INTERVAL 30 DAY)
    AND e.id_estado_previo = 1
    AND e.id_estado_final NOT IN (5,6)    -- excluir “Suspendido” (5) y “Postergado” (6)
    /* sólo si participas en el equipo O es general */
    AND (
      user_epe.id_equipo_proyecto IS NOT NULL
      OR e.es_general = 1
    )

  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
";
$stmtUp = $pdo->prepare($sqlUp);
$stmtUp->execute($userTeamIds);
$upcomingEvents = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

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

// 3) Añadimos a cada evento la bandera show_observacion
foreach ($upcomingEvents as &$e) {
    $e['show_observacion'] =
         $isLiderNacional
      || in_array($e['id_evento'], $obsEventIds, true);
}
unset($e);
// ── Termina lógica de show_observacion ──

// — Trae nombre y foto para el menú —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
// cambiamos 'id' => $id  por 'id' => $id_usuario
$stmt->execute(['id'=>$id_usuario]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Inicio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
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
    /* === Diseño pill‑counter igual que eventos.php === */
    .equipos-list{
      margin:.2rem 0 0;
      padding:0;
      list-style:none;
      counter-reset:eqnum;
      display:flex;
      flex-wrap:wrap;                 /* pills fluyen en varias filas   */
      gap:.35rem .45rem;
      max-width:100%;
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
      white-space:normal;
      max-width:calc(100% - .25rem);
      line-height:1.2;
      word-break:break-word;
    }
    .equipos-list li::before{
      content:counter(eqnum);
      position:absolute;
      left:.55rem;
      top:50%;
      transform:translateY(-50%);
      width:1.1rem; height:1.1rem;
      border-radius:6px;
      background:var(--primary);
      color:#fff;
      font:600 .65rem/1.1 "Poppins",sans-serif;
      display:flex; align-items:center; justify-content:center;
      box-shadow:0 1px 2px rgba(0,0,0,.25);
    }
    /* alto máx. con scroll interno si la lista es muy larga */
    td:nth-child(4) .equipos-list{        /* col. Equipo/Proyecto */
      max-height:80px;
      overflow:auto;
      scrollbar-width:thin;
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

    /* ═════ DISEÑO UNIFICADO CON eventos.php ═════ */

    /* ——— main desplazable y con mismo padding ——— */
    #home-main{
      padding:2rem 2.2rem;
      min-height:calc(100vh - var(--nav-h));
      display:block;
    }

    /* ——— “card” contenedor que envuelve las tablas ——— */
    #home-card{
      background:#fff;
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:1.25rem 1.4rem 1.8rem;
      overflow:hidden;
    }

    /* ——— tabla responsiva, mismo estilo de eventos.php ——— */
    #home-card .table-responsive{
      border:1px solid #e6e8ef;
      border-radius:10px;
      overflow:auto;
      box-shadow:inset 0 0 0 1px #fff;
    }

    /* cabecera fija y zebra‑stripes */
    #home-card table{
      width:100%;
      min-width:920px;
      border-collapse:collapse;
      font:400 .8rem/1.4 "Poppins",sans-serif;
    }

    #home-card thead th{
      position:sticky; top:0;
      background:#f9fafb;
      color:#4b5563;
      font:600 .72rem/1.2 "Poppins",sans-serif;
      text-transform:uppercase;
      letter-spacing:.5px;
      padding:.65rem .75rem;
      border-bottom:1px solid #e5e7eb;
      z-index:5;
    }

    #home-card tbody td{
      padding:.65rem .75rem;
      border-bottom:1px solid #f0f2f5;
    }

    #home-card tbody tr:nth-child(odd) td{
      background:#fcfdfe;
    }

    #home-card tbody tr:hover td{
      background:#ffffff;
    }

    /* scroll horizontal suave en móviles */
    .table-responsive{
      -webkit-overflow-scrolling:touch;
    }

    /* ===== Scrollbar igual que en eventos.php ===== */
    ::-webkit-scrollbar{height:8px;width:8px;}
    ::-webkit-scrollbar-thumb{background:#c5c9d6;border-radius:8px;}
    ::-webkit-scrollbar-thumb:hover{background:#a9afc4;}

    /* ——— Estilo unificado para selector de justificación e input “Otros” ——— */
    .past-attendance .past-just,
    .past-attendance .past-other{
      padding:.45rem .65rem;
      border:1px solid #d6d9e2;
      border-radius:8px;
      font:400 .8rem/1 "Poppins",sans-serif;
      background:#ffffff;
      transition:border-color .18s, box-shadow .18s;
    }

    .past-attendance .past-just:focus,
    .past-attendance .past-other:focus{
      outline:none;
      border-color:var(--primary);
      box-shadow:0 0 0 2px rgba(255,86,20,.25);
    }

    .past-attendance .past-other{          /* ancho cómodo del campo texto  */
      max-width:220px;
    }

    .past-attendance .extras{
      gap:.75rem;                          /* un poco más aire entre campos */
    }

    /* ——— Botón “ver detalles” redondo y con hover ——— */
    .action-btn{
      display:inline-flex;               /* centra el ícono             */
      align-items:center;
      justify-content:center;
      width:34px;                        /* círculo de 34 px            */
      height:34px;
      border-radius:50%;
      border:1px solid #d1d5db;
      background:#f3f4f6;
      color:#ff5614;                     /* tono primario               */
      font-size:.9rem;
      cursor:pointer;
      transition:background .2s, box-shadow .2s, transform .08s;
    }
    .action-btn:hover{
      background:#ffffff;
      box-shadow:0 0 0 3px rgba(255,86,20,.15);
    }
    .action-btn:active{ transform:scale(.9); }

    /* ——— Columna de asistencia “pegada” a la derecha en pantallas ≤768px ——— */
    @media(max-width:768px){
      th.sticky-col,
      td.sticky-col{
        position:sticky;
        right:0;                         /* siempre visible al hacer scroll */
        background:#ffffff;
        z-index:6;
        box-shadow:-2px 0 4px rgba(0,0,0,.05);
      }
      /* pills un poco más pequeñas para que quepan */
      .attendance-options{ gap:.6rem; }
      .att-item .pill{ padding:.25rem .55rem; font-size:.75rem; }
    }

    /* ░░░ Ajustes cuando la pantalla es chica ░░░ */
    @media (max-width:768px){

      /* ——— 1) Botones de asistencia apilados ——— */
      .attendance-options{                 /* ambos módulos (próx. y pasados) */
        flex-direction:column;             /* ✔ ancho mínimo (~80 px)         */
        gap:.35rem;
        align-items:flex-start;
      }
      .att-item .pill{                     /* mini‑píldoras                   */
        padding:.25rem .55rem;
        font-size:.75rem;
        width:64px;                        /* ancho constante para alinear    */
        text-align:center;
      }

      /* ——— 2) Contenedor “past‑attendance” más compacto ——— */
      .past-attendance{
        flex-direction:column;
        align-items:flex-start;
        gap:.6rem;
      }
      .past-attendance .extras{
        flex-direction:column;             /* select y texto uno debajo del otro */
        gap:.45rem;
      }

      /* limitamos el ancho para que la sticky‑col siga siendo angosta */
      .past-attendance .past-just,
      .past-attendance .past-other{
        width:120px;
        max-width:120px;
      }

      /* ——— 3) Aseguramos fondo sólo del ancho ocupado ——— */
      th.sticky-col,
      td.sticky-col{
        min-width:94px;                    /* ≈64 px pill + padding           */
        background:#ffffff;
      }
    }

    /* === Cabecera del modal “Ver detalles” uniformada === */
    #modal-detalles .card-header{
      background:linear-gradient(90deg,var(--primary) 0%, #ff7a33 60%, #ff934d 100%);
      color:#fff;
    }
    #modal-detalles .modal-close{
      background:rgba(255,255,255,.15);
      color:#fff;
    }
    #modal-detalles .modal-close:hover{
      background:rgba(255,255,255,.28);
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
  <main id="home-main">
  <div id="home-card">
    <!-- ── Próximos eventos ── -->
    <section id="upcoming-attendance" style="margin-bottom:2rem">
      <h2>Próximos eventos</h2>

      <?php if (empty($upcomingEvents)): ?>
        <p>No tienes próximos eventos.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th>Inicio</th><th>Término</th><th>Evento</th>
                <th>Equipo / Proyecto</th><th>Estado previo</th>
                <th>Asistencia previa</th><th>Estado final</th>
                <th class="sticky-col">¿Asistirás?</th><th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php 
                $dias = [
                  '0'=>'Domingo','1'=>'Lunes','2'=>'Martes','3'=>'Miércoles',
                  '4'=>'Jueves','5'=>'Viernes','6'=>'Sábado'
                ];
              ?>
              <?php foreach ($upcomingEvents as $ev): 
                // -- fechas con fallback
                $fi_raw = $ev['fecha_hora_inicio'] ?? null;
                $fi = $fi_raw
                  ? date('d/m/Y H:i', strtotime($fi_raw))
                  : '—';
                $ft_raw = $ev['fecha_hora_termino'] ?? null;
                $ft = $ft_raw
                  ? date('d/m/Y H:i', strtotime($ft_raw))
                  : '—';

                // -- otros campos null-safe
                $nombre   = htmlspecialchars($ev['nombre_evento'] ?? '', ENT_QUOTES);
                $lugar    = htmlspecialchars($ev['lugar']          ?? '', ENT_QUOTES);
                $desc     = htmlspecialchars($ev['descripcion']    ?? '', ENT_QUOTES);
                $obs      = htmlspecialchars($ev['observacion']    ?? '', ENT_QUOTES);
                $previo   = htmlspecialchars($ev['nombre_estado_previo'] ?? '', ENT_QUOTES);
                $final    = htmlspecialchars($ev['nombre_estado_final']  ?? '', ENT_QUOTES);

                // -- asistentes
                $cnt_pres = (int)($ev['cnt_presente']      ?? 0);
                $cnt_tot  = (int)($ev['total_integrantes'] ?? 0);

                // -- equipos
                $rawTeams = $ev['equipos'] ?? '';
                $teams    = array_filter(array_map('trim', explode(',', $rawTeams)));
                // -- estado actual del usuario
                $stmtA = $pdo->prepare("
                  SELECT id_estado_previo_asistencia
                    FROM asistencias
                   WHERE id_usuario = ?
                     AND id_evento  = ?
                ");
                $stmtA->execute([$id_usuario, $ev['id_evento']]);
                $current = (int)$stmtA->fetchColumn(); // 1,2,3 o 0
              ?>
              <tr>
                <td><?= $fi ?></td>
                <td><?= $ft ?></td>
                <td><?= $nombre ?></td>
                <td>
                  <ul class="equipos-list">
                    <?php if (empty($teams)): ?>
                      <li>General</li>
                    <?php else: ?>
                      <?php foreach ($teams as $t): ?>
                        <li><?= htmlspecialchars($t, ENT_QUOTES) ?></li>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </ul>
                </td>
                <td><?= $previo ?></td>
                <td><?= "{$cnt_pres} de {$cnt_tot}" ?></td>
                <td><?= $final ?></td>
                <!-- Asistencia interactiva -->
                <td class="sticky-col">
                  <div class="attendance-options" data-event-id="<?= $ev['id_evento'] ?>">
                    <?php
                      $labels = [1=>'Sí', 2=>'No', 3=>'No sé'];
                      for ($i=1; $i<=3; $i++):
                        $sel = $current === $i ? 'selected' : '';
                        $chk = $current === $i ? 'checked'  : '';
                    ?>
                      <label class="att-item att-<?= $i ?> <?= $sel ?>">
                        <input 
                          type="radio" 
                          name="att_<?= $ev['id_evento'] ?>" 
                          value="<?= $i ?>" 
                          <?= $chk ?> hidden>
                        <span class="pill"><?= $labels[$i] ?></span>
                        <span class="circle"></span>
                      </label>
                    <?php endfor; ?>
                  </div>
                </td>
                <!-- Botones -->
                <td class="actions">
                  <!-- Detalles -->
                  <?php 
                    $fi_lbl = $dias[date('w', strtotime($fi_raw))] . ' ' . date('d', strtotime($fi_raw)) . ' | ' . date('H.i', strtotime($fi_raw)) . ' horas';
                    $ft_lbl = $dias[date('w', strtotime($ft_raw))] . ' ' . date('d', strtotime($ft_raw)) . ' | ' . date('H.i', strtotime($ft_raw)) . ' horas';
                  ?>
                  <button 
                    class="action-btn detail-btn"
                    title="Ver detalles"
                    data-fi="<?= $fi_lbl ?>"
                    data-ft="<?= $ft_lbl ?>"
                    data-nombre="<?= $nombre ?>"
                    data-lugar="<?= $lugar ?>"
                    data-descripcion="<?= $desc ?>"
                    data-equipos="<?= htmlspecialchars($rawTeams ?: 'General', ENT_QUOTES) ?>"
                    data-previo="<?= $previo ?>"
                    data-asist="<?= "{$cnt_pres} de {$cnt_tot}" ?>"
                    data-encargado="<?= htmlspecialchars($ev['encargado_nombre_completo'] ?? '', ENT_QUOTES) ?>"
                    data-tipo="<?= htmlspecialchars($ev['nombre_tipo'] ?? '', ENT_QUOTES) ?>"
                    data-observacion="<?= $obs ?>"
                    data-can-see-observacion="<?= $ev['show_observacion'] ? '1':'0' ?>"
                    data-final="<?= $final ?>"
                  ><i class="fas fa-eye"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <?php
      // ── Registrar asistencia en eventos pasados ──

      // 1) Coger los mismos equipos del usuario que para los próximos
      $placeholders = implode(',', array_fill(0, count($userTeamIds), '?'));

      // 2) Traer todos los eventos que ya empezaron, pero que no hayan terminado
      //    hace más de 1 día, con los mismos filtros de estado y participación.
      $sqlPast = "
        SELECT
          e.id_evento,
          e.nombre_evento,
          e.lugar,
          e.fecha_hora_inicio,
          e.fecha_hora_termino,
          CASE
            WHEN e.es_general = 1 THEN 'General'
            ELSE GROUP_CONCAT(DISTINCT epj.nombre_equipo_proyecto SEPARATOR ', ')
          END AS equipos
        FROM eventos e
        LEFT JOIN equipos_proyectos_eventos AS user_epe
          ON e.id_evento = user_epe.id_evento
        AND user_epe.id_equipo_proyecto IN ($placeholders)
        LEFT JOIN equipos_proyectos_eventos AS all_epe
          ON e.id_evento = all_epe.id_evento
        LEFT JOIN equipos_proyectos AS epj
          ON all_epe.id_equipo_proyecto = epj.id_equipo_proyecto
        WHERE
          e.fecha_hora_inicio   <= NOW()                           -- ya empezó
          AND e.fecha_hora_termino >= DATE_SUB(NOW(), INTERVAL 1 DAY) -- no terminó hace más de 24 h
          AND e.id_estado_previo   = 1
          AND e.id_estado_final   NOT IN (5,6)
          AND (
            user_epe.id_equipo_proyecto IS NOT NULL
            OR e.es_general = 1
          )
        GROUP BY e.id_evento
        ORDER BY e.fecha_hora_termino DESC
      ";
      $stmtPast = $pdo->prepare($sqlPast);
      $stmtPast->execute($userTeamIds);
      $pastEvents = $stmtPast->fetchAll(PDO::FETCH_ASSOC);

      // 3) Traer listas de estados y justificaciones
      $states     = $pdo->query("SELECT id_estado_asistencia, nombre_estado_asistencia FROM estados_asistencia ORDER BY id_estado_asistencia")->fetchAll(PDO::FETCH_ASSOC);
      $justs      = $pdo->query("SELECT id_justificacion_inasistencia, nombre_justificacion_inasistencia FROM justificacion_inasistencia ORDER BY id_justificacion_inasistencia")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section id="past-attendance" style="margin-bottom:2rem">
      <h2>Registrar asistencia en eventos pasados</h2>

      <?php if (empty($pastEvents)): ?>
        <p>No tienes eventos recientes para marcar.</p>
      <?php else: ?>
        <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Inicio</th><th>Término</th><th>Evento</th>
              <th>Equipo / Proyecto</th><th>Lugar</th><th class="sticky-col">Asistencia</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pastEvents as $ev): 
              // fechas
              $si_raw = $ev['fecha_hora_inicio']  ?? null;
              $st_raw = $ev['fecha_hora_termino'] ?? null;
              $si = $si_raw ? date('d/m/Y H:i', strtotime($si_raw)) : '—';
              $st = $st_raw ? date('d/m/Y H:i', strtotime($st_raw)) : '—';
              // nombre, lugar
              $nombre = htmlspecialchars($ev['nombre_evento'] ?? '', ENT_QUOTES);
              $lugar  = htmlspecialchars($ev['lugar']         ?? '', ENT_QUOTES);
              // equipos
              $rawT = $ev['equipos'] ?? '';
              $teams= array_filter(array_map('trim', explode(',', $rawT)));
              // asistencia actual
              $stmtA = $pdo->prepare("
                SELECT id_estado_asistencia, id_justificacion_inasistencia, descripcion_otros
                  FROM asistencias
                 WHERE id_usuario = ? AND id_evento = ?
              ");
              $stmtA->execute([$id_usuario, $ev['id_evento']]);
              $cur = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];
            ?>
            <tr>
              <td><?= $si ?></td>
              <td><?= $st ?></td>
              <td><?= $nombre ?></td>
              <td>
                <ul class="equipos-list">
                  <?php if (empty($teams)): ?>
                    <li>General</li>
                  <?php else: ?>
                    <?php foreach ($teams as $t): ?>
                      <li><?= htmlspecialchars($t, ENT_QUOTES) ?></li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </td>
              <td><?= $lugar ?></td>
              <td class="sticky-col">
                <?php
                  // estado actual
                  $curState = $cur['id_estado_asistencia'] ?? 0;
                  $curJust  = $cur['id_justificacion_inasistencia'] ?? '';
                  $curOther = $cur['descripcion_otros'] ?? '';

                  // ── flags para mostrar al cargar ──
                  // mostramos toda la sección de "extras" si está justificado o si ya hay texto en "Otros"
                  $showExtras     = $curState === 3 || $curOther !== '';
                  // el select de justificación se muestra si existe justificación o texto en "Otros"
                  $showJustSelect = $curState === 3 || $curOther !== '';
                  // el campo “Otros” se muestra si eligió la opción 9 o ya hay texto guardado
                  $showOtherInput = $curJust === '9' || $curOther !== '';
                ?>
                <div class="past-attendance" data-ev="<?= $ev['id_evento'] ?>">
                  <!-- 1) Botones de estado, igual que antes… -->
                  <div class="attendance-options">
                    <?php foreach ($states as $s):
                      $sid = $s['id_estado_asistencia'];
                      $sel = $sid == $curState ? 'selected' : '';
                      $chk = $sid == $curState ? 'checked'  : '';
                    ?>
                    <label class="att-item att-<?= $sid ?> <?= $sel ?>">
                      <input type="radio"
                            name="past_<?= $ev['id_evento'] ?>"
                            value="<?= $sid ?>"
                            <?= $chk ?> hidden>
                      <span class="pill"><?= htmlspecialchars($s['nombre_estado_asistencia'], ENT_QUOTES) ?></span>
                      <span class="circle"></span>
                    </label>
                    <?php endforeach; ?>
                  </div>

                  <!-- 2) Extras: render siempre pero oculto/visible según PHP -->
                  <div class="extras" style="display: <?= $showExtras     ? 'flex'          : 'none' ?>;">
                    <select class="past-just"
                            style="display: <?= $showJustSelect ? 'inline-block' : 'none' ?>;">
                      <option value="">—</option>
                      <?php foreach ($justs as $j): ?>
                        <?php
                          // saltamos id=10 y id=11 para que no aparezcan
                          if (in_array($j['id_justificacion_inasistencia'], [10,11], true)) {
                              continue;
                          }
                        ?>
                        <option 
                          value="<?= $j['id_justificacion_inasistencia'] ?>"
                          <?= $j['id_justificacion_inasistencia']==$curJust?'selected':'' ?>>
                          <?= htmlspecialchars($j['nombre_justificacion_inasistencia'], ENT_QUOTES) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text"
                          class="past-other"
                          placeholder="Describir..."
                          maxlength="255"
                          value="<?= htmlspecialchars($curOther, ENT_QUOTES) ?>"
                          style="display: <?= $showOtherInput ? 'inline-block' : 'none' ?>;">
                    <span class="error-msg">
                      Sólo A-Z, 0-9, espacio y ,.;:()¡!¿?_- (máx.255)
                    </span>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div><!-- /table‑responsive -->
      <?php endif; ?>
    </section>

    <script>
    document.querySelectorAll('.past-attendance').forEach(wrapper => {
      const radios     = wrapper.querySelectorAll('.attendance-options input[type=radio]');
      const extras     = wrapper.querySelector('.extras');
      const selectJust = extras.querySelector('.past-just');
      const inputOther = extras.querySelector('.past-other');
      const errMsg     = extras.querySelector('.error-msg');
      const evId       = wrapper.dataset.ev;

      // 1) Cambio de estado principal
      radios.forEach(radio => {
        radio.addEventListener('change', () => {
          wrapper.querySelectorAll('.att-item').forEach(l => l.classList.remove('selected'));
          radio.closest('.att-item').classList.add('selected');

          const v = radio.value;

          // Presente → justificación 10
          if (v === '1') {
            extras.style.display = 'none';
            savePast(evId, v, 10, null);
            return;
          }
          // Ausente → justificación 11
          if (v === '2') {
            extras.style.display = 'none';
            savePast(evId, v, 11, null);
            return;
          }
          // “No sé” → abrimos select como antes
          // (estado v==='3')
          extras.style.display     = 'flex';
          selectJust.style.display = 'inline-block';
        });
      });

      // 2) Cambio de justificación
      selectJust.addEventListener('change', () => {
        const j = selectJust.value;
        if (j === '9') {
          // “Otros”
          inputOther.style.display = 'inline-block';
        } else {
          inputOther.style.display = 'none';
          inputOther.value = '';
          errMsg.style.display = 'none';
          savePast(evId, wrapper.querySelector('.attendance-options input:checked').value, j, null);
        }
      });

      // 3) Validación inline al escribir “Otros”
      inputOther.addEventListener('input', () => {
        const val = inputOther.value;
        // sólo letras, números, espacios y estos símbolos ,.;:()¡!¿?_- / largo ≤255
        const valid = /^[A-Za-zÁÉÍÓÚáéíóúÑñ0-9\s,.;:()¡!¿?_\-]*$/.test(val) && val.length <= 255;
        errMsg.style.display = valid ? 'none' : 'block';
      });
      // 4) Guardar al perder foco (si no hay error)
      inputOther.addEventListener('blur', () => {
        if (errMsg.style.display === 'none') {
          const st   = wrapper.querySelector('.attendance-options input:checked').value;
          const just = selectJust.value;
          savePast(evId, st, just, inputOther.value);
        }
      });

    }); // end each

    // función ya existente para POST
    async function savePast(ev, st, just, other) {
      const form = new FormData();
      form.append('id_evento', ev);
      form.append('id_estado_asistencia', st);
      if (just)  form.append('id_justificacion_inasistencia', just);
      if (other) form.append('descripcion_otros', other);

      const res  = await fetch('marcar_asistencia_pasados.php', {
        method: 'POST',
        body: form
      });
      const data = await res.json();
      if (!data.ok) alert(data.error || 'Error guardando asistencia');
    }
    </script>
  </div><!-- /home-card -->
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

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script src="eventos.js"></script> <!-- ya tenías en eventos.php -->
  <script>
    // AHORA: sólo dentro de #upcoming-attendance
    document
      .querySelectorAll('#upcoming-attendance .attendance-options input[type=radio]')
      .forEach(radio => {
        radio.addEventListener('change', async e => {
          const container = e.target.closest('.attendance-options');
          const evId      = container.dataset.eventId;       // ya existe en próximos
          const valor     = e.target.value;

          const form = new FormData();
          form.append('id_evento', evId);
          form.append('id_estado_previo_asistencia', valor);

          const res  = await fetch('marcar_asistencia.php', {
            method: 'POST',
            body: form
          });
          const data = await res.json();
          if (!data.ok) {
            return alert(data.error || 'Error al guardar');
          }

          // 2) Actualizar contador “Presente” en la celda (6ª columna)
          const row  = container.closest('tr');
          const cell = row.querySelector('td:nth-child(6)');
          cell.textContent = `${data.cnt_presente} de ${data.total_integrantes}`;

          // 3) Refrescar UI: marcar solo el label seleccionado
          container.querySelectorAll('.att-item').forEach(lbl => {
            lbl.classList.remove('selected');
          });
          e.target.closest('label').classList.add('selected');
        });
      });
  </script>
</body>
</html>
