
document.addEventListener("DOMContentLoaded", () => {
  const token = localStorage.getItem("token");
  if (!token) return window.location.replace("login.html");

  fetch("get_usuario.php?token=" + token)
    .then(res => res.json())
    .then(data => {
      if (data.error) return alert("Error al obtener datos del usuario.");

      document.getElementById("nombres").innerText = data.nombres || "";
      document.getElementById("apellido_paterno").innerText = data.apellido_paterno || "";
      document.getElementById("apellido_materno").innerText = data.apellido_materno || "";
      document.getElementById("fecha_nacimiento").innerText = data.fecha_nacimiento || "";
      document.getElementById("rut_dni").innerText = data.rut_dni || "";
      document.getElementById("direccion").innerText = data.direccion || "";
      document.getElementById("iglesia").innerText = data.iglesia_ministerio || "";
      document.getElementById("profesion").innerText = data.profesion_oficio_estudio || "";
      document.getElementById("correo").innerText = data.correo || "";
      document.getElementById("boletin").innerText = data.boletin ? "Sí" : "No";

      const foto = data.foto_perfil || "uploads/fotos/default.png";
      document.getElementById("foto_perfil").src = foto;

      cargarNombre("pais", "paises", "id_pais", data.id_pais);
      cargarNombre("region", "region_estado", "id_region_estado", data.id_region_estado);
      cargarNombre("ciudad", "ciudad_comuna", "id_ciudad_comuna", data.id_ciudad_comuna);
      cargarNombre("ocupacion", "ocupaciones", "id_ocupacion", data.id_ocupacion);

      const listaTelefonos = document.getElementById("telefonos");
      (data.telefonos || []).forEach(t => {
        const li = document.createElement("li");
        const tipo = {
          1: "Solo llamadas",
          2: "Solo WhatsApp",
          3: "Llamadas y WhatsApp"
        }[t.descripcion_id] || "Sin tipo";
        li.innerText = `${t.numero} (${tipo}${t.es_principal ? " - Principal" : ""})`;
        listaTelefonos.appendChild(li);
      });

      const tablaRoles = document.querySelector("#tabla-equipos tbody");
      (data.roles_equipos || []).forEach(r => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${r.equipo}</td><td>${r.rol}</td>`;
        tablaRoles.appendChild(tr);
      });

      const tablaAct = document.querySelector("#tabla-actividad tbody");
      (data.actividades || []).forEach(a => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${a.fecha}</td><td>${a.descripcion}</td>`;
        tablaAct.appendChild(tr);
      });
    });

  function cargarNombre(campo, tabla, columna, valor) {
    if (!valor) return;
    fetch(`get_nombre.php?tabla=${tabla}&columna=${columna}&id=${valor}`)
      .then(res => res.json())
      .then(res => {
        document.getElementById(campo).innerText = res.nombre || "";
      });
  }
});
