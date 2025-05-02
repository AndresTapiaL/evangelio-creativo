<?php
/*  EDITAR MIS DATOS  –  Soporta:
    · Cascada País → Región → Ciudad
    · Varias ocupaciones con checkbox
---------------------------------------------------------------- */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar mis datos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-store">
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.min.css">
  <style>
    /* ---- layout básico ---- */
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

    /* foto */
    .foto-wrapper{display:flex;align-items:center;gap:.8rem;margin-bottom:.5rem}
    .foto-wrapper img{width:120px;height:120px;border-radius:50%;object-fit:cover}
    .delete-btn{cursor:pointer;color:#e11d48;font-weight:bold;font-size:1.3rem}

    /* ocupaciones */
    .ocups{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.3rem .8rem;margin-top:.3rem}
    .ocups label{font-weight:normal;display:flex;align-items:center;gap:.4rem}

    /* teléfonos */
    .tel-row{display:grid;grid-template-columns:220px 1fr 40px;gap:.5rem;align-items:center}
    .tel-row input{width:100%}

    .error-msg{color:#e11d48;font-size:.85rem;display:none}

    .botones{display:flex;justify-content:center;gap:1rem;margin-top:2rem}
    .btn-primario{background:#ff5722;color:#fff;border:none;padding:.75rem 2.5rem;font-weight:bold;border-radius:8px;cursor:pointer}
    .btn-secundario{background:#ddd;border:none;padding:.75rem 2.5rem;border-radius:8px;cursor:pointer}
  </style>

  <script>
    /* esconde hasta validar token */
    document.documentElement.style.display='none';
    (async()=>{
      const t=localStorage.getItem('token');
      if(!t) return location.replace('login.html');
      const ok=await fetch(`validar_token.php?token=${t}`).then(r=>r.text()).catch(()=>null);
      if(!ok||!ok.startsWith('Token válido')){
        localStorage.clear();location.replace('login.html');
      }else document.documentElement.style.display='';
    })();
  </script>
</head>

<body>
  <!-- NAV -->
  <nav>
    <div class="menu">
      <a href="home.php">Inicio</a><a href="#">Eventos</a><a href="#">Integrantes</a>
      <a href="ver_mis_datos.php">Mis datos</a>
    </div>
    <div class="perfil">
      <span id="nombre-usuario">Usuario</span>
      <img id="foto-perfil-nav" src="uploads/fotos/default.png" alt="Foto">
      <a href="#" onclick="cerrarSesion()" title="Cerrar sesión">🚪</a>
    </div>
  </nav>

  <!-- FORM -->
  <div class="container">
    <h1>Editar mis datos</h1>
    <form id="form-editar" novalidate>
      <!-- FOTO -->
      <div class="grupo">
        <label>Foto de perfil:</label>
        <div class="foto-wrapper">
          <img id="foto_perfil" src="uploads/fotos/default.png" alt="Foto">
          <span id="eliminar_foto" class="delete-btn" title="Eliminar">✕</span>
        </div>
        <input type="file" id="nueva_foto" accept="image/*">
        <small class="error-msg" id="error_foto"></small>
      </div>

      <!-- FECHA -->
      <div class="grupo"><label for="fecha_nacimiento">Fecha de nacimiento:</label>
        <input type="date" id="fecha_nacimiento" max="<?=date('Y-m-d')?>">
        <small class="error-msg" id="error_fecha"></small></div>

      <!-- DOC -->
      <div class="grupo">
        <label for="rut_dni">Documento de identidad:</label>
        <select id="tipo_doc"><option value="rut">RUT (Chile)</option><option value="int">Internacional</option></select>
        <input type="text" id="rut_dni" placeholder="Solo números">
        <small class="error-msg" id="error_rut"></small>
      </div>

      <!-- UBICACIÓN -->
      <div class="grupo"><label for="pais">País:</label><select id="pais"></select><small class="error-msg" id="error_pais"></small></div>
      <div class="grupo"><label for="region">Región / Estado:</label><select id="region"></select><small class="error-msg" id="error_region"></small></div>
      <div class="grupo"><label for="ciudad">Ciudad / Comuna:</label><select id="ciudad"></select><small class="error-msg" id="error_ciudad"></small></div>

      <!-- VARIOS -->
      <div class="grupo"><label for="direccion">Dirección:</label><input type="text" id="direccion"><small class="error-msg" id="error_direccion"></small></div>
      <div class="grupo"><label for="iglesia">Iglesia o Ministerio:</label><input type="text" id="iglesia"><small class="error-msg" id="error_iglesia"></small></div>
      <div class="grupo"><label for="profesion">Profesión / Oficio / Estudio:</label><input type="text" id="profesion"><small class="error-msg" id="error_profesion"></small></div>

      <!-- OCUPACIONES -->
      <div class="grupo">
        <label>Ocupación(es):</label>
        <div id="ocupaciones-wrapper" class="ocups"></div>
        <small class="error-msg" id="error_ocupacion"></small>
      </div>

      <!-- CORREO -->
      <div class="grupo"><label for="correo">Correo electrónico:</label><input type="email" id="correo"><small class="error-msg" id="error_correo"></small></div>
      <div class="grupo"><label><input type="checkbox" id="boletin"> Recibir boletines</label></div>

      <!-- TELÉFONOS -->
<?php
require 'conexion.php';
$token=$_GET['token']??'';$uid=0;
if($token){
  $q=$pdo->prepare("SELECT id_usuario FROM tokens_usuarios WHERE token=?");$q->execute([$token]);$uid=$q->fetchColumn();
}
$desc=$pdo->query("SELECT id_descripcion_telefono,nombre_descripcion_telefono FROM descripcion_telefonos ORDER BY nombre_descripcion_telefono")->fetchAll(PDO::FETCH_ASSOC);
$t=[];
if($uid){
  $z=$pdo->prepare("SELECT telefono,id_descripcion_telefono FROM telefonos WHERE id_usuario=? ORDER BY es_principal DESC");$z->execute([$uid]);$t=$z->fetchAll(PDO::FETCH_ASSOC);
}
for($i=0;$i<3;$i++):
  $tel=$t[$i]['telefono']??'';$ds=$t[$i]['id_descripcion_telefono']??'';
?>
      <div class="grupo tel-row">
        <input type="tel" id="telefono_<?=$i+1?>" value="<?=htmlspecialchars($tel)?>" placeholder="Teléfono <?=($i?'secundario':'principal')?>">
        <select id="tipo_telefono_<?=$i+1?>">
<?php foreach($desc as $d):?>
          <option value="<?=$d['id_descripcion_telefono']?>" <?=$ds==$d['id_descripcion_telefono']?'selected':''?>><?=$d['nombre_descripcion_telefono']?></option>
<?php endforeach;?>
        </select>
        <span class="delete-btn delete-telefono" data-indice="<?=$i+1?>">✕</span>
      </div>
      <small class="error-msg" id="error_tel<?=$i+1?>"></small>
<?php endfor; ?>

      <!-- BOTONES -->
      <div class="botones">
        <button type="submit" class="btn-primario">Guardar cambios</button>
        <a href="ver_mis_datos.php"><button type="button" class="btn-secundario">Cancelar</button></a>
      </div>
    </form>
  </div>

  <!-- LIBRERÍAS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"></script>
  <script src="editar_mis_datos.js?v=6.4"></script>
</body>
</html>
