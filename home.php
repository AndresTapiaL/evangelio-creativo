<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inicio</title>
  <link rel="stylesheet" href="styles/main.css">
  <style>
    nav {
      background-color: #f0f0f0;
      padding: 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    nav a {
      margin-right: 15px;
      text-decoration: none;
      color: #222;
      font-weight: bold;
    }
    .container {
      max-width: 900px;
      margin: 2rem auto;
      background-color: white;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    th, td {
      padding: 0.75rem;
      text-align: left;
      border-bottom: 1px solid #ddd;
    }
    .oculto {
      display: none;
    }
  </style>
  <script>
    document.addEventListener("DOMContentLoaded", () => {
        const token = localStorage.getItem('token');
        const rolesEquipos = JSON.parse(localStorage.getItem('roles_equipos') || '[]');

        if (!token) {
            localStorage.clear();
            return window.location.replace('login.html');
        }

        fetch(`validar_token.php?token=${token}`)
            .then(res => res.text())
            .then(data => {
                if (!data.includes("Token válido")) {
                    localStorage.clear();
                    window.location.replace('login.html');
                } else {
                    const tabla = document.getElementById("tabla-roles");
                    rolesEquipos.forEach(({ rol, equipo }) => {
                        const fila = document.createElement("tr");
                        fila.innerHTML = `<td>${rol}</td><td>${equipo}</td>`;
                        tabla.appendChild(fila);
                    });
                }
            });
    });

    function cerrarSesion() {
        const token = localStorage.getItem('token');
        if (!token) return window.location.href = 'login.html';

        fetch(`cerrar_sesion.php?token=${token}`)
            .then(() => {
                localStorage.clear();
                window.location.replace('login.html');
            });
    }
  </script>
</head>
<body style="background-image: url('images/Fondo-blanco.jpeg'); background-size: cover;">
  <nav>
    <div>
      <a href="index.html">Inicio</a>
      <a href="#">Integrantes</a>
      <a href="#">Eventos</a>
    </div>
    <a href="#" onclick="cerrarSesion()">Cerrar sesión</a>
  </nav>

  <main class="container">
    <h1>Bienvenido al Panel Principal</h1>
    <p>A continuación se listan todos los roles y equipos/proyectos que tienes asignados:</p>
    <table>
      <thead>
        <tr>
          <th>Rol</th>
          <th>Equipo / Proyecto</th>
        </tr>
      </thead>
      <tbody id="tabla-roles">
        <!-- filas dinámicas -->
      </tbody>
    </table>
  </main>
</body>
</html>
