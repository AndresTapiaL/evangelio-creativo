<!DOCTYPE html>
<html lang="es">
<head>
  <link rel="stylesheet" href="styles/main.css">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Mis Datos</title>
  
  <style>
    .container {
      max-width: 1000px;
      margin: 2rem auto;
      background-color: white;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      font-family: Arial, sans-serif;
    }
    h2 {
      margin-bottom: 1rem;
      border-bottom: 2px solid #eee;
      padding-bottom: 0.5rem;
    }
    .campo {
      margin-bottom: 1rem;
    }
    label {
      font-weight: bold;
      display: block;
      margin-bottom: 0.25rem;
    }
    input[type="text"], input[type="email"], input[type="date"], select {
      width: 100%;
      padding: 0.5rem;
      border-radius: 4px;
      border: 1px solid #ccc;
    }
    .foto {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .foto img {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      object-fit: cover;
    }
    .telefonos {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    th, td {
      padding: 0.5rem;
      border-bottom: 1px solid #ccc;
      text-align: left;
    }
  </style>
</head>
<body>
  <main class="container">
    <h2>Mis Datos</h2>

    <div class="campo"><label>Nombres:</label><div id="nombres"></div></div>
    <div class="campo"><label>Apellido paterno:</label><div id="apellido_paterno"></div></div>
    <div class="campo"><label>Apellido materno:</label><div id="apellido_materno"></div></div>

    <div class="campo foto">
      <img id="foto_perfil" src="images/default-profile.png" alt="Foto de perfil">
      <div>
        <label for="nueva_foto">Cambiar foto de perfil:</label>
        <input type="file" id="nueva_foto" accept="image/*">
      </div>
    </div>

    <div class="campo"><label for="fecha_nacimiento">Fecha de nacimiento:</label><input type="date" id="fecha_nacimiento"></div>
    <div class="campo"><label for="rut_dni">RUT / DNI:</label><input type="text" id="rut_dni"></div>

    <div class="campo"><label for="pais">País:</label><select id="pais"></select></div>
    <div class="campo"><label for="region">Región / Estado:</label><select id="region"></select></div>
    <div class="campo"><label for="ciudad">Ciudad / Comuna:</label><select id="ciudad"></select></div>

    <div class="campo"><label for="direccion">Dirección:</label><input type="text" id="direccion"></div>
    <div class="campo"><label for="iglesia">Iglesia o Ministerio:</label><input type="text" id="iglesia"></div>
    <div class="campo"><label for="profesion">Profesión / Oficio / Estudio:</label><input type="text" id="profesion"></div>
    <div class="campo"><label for="ocupacion">Ocupación:</label><select id="ocupacion"></select></div>

    <div class="campo"><label for="correo">Correo electrónico:</label><input type="email" id="correo"></div>
    <div class="campo"><label><input type="checkbox" id="boletin"> Recibir boletines informativos</label></div>

    <div class="campo">
      <label>Teléfonos (máximo 3):</label>
      <div class="telefonos" id="lista_telefonos">
        <!-- Teléfonos se cargan dinámicamente -->
      </div>
    </div>

    <div class="campo">
      <h3>Equipos y roles</h3>
      <table id="tabla-equipos">
        <thead><tr><th>Equipo / Proyecto</th><th>Rol</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="campo">
      <h3>Últimas actividades</h3>
      <table id="tabla-actividad">
        <thead><tr><th>Fecha</th><th>Actividad</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="campo" style="margin-top:2rem;">
      <button type="submit">Guardar cambios</button> <button onclick="document.getElementById('modal-password').style.display='flex'">Cambiar contraseña</button>
      <button onclick="window.location.href='home.php'">Cancelar</button>
    </div>

  </main>

<!-- Modal Cambiar Contraseña -->
<div id="modal-password" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:#000000aa; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:2rem; border-radius:8px; max-width:400px; width:100%;">
    <h3>Cambiar contraseña</h3>
    <div class="campo"><label>Contraseña actual:</label><input type="password" id="clave_actual"></div>
    <div class="campo"><label>Nueva contraseña:</label><input type="password" id="clave_nueva"></div>
    <div class="campo"><label>Confirmar nueva contraseña:</label><input type="password" id="clave_nueva2"></div>
    <div style="text-align:right; margin-top:1rem;">
      <button onclick="document.getElementById('modal-password').style.display='none'">Cancelar</button>
      <button onclick="cambiarPassword()">Guardar</button>
    </div>
  </div>
</div>

</body>
</html>
