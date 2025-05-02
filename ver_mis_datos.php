<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">

  <!-- Bloquea caché y redirige si el token es inválido -->
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate"/>
  <script>
    document.documentElement.style.display = 'none';
    (async () => {
      const t = localStorage.getItem('token');
      if (!t) return location.replace('login.html');
      const ok = await fetch(`validar_token.php?token=${t}`).then(r => r.text()).catch(() => null);
      if (!ok || !ok.startsWith('Token válido')) {
        localStorage.clear();
        location.replace('login.html');
      } else {
        document.documentElement.style.display = '';
      }
    })();
  </script>

  <title>Ver mis datos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    nav {background:#f0f0f0;padding:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}
    nav .menu {display:flex;flex-wrap:wrap;gap:1rem;align-items:center}
    nav a {text-decoration:none;color:#222;font-weight:bold}
    .perfil {display:flex;align-items:center;gap:.5rem}
    .perfil img {width:32px;height:32px;border-radius:50%;object-fit:cover}

    body {font-family:sans-serif;background:#f6f6f6;margin:0;padding:2rem}
    .container {max-width:800px;margin:auto;background:#fff;padding:2rem;border-radius:10px;box-shadow:0 0 12px rgba(0,0,0,.1)}
    h1 {margin-top:0}
    .grupo {margin-bottom:1rem}
    .grupo label {font-weight:bold;display:block;margin-bottom:.25rem}
    .grupo span {display:inline-block;width:100%;padding:.5rem;background:#f0f0f0;border-radius:5px}
    img.foto {max-width:120px;max-height:120px;border-radius:50%;cursor:pointer}

    table {width:100%;border-collapse:collapse;margin-top:1rem}
    th,td {border:1px solid #ddd;padding:.5rem;text-align:left}
    th {background:#f3f3f3}

    .editar-btn {text-align:center;margin-top:2rem}
    .editar-btn button {background:linear-gradient(135deg,#ff7b3a 0%,#ff5722 100%);color:#fff;border:none;padding:.75rem 2.5rem;font-size:1rem;font-weight:bold;border-radius:8px;cursor:pointer;transition:.2s;box-shadow:0 4px 10px rgba(255,87,34,.3)}
    .editar-btn button:hover {filter:brightness(1.1);transform:translateY(-2px)}

    /* pop-up foto */
    #overlay {position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;justify-content:center;align-items:center;z-index:2000}
    #overlay img {max-width:80vw;max-height:80vh;border-radius:10px;box-shadow:0 0 20px rgba(0,0,0,.6)}
    #overlay .close {position:absolute;top:30px;right:40px;color:#fff;font-size:2rem;font-weight:bold;cursor:pointer}
  </style>
</head>

<body>
  <!-- NAV -->
  <nav>
    <div class="menu">
      <a href="home.php">Inicio</a>
      <a href="#">Eventos</a>
      <a href="#">Integrantes</a>
      <a href="ver_mis_datos.php">Mis datos</a>
      <a href="#">Reportes</a>
      <a href="#">Admisión</a>
      <a href="#"><i class="fas fa-bell"></i></a>
    </div>
    <div class="perfil">
      <span id="nombre-usuario">...</span>
      <img id="foto-perfil-nav" src="" alt="Foto">
      <a href="#" id="logout" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <!-- CONTENIDO -->
  <div class="container">
    <h1>Mis Datos</h1>

    <div class="grupo"><label>Foto de perfil:</label>
      <img id="foto_perfil" class="foto" src="" alt="Foto (clic para ampliar)">
    </div>

    <div class="grupo"><label>Nombre completo:</label><span id="nombre_completo"></span></div>
    <div class="grupo"><label>RUT / DNI:</label><span id="rut_dni"></span></div>
    <div class="grupo"><label>Fecha de nacimiento:</label><span id="fecha_nacimiento"></span></div>
    <div class="grupo"><label>Fecha de ingreso:</label><span id="fecha_ingreso"></span></div>

    <div class="grupo"><label>País:</label><span id="pais"></span></div>
    <div class="grupo"><label>Región / Estado:</label><span id="region"></span></div>
    <div class="grupo"><label>Ciudad / Comuna:</label><span id="ciudad"></span></div>

    <div class="grupo"><label>Dirección:</label><span id="direccion"></span></div>
    <div class="grupo"><label>Iglesia / Ministerio:</label><span id="iglesia"></span></div>
    <div class="grupo"><label>Profesión / Oficio / Estudio:</label><span id="profesion"></span></div>
    <div class="grupo"><label>Ocupación:</label><span id="ocupacion"></span></div>

    <div class="grupo"><label>Correo electrónico:</label><span id="correo"></span></div>
    <div class="grupo"><label>Boletín:</label><span id="boletin"></span></div>

    <h2>Teléfonos</h2>
    <table>
      <thead><tr><th>Número</th><th>Descripción</th></tr></thead>
      <tbody id="tabla-telefonos"></tbody>
    </table>

    <h2>Roles y Equipos</h2>
    <table>
      <thead><tr><th>Rol</th><th>Equipo / Proyecto</th></tr></thead>
      <tbody id="tabla-roles"></tbody>
    </table>

    <div class="editar-btn">
      <a href="editar_mis_datos.php"><button>Editar mis datos</button></a>
    </div>
  </div>

  <!-- POP-UP -->
  <div id="overlay">
    <span class="close" onclick="overlay.style.display='none'">✕</span>
    <img id="big-img" src="">
  </div>

  <!-- Versión 6.0 -->
  <script src="ver_mis_datos.js?v=6.0"></script>
</body>
</html>
