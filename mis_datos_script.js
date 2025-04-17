
document.addEventListener("DOMContentLoaded", () => {
  const token = localStorage.getItem('token');
  if (!token) return window.location.replace('login.html');

  const paisSel = document.getElementById("pais");
  const regionSel = document.getElementById("region");
  const ciudadSel = document.getElementById("ciudad");
  const ocupacionSel = document.getElementById("ocupacion");

  function cargarUbicaciones(tipo, id, destino, valorSeleccionado) {
    let url = `ubicacion.php?tipo=${tipo}`;
    if (id) url += `&id=${id}`;
    fetch(url)
      .then(res => res.json())
      .then(data => {
        destino.innerHTML = "";
        data.forEach(item => {
          const opt = document.createElement("option");
          opt.value = item.id;
          opt.text = item.nombre;
          if (item.id == valorSeleccionado) opt.selected = true;
          destino.appendChild(opt);
        });
      });
  }

  function cargarOcupaciones(seleccionada) {
    fetch("get_ocupaciones.php")
      .then(res => res.json())
      .then(data => {
        data.forEach(ocup => {
          const opt = document.createElement("option");
          opt.value = ocup.id_ocupacion;
          opt.text = ocup.nombre_ocupacion;
          if (ocup.id_ocupacion == seleccionada) opt.selected = true;
          ocupacionSel.appendChild(opt);
        });
      });
  }

  fetch('get_usuario.php?token=' + token)
    .then(res => res.json())
    .then(data => {
      if (data.error) return window.location.replace('login.html');

      document.getElementById('nombres').innerText = data.nombres || '';
      document.getElementById('apellido_paterno').innerText = data.apellido_paterno || '';
      document.getElementById('apellido_materno').innerText = data.apellido_materno || '';
      document.getElementById('foto_perfil').src = data.foto_perfil || 'images/default-profile.png';
      document.getElementById('fecha_nacimiento').value = data.fecha_nacimiento || '';
      document.getElementById('rut_dni').value = data.rut_dni || '';
      document.getElementById('direccion').value = data.direccion || '';
      document.getElementById('iglesia').value = data.iglesia_ministerio || '';
      document.getElementById('profesion').value = data.profesion_oficio_estudio || '';
      document.getElementById('correo').value = data.correo || '';
      document.getElementById('boletin').checked = data.boletin || false;

      cargarUbicaciones('pais', null, paisSel, data.id_pais);
      paisSel.addEventListener("change", () => {
        cargarUbicaciones('region', paisSel.value, regionSel);
        ciudadSel.innerHTML = "";
      });

      cargarUbicaciones('region', data.id_pais, regionSel, data.id_region_estado);
      regionSel.addEventListener("change", () => {
        cargarUbicaciones('ciudad', regionSel.value, ciudadSel);
      });

      cargarUbicaciones('ciudad', data.id_region_estado, ciudadSel, data.id_ciudad_comuna);
      cargarOcupaciones(data.id_ocupacion);

      const tablaEquipos = document.querySelector("#tabla-equipos tbody");
      data.roles_equipos.forEach(({ rol, equipo }) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${equipo}</td><td>${rol}</td>`;
        tablaEquipos.appendChild(tr);
      });

      const tablaActividad = document.querySelector("#tabla-actividad tbody");
      (data.actividades || []).slice(0, 3).forEach(({ fecha, descripcion }) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${fecha}</td><td>${descripcion}</td>`;
        tablaActividad.appendChild(tr);
      });

      const listaTelefonos = document.getElementById("lista_telefonos");
      (data.telefonos || []).forEach((telefono, index) => {
        const div = document.createElement("div");
        div.innerHTML = `
          <input type="text" value="${telefono.numero}" placeholder="Teléfono ${index + 1}">
          <select>
            <option value="">Tipo</option>
            <option value="1"${telefono.descripcion_id == 1 ? ' selected' : ''}>Solo llamadas</option>
            <option value="2"${telefono.descripcion_id == 2 ? ' selected' : ''}>Solo WhatsApp</option>
            <option value="3"${telefono.descripcion_id == 3 ? ' selected' : ''}>Llamadas y WhatsApp</option>
          </select>
          ${telefono.es_principal ? '<span>(Principal)</span>' : ''}
        `;
        listaTelefonos.appendChild(div);
      });
    });

  document.getElementById("nueva_foto").addEventListener("change", function () {
    const archivo = this.files[0];
    if (!archivo || !token) return;
    const formData = new FormData();
    formData.append("foto", archivo);
    formData.append("token", token);
    fetch("subir_foto.php", {
      method: "POST",
      body: formData
    })
      .then(res => res.json())
      .then(data => {
        if (data.ruta) {
          document.getElementById("foto_perfil").src = data.ruta;
          alert("Foto de perfil actualizada correctamente");
        } else {
          alert(data.error || "Error al subir la imagen");
        }
      });
  });

  const guardarBtn = document.querySelector("button[type='submit']");
  guardarBtn.addEventListener("click", function (e) {
    e.preventDefault();
    guardarBtn.disabled = true;
    guardarBtn.textContent = "Guardando...";
    const datos = new FormData();
    datos.append("token", token);
    datos.append("fecha_nacimiento", document.getElementById("fecha_nacimiento").value);
    datos.append("rut_dni", document.getElementById("rut_dni").value);
    datos.append("id_pais", document.getElementById("pais").value);
    datos.append("id_region_estado", document.getElementById("region").value);
    datos.append("id_ciudad_comuna", document.getElementById("ciudad").value);
    datos.append("direccion", document.getElementById("direccion").value);
    datos.append("iglesia_ministerio", document.getElementById("iglesia").value);
    datos.append("profesion_oficio_estudio", document.getElementById("profesion").value);
    datos.append("id_ocupacion", document.getElementById("ocupacion").value);
    datos.append("correo", document.getElementById("correo").value);
    if (document.getElementById("boletin").checked) {
      datos.append("boletin", 1);
    }

    fetch("actualizar_usuario.php", {
      method: "POST",
      body: datos
    })
      .then(res => res.json())
      .then(res => {
        alert(res.mensaje || res.error);
        guardarBtn.disabled = false;
        guardarBtn.textContent = "Guardar cambios";
        if (res.mensaje) window.location.reload();
      })
      .catch(err => {
        console.error("Error al guardar:", err);
        alert("Error al guardar los datos.");
        guardarBtn.disabled = false;
        guardarBtn.textContent = "Guardar cambios";
      });
  });
});

function cambiarPassword() {
  const actual = document.getElementById("clave_actual").value;
  const nueva = document.getElementById("clave_nueva").value;
  const nueva2 = document.getElementById("clave_nueva2").value;
  const token = localStorage.getItem("token");

  if (!actual || !nueva || !nueva2) {
    return alert("Completa todos los campos.");
  }
  if (nueva !== nueva2) {
    return alert("Las nuevas contraseñas no coinciden.");
  }

  const datos = new FormData();
  datos.append("token", token);
  datos.append("clave_actual", actual);
  datos.append("clave_nueva", nueva);

  fetch("cambiar_password.php", {
    method: "POST",
    body: datos
  })
    .then(res => res.json())
    .then(res => {
      if (res.mensaje) {
        alert(res.mensaje);
        document.getElementById("modal-password").style.display = "none";
      } else {
        alert(res.error || "Error al cambiar la contraseña.");
      }
    })
    .catch(err => {
      console.error("Error:", err);
      alert("Error inesperado.");
    });
}
