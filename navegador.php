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
?>
<!-- Font Awesome (solo si tu plantilla aún no lo incluye) -->
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- ===== Estilos del navegador (auto-contenidos) ===== -->
<style>
/* paleta rápida */
:root{
  --nav-bg       : #ffffff;
  --nav-text     : #4b5563;      /* gris 600 */
  --nav-text-hov : #374151;      /* gris 700 */
  --nav-accent   : #667eea;      /* indigo-400 */
  --nav-accent-2 : #764ba2;      /* púrpura-500 */
}
/* barra apilada y sticky */
nav{
  position:sticky; top:0; z-index:1000;
  display:flex; justify-content:space-between; align-items:center;
  padding:.85rem 1.5rem;
  background:var(--nav-bg);
  box-shadow:0 2px 6px rgba(0,0,0,.08);
  font-family:'Poppins',system-ui,sans-serif;
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
</style>

<!-- ░░░░ NAV ░░░░ -->
<nav>
  <!-- -------- enlaces principales -------- -->
  <div class="menu">
    <a class="logo" href="home.php">
      <img src="images/LogoEC.png" alt="Logo Evangelio Creativo">
    </a>
    <a href="home.php">Inicio</a>
    <a href="eventos.php">Eventos</a>
    <a href="integrantes.php">Integrantes</a>
    <a href="ver_mis_datos.php">Mis datos</a>

    <?php if (user_can_use_reports($pdo,$uid)): ?>
        <a href="reportes.php">Reportes</a>
    <?php endif; ?>

    <a href="tickets.php">Tickets</a>
  </div>

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
