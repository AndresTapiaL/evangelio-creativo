
document.addEventListener("DOMContentLoaded", () => {
  const token = localStorage.getItem("token");
  if (!token) return;

  fetch("get_usuario.php?token=" + token)
    .then(res => res.json())
    .then(data => {
      const contenedor = document.getElementById("estados-actividad");
      const estados = data.estado_actividad_por_equipo || {};

      for (const equipo in estados) {
        const grupo = estados[equipo];
        const divEquipo = document.createElement("div");
        divEquipo.classList.add("equipo");

        const titulo = document.createElement("h4");
        titulo.textContent = `Equipo: ${equipo}`;
        divEquipo.appendChild(titulo);

        const ul = document.createElement("ul");
        grupo.forEach(e => {
          const li = document.createElement("li");
          li.textContent = `${e.fecha}: ${e.estado}`;
          ul.appendChild(li);
        });

        divEquipo.appendChild(ul);
        contenedor.appendChild(divEquipo);
      }
    });
});
