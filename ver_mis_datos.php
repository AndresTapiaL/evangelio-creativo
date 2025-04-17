<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ver mis datos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="styles/main.css">
  <style>
    body {
      font-family: sans-serif;
      background: #f6f6f6;
      margin: 0;
      padding: 2rem;
    }
    .container {
      max-width: 800px;
      margin: auto;
      background: white;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 0 15px rgba(0,0,0,0.1);
    }
    h1 {
      text-align: center;
    }
    .grupo {
      margin-bottom: 1rem;
    }
    .grupo label {
      font-weight: bold;
      display: block;
    }
    .grupo span {
      display: inline-block;
      margin-top: 0.25rem;
      padding: 0.5rem;
      background: #f0f0f0;
      border-radius: 5px;
      width: 100%;
    }
    img.foto {
      max-width: 120px;
      max-height: 120px;
      border-radius: 50%;
      display: block;
      margin-bottom: 1rem;
    }
    table {
      width: 100%;
      margin-top: 1rem;
      border-collapse: collapse;
    }
    th, td {
      text-align: left;
      border-bottom: 1px solid #ddd;
      padding: 0.5rem;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Mis Datos</h1>
    <div class="grupo"><label>Foto de perfil:</label><img class="foto" id="foto_perfil" src="" alt="Foto"></div>
    <div class="grupo"><label>Nombres:</label><span id="nombres"></span></div>
    <div class="grupo"><label>Apellido paterno:</label><span id="apellido_paterno"></span></div>
    <div class="grupo"><label>Apellido materno:</label><span id="apellido_materno"></span></div>
    <div class="grupo"><label>Fecha de nacimiento:</label><span id="fecha_nacimiento"></span></div>
    <div class="grupo"><label>RUT / DNI:</label><span id="rut_dni"></span></div>
    <div class="grupo"><label>País:</label><span id="pais"></span></div>
    <div class="grupo"><label>Región / Estado:</label><span id="region"></span></div>
    <div class="grupo"><label>Ciudad / Comuna:</label><span id="ciudad"></span></div>
    <div class="grupo"><label>Dirección:</label><span id="direccion"></span></div>
    <div class="grupo"><label>Iglesia o Ministerio:</label><span id="iglesia"></span></div>
    <div class="grupo"><label>Profesión u Oficio:</label><span id="profesion"></span></div>
    <div class="grupo"><label>Ocupación:</label><span id="ocupacion"></span></div>
    <div class="grupo"><label>Correo electrónico:</label><span id="correo"></span></div>
    <div class="grupo"><label>Recibe boletines:</label><span id="boletin"></span></div>
    <div class="grupo"><label>Teléfonos:</label><ul id="telefonos"></ul></div>
    <div class="grupo"><label>Equipos y roles:</label>
      <table id="tabla-equipos"><thead><tr><th>Equipo / Proyecto</th><th>Rol</th></tr></thead><tbody></tbody></table>
    </div>
    <div class="grupo"><label>Últimas actividades:</label>
      <table id="tabla-actividad"><thead><tr><th>Fecha</th><th>Descripción</th></tr></thead><tbody></tbody></table>
    </div>
  </div>
  <script src="ver_mis_datos.js"></script>
</body>
</html>
