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
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Integrantes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preload" href="styles/poppins-v23-latin-400.woff2"
        as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="styles/poppins-v23-latin-500.woff2"
        as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="styles/poppins-v23-latin-600.woff2"
        as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="styles/poppins-v23-latin-700.woff2"
      as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="styles/poppins-v23-latin-400italic.woff2"
      as="font" type="font/woff2" crossorigin>

  <style>
  /* ===== Poppins (latin) ===== */
  @font-face{
    font-family:"Poppins";
    src:url("/styles/poppins-v23-latin-400.woff2") format("woff2");
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
    --bg-main:#f1f4f9;
    --bg-card:#ffffff;
    --bg-sidebar:#21263a;
    --bg-modal:#ffffff;
    --text-main:#242424;
    --text-muted:#6d7280;
    --primary:#5562ff;
    --primary-dark:#3841d8;
    --radius:12px;
    --shadow:0 6px 24px rgba(0,0,0,.08);
    --transition:.2s ease;
  }
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  html{scroll-behavior:smooth;}
  body{
    font:400 16px/1.5 "Poppins",sans-serif;
    background:var(--bg-main);
    color:var(--text-main);
  }

  /* ───────────  NAV  ─────────── */
  nav{
    background:#fff;
    box-shadow:var(--shadow);
    padding:.85rem 1.5rem;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:500;
  }
  nav .menu{display:flex;gap:1.4rem;}
  nav a{
    text-decoration:none;color:var(--text-muted);
    font-weight:500;transition:color var(--transition);
  }
  nav a:hover,nav a.active{color:var(--primary);}
  .perfil{display:flex;align-items:center;gap:.6rem}
  .perfil img{width:38px;height:38px;border-radius:50%;object-fit:cover}

  /* ───────────  SIDEBAR  ─────────── */
  /* sidebar absoluto contra el borde izquierdo */
  .sidebar{
    position:fixed;
    top:72px;              /* = altura real del <nav>  */
    left:0;
    bottom:0;
    width:240px;
    color:#fff;

    background:var(--bg-sidebar);
    padding:1rem .5rem 2rem;          /* 2 rem extra → no se corta último item */
    overflow-y:auto;

    border-radius:0 var(--radius) var(--radius) 0;
  }

  .sidebar ul{list-style:none}
  .sidebar li{
    padding:.6rem .9rem;border-radius:6px;margin-bottom:.3rem;
    cursor:pointer;user-select:none;font-size:.95rem;
    transition:background var(--transition);
  }
  .sidebar li:hover{background:rgba(255,255,255,.1)}
  .sidebar li.sel{background:var(--primary);}

  /* ← 3.3 Layout flexible (sidebar + contenido) */
  .layout{
    display:flex;
  }

  /* toda la columna derecha desplazada 240 px */
  .layout{
    margin-left:240px;          /* <- mueve solo el “lado derecho” */
    display:flex;
  }

  /* la sección tabla ocupa todo el espacio restante */
  .layout > main{
    flex:1;
    padding:2rem;
    overflow-x:auto;
  }

  /* ← 3.4 Botón Columnas renovado */
  #btn-cols{
    margin-bottom:.8rem;      /* deja un pequeño aire antes de la tabla */
    background:#1b2033;
    border-radius:20px;
    display:flex;align-items:center;gap:.4rem;
    font-size:.85rem;
  }
  #btn-cols{
    position:sticky;
    top:0;                 /* queda pegado al borde superior del panel */
    z-index:10;
  }
  #btn-cols i{font-size:.95rem;}

  /* ← 3.5 Pop-up de columnas */
  #section-table{position:relative;}          /* contenedor ancla */

  #cols-menu{
    position:absolute;
    top:48px; right:0;
    background:#fff;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:1rem 1.2rem;
    width:220px;max-height:70vh;overflow:auto;

    opacity:0;pointer-events:none;transform:translateY(-6px);
    transition:opacity .18s ease, transform .18s ease;
  }
  #cols-menu.show{opacity:1;pointer-events:auto;transform:none;}

  #cols-menu label{
    display:flex;
    align-items:center;
    gap:.45rem;
    margin-bottom:.5rem;
    font-size:.86rem;
  }

  #cols-menu label:hover{background:#f6f8ff;border-radius:6px;padding:.25rem;}

  #cols-menu input{accent-color:var(--primary);}

  /* ───────────  TABLA  ─────────── */
  table{width:100%;border-collapse:collapse}
  /* 1-A)  la tabla usa tamaño automático (no ‘fixed’)                */
  table{table-layout:auto;}
  /* 1-B)  las celdas con texto largo no hacen saltos de línea        */
  td,th{white-space:nowrap;}
  thead{background:#fff;box-shadow:var(--shadow)}
  th,td{padding:.8rem .9rem;text-align:left;font-size:.9rem;}
  tbody tr:nth-child(odd){background:#fafbfc}
  tbody tr:hover{background:#e9edff}
  th{white-space:nowrap;color:var(--text-muted);font-weight:600;}
  td button{background:none;border:0;cursor:pointer;font-size:1rem}

  /* ───────────  BOTONES  ─────────── */
  .btn{padding:.5rem .95rem;border-radius:8px;border:0;font-weight:500;
      background:var(--primary);color:#fff;cursor:pointer;
      transition:background var(--transition);}
  .btn:hover{background:var(--primary-dark);}

  /* ───────────  MODAL  ─────────── */
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.45);
        display:flex;justify-content:center;align-items:flex-start;
        padding-top:5vh;z-index:1000;overflow:auto;opacity:0;pointer-events:none;
        transition:opacity .25s ease;}
  .modal.show{opacity:1;pointer-events:auto;}
  .modal-box{
    background:var(--bg-modal);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:2rem;max-width:clamp(340px,85vw,880px);
    width:100%;animation:slideDown .3s ease;
  }

  .modal-box dl{
    display:grid;
    grid-template-columns:160px 1fr;
    row-gap:.4rem;column-gap:1rem;
    font-size:.9rem;
  }
  .modal-box dl dt{font-weight:600;color:var(--primary);text-align:right;}
  .modal-box dl dd{margin:0;}

  @keyframes slideDown{from{translate:0 -20px;opacity:.3;}}

  .close{
    position:absolute;top:1.1rem;right:1.1rem;
    background:none;border:0;font-size:1.3rem;color:var(--text-muted);
    cursor:pointer;transition:color var(--transition);}
  .close:hover{color:var(--primary);}

  .avatar{width:140px;height:140px;border-radius:50%;
          object-fit:cover;box-shadow:var(--shadow);}

  /* ───────────  FIELDSETS  ─────────── */
  fieldset{border:0;margin-block:1.6rem;}
  legend{font-weight:600;font-size:1.05rem;color:var(--primary);
        margin-bottom:1rem;padding-bottom:.3rem;border-bottom:2px solid #eef1ff;}
  label{display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;}
  input,select{
    padding:.55rem .8rem;border:1px solid #d6d9e2;border-radius:8px;
    font:inherit;background:#fff;transition:border-color var(--transition);}
  input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px #dfe2ff;}

  #fs-personales{
    display:grid;gap:1.4rem 1.8rem;
    grid-template-columns:160px 1fr 1fr;
    align-items:start;
  }
  .foto-box{grid-row:span 3;display:flex;flex-direction:column;gap:.9rem;align-items:center;}

  @media(max-width:700px){
    #fs-personales{grid-template-columns:1fr;}
    .foto-box{grid-row:auto;flex-direction:row;gap:1.2rem;}
  }

  /* inputs anchos */
  #ed-dir,#ed-correo{grid-column:1/-1}

  /* ───────────  TELÉFONOS  ─────────── */
  #phone-container .phone-row{
    display:grid;grid-template-columns:1fr 200px;gap:1rem;margin-bottom:.8rem;}

  /* ───────────  OCUPACIONES (chips)  ─────────── */
  #ocup-container{
    display:flex;flex-wrap:wrap;gap:.6rem;}
  #ocup-container label{
    flex-direction:row;align-items:center;background:#eef1ff;color:var(--primary);
    padding:.35rem .7rem;border-radius:20px;font-size:.78rem;font-weight:500;
    cursor:pointer;user-select:none;}
  #ocup-container input{margin-right:.4rem;accent-color:var(--primary);}

  /* ───────────  SCROLLBAR (Chrome / Edge)  ─────────── */
  ::-webkit-scrollbar{height:8px;width:8px;}
  ::-webkit-scrollbar-thumb{background:#c5c9d6;border-radius:8px;}
  ::-webkit-scrollbar-thumb:hover{background:#a9afc4;}

  /* ───────────  NUEVO CONTENEDOR FLEX  ─────────── */
  /* envuelve <aside id="sidebar"> y <section id="section-table">   */
  .layout{
    display:flex;                 /* ← sidebar + tabla uno al lado del otro   */
    align-items:flex-start;
  }

  /* el viejo #sidebar mantiene sus 240 px y sticky ↓ */
  .sidebar{flex:0 0 240px;}       /* ya no “flota”, ocupa su hueco fijo       */

  #section-table{
    flex:1;
    overflow-x:auto;
  }

  /* botón – cambia copy & color */
  #btn-cols{
    background:var(--bg-sidebar);
    display:flex;align-items:center;gap:.4rem;
  }
  #btn-cols::before{content:"⚙︎";}

  /* menú flotante */
  #cols-menu{
    position:absolute;
    top:55px; right:0;               /* pegado al botón */
    background:#fff;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:1rem 1.2rem;
    width:220px; max-height:65vh; overflow:auto;

    opacity:0; pointer-events:none; transform:translateY(-6px);
    transition:opacity .2s, transform .2s;
  }
  #cols-menu.show{opacity:1;pointer-events:auto;transform:none;}

  .img-viewer{
    position:fixed;inset:0;z-index:1600;
    background:rgba(0,0,0,.75);
    display:flex;justify-content:center;align-items:center;
    opacity:0;pointer-events:none;transition:opacity .25s;
  }
  .img-viewer.show{opacity:1;pointer-events:auto;}

  .img-viewer img{
    max-width:90vw;max-height:90vh;border-radius:8px;
    box-shadow:var(--shadow);           /* mismo estilo que modales  */
  }

  #section-table{background:#fff;border-radius:var(--radius);
                box-shadow:var(--shadow);}

  td:last-child{position:sticky;right:0;background:#fff;}
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
      <?php
      require_once 'lib_auth.php';
      $uid = $_SESSION['id_usuario'] ?? 0;
      if (user_can_use_reports($pdo,$uid)): ?>
          <a href="reportes.php">Reportes</a>
      <?php endif; ?>
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
  <!-- contenedor de DOS columnas -->
  <div class="layout">
    <!-- ░░░░ SIDEBAR ░░░░ -->
    <aside id="sidebar" class="sidebar">
        <ul id="equipos-list"></ul>
    </aside>

    <!-- ░░░░ CONTENIDO ░░░░ -->
    <main>
      <h1>Integrantes</h1>

      <button id="btn-cols" class="btn"><i class="fa-solid fa-sliders"></i>&nbsp;Columnas</button>

      <section id="section-table">
          <div id="cols-menu" class="cols-menu"></div>

          <table id="tbl-integrantes">
            <thead></thead>
            <tbody></tbody>
          </table>
      </section>
    </main>
  </div>
  
    <!-- ░░░░ MODAL ─ VER DETALLES ░░░░ -->
    <div id="modal-det" class="modal hidden">
      <div class="modal-box">
        <button id="det-close" class="close">✖</button>

        <!-- Foto + nombre + última actualización -->
        <img id="det-foto" class="avatar" alt="Foto perfil" title="Ver foto" style="cursor: zoom-in">
        <h2 id="det-nombre" style="text-align:center"></h2>
        <p style="text-align:center;font-size:.9rem;margin-top:-.5rem">
          Última actualización: <span id="det-tiempo"></span>
        </p>
        <hr>

        <!-- Datos personales -->
        <dl>
          <dt>Fecha de nacimiento</dt>  <dd id="det-nac"></dd>
          <dt>Edad</dt>              <dd id="det-edad"></dd>
          <dt>Documento de identidad</dt>         <dd id="det-rut"></dd>
          <dt>País</dt>              <dd id="det-pais"></dd>
          <dt>Región / Estado</dt>   <dd id="det-region"></dd>
          <dt>Ciudad / Comuna</dt>   <dd id="det-ciudad"></dd>
          <dt>Dirección</dt>         <dd id="det-dir"></dd>
          <dt>Iglesia / Ministerio</dt> <dd id="det-iglesia"></dd>
          <dt>Profesión / Oficio / Estudio</dt> <dd id="det-prof"></dd>
          <dt>Fecha de ingreso</dt>  <dd id="det-ingreso"></dd>
          <dt>Correo electrónico</dt><dd id="det-correo"></dd>
          <dt>Teléfonos</dt>         <dd id="det-tels"></dd>
          <dt>Ocupaciones</dt>       <dd id="det-ocup"></dd>
        </dl>

        <!-- Tabla estado periodos -->
        <div id="estados-wrap" style="margin-top:1.5rem">
          <table id="det-tab-estados" style="width:100%;border-collapse:collapse">
            <thead></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Visor de imagen (oculto por defecto) -->
    <div id="img-viewer" class="img-viewer hidden">
      <img id="viewer-img" alt="Foto perfil ampliada">
    </div>

    <!-- ░░░░ MODAL ─ EDITAR ░░░░ -->
    <div id="modal-edit" class="modal hidden">
      <div class="modal-box big">
        <button id="edit-close" class="close">✖</button>
        <h2>Editar integrante</h2>
        <form id="form-edit">

          <input type="hidden" name="id" id="ed-id">

          <!-- Datos personales -->
          <fieldset id="fs-personales">
            <legend>Datos personales</legend>

            <!-- fila 0 : foto + eliminar -->
            <div class="foto-box">
                <img id="ed-foto" class="avatar">
                <button type="button" id="btn-del-photo" class="btn">🗑️ Eliminar foto</button>
            </div>

            <!-- fila 1 : nombres -->
            <label>Nombres
              <input id="ed-nom" name="nombres" required maxlength="60">
            </label>
            <label>Apellido paterno
              <input id="ed-ap" name="apellido_paterno" required maxlength="30">
            </label>
            <label>Apellido materno
              <input id="ed-am" name="apellido_materno" maxlength="30">
            </label>

            <!-- fila 2 : fecha + tipo doc + nro -->
            <label>Fecha de nacimiento
              <input type="date" id="ed-fnac" name="fecha_nacimiento">
            </label>
            <label>Tipo documento
              <select id="ed-doc-type">
                <option value="CL">RUT (Chile)</option>
                <option value="INT">Internacional</option>
              </select>
            </label>
            <label>N° documento
              <input id="ed-rut" name="rut_dni" maxlength="20">
            </label>
          </fieldset>

          <!-- Ubicación -->
          <fieldset>
            <legend>Ubicación</legend>
            <label>País
              <select id="ed-pais" name="id_pais"></select>
            </label>
            <label>Región / Estado
              <select id="ed-region" name="id_region_estado"></select>
            </label>
            <label>Ciudad / Comuna
              <select id="ed-ciudad" name="id_ciudad_comuna"></select>
            </label>
            <label>Dirección
              <input id="ed-dir" name="direccion" maxlength="255">
            </label>
          </fieldset>

          <!-- Iglesia / Profesión -->
          <fieldset>
            <legend>Información adicional</legend>
            <label>Iglesia / Ministerio
              <input id="ed-ig" name="iglesia_ministerio" maxlength="255">
            </label>
            <label>Profesión / Oficio / Estudio
              <input id="ed-pro" name="profesion_oficio_estudio" maxlength="255">
            </label>
          </fieldset>

          <!-- Contacto -->
          <fieldset>
            <legend>Contacto</legend>
            <label>Correo electrónico
              <input id="ed-correo" name="correo" type="email" maxlength="320" required>
            </label>

            <div id="phone-container">
              <!-- fila teléfono principal -->
              <div class="phone-row">
                <label>📞 Teléfono&nbsp;1&nbsp;(principal)
                  <input name="tel0" maxlength="16">
                </label>
                <select name="tel_desc0"></select>
              </div>
              <!-- Tel 2 -->
              <div class="phone-row">
                <label>📞 Teléfono&nbsp;2
                  <input name="tel1" maxlength="16">
                </label>
                <select name="tel_desc1"></select>
              </div>
              <!-- Tel 3 -->
              <div class="phone-row">
                <label>📞 Teléfono&nbsp;3
                  <input name="tel2" maxlength="16">
                </label>
                <select name="tel_desc2"></select>
              </div>
            </div>
          </fieldset>

          <!-- Ocupaciones -->
          <fieldset>
            <legend>Ocupaciones</legend>
            <div id="ocup-container"
                style="display:flex;flex-wrap:wrap;gap:.5rem"></div>
          </fieldset>

          <!-- Equipos / Proyectos -->
          <fieldset>
            <legend>Equipos / Proyectos (añadir)</legend>
            <div id="eq-container"></div>
            <button type="button" id="btn-add-eq" class="btn">+ Añadir</button>
          </fieldset>

          <!-- Guardar -->
          <div style="text-align:right;margin-top:1rem">
            <button class="btn-prim">Guardar cambios</button>
          </div>
        </form>
      </div>
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

  <script src="integrantes.js"></script>
  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
</body>
</html>
