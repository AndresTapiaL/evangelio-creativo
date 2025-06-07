<?php
date_default_timezone_set('UTC');
require 'conexion.php';

session_start();
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

// — Nav (nombre + foto) —
$stmt = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
$stmt->execute(['id' => $id_usuario]);
$navUser = $stmt->fetch(PDO::FETCH_ASSOC);

// — Datos completos del usuario —
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id_usuario = :id");
$stmt->execute(['id' => $id_usuario]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// — Descripciones de teléfono —
$descList = $pdo->query("
  SELECT id_descripcion_telefono AS clave, nombre_descripcion_telefono AS nombre
    FROM descripcion_telefonos
   ORDER BY nombre_descripcion_telefono
")->fetchAll(PDO::FETCH_ASSOC);

// — Teléfonos del usuario (hasta 3) —
$telRows = $pdo->prepare("
  SELECT telefono, id_descripcion_telefono AS tipo_telefono
    FROM telefonos
   WHERE id_usuario = :id
   ORDER BY es_principal DESC
   LIMIT 3
");
$telRows->execute(['id' => $id_usuario]);
$telefonos = $telRows->fetchAll(PDO::FETCH_ASSOC);

// — Correo y boletines — 
$stmt = $pdo->prepare("
  SELECT correo_electronico AS correo, boletin
    FROM correos_electronicos
   WHERE id_usuario = :id
   LIMIT 1
");
$stmt->execute(['id' => $id_usuario]);
$emailData = $stmt->fetch(PDO::FETCH_ASSOC);

// — Ocupaciones y las que tiene el usuario —
$ocupList = $pdo->query("
  SELECT id_ocupacion, nombre
    FROM ocupaciones
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
  SELECT id_ocupacion
    FROM usuarios_ocupaciones
   WHERE id_usuario = :id
");
$stmt->execute(['id' => $id_usuario]);
$userOcupIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id_ocupacion');

// — Países, Regiones y Ciudades —
$paises = $pdo->query("
  SELECT id_pais, nombre_pais
    FROM paises
")->fetchAll(PDO::FETCH_ASSOC);

$regionesStmt = $pdo->prepare("
  SELECT id_region_estado, nombre_region_estado
    FROM region_estado
   WHERE id_pais = :p
");
$regionesStmt->execute(['p' => $user['id_pais']]);
$regionList = $regionesStmt->fetchAll(PDO::FETCH_ASSOC);

$ciudadesStmt = $pdo->prepare("
  SELECT id_ciudad_comuna, nombre_ciudad_comuna
    FROM ciudad_comuna
   WHERE id_region_estado = :r
");
$ciudadesStmt->execute(['r' => $user['id_region_estado']]);
$ciudadList = $ciudadesStmt->fetchAll(PDO::FETCH_ASSOC);

// — Cache-bust para default.png —
$defaultVersion = filemtime(__DIR__ . '/uploads/fotos/default.png');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-store, must-revalidate"/>
  <title>Editar mis datos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.min.css">
  <style>
    nav{background:#f0f0f0;padding:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}
    nav .menu{display:flex;gap:1rem;flex-wrap:wrap}
    nav a{text-decoration:none;color:#222;font-weight:bold}
    .perfil{display:flex;align-items:center;gap:.5rem}
    .perfil img{width:32px;height:32px;border-radius:50%;object-fit:cover}
    body{font-family:sans-serif;margin:0;background:#f6f6f6;padding:2rem}
    .container{max-width:840px;margin:auto;background:#fff;padding:2rem;border-radius:10px;box-shadow:0 0 12px rgba(0,0,0,.1)}
    h1{margin-top:0}
    .grupo{display:flex;flex-direction:column;margin-bottom:1rem}
    .grupo label{font-weight:bold;margin-bottom:.25rem}
    .grupo input,.grupo select{padding:.55rem;border:1px solid #ccc;border-radius:6px;font-size:1rem}
    .foto-wrapper{display:flex;align-items:center;gap:.8rem;margin-bottom:.5rem}
    .foto-wrapper img{width:120px;height:120px;border-radius:50%;object-fit:cover}
    .delete-btn{cursor:pointer;color:#e11d48;font-weight:bold;font-size:1.3rem}
    .ocups{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.3rem .8rem;margin-top:.3rem}
    .ocups label{font-weight:normal;display:flex;align-items:center;gap:.4rem}
    .tel-row{display:grid;grid-template-columns:350px 1fr 40px;gap:.5rem;align-items:center}
    .tel-row input{width:100%}
    .error-msg{color:#e11d48;font-size:.85rem;display:none}
    .botones{display:flex;justify-content:center;gap:1rem;margin-top:2rem}
    .btn-primario{background:#ff5722;color:#fff;border:none;padding:.75rem 2.5rem;font-weight:bold;border-radius:8px;cursor:pointer}
    .btn-secundario{background:#ddd;border:none;padding:.75rem 2.5rem;border-radius:8px;cursor:pointer}

    #overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.7);
      display: none;               /* <-- oculto hasta que hagas click en la foto */
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
    /* --------------------------------- */
    /* Apilar ocupaciones en columna    */
    /* --------------------------------- */
    .ocups {
      display: flex;
      flex-direction: column;  /* <<< aquí cambiamos de grid a columna */
      gap: 0.75rem;            /* espacio vertical entre tarjetas */
      margin-top: 0.5rem;
    }

    /* Asegura que cada tarjeta ocupe todo el ancho disponible */
    .ocups label {
      width: 100%;
    }

    .ocups label:hover {
      background-color: #e0e0e0;
    }

    .ocups label input[type="checkbox"] {
      accent-color: #4a90e2;
      width: 1.2em;
      height: 1.2em;
      margin-top: 0.2em; /* opcional: alinea un poco el checkbox con el primer renglón */
    }

    .ocups label span {
      flex-grow: 1;
      line-height: 1.3;
      font-size: 0.95rem;
      color: #333;
    }
  </style>

  <!-- ═════════ Validación única al cargar la página ═════════ -->
  <script>
  (()=>{
    const token = localStorage.getItem('token');
    if (!token) { location.replace('login.html'); return; }
    const ctrl = new AbortController();
    window.addEventListener('beforeunload', ()=> ctrl.abort());
    fetch('validar_token.php',{headers:{'Authorization':'Bearer '+token},signal:ctrl.signal})
      .then(r=>{ if(r.status===401) throw new Error('TokenNoValido'); return r.json(); })
      .then(d=>{ if(!d.ok) throw new Error('TokenNoValido'); })
      .catch(e=>{
        if(e.message==='TokenNoValido'){
          localStorage.clear(); location.replace('login.html');
        }
      });
  })();
  </script>
  <!-- ═══════════════════════════════════════════════ -->
</head>

<body>
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
      <span id="nombre-usuario"><?=htmlspecialchars($navUser['nombres'])?></span>
      <img id="foto-perfil-nav"
          src="<?=htmlspecialchars($navUser['foto_perfil']?:'uploads/fotos/default.png')?>?v=<?= $defaultVersion ?>"
          alt="Foto de <?=htmlspecialchars($navUser['nombres'])?>">
      <a href="#" id="logout" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <div class="container">
    <h1>Editar mis datos</h1>
    <form id="form-editar" novalidate enctype="multipart/form-data">
      <!-- 1. Foto de Perfil -->
      <div class="grupo">
        <label>Foto de perfil:</label>
        <div class="foto-wrapper">
          <img id="foto_perfil"
              src="<?=htmlspecialchars($user['foto_perfil'] ?: 'uploads/fotos/default.png')?>?v=<?= $defaultVersion ?>"
              alt="Foto de perfil">
          <span id="eliminar_foto" class="delete-btn" title="Eliminar">✕</span>
        </div>
        <input type="file" id="nueva_foto" name="foto" accept="image/*">
        <input type="hidden" id="delete_foto" name="delete_foto" value="0">
        <small class="error-msg" id="error_foto"></small>
      </div>

      <!-- 2. Fecha de Nacimiento -->
      <div class="grupo">
        <label for="fecha_nacimiento">Fecha de nacimiento:</label>
        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento"
              max="<?= date('Y-m-d') ?>"
              value="<?= htmlspecialchars($user['fecha_nacimiento']) ?>">
        <small class="error-msg" id="error_fecha"></small>
      </div>

      <!-- 3. Documento de identidad -->
      <div class="grupo">
        <label for="rut_dni">Documento de identidad:</label>
        <select id="tipo_doc" name="tipo_doc">
          <option value="rut" <?= $user['id_pais']==1?'selected':'' ?>>RUT (Chile)</option>
          <option value="int" <?= $user['id_pais']!=1?'selected':'' ?>>Internacional</option>
        </select>
        <input type="text" id="rut_dni" name="rut_dni"
              value="<?= htmlspecialchars($user['rut_dni']) ?>"
              placeholder="Solo números">
        <small class="error-msg" id="error_rut"></small>
      </div>

      <!-- 4. País -->
      <div class="grupo">
        <label for="pais">País:</label>
        <select id="pais" name="id_pais">
          <?php foreach($paises as $p): ?>
            <option value="<?= $p['id_pais']?>"
              <?= $p['id_pais']==$user['id_pais']?'selected':''?>>
              <?= htmlspecialchars($p['nombre_pais'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="error-msg" id="error_pais"></small>
      </div>

      <!-- 5. Región / Estado -->
      <div class="grupo">
        <label for="region">Región / Estado:</label>
        <select id="region" name="id_region_estado">
          <?php foreach($regionList as $r): ?>
            <option value="<?= $r['id_region_estado']?>"
              <?= $r['id_region_estado']==$user['id_region_estado']?'selected':''?>>
              <?= htmlspecialchars($r['nombre_region_estado'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="error-msg" id="error_region"></small>
      </div>

      <!-- 6. Ciudad / Comuna -->
      <div class="grupo">
        <label for="ciudad">Ciudad / Comuna:</label>
        <select id="ciudad" name="id_ciudad_comuna">
          <?php foreach($ciudadList as $c): ?>
            <option value="<?= $c['id_ciudad_comuna']?>"
              <?= $c['id_ciudad_comuna']==$user['id_ciudad_comuna']?'selected':''?>>
              <?= htmlspecialchars($c['nombre_ciudad_comuna'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="error-msg" id="error_ciudad"></small>
      </div>

      <!-- 7. Dirección -->
      <div class="grupo">
        <label for="direccion">Dirección:</label>
        <input type="text" id="direccion" name="direccion"
              value="<?= htmlspecialchars($user['direccion']) ?>">
        <small class="error-msg" id="error_direccion"></small>
      </div>

      <!-- 8. Iglesia / Ministerio -->
      <div class="grupo">
        <label for="iglesia">Iglesia / Ministerio:</label>
        <input type="text" id="iglesia" name="iglesia_ministerio"
              value="<?= htmlspecialchars($user['iglesia_ministerio']) ?>">
        <small class="error-msg" id="error_iglesia"></small>
      </div>

      <!-- 9. Profesión / Oficio / Estudio -->
      <div class="grupo">
        <label for="profesion">Profesión / Oficio / Estudio:</label>
        <input type="text" id="profesion" name="profesion_oficio_estudio"
              value="<?= htmlspecialchars($user['profesion_oficio_estudio']) ?>">
        <small class="error-msg" id="error_profesion"></small>
      </div>

      <!-- 10. Ocupación(es) -->
      <div class="grupo">
        <label>Ocupación(es):</label>
        <div id="ocupaciones-wrapper" class="ocups">
          <?php foreach($ocupList as $o): ?>
            <label>
              <input type="checkbox" name="id_ocupacion[]"
                    value="<?= $o['id_ocupacion'] ?>"
                <?= in_array($o['id_ocupacion'],$userOcupIds)?'checked':''?>>
              <span><?= htmlspecialchars($o['nombre']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <small class="error-msg" id="error_ocupacion"></small>
      </div>

      <!-- 11. Correo electrónico -->
      <div class="grupo">
        <label for="correo">Correo electrónico:</label>
        <input type="email" id="correo" name="correo"
              value="<?= htmlspecialchars($emailData['correo'] ?? '') ?>">
        <small class="error-msg" id="error_correo"></small>
      </div>

      <!-- 12. Recibir boletines -->
      <div class="grupo">
        <label>
          <input type="checkbox" id="boletin" name="boletin"
            <?= !empty($emailData['boletin']) ? 'checked' : '' ?>>
          Recibir boletines
        </label>
      </div>

      <!-- 13. Teléfonos -->
      <h2>Teléfonos</h2>
      <?php for($i=0; $i<3; $i++):
        $tel = $telefonos[$i]['telefono'] ?? '';
        $tp  = $telefonos[$i]['tipo_telefono'] ?? '';
      ?>
        <div class="grupo tel-row">
          <input
            type="tel"
            id="telefono_<?=($i+1)?>"
            name="telefono_<?=($i+1)?>"
            value="<?= htmlspecialchars($tel)?>"
            placeholder="Teléfono <?= $i?($i+1).'':'1 (principal)'?>"
          >
          <select
            id="tipo_telefono_<?=($i+1)?>"
            name="tipo_telefono_<?=($i+1)?>"
          >
            <?php foreach($descList as $d): ?>
              <option
                value="<?= $d['clave']?>"
                <?= $d['clave']==$tp?'selected':''?>
              >
                <?= htmlspecialchars($d['nombre'])?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="delete-btn delete-telefono" data-indice="<?=($i+1)?>">✕</span>
          <small class="error-msg" id="error_tel<?=($i+1)?>"></small>
        </div>
      <?php endfor; ?>

      <!-- Botones -->
      <div class="botones">
        <button type="submit" class="btn-primario">Guardar cambios</button>
        <a href="ver_mis_datos.php">
          <button type="button" class="btn-secundario">Cancelar</button>
        </a>
      </div>
    </form>
  </div>

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

  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
  <script src="editar_mis_datos.js?v=6.4"></script>
  <script src="/heartbeat.js"></script>
</body>
</html>
