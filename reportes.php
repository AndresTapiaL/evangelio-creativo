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

    #tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-bottom: 1rem;
    }
    #tabs button {
      padding: 0.5rem 1rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      background: #f0f0f0;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    #tabs button:hover {
      background: #e6e6e6;
    }
    #tabs button.active {
      background: #ffcb31;
      border-color: #e6b800;
      font-weight: bold;
    }

    /* ── Periodos: ejemplo de botones dentro de #period-buttons ── */
    #period-buttons {
      display: block;
      margin-bottom: 1rem;
    }
    #period-buttons button {
      margin-right: 0.5rem;
      margin-bottom: 0.5rem;
      padding: 0.4rem 0.8rem;
      border: 1px solid #bbb;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
    }
    #period-buttons button.active {
      background: #ffcb31;
      border-color: #e6b800;
      font-weight: bold;
    }

    /* ── Sidebar de Equipos (lista de .team-btn) ── */
    .team-btn {
      width: 100%;
      text-align: left;
      padding: 0.4rem;
      margin-bottom: 0.25rem;
      border: none;
      background: #eee;
      cursor: pointer;
      border-radius: 4px;
      transition: background 0.15s;
    }
    .team-btn:hover {
      background: #e0e0e0;
    }
    .team-btn.active {
      background: #ffcb31;
      font-weight: bold;
    }

    /* Agrupar visualmente cada año */
    .grupo-anio {
      margin-bottom: 1rem;
      border-left: 3px solid #ccc;   /* solo para marcar la separación, opcional */
      padding-left: 0.5rem;
    }

    /* El <h4> que aparece por cada año */
    .grupo-anio h4 {
      color: #222;
    }

    /* Los botones hijos (períodos) pueden compartir .btn-periodo,
      pero si quieres, puedes darles un estilo extra cuando están dentro de un .grupo-anio */
    .grupo-anio .btn-periodo {
      margin-right: 0.5rem;
      margin-bottom: 0.5rem;
      padding: 0.4rem 0.8rem;
      border: 1px solid #bbb;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
      display: inline-block;
    }
    .grupo-anio .btn-periodo.active {
      background: #ffcb31;
      border-color: #e6b800;
      font-weight: bold;
    }

    /* ── estilo para el contenedor de flechas + año ── */
    #period-buttons {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }

    /* Flechas */
    .arrow-btn {
      font-size: 1.2rem;
      padding: 0.25rem 0.5rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    .arrow-btn:hover {
      background: #e6e6e6;
    }

    /* Etiqueta de año (2025, 2024, etc.) */
    .anio-label {
      font-weight: bold;
      font-size: 1rem;
      color: #222;
    }

    /* Contenedor de los botones de ese año */
    .periodos-del-anio {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-top: 0.75rem;
      justify-content: center;
      width: 100%;
    }

    /* Botones de trimestre (mismo estilo que usabas antes) */
    .periodos-del-anio .btn-periodo {
      padding: 0.4rem 0.8rem;
      border: 1px solid #bbb;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    .periodos-del-anio .btn-periodo:hover {
      background: #e6e6e6;
    }
    .periodos-del-anio .btn-periodo.active {
      background: #ffcb31;
      border-color: #e6b800;
      font-weight: bold;
    }
    /* Puedes poner esto en tu CSS o inyectarlo con JavaScript */
    section.ocupandoTodoElEspacio {
      /* Hacer que ocupe columna 1 hasta la última columna del grid (span 2 columnas) */
      grid-column: 1 / -1;
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
                <button data-report="eventos_estado" id="tab-eventos_estado">Eventos · Estados</button>
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
