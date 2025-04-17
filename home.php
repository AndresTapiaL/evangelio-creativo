<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inicio</title>
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    nav {
      background-color: #f0f0f0;
      padding: 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
    }
    nav .menu {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: center;
    }
    nav a {
      text-decoration: none;
      color: #222;
      font-weight: bold;
    }
    .perfil {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .perfil img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      object-fit: cover;
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
  </style>
  <script>
    document.addEventListener("DOMContentLoaded", () => {
        const token = localStorage.getItem('token');
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
                    fetch(`get_usuario.php?token=${token}`)
                        .then(res => res.json())
                        .then(usuario => {
                            document.getElementById("nombre-usuario").innerText = usuario.nombres || "Usuario";
                            if (usuario.foto_perfil) {
                                const img = document.getElementById("foto-perfil");
                                img.src = usuario.foto_perfil;
                                img.alt = usuario.nombres || "Foto perfil";
                            }
                            const tabla = document.getElementById("tabla-roles");
                            usuario.roles_equipos.forEach(({ rol, equipo }) => {
                                const fila = document.createElement("tr");
                                fila.innerHTML = `<td>${rol}</td><td>${equipo}</td>`;
                                tabla.appendChild(fila);
                            });
                        });
                }
            });
    });

    function cerrarSesion() {
        const token = localStorage.getItem('token');
        if (token) {
            fetch(`cerrar_sesion.php?token=${token}`)
                .then(() => {
                    localStorage.clear();
                    window.location.replace('login.html');
                });
        }
    }
  </script>
</head>
<body style="background-image: url('images/Fondo-blanco.jpeg'); background-size: cover;">
  <nav>
    <div class="menu">
      <a href="home.php">Inicio</a>
      <a href="#">Eventos</a>
      <a href="#">Integrantes</a>
      <a href="ver_mis_datos.php">Mis datos</a>
      <a href="#">Reportes</a>
      <a href="#">Admisión</a>
      <a href="#"><i class="fas fa-bell"></i></a>
    </div>
    <div class="perfil">
      <span id="nombre-usuario">Usuario</span>
      <img id="foto-perfil" src="uploads/fotos/default.png" alt="Foto de perfil">
      <a href="#" onclick="cerrarSesion()" title="Cerrar sesión">🚪</a>
    </div>
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
