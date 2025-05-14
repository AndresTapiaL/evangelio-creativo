/* =====================================================================
   EDITAR MIS DATOS – v 6.4  (completo, con renderizado PHP, validar_token, heartbeat y logout)
   ---------------------------------------------------------------------
   • Nav inyectada en PHP (sin parpadeos)
   • Valida/renueva token al cargar (abortable)
   • Heartbeat.js extiende el token cada 10 min
   • Sincroniza País ↔ Documento, cascada País→Región→Ciudad
   • Carga y valida ocupaciones, teléfonos, foto, RUT, fechas, dirección…
   • Submit ↦ actualizar_usuario.php + actualizar_telefonos.php
   • Pop-up de foto y cerrar sesión vía cerrar_sesion.php
===================================================================== */

document.addEventListener('DOMContentLoaded', () => {
  const token = localStorage.getItem('token'); 
  if (!token) return location.replace('login.html');

  /* ─── VALIDAR TOKEN ÚNICO AL CARGAR ─── */
  const ctrl = new AbortController();
  window.addEventListener('beforeunload', () => ctrl.abort());
  (async () => {
    try {
      const res = await fetch('validar_token.php', {
        headers: { 'Authorization': 'Bearer ' + token },
        signal: ctrl.signal
      });
      if (res.status === 401) throw new Error('TokenNoValido');
      const { ok } = await res.json();
      if (!ok) throw new Error('TokenNoValido');
    } catch (e) {
      if (e.message === 'TokenNoValido') {
        localStorage.clear();
        return location.replace('login.html');
      }
      // AbortError o NetworkFail → ignorar
    }
  })();
  /* ───────────────────────────────────── */

  /* ─── SELECTORES ─── */
  const $           = id => document.getElementById(id);
  const navName     = $('nombre-usuario'), navPic = $('foto-perfil-nav');
  const fotoDom        = $('foto_perfil'),
  fileInp        = $('nueva_foto'),
  deleteBtn      = $('eliminar_foto'),
  deleteFlagInput= $('delete_foto');
  const tipoDocSel  = $('tipo_doc'),    rutInp  = $('rut_dni');
  const paisSel     = $('pais'),        regSel  = $('region'), ciuSel = $('ciudad');
  const fechaInp    = $('fecha_nacimiento'), dirInp = $('direccion'),
        iglInp     = $('iglesia'),      profInp = $('profesion'),
        mailInp    = $('correo'),       bolChk  = $('boletin');
  const ocuWrap     = $('ocupaciones-wrapper');
  const telInp      = [...document.querySelectorAll("input[id^='telefono_']")];
  const telSel      = [...document.querySelectorAll("select[id^='tipo_telefono_']")];
  const delBtn      = [...document.querySelectorAll('.delete-telefono')];
  const overlay     = $('overlay'),     bigImg  = $('big-img');
  const logoutBtn   = $('logout');
  const MAX_FILE_SIZE = 5 * 1024 * 1024;  // en bytes
  // Evita envíos múltiples
  let isSubmitting = false;
  // Botón de envío (.btn-primario)
  const submitBtn = document.querySelector('.btn-primario');



  /* ─── HELPERS ─── */
  const jFetch = u => fetch(u).then(r => r.json());
  const err    = (id, msg='') => {
    const e = $(id);
    if (!e) return;
    e.textContent = msg;
    e.style.display = msg ? 'block' : 'none';
  };

  /* ─── FORMATO RUT ─── */
  const cleanRut = v => v.replace(/\./g,'').replace(/-/g,'').trim();
  const fmtRut   = v => {
    const n = cleanRut(v);
    if (n.length < 2) return n;
    const body = n.slice(0,-1).replace(/\B(?=(\d{3})+(?!\d))/g,'.'),
          dv   = n.slice(-1).toUpperCase();
    return body + '-' + dv;
  };
  const dv       = c => {
    let s = 1, m = 0;
    for (let i=c.length-1; i>=0; i--) {
      s = (s + c[i]*(9 - (m++%6))) % 11;
    }
    return s ? s-1+'' : 'K';
  };
  const validRut = v => {
    const n = cleanRut(v);
    return /^\d{7,8}[0-9Kk]$/.test(n) && dv(n.slice(0,-1)) === n.slice(-1).toUpperCase();
  };

  /* ─── VALIDACIONES BÁSICAS ─── */
  const onlyNum = v => /^\d+$/.test(v);
  const safeTxt = v => /^[\w\sÁÉÍÓÚÜÑáéíóúüñ.\-]+$/.test(v);
  const safeDir = v => /^[\w\s.,\-#]+$/.test(v);
  const mailOK  = v => /^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/.test(v);
  const imgOK   = f => ['image/jpeg','image/png','image/gif','image/webp','image/jpg']
                      .includes(f.type);

  /* ─── INTL-TEL-INPUT ─── */
  const iti = telInp.map(el =>
    window.intlTelInput(el, {
      initialCountry:'cl',
      nationalMode:false,
      formatOnDisplay:true
    })
  );
  telInp.forEach((inp, i) => {
    inp.addEventListener('input', () => {
      let v = inp.value.replace(/[^0-9\+]/g,''), plus = v.startsWith('+');
      inp.value = plus ? '+' + v.replace(/\+/g,'') : v.replace(/\+/g,'');
    });
  });
  delBtn.forEach(btn => {
    btn.onclick = () => {
      const i = btn.dataset.indice - 1;
      telInp[i].value = '';
      iti[i].setNumber('');
      telSel[i].selectedIndex = 0;
      err('error_tel'+(i+1));
    };
  });

  /* ─── UBICACIÓN PAISES/REGIONES/CIUDADES ─── */
  const fillSel = (sel, list, val='') => {
    sel.innerHTML = '<option value="">Seleccione…</option>';
    list.forEach(o => {
      sel.innerHTML += `<option value="${o.id}" ${String(o.id)===String(val)?'selected':''}>${o.nombre}</option>`;
    });
  };
  const loadPaises = async(id='') =>
    fillSel(paisSel, await jFetch('ubicacion.php?tipo=pais'), id);
  const loadRegs   = async(pid, rid='') => {
    if (!pid) { regSel.innerHTML = ciuSel.innerHTML = ''; return; }
    fillSel(regSel, await jFetch(`ubicacion.php?tipo=region&id=${pid}`), rid);
    ciuSel.innerHTML = '';
  };
  const loadCiuds  = async(rid, cid='') => {
    if (!rid) { ciuSel.innerHTML = ''; return; }
    fillSel(ciuSel, await jFetch(`ubicacion.php?tipo=ciudad&id=${rid}`), cid);
  };

  /* ─── SINCRONIZAR DOC ↔ PAÍS ─── */
  const CHILE_ID = '1';
  const syncDocPais = src => {
    if (src === 'pais') {
      tipoDocSel.value = paisSel.value === CHILE_ID ? 'rut' : 'int';
      rutInp.value = '';
    } else {
      if (tipoDocSel.value === 'rut') {
        paisSel.value = CHILE_ID;
        loadRegs(CHILE_ID);
      } else if (paisSel.value === CHILE_ID) {
        paisSel.value = '';
        loadRegs('');
      }
      rutInp.value = '';
    }
  };
  paisSel.addEventListener('change', ()=> { syncDocPais('pais'); loadRegs(paisSel.value); });
  regSel .addEventListener('change', ()=> loadCiuds(regSel.value));
  tipoDocSel.addEventListener('change', ()=> syncDocPais('doc'));

  /* ─── CARGAR OCUPACIONES ─── */
  const loadOcus = async(sel=[]) => {
    ocuWrap.innerHTML = '';
    (await jFetch('get_ocupaciones.php')).forEach(o => {
      ocuWrap.insertAdjacentHTML('beforeend',
        `<label><input type="checkbox" value="${o.id_ocupacion}" ${sel.includes(String(o.id_ocupacion))?'checked':''}> ${o.nombre}</label>`
      );
    });
  };

  deleteBtn.addEventListener('click', () => {
    deleteFlagInput.value = '1';
    // 1) Generar URL con cache-bust
    const defSrc = 'uploads/fotos/default.png?v=' + Date.now();
    // 2) Actualizar formulario y nav
    fotoDom.src = defSrc;
    navPic.src  = defSrc;
    // 3) Limpiar input file y errores
    fileInp.value = '';
    err('error_foto', '');
  });

  /* ─── BLUR VALIDATIONS ─── */
  rutInp.onblur   = () => {
    if (tipoDocSel.value === 'rut') {
      rutInp.value = fmtRut(rutInp.value);
      err('error_rut', validRut(rutInp.value) ? '' : 'RUT inválido');
    } else {
      err('error_rut', onlyNum(rutInp.value) ? '' : 'Solo números');
    }
  };
  fileInp.addEventListener('change', () => {
    const f = fileInp.files[0];
    // 1) Verificar tamaño
    if (f && f.size > MAX_FILE_SIZE) {
      err('error_foto', 'El archivo excede el límite de 5 MB.');
      fileInp.value = '';
      deleteFlagInput.value = '0';  // opcional: resetear flag
      return;
    }
    // 2) Tu validación de tipo existente
    if (!f || !imgOK(f)) {
      fileInp.value = '';
      err('error_foto', 'Archivo no permitido');
    } else {
      deleteFlagInput.value = '0';
      err('error_foto', '');
    }
  });  
  fechaInp.onblur = () => {
    err('error_fecha',
      fechaInp.value && fechaInp.value <= new Date().toISOString().split('T')[0]
      ? '' : 'Fecha inválida'
    );
  };
  dirInp.onblur   = () => err('error_direccion', safeDir(dirInp.value) ? '' : 'Carácter no permitido');
  iglInp.onblur   = () => err('error_iglesia',  safeTxt(iglInp.value)   ? '' : 'Carácter no permitido');
  profInp.onblur  = () => err('error_profesion',safeTxt(profInp.value)  ? '' : 'Carácter no permitido');
  mailInp.onblur  = () => err('error_correo',   mailOK(mailInp.value)   ? '' : 'Correo inválido');
  telInp.forEach((inp,i) =>
    inp.addEventListener('blur', () => {
      const n = iti[i].getNumber().replace(/[^0-9\+]/g,'');
      err('error_tel'+(i+1), (n===''||/^\+?\d+$/.test(n)) ? '' : 'Solo números y +');
    })
  );

  /* ─── SUBMIT ─── */
  $('form-editar').onsubmit = async e => {
    e.preventDefault();
  
    // 1) Evita reenvíos
    if (isSubmitting) return;
    isSubmitting = true;
  
    // 2) Deshabilita botón y da feedback
    submitBtn.disabled    = true;
    submitBtn.textContent = 'Enviando…';
  
    // 3) Validaciones
    [...document.querySelectorAll('input,select')].forEach(el =>
      el.dispatchEvent(new Event('blur'))
    );
    if ([...document.querySelectorAll('.error-msg')]
          .some(el => el.style.display === 'block')) {
      isSubmitting = false;
      submitBtn.disabled    = false;
      submitBtn.textContent = 'Guardar cambios';
      return alert('Corrige los errores.');
    }
  
    try {
      // ─── FOTO ───
      const deleteFlag = deleteFlagInput.value === '1';
      if (deleteFlag && !fileInp.files.length) {
        const fdD = new FormData();
        fdD.append('token', token);
        fdD.append('delete_foto', '1');
        const dres = await fetch('subir_foto.php', { method: 'POST', body: fdD })
                           .then(r => r.json());
        if (!dres.mensaje) throw new Error(dres.error || 'Error al eliminar foto');
      } else if (fileInp.files.length) {
        const fdF = new FormData();
        fdF.append('token', token);
        fdF.append('foto', fileInp.files[0]);
        const fres = await fetch('subir_foto.php', { method: 'POST', body: fdF })
                           .then(r => r.json());
        if (!fres.mensaje) throw new Error(fres.error || 'Error al subir foto');
      }
  
      // ─── DATOS DE USUARIO ───
      const fd = new FormData();
      fd.append('token', token);
      fd.append('tipo_doc', tipoDocSel.value);
      fd.append('rut_dni', cleanRut(rutInp.value));
      fd.append('fecha_nacimiento', fechaInp.value);
      fd.append('id_pais', paisSel.value);
      fd.append('id_region_estado', regSel.value);
      fd.append('id_ciudad_comuna', ciuSel.value);
      fd.append('direccion', dirInp.value);
      fd.append('iglesia_ministerio', iglInp.value);
      fd.append('profesion_oficio_estudio', profInp.value);
      fd.append('correo', mailInp.value);
      if (bolChk.checked) fd.append('boletin', 1);
      ocuWrap.querySelectorAll('input[type=checkbox]:checked')
           .forEach(c => fd.append('id_ocupacion[]', c.value));
  
      const ju = await fetch('actualizar_usuario.php', { method: 'POST', body: fd })
                         .then(r => r.json());
      if (!ju.mensaje) throw new Error(ju.error || 'Error al guardar datos');
  
      // ─── TELÉFONOS ───
      const fdT = new FormData();
      fdT.append('token', token);
      telInp.forEach((inp, i) => {
        fdT.append(`telefono_${i+1}`, iti[i].getNumber());
        fdT.append(`tipo_telefono_${i+1}`, telSel[i].value);
      });
      const jt = await fetch('actualizar_telefonos.php', { method: 'POST', body: fdT })
                         .then(r => r.json());
      if (!jt.mensaje) throw new Error(jt.error || 'Error al guardar teléfonos');
  
      // ─── ÉXITO ───
      alert('Datos actualizados correctamente');
      location.replace('ver_mis_datos.php');
  
    } catch (err) {
      // En caso de error, rehabilita para reintentar
      isSubmitting = false;
      submitBtn.disabled    = false;
      submitBtn.textContent = 'Guardar cambios';
      alert(err.message || err);
    }
  };
  

  /* ─── POP-UP FOTO ─── */
  fotoDom.addEventListener('click', () => {
    bigImg.src = fotoDom.src;
    overlay.style.display = 'flex';
  });
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.style.display = 'none';
  });

  /* ─── CERRAR SESIÓN ─── */
  window.cerrarSesion = () => {
    fetch('cerrar_sesion.php', {
      headers: { 'Authorization': 'Bearer ' + token }
    }).finally(() => {
      localStorage.clear();
      location.replace('login.html');
    });
  };
  logoutBtn.addEventListener('click', e => {
    e.preventDefault();
    window.cerrarSesion();
  });
});
