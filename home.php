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
    /* ——— estilo mínimo para el nav ——— */
    nav{background:#f0f0f0;padding:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}
    nav .menu{display:flex;gap:1rem;flex-wrap:wrap}
    nav a{text-decoration:none;color:#222;font-weight:bold}
    .perfil{display:flex;align-items:center;gap:.5rem}
    .perfil img{width:32px;height:32px;border-radius:50%;object-fit:cover}
  </style>
</head>

<body>
  <!-- ═════════ NAV ═════════ -->
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
      <span id="nombre-usuario">Usuario</span>
      <img id="foto-perfil" src="uploads/fotos/default.png" alt="Foto">
      <a href="#" onclick="cerrarSesion()" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <!-- ─── CONTENIDO PRINCIPAL (tu dashboard) ─── -->
  <main style="padding:2rem">
    <h1>Bienvenido</h1>
    <!-- … resto de tu página … -->
  </main>

  <!-- ═════════ JS ═════════ -->
  <script>
    /* —— carga nombre + foto —— */
    (async ()=>{
      const t = localStorage.getItem('token');
      if (!t) return;
      const u = await fetch(`get_usuario.php?token=${t}`).then(r=>r.json());
      if (u.error) return;
      document.getElementById('nombre-usuario').textContent = u.nombres || 'Usuario';
      document.getElementById('foto-perfil').src = u.foto_perfil || 'uploads/fotos/default.png';
    })();

    function cerrarSesion(){
      const t = localStorage.getItem('token');
      if (t){
        fetch(`cerrar_sesion.php?token=${t}`).finally(()=>{
          localStorage.clear(); location.replace('login.html');
        });
      }
    }
  </script>
</body>
</html>
