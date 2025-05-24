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
    e.fecha_hora_inicio > NOW()
    AND e.id_estado_previo = 1
    /* sólo si participas en el equipo O es general */
    AND (
      user_epe.id_equipo_proyecto IS NOT NULL
      OR e.es_general = 1
    )

  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
  LIMIT 3
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
        <?= htmlspecialchars($user['nombres']) ?>
      </span>
      <img
        id="foto-perfil-nav"
        src="<?= htmlspecialchars($user['foto_perfil']) ?>"
        alt="Foto de <?= htmlspecialchars($user['nombres']) ?>">
      <a href="#" id="logout" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <!-- ░░░░ CONTENIDO PRINCIPAL ░░░░ -->
  <main style="padding:2rem">
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
                <th>Equipo/Proyecto</th><th>Estado previo</th>
                <th>Asist. previa</th><th>Estado final</th>
                <th>¿Asistirás?</th><th>Acciones</th>
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
              $si = strtotime($ev['fecha_hora_inicio']);
              $st = strtotime($ev['fecha_hora_termino']);

              // dentro de tu foreach, antes del <tr>
              $stmtA = $pdo->prepare("
                SELECT id_estado_previo_asistencia
                  FROM asistencias
                WHERE id_usuario = ?
                  AND id_evento  = ?
              ");
              $stmtA->execute([$id_usuario, $ev['id_evento']]);
              $current = (int)$stmtA->fetchColumn();  // 1,2,3 o 0 si no existe
            ?>
              <tr>
                <td><?= date('d/m/Y H:i', $si) ?></td>
                <td><?= date('d/m/Y H:i', $st) ?></td>
                <td><?= htmlspecialchars($ev['nombre_evento']) ?></td>
                <td>
                  <?php
                    // Si no hay nada, 'General'
                    $raw   = $ev['equipos'] ?: 'General';
                    $teams = array_filter(array_map('trim', explode(',', $raw)));
                  ?>
                  <ul class="equipos-list">
                    <?php foreach ($teams as $team): ?>
                      <li><?= htmlspecialchars($team) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>
                <td><?= htmlspecialchars($ev['nombre_estado_previo']) ?></td>
                <td><?= (int)$ev['cnt_presente'] ?> de <?= (int)$ev['total_integrantes'] ?></td>
                <td><?= htmlspecialchars($ev['nombre_estado_final']) ?></td>
                <!-- Opciones de asistencia -->
                <td>
                  <div class="attendance-options"
                      data-event-id="<?= $ev['id_evento'] ?>">
                    <?php
                      $labels = [1=>'Sí', 2=>'No', 3=>'No sé'];
                      for ($i = 1; $i <= 3; $i++):
                        $sel = $current === $i ? 'selected' : '';
                        $chk = $current === $i ? 'checked'  : '';
                    ?>
                      <label class="att-item att-<?= $i ?> <?= $sel ?>">
                        <input
                          type="radio"
                          name="att_<?= $ev['id_evento'] ?>"
                          value="<?= $i ?>"
                          <?= $chk ?>
                          hidden
                        >
                        <span class="pill"><?= $labels[$i] ?></span>
                        <span class="circle"></span>
                      </label>
                    <?php endfor; ?>
                  </div>
                </td>
                <!-- Acciones: Ver detalles + Notificar -->
                <td class="actions">
                  <!-- Ver detalles -->
                  <?php 
                    // formatea igual que en eventos.php
                    $fi = $dias[date('w',$si)] . ' ' . date('d',$si) . ' | ' 
                        . date('H.i',$si) . ' horas';
                    $ft = $dias[date('w',$st)] . ' ' . date('d',$st) . ' | '
                        . date('H.i',$st) . ' horas';
                  ?>
                  <button
                    title="Ver detalles"
                    class="action-btn detail-btn"
                    data-fi="<?= $dias[date('w',$si)] . ' ' . date('d',$si) . ' | ' . date('H.i',$si) . ' horas' ?>"
                    data-ft="<?= $dias[date('w',$st)] . ' ' . date('d',$st) . ' | ' . date('H.i',$st) . ' horas' ?>"
                    data-nombre="<?= htmlspecialchars($ev['nombre_evento'], ENT_QUOTES) ?>"
                    data-lugar="<?= htmlspecialchars($ev['lugar'] ?? '',         ENT_QUOTES) ?>"
                    data-encargado="<?= htmlspecialchars($ev['encargado_nombre_completo'] ?? '', ENT_QUOTES) ?>"
                    data-descripcion="<?= htmlspecialchars($ev['descripcion'] ?? '', ENT_QUOTES) ?>"
                    data-equipos="<?= htmlspecialchars($ev['equipos'] ?: 'General', ENT_QUOTES) ?>"
                    data-previo="<?= htmlspecialchars($ev['nombre_estado_previo'] ?? '', ENT_QUOTES) ?>"
                    data-tipo="<?= htmlspecialchars($ev['nombre_tipo'] ?? '', ENT_QUOTES) ?>"
                    data-asist="<?= (int)$ev['cnt_presente'] . ' de ' . (int)$ev['total_integrantes'] ?>"
                    data-observacion="<?= htmlspecialchars($ev['observacion'] ?? '', ENT_QUOTES) ?>"
                    data-can-see-observacion="<?= $ev['show_observacion'] ? '1':'0' ?>"
                    data-final="<?= htmlspecialchars($ev['nombre_estado_final'] ?? '', ENT_QUOTES) ?>"
                  >
                    <i class="fas fa-eye"></i>
                  </button>

                  <!-- Notificar -->
                  <button title="Notificar" class="action-btn notify-btn"
                          data-id="<?= $ev['id_evento'] ?>">
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
    document.querySelectorAll('.attendance-options input[type=radio]')
      .forEach(radio => {
        radio.addEventListener('change', async e => {
          const container = e.target.closest('.attendance-options');
          const evId      = container.dataset.eventId;
          const valor     = e.target.value;

          // 1) Enviar al servidor
          const form = new FormData();
          form.append('id_evento', evId);
          form.append('id_estado_previo_asistencia', valor);

          const res = await fetch('marcar_asistencia.php', {
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
