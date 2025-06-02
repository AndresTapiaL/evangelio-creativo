<?php
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id = $_SESSION['id_usuario'];

// — Trae nombre y foto para el menú —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
$stmt->execute(['id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* justo después de leer $user */
$isLiderNacional = ($pdo->query("
      SELECT 1
        FROM integrantes_equipos_proyectos
       WHERE id_usuario = $id
         AND id_equipo_proyecto = 1
       LIMIT 1")->fetchColumn()) ? true : false;

/* Equipos que verá el usuario */
$teams = $isLiderNacional
         ? $pdo->query("SELECT id_equipo_proyecto,nombre_equipo_proyecto
                          FROM equipos_proyectos
                         ORDER BY nombre_equipo_proyecto")
                ->fetchAll(PDO::FETCH_ASSOC)
         : $pdo->prepare("
              SELECT ep.id_equipo_proyecto, ep.nombre_equipo_proyecto
                FROM integrantes_equipos_proyectos iep
                JOIN equipos_proyectos ep USING(id_equipo_proyecto)
               WHERE iep.id_usuario = :u
               ORDER BY ep.nombre_equipo_proyecto");
if (!$isLiderNacional){ $teams->execute(['u'=>$id]); $teams=$teams->fetchAll(PDO::FETCH_ASSOC); }
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

    .team-btn{
    border:none;background:#eee;padding:.4rem;border-radius:4px;cursor:pointer;width:100%;
    }
    .team-btn.active{background:#ffcb31;font-weight:bold;}
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
      <a href="asistencia.php">Asistencia</a>
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
    <div style="display:grid;grid-template-columns:200px 1fr;gap:1rem">

        <!-- =====  SIDEBAR  ===== -->
        <aside style="background:#f9f9f9;padding:1rem;border-radius:6px">
            <h3 style="margin-top:0">Equipos/Proyectos</h3>
            <ul id="team-list" style="list-style:none;padding:0">
            <?php foreach($teams as $ix=>$t): ?>
              <li style="margin-top:.4rem">
                <button class="team-btn<?= $ix==0?' active':'' ?>"
                        data-id="<?= $t['id_equipo_proyecto'] ?>" style="width:100%">
                    <?= htmlspecialchars($t['nombre_equipo_proyecto']) ?>
                </button>
              </li>
            <?php endforeach; ?>
            </ul>
        </aside>
        <section>
            <h1>Reportes</h1>

            <!-- pestañas -->
            <nav id="tabs" style="margin:1rem 0">
                <button data-report="integrantes"   id="tab-integrantes">Justificaciones · Integrantes</button>
                <button data-report="eventos"       id="tab-eventos">Justificaciones · Eventos</button>
                <button data-report="equipos"       id="tab-equipos">Equipos</button>
                <button data-report="eventos_estado" id="tab-ev-estado">Eventos · Estados</button>
            </nav>

            <!-- barra de periodos -->
            <div id="period-buttons" style="margin-bottom:1rem"></div>

            <!-- el reporte se inyecta aquí -->
            <div id="report-container" class="table-responsive"></div>
        </section>
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

  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
  <script defer src="reportes.js"></script>
</body>
</html>
