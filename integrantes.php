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
  <style>
    /* ————————————————— NAV ————————————————— */
    nav{
      background:#f0f0f0;
      padding:1rem;
      display:flex;
      justify-content:space-between;
      align-items:center;
      flex-wrap:wrap;
    }
    nav .menu{
      display:flex;flex-wrap:wrap;gap:1rem;align-items:center;
    }
    nav a{ text-decoration:none;color:#222;font-weight:bold; }
    .perfil{display:flex;align-items:center;gap:.5rem}
    .perfil img{width:32px;height:32px;border-radius:50%;object-fit:cover}

    /* ————————————————— BASE ————————————————— */
    body{font-family:sans-serif;background:#f6f6f6;margin:0;padding:2rem}
    .container{
      max-width:800px;margin:auto;background:#fff;padding:2rem;
      border-radius:10px;box-shadow:0 0 12px rgba(0,0,0,.1);
    }
    h1{margin-top:0}

    /* ————————————————— SIDEBAR ————————————————— */
    .sidebar{
      width:220px;float:left;margin-right:2rem;background:#fff;
      border:1px solid #d7d7d7;border-radius:8px;
      box-shadow:0 2px 6px rgba(0,0,0,.05);
      max-height:calc(100vh - 160px);overflow-y:auto;padding:0;
    }
    .sidebar ul{list-style:none;margin:0;padding:0}
    .sidebar li{
      cursor:pointer;padding:.65rem 1rem;font-size:.95rem;
      border-left:4px solid transparent;transition:background .15s,border-color .15s;
    }
    .sidebar li:hover{background:#f5f7ff}
    .sidebar li.sel{background:#e8edff;border-left-color:#3a67f8;font-weight:600}

    /* ————————————————— TABLA ————————————————— */
    #section-table{overflow-x:auto}

    .btn{margin-bottom:.5rem}
    .btn-prim{
      background:#36f;color:#fff;padding:.5rem 1rem;border:none;border-radius:6px;
    }

    /* ————————————————— MODAL GENÉRICO ————————————————— */
    .modal{
      position:fixed;inset:0;z-index:1000;background:#0008;
      display:flex;align-items:center;justify-content:center;
    }
    .modal.hidden{display:none}

    .modal-box{
      background:#fff;padding:2rem;border-radius:10px;max-width:600px;
      max-height:90%;overflow:auto;position:relative;
      /* ancho + sombra coherentes */
      box-shadow:0 8px 24px rgba(0,0,0,.18);
    }
    .close{
      position:absolute;right:1rem;top:1rem;border:none;
      background:none;font-size:1.2rem
    }
    .avatar{
      width:120px;height:120px;border-radius:50%;object-fit:cover;
      margin:auto;display:block
    }

    /* ═════════════ FORMULARIO EDITAR – NUEVO LAYOUT ═════════════ */

    /* ancho general del modal grande */
    .modal-box.big{max-width:760px;}

    /* cada fieldset → grid 2 cols (1 col <600 px) */
    fieldset{
      border:none;margin:0 0 1.2rem;padding:0;
      display:grid;grid-template-columns:1fr 1fr;column-gap:1.2rem;
    }
    @media(max-width:600px){fieldset{grid-template-columns:1fr;}}

    legend{font-weight:700;margin-bottom:.4rem;grid-column:1/-1}

    fieldset>label{display:flex;flex-direction:column;gap:.25rem;font-size:.88rem;margin-bottom:.7rem}
    fieldset input,fieldset select{width:100%;box-sizing:border-box;font-size:.9rem;padding:.35rem .45rem}

    /* bloque foto + botón */
    #btn-del-photo{flex-shrink:0}

    /* teléfonos */
    .phone-row{display:flex;align-items:center;gap:.45rem;margin-bottom:.55rem}
    .phone-row label{flex:1;display:flex;flex-direction:column;font-size:.88rem}
    .phone-row select{width:150px}
    .phone-row label::before{content:"\f095";font:900 14px "Font Awesome 6 Free";color:#d33;margin-bottom:3px}

    /* check-list ocupaciones */
    #ocup-container{display:flex;flex-wrap:wrap;gap:.5rem}
    #ocup-container label{min-width:170px;font-size:.88rem}

    /* combos dinámicos Equipo / Rol */
    .eq-row{display:flex;gap:.55rem;margin-bottom:.6rem}
    .eq-row select{flex:1}

    /* ————————————————— MODAL › DETALLE ————————————————— */
    .modal-box dl{
      display:grid;
      grid-template-columns:210px 1fr;
      column-gap:1rem;row-gap:.35rem;
      font-size:.9rem;margin:0;
    }
    .modal-box dl dt{
      font-weight:600;text-align:right;white-space:nowrap;
      align-self:flex-start;           /* etiqueta arriba cuando hay wrap */
    }
    .modal-box dl dd{
      margin:0;white-space:normal;word-break:break-word;text-align:left; /* permite saltos */
    }

    #det-ocup{list-style:none;padding:0}

    /* —— Tabla de estados —— */
    #det-tab-estados{
      width:100%;border-collapse:collapse;font-size:.9rem;
    }
    #det-tab-estados th,#det-tab-estados td{
      padding:.25rem .5rem;text-align:center;
    }
    #det-tab-estados tbody tr:nth-child(odd){background:#f9f9f9}

    /* ————————————————— Visor de imagen ————————————————— */
    .img-viewer{
      position:fixed;inset:0;background:#000d;
      display:flex;align-items:center;justify-content:center;z-index:2000;
    }
    .img-viewer img{
      max-width:90vw;max-height:90vh;border-radius:8px;
      box-shadow:0 4px 16px rgba(0,0,0,.6);
    }
    .img-viewer.hidden{display:none}

    #det-tab-estados th button{
      width:30px;height:24px;
      background:#eee;border:1px solid #999;border-radius:4px;
      cursor:pointer;
    }
    #det-tab-estados th button:disabled{opacity:.4;cursor:default}
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
  <main style="padding:2rem">
    <h1>Integrantes</h1>

    <!-- ░░░░ SIDEBAR + TABLA ░░░░ -->
    <aside id="sidebar" class="sidebar">
      <ul id="equipos-list"></ul>
    </aside>

    <section id="section-table">
      <button id="btn-cols" class="btn">🗂️ Columnas</button>
      <div id="cols-menu" class="cols-menu hidden"></div>

      <table id="tbl-integrantes">
        <thead></thead>
        <tbody></tbody>
      </table>
    </section>

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
          <fieldset>
            <legend>Datos personales</legend>
            <label>Nombres
              <input id="ed-nom" name="nombres" required maxlength="60">
            </label>
            <label>Apellido paterno
              <input id="ed-ap" name="apellido_paterno" required maxlength="30">
            </label>
            <label>Apellido materno
              <input id="ed-am" name="apellido_materno" maxlength="30">
            </label>

            <!-- Foto – solo eliminar -->
            <div style="display:flex;align-items:center;gap:.5rem">
              <img id="ed-foto" class="avatar" style="width:70px;height:70px">
              <button type="button" id="btn-del-photo" class="btn">
                🗑️ Eliminar foto
              </button>
            </div>

            <label>Fecha de nacimiento
              <input type="date" id="ed-fnac" name="fecha_nacimiento">
            </label>

            <!-- Documento de identidad -->
            <label>Tipo documento
              <select id="ed-doc-type" name="doc_type">
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

  <script src="integrantes.js"></script>
  <!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
  <script src="heartbeat.js"></script>
</body>
</html>
