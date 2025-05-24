<?php
// ver_mis_datos.php
// Renderizado en servidor + protección vía JS (validar_token.php & heartbeat.js)

date_default_timezone_set('UTC');
require 'conexion.php';

// NO requerimos validar_token.php aquí para no abortar el HTML.
// La validación la hará el bloque de JS en <head>.

session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id = $_SESSION['id_usuario'];

// 1) Datos básicos de usuario + email + boletín + verificación
$stmt = $pdo->prepare("
  SELECT 
    u.*, 
    c.correo_electronico AS correo, 
    c.boletin, 
    c.verified, 
    c.verify_token, 
    c.token_expires
  FROM usuarios u
  JOIN correos_electronicos c 
    ON u.id_usuario = c.id_usuario
  WHERE u.id_usuario = :id
");
$stmt->execute(['id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);


// 2) Ubicación
// 2a) País
$pstmt = $pdo->prepare("
  SELECT nombre_pais 
  FROM paises 
  WHERE id_pais = :id_pais
");
$pstmt->execute(['id_pais' => $user['id_pais']]);
$pais = $pstmt->fetchColumn() ?: '';

// 2b) Región / Estado
$rstmt = $pdo->prepare("
  SELECT nombre_region_estado 
  FROM region_estado 
  WHERE id_region_estado = :id_reg
");
$rstmt->execute(['id_reg' => $user['id_region_estado']]);
$region = $rstmt->fetchColumn() ?: '';

// 2c) Ciudad / Comuna
$cstmt = $pdo->prepare("
  SELECT nombre_ciudad_comuna 
  FROM ciudad_comuna 
  WHERE id_ciudad_comuna = :id_ciu
");
$cstmt->execute(['id_ciu' => $user['id_ciudad_comuna']]);
$ciudad = $cstmt->fetchColumn() ?: '';

// 3) Ocupaciones
$ocstmt = $pdo->prepare("
  SELECT o.nombre
  FROM usuarios_ocupaciones uo
  JOIN ocupaciones o ON uo.id_ocupacion = o.id_ocupacion
  WHERE uo.id_usuario = :id
");
$ocstmt->execute(['id' => $id]);
$ocupaciones = array_column($ocstmt->fetchAll(PDO::FETCH_ASSOC), 'nombre');

// 4) Teléfonos con descripción
$tstmt = $pdo->prepare("
  SELECT t.telefono, dt.nombre_descripcion_telefono AS descripcion, t.es_principal
  FROM telefonos t
  LEFT JOIN descripcion_telefonos dt 
    ON t.id_descripcion_telefono = dt.id_descripcion_telefono
  WHERE t.id_usuario = :id
");
$tstmt->execute(['id' => $id]);
$telefonos = $tstmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Roles y Equipos
$restmt = $pdo->prepare("
  SELECT 
    r.nombre_rol AS rol, 
    ep.nombre_equipo_proyecto AS equipo
  FROM integrantes_equipos_proyectos iep
  JOIN roles r 
    ON iep.id_rol = r.id_rol
  JOIN equipos_proyectos ep 
    ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
  WHERE iep.id_usuario = :id
");
$restmt->execute(['id' => $id]);
$rolesEquipos = $restmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate"/>
  <title>Mis Datos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>

  <!-- estilos originales -->
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    .grupo {
      margin-bottom: 1rem;
      width: 100%;
      box-sizing: border-box;
    }
    .grupo label {
      font-weight: bold;
      display: block;
      margin-bottom: .25rem;
    }
    .grupo span {
      display: inline-block;
      width: 100%;
      padding: .5rem;
      background: #f0f0f0;
      border-radius: 5px;
      box-sizing: border-box;
    }
    /* ─── Foto de perfil circular fija ─── */
    img.foto {
      width: 120px;           /* ancho fijo */
      height: 120px;          /* alto fijo */
      border-radius: 50%;     /* círculo perfecto */
      object-fit: cover;      /* recorta centrado si la imagen no es cuadrada */
      display: block;         /* elimina espacios blancos si hay */
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    th, td {
      border: 1px solid #ddd;
      padding: .5rem;
      text-align: left;
    }
    th {
      background: #f3f3f3;
    }
    .editar-btn {
      text-align: center;
      margin-top: 2rem;
    }
    .editar-btn button {
      background: linear-gradient(135deg,#ff7b3a 0%,#ff5722 100%);
      color: #fff;
      border: none;
      padding: .75rem 2.5rem;
      font-size: 1rem;
      font-weight: bold;
      border-radius: 8px;
      cursor: pointer;
      transition: .2s;
      box-shadow: 0 4px 10px rgba(255,87,34,.3);
    }
    .editar-btn button:hover {
      filter: brightness(1.1);
      transform: translateY(-2px);
    }
    #overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.7);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 2000;
    }
    #overlay img {
      max-width: 80vw;
      max-height: 80vh;
      border-radius: 10px;
      box-shadow: 0 0 20px rgba(0,0,0,.6);
    }
    #overlay .close {
      position: absolute;
      top: 30px;
      right: 40px;
      color: #fff;
      font-size: 2rem;
      font-weight: bold;
      cursor: pointer;
    }

    /* ——— Estado de verificación ——— */
    .status.verified {
      color: #28a745;
    }
    .status.not-verified {
      color: #dc3545;
    }

    /* ——— Fila de Correo Electrónico (idéntica a Ocupación) ——— */
    .grupo.email-group > span {
      display: flex;
      align-items: center;
      gap: 1rem;

      /* ==== AÑADE ESTAS LÍNEAS ==== */
      padding: .1rem;           /* mismo padding que .grupo span */
      padding-right: .75rem;
      background: #f0f0f0;      /* igual que ocupación */
      border-radius: 5px;       /* igual que ocupación */
      box-sizing: border-box;   /* para que el padding no aumente el ancho */
      /* ============================ */
    }
    /* El texto del correo ocupa el espacio restante */
    .grupo.email-group > span .email-text {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin: 0;
      color: #black;
      font-size: 1rem;
    }
    /* Botón “Verificar” en verde */
    .grupo.email-group > span .btn-verify {
      padding: .4rem .8rem;
      font-size: .9rem;
      background: #fff;
      border: 1px solid #28a745;
      color: #28a745;
      border-radius: 4px;
      cursor: pointer;
    }
    .grupo.email-group > span .btn-verify:hover {
      background: #e6f4ea;
    }
    /* Empuja el botón y el estado al extremo derecho */
    .grupo.email-group > span .btn-verify,
    .grupo.email-group > span strong.status {
      margin-left: center;
    }
  </style>
  <!-- ═════════ Validación única al cargar la página ═════════ -->
  <script>
  (()=>{
    const token = localStorage.getItem('token');
    if (!token) { location.replace('login.html'); return; }
    const ctrl = new AbortController();
    window.addEventListener('beforeunload', ()=> ctrl.abort());

    validarToken(ctrl.signal)
      .then(()=> cargarNav())
      .catch(err=>{
        if (err.message==='TokenNoValido') {
          localStorage.clear();
          location.replace('login.html');
        }
      });

    async function validarToken(signal) {
      let res;
      try {
        res = await fetch('validar_token.php', {
          headers: { 'Authorization': 'Bearer '+token },
          signal
        });
      } catch(e) {
        if(e.name==='AbortError') throw e;
        throw new Error('NetworkFail');
      }
      if (res.status===401) throw new Error('TokenNoValido');
      const data = await res.json();
      if (!data.ok) throw new Error('TokenNoValido');
      return true;
    }

    function cargarNav() {
      // ya renderizado en PHP
    }
  })();
  </script>
  <!-- ═══════════════════════════════════════════════ -->
</head>

<body>
  <!-- NAV con nombre y foto renderizados en PHP -->
  <nav>
    <div class="menu">
      <a href="home.php">Inicio</a>
      <a href="eventos.php">Eventos</a>
      <a href="#">Integrantes</a>
      <a href="ver_mis_datos.php">Mis datos</a>
      <a href="#">Reportes</a>
      <a href="#">Admisión</a>
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

  <!-- CONTENIDO renderizado en servidor -->
  <div class="container">
    <h1>Mis Datos</h1>

    <div class="grupo">
      <label>Foto de perfil:</label>
      <img id="foto_perfil" class="foto"
           src="<?= htmlspecialchars($user['foto_perfil']) ?>"
           alt="Foto (clic para ampliar)">
    </div>

    <div class="grupo">
      <label>Nombre completo:</label>
      <span>
        <?= htmlspecialchars("{$user['nombres']} {$user['apellido_paterno']} {$user['apellido_materno']}") ?>
      </span>
    </div>

    <div class="grupo">
      <label>RUT / DNI:</label>
      <span><?php
        $raw = preg_replace('/[^0-9kK]/', '', $user['rut_dni']);
        if ($user['id_pais'] === 1) {
          $body = substr($raw, 0, -1);
          $dv   = strtoupper(substr($raw, -1));
          echo htmlspecialchars(number_format($body, 0, '', '.') . '-' . $dv);
        } else {
          echo htmlspecialchars(preg_replace('/\D/', '', $raw));
        }
      ?></span>
    </div>

    <div class="grupo">
      <label>Fecha de nacimiento:</label>
      <span><?= date('d/m/Y', strtotime($user['fecha_nacimiento'])) ?></span>
    </div>

    <div class="grupo">
      <label>Fecha de ingreso:</label>
      <span><?= date('d/m/Y', strtotime($user['fecha_registro'])) ?></span>
    </div>

    <div class="grupo"><label>País:</label><span><?= htmlspecialchars($pais) ?></span></div>
    <div class="grupo"><label>Región / Estado:</label><span><?= htmlspecialchars($region) ?></span></div>
    <div class="grupo"><label>Ciudad / Comuna:</label><span><?= htmlspecialchars($ciudad) ?></span></div>
    <div class="grupo"><label>Dirección:</label><span><?= htmlspecialchars($user['direccion']) ?></span></div>
    <div class="grupo"><label>Iglesia / Ministerio:</label><span><?= htmlspecialchars($user['iglesia_ministerio']) ?></span></div>
    <div class="grupo"><label>Profesión / Oficio / Estudio:</label><span><?= htmlspecialchars($user['profesion_oficio_estudio']) ?></span></div>
    <div class="grupo"><label>Ocupación:</label><span><?= htmlspecialchars(implode(', ', $ocupaciones)) ?></span></div>
    <div class="grupo email-group">
      <label for="correo">Correo electrónico:</label>
      <span>
        <span class="email-text">
          <?= htmlspecialchars($user['correo'] ?? '') ?>
        </span>
        <?php if (!empty($user['verified'])): ?>
          <strong class="status verified">✔ Verificado</strong>
        <?php else: ?>
          <button type="button" id="btn-verificar" class="btn-verify">
            Verificar
          </button>
          <strong class="status not-verified">✖ No verificado</strong>
        <?php endif; ?>
      </span>
      <small class="error-msg" id="error_correo"></small>
    </div>
    <div class="grupo"><label>Recibe boletín:</label><span><?= $user['boletin'] ? 'Sí' : 'No' ?></span></div>

    <h2>Teléfonos</h2>
    <table>
      <thead><tr><th>Número</th><th>Descripción</th></tr></thead>
      <tbody>
      <?php foreach($telefonos as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['telefono']) ?></td>
          <td>
            <?= htmlspecialchars($t['descripcion'] ?? '') ?>
            <?= !empty($t['es_principal']) ? ' – Principal' : '' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Roles y Equipos</h2>
    <table>
      <thead><tr><th>Rol</th><th>Equipo / Proyecto</th></tr></thead>
      <tbody>
      <?php foreach($rolesEquipos as $re): ?>
        <tr>
          <td><?= htmlspecialchars($re['rol']) ?></td>
          <td><?= htmlspecialchars($re['equipo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="editar-btn">
      <a href="editar_mis_datos.php"><button>Editar mis datos</button></a>
    </div>
  </div>

  <!-- POP-UP para la foto -->
  <div id="overlay">
    <span class="close" onclick="overlay.style.display='none'">✕</span>
    <img id="big-img" src="">
  </div>

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

  <!-- lógica de UI -->
  <script src="ver_mis_datos.js"></script>
  <!-- heartbeat -->
  <script src="/heartbeat.js"></script>
</body>
</html>
