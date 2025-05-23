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

// 2. Query de eventos futuros asociados
$placeholders = implode(',', array_fill(0, count($userTeamIds), '?'));
$sqlUp = "
  SELECT
    e.id_evento,
    e.nombre_evento,
    e.fecha_hora_inicio,
    e.fecha_hora_termino,
    prev.nombre_estado_previo,
    fin.nombre_estado_final,
    GROUP_CONCAT(DISTINCT epj.nombre_equipo_proyecto SEPARATOR ', ') AS equipos,
    COALESCE(ap.cnt_presente, 0)      AS cnt_presente,
    COALESCE(tu.total_integrantes, 0) AS total_integrantes
  FROM eventos e
  JOIN equipos_proyectos_eventos epe
    ON e.id_evento = epe.id_evento
  JOIN equipos_proyectos epj
    ON epe.id_equipo_proyecto = epj.id_equipo_proyecto
  LEFT JOIN estados_previos_eventos prev
    ON e.id_estado_previo = prev.id_estado_previo
  LEFT JOIN estados_finales_eventos fin
    ON e.id_estado_final  = fin.id_estado_final

  /* asistentes “Presente” */
  LEFT JOIN (
    SELECT id_evento, COUNT(*) AS cnt_presente
      FROM asistencias
     WHERE id_estado_previo_asistencia = 1
     GROUP BY id_evento
  ) ap ON ap.id_evento = e.id_evento

  /* total integrantes únicos por evento */
  LEFT JOIN (
    SELECT epe.id_evento,
           COUNT(DISTINCT iep.id_usuario) AS total_integrantes
      FROM equipos_proyectos_eventos epe
      JOIN integrantes_equipos_proyectos iep
        ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     GROUP BY epe.id_evento
  ) tu ON tu.id_evento = e.id_evento

  WHERE e.fecha_hora_inicio > NOW()
    AND epe.id_equipo_proyecto IN ($placeholders)

  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
  LIMIT 5
";
$stmtUp = $pdo->prepare($sqlUp);
$stmtUp->execute($userTeamIds);
$upcomingEvents = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

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
                <td><?= htmlspecialchars($ev['equipos']) ?></td>
                <td><?= htmlspecialchars($ev['nombre_estado_previo']) ?></td>
                <td><?= (int)$ev['cnt_presente'] ?> de <?= (int)$ev['total_integrantes'] ?></td>
                <td><?= htmlspecialchars($ev['nombre_estado_final']) ?></td>
                <!-- Opciones de asistencia -->
                <td>
                  <div class="attendance-options"
                      data-event-id="<?= $ev['id_evento'] ?>">
                    <?php
                      $labels = [1=>'Presente', 2=>'Ausente', 3=>'No sé'];
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

  <!-- Modal Detalles Evento (copiado de eventos.php) -->
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
