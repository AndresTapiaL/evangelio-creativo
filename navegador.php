<?php
/* ===========================================================
   Navegador / barra superior reutilizable
   © Evangelio Creativo · 2025
   Uso:  include_once 'navegador.php';
   Requisitos:
     • conexión PDO disponible en $pdo  (se crea aquí si no existe)
     • sesión iniciada con id_usuario en $_SESSION
   =========================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}

/* ─────────── conexión (una sola vez) ─────────── */
if (!isset($pdo)) {
    require_once __DIR__.'/conexion.php';
}

/* ─────────── helpers de permisos ─────────── */
require_once __DIR__.'/lib_auth.php';
$uid = (int)$_SESSION['id_usuario'];

/* ─────────── datos del usuario ─────────── */
$stmt = $pdo->prepare("
    SELECT nombres, foto_perfil
      FROM usuarios
     WHERE id_usuario = :id
     LIMIT 1");
$stmt->execute([':id' => $uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nombres'=>'','foto_perfil'=>''];

/* foto por defecto si viene NULL / vacía */
$fotoNav = trim((string)($user['foto_perfil'] ?? '')) ?: 'uploads/fotos/default.png';

/* NUEVO – nombre del archivo que se está ejecutando */
$curr = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
?>
<!-- Font Awesome (solo si tu plantilla aún no lo incluye) -->
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- ===== Estilos del navegador (auto-contenidos) ===== -->
<style>
/* ===== Poppins (latin) ===== */
@font-face{
  font-family:"Poppins";
  src:url("styles/poppins-v23-latin-400.woff2") format("woff2");
  font-weight:400;
  font-style:normal;
  font-display:swap;      /* evita FOIT, mejora LCP */
}

/* 700 bold */
@font-face{
  font-family:"Poppins";
  src:url("styles/poppins-v23-latin-700.woff2") format("woff2");
  font-weight:700;
  font-style:normal;
  font-display:swap;
}

/* (opcional) 400 italic */
@font-face{
  font-family:"Poppins";
  src:url("styles/poppins-v23-latin-400italic.woff2") format("woff2");
  font-weight:400;
  font-style:italic;
  font-display:swap;
}

@font-face{
  font-family:"Poppins";
  src:url("styles/poppins-v23-latin-500.woff2") format("woff2");
  font-weight:500;
  font-style:normal;
  font-display:swap;
}

@font-face{
  font-family:"Poppins";
  src:url("styles/poppins-v23-latin-600.woff2") format("woff2");
  font-weight:600;
  font-style:normal;
  font-display:swap;
}

/* fallback chain */
body{
  font-family:"Poppins", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}

/* ───────────  VARIABLES Y RESET BÁSICO  ─────────── */
:root{
  /* ◇ Paleta base */
  --negro:    #2e292c;
  --naranjo:  #ff3600;
  --amarillo: #fee920;
  --rojo:     #d60000;
  --blanco:   #e1e5ea;

  /* ◇ Semánticas (la app referencia solo éstas) */
  --bg-main:     var(--blanco);      /* fondo general */
  --bg-card:     #ffffff;            /* tarjetas / tablas */
  --bg-sidebar:  var(--negro);       /* lateral */
  --bg-modal:    #ffffff;            /* diálogos */
  --text-main:   var(--negro);
  --text-muted:  #6d7280;            /* gris que ya usabas funciona bien */
  --primary:     var(--naranjo);     /* botones, enlaces activos, etc. */
  --primary-dark:#c13600;            /* naranjo más oscuro para :hover */
  --warning:     var(--amarillo);    /* fondos de alerta */
  --danger:      var(--rojo);        /* textos/íconos de error */
  --radius:12px;
  --shadow:0 6px 24px rgba(0,0,0,.08);
  --transition:.2s ease;
  --nav-h:72px;

  --nav-bg       : #ffffff;
  --nav-text     : #4b5563;      /* gris 600 */
  --nav-text-hov : #374151;      /* gris 700 */
  --nav-accent   : #667eea;      /* indigo-400 */
  --nav-accent-2 : #764ba2;      /* púrpura-500 */
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
/* 1️⃣  Declaración normal del body */
/* 1.  Declaración normal ─ sin background */
body{
  font:400 16px/1.5 "Poppins",sans-serif;
  position:relative;          /* ancla (::before se posiciona respecto a body) */
  color:var(--text-main);
  margin:0;
  min-height:100vh;
}

/* 2.  Pseudo-elemento con el fondo rotado */
body::before{
  content:"";
  position:fixed;             /* cubre SIEMPRE todo el viewport */
  inset:0;                    /* top:0 right:0 bottom:0 left:0 */
  z-index:-1;

  background:url("images/Fondo-blanco.jpeg") center/cover no-repeat;

  /* 90 ° sentido horario + traslación para que encaje */
  transform:
      rotate(90deg)          /* primero lo giramos … */
      translateY(-100%);     /* … y luego lo bajamos una “altura” entera */
  /*               ↑
    order-of-operations: el translate se aplica **después** del rotate    */

  transform-origin:top left;  /* el pivote de la rotación */

  /* ancho ↔ alto intercambiados para seguir cubriendo el viewport */
  width:100vh;
  height:100vw;
}

/* barra apilada y sticky */
nav{
  position:sticky; top:0; z-index:1000;
  display:flex; justify-content:space-between; align-items:center;
  padding:.85rem 1.5rem;
  background:var(--nav-bg);
  box-shadow:0 2px 6px rgba(0,0,0,.08);
  /* tamaño y peso consistentes, independientes del <body> de cada pantalla */
  font:500 16px/1 "Poppins",system-ui,sans-serif;
}

/* navegación izquierda */
nav .menu{
  display:flex;
  align-items:center;   /* ⬅️ fuerza la alineación vertical */
  gap:1.6rem;
}
/* enlaces dentro del menú en línea con el logo */
nav .menu a{
  display:inline-flex;
  align-items:center;
}
nav .menu a{
  text-decoration:none; color:var(--nav-text);
  font-weight:500; position:relative;
  transition:color .22s;
}
nav .menu a::after{
  content:''; position:absolute; left:0; bottom:-6px;
  width:0; height:2px; border-radius:2px;
  background:linear-gradient(90deg,var(--nav-accent),var(--nav-accent-2));
  transition:width .25s;
}
nav .menu a:hover,
nav .menu a:focus{color:var(--nav-text-hov);}
nav .menu a:hover::after,
nav .menu a:focus::after{width:100%;}

/* perfil + avatar + logout */
.perfil{display:flex; align-items:center; gap:.85rem;}
.perfil span{
  font-weight:600; color:#1f2937;   /* gris 800 */
}
.perfil img{
  width:38px; height:38px; object-fit:cover;
  border-radius:50%; border:2px solid #e5e7eb;
}

/* botón de logout */
#logout{
  display:flex; align-items:center; justify-content:center;
  font-size:1.25rem;
  color:#6b7280;        /* gris 500 */
  text-decoration:none;
  transition:color .2s, transform .2s;
}
#logout:hover{
  color:#ef4444;        /* rojo 400 */
  transform:rotate(6deg);
}

/* ───── Logo animado ───── */
nav .logo{
  display:flex;
  align-items:center;
  margin-right:.8rem;            /* separación con el primer enlace */
}

nav .logo img{
  height:36px;                   /* alto del logo */
  transition:transform .45s ease, filter .45s ease;
  transform-origin:center;
}

nav .logo:hover img{
  transform:rotateY(360deg) scale(1.07);
  filter:drop-shadow(0 0 6px rgba(118, 78, 233, .45));
}

/* enlace de la página actual */
nav .menu a.active{
  color:var(--nav-text-hov);
}
nav .menu a.active::after{
  width:100%;
}

/* ═════════════  NAV RESPONSIVO  ═════════════ */

/* ------ botón hamburger (oculto en escritorio) ------ */
.nav-btn{
  display:none;                       /* visible solo < 780 px */
  background:none;border:0;
  color:var(--nav-text); font-size:1.5rem;
  cursor:pointer; transition:transform .25s;
}
.nav-btn:focus-visible{outline:2px solid var(--nav-accent);}
.nav-btn.rotate{transform:rotate(90deg);}   /* animación al abrir */

/* ——— Breakpoint principal (≤ 780 px) ——— */
@media(max-width:780px){

  /* compactar nav — se quitan paddings laterales */
  nav{padding:.7rem 1rem;}

  /* 1️⃣  Muestra el botón */
  .nav-btn{display:inline-flex; align-items:center;}

  /* 2️⃣  Logo más pequeño */
  nav .logo img{height:30px;}

  /* 3️⃣  MENÚ colapsable ----------------------------------- */
  nav .menu{
    position:fixed;                  /* super-puesto */
    top:var(--nav-h); left:0; right:0;
    background:var(--nav-bg);
    flex-direction:column;           /* vertical */
    gap:0;
    padding:1rem 1.3rem;
    transform:translateY(-120%);     /* oculto */
    transition:transform .28s var(--transition);
    box-shadow:0 4px 12px rgba(0,0,0,.18);
  }

  /* cada link ocupa toda la línea */
  nav .menu a{
    padding:.75rem 0;
    font-size:1.05rem;
  }

  /* estado ABIERTO */
  nav .menu.open{transform:none;}

  /* evitar que el contenido salte por el pseudo-backdrop */
  body.menu-open{overflow:hidden;}
}
</style>

<!-- ░░░░ NAV ░░░░ -->
<nav>
  <!-- -------- enlaces principales -------- -->
  <div class="menu">
    <a class="logo" href="home.php">
      <img src="images/LogoEC.png" alt="Logo Evangelio Creativo">
    </a>

    <a href="home.php"           <?= $curr==='home.php'            ? 'class="active"' : '' ?>>Inicio</a>
    <a href="eventos.php"        <?= $curr==='eventos.php'         ? 'class="active"' : '' ?>>Eventos</a>
    <a href="integrantes.php"    <?= $curr==='integrantes.php'     ? 'class="active"' : '' ?>>Integrantes</a>
    <a href="ver_mis_datos.php"  <?= $curr==='ver_mis_datos.php'   ? 'class="active"' : '' ?>>Mis datos</a>

    <?php if (user_can_use_reports($pdo,$uid)): ?>
      <a href="reportes.php"    <?= $curr==='reportes.php'        ? 'class="active"' : '' ?>>Reportes</a>
    <?php endif; ?>

    <a href="tickets.php"
      <?= in_array($curr,
                    ['tickets.php',
                    'ticket_detalle.php',
                    'ticket_resumen.php',    // ← nuevo
                    'ticket_escaneados.php']) // ← nuevo (si aplica)
            ? 'class="active"' : '' ?>>
      Tickets
    </a>
  </div>

  <!-- ▼ 1.-a  Nuevo botón hamburger (colócalo dentro del <nav>) -->
  <button id="nav-btn" class="nav-btn" aria-label="Mostrar menú"
          aria-expanded="false">
    <i class="fa-solid fa-bars"></i>
  </button>

  <!-- -------- avatar + nombre + logout -------- -->
  <div class="perfil">
    <span id="nombre-usuario"><?= htmlspecialchars($user['nombres']) ?></span>

    <img id="foto-perfil-nav"
         src="<?= htmlspecialchars($fotoNav) ?>"
         alt="Foto de <?= htmlspecialchars($user['nombres']) ?>">

    <a href="#" id="logout" title="Cerrar sesión">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>
</nav>

<script>
/* ▼ 3.-a  Toggle menú móvil sin librerías */
(() => {
  const btn   = document.getElementById('nav-btn');
  const menu  = document.querySelector('nav .menu');
  if(!btn || !menu) return;

  btn.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    document.body.classList.toggle('menu-open', open);
    btn.classList.toggle('rotate', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  /* cierra menú al pulsar un enlace */
  menu.addEventListener('click', e => {
    if(e.target.tagName === 'A'){
      menu.classList.remove('open');
      document.body.classList.remove('menu-open');
      btn.classList.remove('rotate');
      btn.setAttribute('aria-expanded','false');
    }
  });
})();
</script>
