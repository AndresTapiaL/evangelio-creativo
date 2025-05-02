/* ================================================================
   VER MIS DATOS – v 6.0
   · Soporta lista de ocupaciones (muchos-a-muchos)
   · Formatea RUT chileno y fechas
   · Pop-up de foto  • Teléfonos y roles
================================================================= */
document.addEventListener('DOMContentLoaded', () => {
  const token = localStorage.getItem('token');
  if (!token) return;

  /* ─── elementos del DOM ─── */
  const $       = id => document.getElementById(id);
  const overlay = $('overlay');
  const bigImg  = $('big-img');
  const navName = $('nombre-usuario');
  const navPic  = $('foto-perfil-nav');
  const fotoDom = $('foto_perfil');

  /* ─── helpers de formato ─── */
  const fmtRut = v => {
    const n = v.replace(/\./g, '').replace(/-/g, '').trim();
    if (n.length < 2) return n;
    const cuerpo = n.slice(0, -1), dv = n.slice(-1);
    return cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
  };
  const fmtDate = iso => iso ? iso.split('-').reverse().join('/') : '';

  /* helpers de nombres para ubicación */
  const J       = u => fetch(u).then(r => r.json());
  const nPais   = id       => id ? J('ubicacion.php?tipo=pais').then(l => l.find(o => o.id == id)?.nombre || '') : '';
  const nRegion = (id, p)  => id && p ? J(`ubicacion.php?tipo=region&id=${p}`)
                                       .then(l => l.find(o => o.id == id)?.nombre || '') : '';
  const nCiudad = (id, r)  => id && r ? J(`ubicacion.php?tipo=ciudad&id=${r}`)
                                       .then(l => l.find(o => o.id == id)?.nombre || '') : '';

  /* ─── carga principal ─── */
  fetch(`get_usuario.php?token=${token}`)
    .then(r => r.json())
    .then(async u => {
      if (u.error) { alert(u.error); return; }

      /* nav */
      navName.textContent = u.nombres || 'Usuario';
      navPic.src          = u.foto_perfil || 'uploads/fotos/default.png';

      /* foto + pop-up */
      const foto = u.foto_perfil || 'uploads/fotos/default.png';
      fotoDom.src      = foto;
      fotoDom.onclick  = () => { bigImg.src = foto; overlay.style.display = 'flex'; };

      /* texto plano */
      $('nombre_completo').textContent =
        [u.nombres, u.apellido_paterno, u.apellido_materno].filter(Boolean).join(' ');
      $('rut_dni').textContent =
        u.id_pais == 1 ? fmtRut(u.rut_dni)               // 🇨🇱 RUT con puntos-y-guion
                       : u.rut_dni.replace(/\D/g, '');   // 🌐 solo dígitos sin formato
      $('fecha_nacimiento').textContent = fmtDate(u.fecha_nacimiento);
      $('fecha_ingreso').textContent    = fmtDate(u.fecha_registro);

      $('pais').textContent   = await nPais(u.id_pais);
      $('region').textContent = await nRegion(u.id_region_estado, u.id_pais);
      $('ciudad').textContent = await nCiudad(u.id_ciudad_comuna, u.id_region_estado);

      $('direccion').textContent = u.direccion || '';
      $('iglesia').textContent   = u.iglesia_ministerio || '';
      $('profesion').textContent = u.profesion_oficio_estudio || '';
      $('correo').textContent    = u.correo || '';
      $('boletin').textContent   = u.boletin ? 'Sí' : 'No';

      /* ─── ocupaciones múltiples ─── */
      $('ocupacion').textContent = u.ocupaciones.map(o => o.nombre).join(', ');

      /* ─── teléfonos ─── */
      const tbT = $('tabla-telefonos');
      (u.telefonos || []).forEach(t => {
        tbT.innerHTML += `<tr>
          <td>${t.numero}</td>
          <td>${t.descripcion || ''}${t.es_principal ? ' – Principal' : ''}</td>
        </tr>`;
      });

      /* ─── roles / equipos ─── */
      const tbR = $('tabla-roles');
      (u.roles_equipos || []).forEach(r => {
        tbR.innerHTML += `<tr><td>${r.rol}</td><td>${r.equipo}</td></tr>`;
      });
    });

  /* ─── cerrar sesión ─── */
  window.cerrarSesion = () => {
    fetch(`cerrar_sesion.php?token=${token}`).finally(() => {
      localStorage.clear();
      location.replace('login.html');
    });
  };

  /* cerrar pop-up */
  window.addEventListener('click', e => {
    if (e.target === overlay) overlay.style.display = 'none';
  });
});
