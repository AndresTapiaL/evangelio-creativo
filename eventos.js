document.addEventListener('DOMContentLoaded', () => {
  // ——— Modal de Detalles ———
  const modalDetail = document.getElementById('modal-detalles');
  const closeDetail = modalDetail.querySelector('.modal-close');
  // Devuelve array sin blancos y sin falsy: "3, 4"  -> ["3","4"]
  const cleanList = str => (str || '')
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);

  document.querySelectorAll('.detail-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      modalDetail.querySelector('#md-nombre').textContent      = btn.dataset.nombre;
      modalDetail.querySelector('#md-nombre2').textContent     = btn.dataset.nombre;
      modalDetail.querySelector('#md-fi').textContent          = btn.dataset.fi;
      modalDetail.querySelector('#md-ft').textContent          = btn.dataset.ft;
      modalDetail.querySelector('#md-lugar').textContent       = btn.dataset.lugar;
      modalDetail.querySelector('#md-encargado').textContent   = btn.dataset.encargado;
      modalDetail.querySelector('#md-descripcion').textContent = btn.dataset.descripcion;
      modalDetail.querySelector('#md-equipos').textContent     = btn.dataset.equipos;
      modalDetail.querySelector('#md-previo').textContent      = btn.dataset.previo;
      modalDetail.querySelector('#md-tipo').textContent        = btn.dataset.tipo;
      modalDetail.querySelector('#md-asist').textContent       = btn.dataset.asist;
      modalDetail.querySelector('#md-observacion').textContent = btn.dataset.observacion;
      modalDetail.querySelector('#md-final').textContent       = btn.dataset.final;
      modalDetail.style.display = 'flex';
    });
  });

  closeDetail.addEventListener('click', () => modalDetail.style.display = 'none');
  modalDetail.addEventListener('click', e => {
    if (e.target === modalDetail) modalDetail.style.display = 'none';
  });

  // ——— Modal de Edición ———
  const modalEdit = document.getElementById('modal-edit');
  const closeEdit = modalEdit.querySelector('.modal-close');
  const txtRules = [
    {inp:'edit-nombre',       req:'err-required-nombre',  rgx:'err-regex-nombre'},
    {inp:'edit-lugar',        req:null,                   rgx:'err-regex-lugar'},
    {inp:'edit-descripcion',  req:null,                   rgx:'err-regex-descripcion'},
    {inp:'edit-observacion',  req:null,                   rgx:'err-regex-observacion'}
  ];
  const dtRules = [
    {inp:'edit-start', req:'err-required-start'},
    {inp:'edit-end',   req:'err-required-end'}
  ];
  const allowedRE = /^[A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .,\-()]+$/;
  const modalBody = modalEdit.querySelector('.card-body');   // ← tu clase real

  // —— dispara validación al instante ——
  [...txtRules, ...dtRules].forEach(({inp})=>{
    const el = document.getElementById(inp);
    if(!el) return;
    const ev = el.type === 'datetime-local' ? 'change' : 'input';
    el.addEventListener(ev , ()=>validateField(inp));
    el.addEventListener('blur', ()=>validateField(inp));
  });

  // Función que filtra el <select> de encargado según proyectos seleccionados
  function filterEncargados(selectedProjects, selectedEnc) {
    // Normalizamos a strings y construimos un Set para búsquedas O(1)
    const selSet = new Set(selectedProjects.map(String));
    let encStillValid = false;

    Array.from(encSelect.options).forEach(opt => {
      if (!opt.value) return;                       // placeholder

      const optProjects = cleanList(opt.dataset.projects);

      const visible =
          selSet.size === 0                        // “General” marcado
        || optProjects.length === 0                 // líder global
        || optProjects.some(p => selSet.has(p))     // comparte proyecto

      opt.hidden = !visible;
      if (opt.value === selectedEnc && visible) encStillValid = true;
    });

    encSelect.value = encStillValid ? selectedEnc : '';
  }

    // ─── validación de Equipos/Proyectos ───
  const generalChk  = document.getElementById('edit-general');
  const projectChks = Array.from(document.querySelectorAll('.edit-project-chk'));
  const projectsErr = document.getElementById('projects-error');
  const encSelect   = document.getElementById('edit-encargado');

  function validateProjects() {
    const any = generalChk.checked || projectChks.some(c=>c.checked);
    projectsErr.style.display = any ? 'none' : 'block';
    return any;
  }
  // ────────────────────────────────────────

  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      // limpiar mensajes de error cada vez que se abre el modal
      document.querySelectorAll('.err-inline').forEach(e=>e.style.display='none');
      projectsErr.style.display = 'none';
      firstInvalid = null;
      
      // 1) Rellenar campos básicos
      document.getElementById('edit-id').value          = btn.dataset.id;
      document.getElementById('edit-nombre').value      = btn.dataset.nombre;
      document.getElementById('edit-lugar').value       = btn.dataset.lugar;
      document.getElementById('edit-descripcion').value = btn.dataset.descripcion;
      document.getElementById('edit-observacion').value = btn.dataset.observacion;
      document.getElementById('edit-previo').value      = btn.dataset.previo;
      document.getElementById('edit-tipo').value        = btn.dataset.tipo;
      document.getElementById('edit-final').value       = btn.dataset.final;

      // 2) Referencias a inputs y mensaje de error
      const startInp = document.getElementById('edit-start');
      const endInp   = document.getElementById('edit-end');
      const errEnd   = document.getElementById('end-error');

      // 3) Cargar valores iniciales de fecha
      startInp.value = btn.dataset.start.replace(' ', 'T');
      endInp.value   = btn.dataset.end.replace(' ', 'T');
      endInp.min     = startInp.value;

      // 4) Validación inline de fechas
      function validateDate() {
        if (endInp.value && endInp.value < startInp.value) {
          errEnd.style.display = 'block';
        } else {
          errEnd.style.display = 'none';
        }
      }
      startInp.onchange = () => {
        endInp.min = startInp.value;
        if (endInp.value < endInp.min) endInp.value = endInp.min;
        validateDate();
      };
      endInp.oninput = validateDate;

      // 5) Marcar checkboxes de proyectos
      const eqRaw = btn.dataset.equipos || '';
      const eqArr = cleanList(eqRaw);
      document.querySelectorAll('.checkbox-item input').forEach(chk => {
        chk.checked = eqArr.includes(chk.value);
      });

      // ─── sincronizar “General” vs Proyectos ───

      // 1) Estado inicial: sin proyectos → general marcado
      generalChk.checked = projectChks.every(c=>!c.checked);

      // 2) “General” limpia proyectos
      generalChk.addEventListener('change', () => {
        if (generalChk.checked) {
          projectChks.forEach(c=>c.checked = false);
          filterEncargados([], document.getElementById('edit-encargado').value);
          validateProjects();
        }
      });

      // 3) Cada proyecto actualiza “General”
      projectChks.forEach(chk => chk.addEventListener('change', () => {
        const sel = projectChks.filter(c=>c.checked).map(c=>c.value);
        generalChk.checked = sel.length === 0;
        filterEncargados(sel, document.getElementById('edit-encargado').value);
        validateProjects();
      }));

      // 6) Filtrar encargado inicialmente
      // 6) Pre-seleccionar y filtrar encargado
      encSelect.value = btn.dataset.encargado || '';   // fija la opción actual

      const syncEncargados = () => {
        const selProjects = generalChk.checked
          ? []                                          // “General” = todos
          : projectChks.filter(c => c.checked).map(c => c.value);

        filterEncargados(selProjects, encSelect.value); // muestra/oculta opciones
      };

      // listeners para refrescar la lista si cambian los checks
      generalChk.onchange = () => {
        projectChks.forEach(c => c.checked = false);    // desmarca proyectos
        syncEncargados();
      };

      projectChks.forEach(c => {
        c.onchange = () => {
          generalChk.checked = projectChks.every(x => !x.checked);
          syncEncargados();
        };
      });

      syncEncargados();                                 // llamada inicial

      validateDate();

      // 8) Mostrar modal
      modalEdit.style.display = 'flex';
    });
  });

  // ——— Modal de Duplicar ———
  const modalCopy  = document.getElementById('modal-copy');
  const closeCopy  = modalCopy.querySelector('.modal-close');
  const saveCopyBt = document.getElementById('btn-create-evento');

  /* ——— Reglas de validación SOLO para modal-copy ——— */
  const copyTxtRules = [
    {inp:'copy-nombre',      req:'copy-err-required-nombre',  rgx:'copy-err-regex-nombre'},
    {inp:'copy-lugar',       req:null,                        rgx:'copy-err-regex-lugar'},
    {inp:'copy-descripcion', req:null,                        rgx:'copy-err-regex-descripcion'},
    {inp:'copy-observacion', req:null,                        rgx:'copy-err-regex-observacion'}
  ];
  const copyDtRules = [
    {inp:'copy-start', req:'copy-err-required-start'},
    {inp:'copy-end',   req:'copy-err-required-end'}
  ];
  const copyAllowedRE = /^[A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .,\-()]+$/;
  const copyModalBody = modalCopy.querySelector('.card-body');      // container scroll
  const copyGeneralChk  = document.getElementById('copy-general');
  const copyProjectChks = Array.from(document.querySelectorAll('.copy-project-chk'));
  const copyProjectsErr = document.getElementById('copy-projects-error');
  const copyEncSelect   = document.getElementById('copy-encargado');

  /* dispara validación instantánea en inputs del modal-copy */
  [...copyTxtRules, ...copyDtRules].forEach(({inp})=>{
    const el = document.getElementById(inp);
    if(!el) return;
    const ev = el.type === 'datetime-local' ? 'change' : 'input';
    el.addEventListener(ev , ()=>copyValidateField(inp));
    el.addEventListener('blur', ()=>copyValidateField(inp));
  });

  /* —— helpers de validación SOLO modal-copy ——————————— */
  let copyFirstInvalid = null;

  function copyShow(id){
    const el = document.getElementById(id);
    el.style.display = 'inline';
    if(!copyFirstInvalid) copyFirstInvalid = el.closest('.form-group');
  }
  function copyHide(id){
    document.getElementById(id).style.display = 'none';
  }

  function copyScrollToTarget(el){
    const y = el.getBoundingClientRect().top
            - copyModalBody.getBoundingClientRect().top
            + copyModalBody.scrollTop - 40;
    copyModalBody.scrollTo({top:y, behavior:'smooth'});
    el.focus();
  }

  function copyValidateField(id){
    // text inputs -----------------------------------------------------------
    const txt = copyTxtRules.find(r=>r.inp===id);
    if(txt){
      const v = document.getElementById(id).value.trim();
      if(txt.req) (v ? copyHide(txt.req) : copyShow(txt.req));
      if(v && !copyAllowedRE.test(v)) { copyShow(txt.rgx); } else { copyHide(txt.rgx); }
    }
    // datetime inputs -------------------------------------------------------
    const dt = copyDtRules.find(r=>r.inp===id);
    if(dt){
      const v = document.getElementById(id).value;
      v ? copyHide(dt.req) : copyShow(dt.req);
    }
  }

  function copyValidateAll(){
    copyFirstInvalid = null;
    copyTxtRules.forEach(r=>copyValidateField(r.inp));
    copyDtRules .forEach(r=>copyValidateField(r.inp));
    return !copyFirstInvalid;
  }

  function copyValidateProjects(){
    const any = copyGeneralChk.checked || copyProjectChks.some(c=>c.checked);
    copyProjectsErr.style.display = any ? 'none' : 'block';
    return any;
  }

  // ———————————————————————————————
  // Filtra encargados SOLO para modal-copy
  // ———————————————————————————————
  function filterEncargadosCopy(selectedProjects){
    const selSet     = new Set(selectedProjects.map(String));
    const selectEl   = copyEncSelect;             // id="copy-encargado"
    let keepCurrent  = false;

    Array.from(selectEl.options).forEach(opt=>{
      if(!opt.value) return;                      // — sin encargado —

      const optProjects = cleanList(opt.dataset.projects); // ["3","7"]

      const visible =
          selSet.size === 0                     // “General” => ver todos
        || optProjects.length === 0              // líder global
        || optProjects.some(p => selSet.has(p)); // comparte algún proyecto

      opt.hidden = !visible;
      if(opt.value === selectEl.value && visible) keepCurrent = true;
    });

    if(!keepCurrent) selectEl.value = '';        // limpia si quedó oculto
  }

  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      // 0) Limpiar errores
      modalCopy.querySelectorAll('.err-inline').forEach(e=>e.style.display='none');
      document.getElementById('copy-projects-error').style.display='none';

      // 1) Rellenar campos
      document.getElementById('copy-nombre').value        = btn.dataset.nombre;
      document.getElementById('copy-lugar').value         = btn.dataset.lugar;
      document.getElementById('copy-descripcion').value   = btn.dataset.descripcion;
      document.getElementById('copy-observacion').value   = btn.dataset.observacion;
      document.getElementById('copy-previo').value        = btn.dataset.previo;
      document.getElementById('copy-tipo').value          = btn.dataset.tipo;
      document.getElementById('copy-final').value         = btn.dataset.final;

      // 2) Fechas
      const st = document.getElementById('copy-start');
      const en = document.getElementById('copy-end');
      st.value = btn.dataset.start.replace(' ', 'T');
      en.value = btn.dataset.end.replace(' ', 'T');
      en.min   = st.value;

      // 3) Proyectos / General
      const eqArr = (btn.dataset.equipos || '').split(',').map(s=>s.trim()).filter(Boolean);
      const chkProjects = modalCopy.querySelectorAll('.copy-project-chk');
      chkProjects.forEach(c=>c.checked = eqArr.includes(c.value));
      const chkGeneral  = document.getElementById('copy-general');
      chkGeneral.checked = chkProjects.length === 0 || [...chkProjects].every(c => !c.checked);

      // 4) Encargado
      const selEnc = document.getElementById('copy-encargado');
      selEnc.value = btn.dataset.encargado || '';
      filterEncargadosCopy(chkGeneral.checked ? [] : eqArr, selEnc.value);

      /* sincronizar checks y encargado */
      copyGeneralChk.onchange = () => {
        if (copyGeneralChk.checked){
          copyProjectChks.forEach(c=>c.checked = false);
          filterEncargadosCopy([], copyEncSelect.value);
          copyValidateProjects();
        }
      };
      copyProjectChks.forEach(chk => chk.onchange = () => {
        const sel = copyProjectChks.filter(c=>c.checked).map(c=>c.value);
        copyGeneralChk.checked = sel.length === 0;
        filterEncargadosCopy(sel, copyEncSelect.value);
        copyValidateProjects();
      });

      // 5) Mostrar
      modalCopy.style.display = 'flex';
    });
  });

  closeCopy.addEventListener('click', ()=> modalCopy.style.display='none');
  modalCopy.addEventListener('click', e=>{
    if(e.target === modalCopy) modalCopy.style.display='none';
  });

  // ——— Botón Eliminar ———
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;

      if (!confirm('¿Seguro que deseas eliminar este evento y TODOS sus datos asociados?')) return;

      fetch('eliminar_evento.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
        body: 'id_evento=' + encodeURIComponent(id)
      })
      .then(r => r.json())
      .then(j => {
        if (j.mensaje === 'OK') location.reload();
        else alert('Error: ' + (j.error || 'No se pudo eliminar.'));
      })
      .catch(() => alert('Error de conexión al intentar eliminar.'));
    });
  });

  /* —— helpers de validación ————————————————————— */
  let firstInvalid = null;                                 // ← para el scroll

  function show(id){
    const el = document.getElementById(id);
    el.style.display = 'inline';
    if(!firstInvalid) firstInvalid = el.closest('.form-group');
  }
  function hide(id){
    document.getElementById(id).style.display = 'none';
  }

  function scrollToTarget(el){
    const y = el.getBoundingClientRect().top
            - modalBody.getBoundingClientRect().top
            + modalBody.scrollTop
            - 40;                                    // margen superior
    modalBody.scrollTo({top: y, behavior:'smooth'});
    el.focus();                                     // opcional
  }

  function validateField(id){
    // text inputs ------------------------------------------------------------
    const txt = txtRules.find(r=>r.inp===id);
    if(txt){
      const v = document.getElementById(id).value.trim();
      if(txt.req) (v ? hide(txt.req) : show(txt.req));
      if(v && !allowedRE.test(v)) { show(txt.rgx); } else { hide(txt.rgx); }
    }
    // datetime inputs --------------------------------------------------------
    const dt = dtRules.find(r=>r.inp===id);
    if(dt){
      const v = document.getElementById(id).value;
      v ? hide(dt.req) : show(dt.req);
    }
  }

  function validateAll(){
    firstInvalid = null;                   // reinicia scroll target
    txtRules.forEach(r=>validateField(r.inp));
    dtRules .forEach(r=>validateField(r.inp));
    return !firstInvalid;                  // true si no hay errores
  }

  closeEdit.addEventListener('click', () => modalEdit.style.display = 'none');
  modalEdit.addEventListener('click', e => {
    if (e.target === modalEdit) modalEdit.style.display = 'none';
  });

  // ——— Guardar Cambios Edición ———
  const saveBtn = document.getElementById('btn-save-evento');
  saveBtn.addEventListener('click', async () => {
    const errEnd = document.getElementById('end-error');
    errEnd.style.display = 'none';

    if (saveBtn.disabled) return;
    saveBtn.disabled = true;
    const orig = saveBtn.textContent;
    saveBtn.textContent = 'Guardando…';

    if (!validateAll()) {                       // hay errores en campos normales
      scrollToTarget(firstInvalid);             // ← desplazamiento suave
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
      return;
    }

    // Validación de proyectos
    if (!validateProjects()) {                   // ⇒ falta “General” o equipo
      projectsErr.style.display = 'block';       // muestra el mensaje

      firstInvalid = projectsErr;          // ahora el propio <small> es el destino
      scrollToTarget(projectsErr);

      saveBtn.disabled = false;
      saveBtn.textContent = orig;
      return;
    }
    projectsErr.style.display = 'none';          // limpia si ya está bien

    // Validación final de fechas
    const startInp = document.getElementById('edit-start');
    const endInp   = document.getElementById('edit-end');

    if (endInp.value && endInp.value < startInp.value) {
      errEnd.style.display = 'block';            // ‹ small id="err-end" …›
      firstInvalid = projectsErr;                //  o  errEnd   según el caso
      scrollToTarget(errEnd);                    // ⬅ desplazamos al MENSAJE
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
      return;
    }
    errEnd.style.display = 'none';               // limpia si ya está bien

    // Envío AJAX
    const form = document.getElementById('form-edit-evento');
    const fd   = new FormData(form);
    try {
      const res  = await fetch('actualizar_evento.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (!json.mensaje) throw new Error(json.error || 'Error al guardar');
      location.reload();
    } catch (err) {
      alert('Error: ' + err.message);
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
    }
  });

  saveCopyBt.addEventListener('click', async () => {
    const errEnd = document.getElementById('copy-end-error');
    errEnd.style.display = 'none';

    if (saveCopyBt.disabled) return;
    saveCopyBt.disabled = true;
    const orig = saveCopyBt.textContent;
    saveCopyBt.textContent = 'Guardando…';

    /* 1) Validación campos + proyectos */
    if (!copyValidateAll()) {
      copyScrollToTarget(copyFirstInvalid);
      restore();
      return;
    }
    if (!copyValidateProjects()) {
      copyFirstInvalid = copyProjectsErr;
      copyScrollToTarget(copyProjectsErr);
      restore();
      return;
    }

    /* 2) Validación final de fechas */
    const st = document.getElementById('copy-start');
    const en = document.getElementById('copy-end');
    if (en.value && en.value < st.value) {
      errEnd.style.display = 'block';
      copyScrollToTarget(errEnd);
      restore();
      return;
    }

    /* 3) Envío AJAX */
    const fd = new FormData(document.getElementById('form-copy-evento'));
    try {
      const r = await fetch('crear_evento.php', { method:'POST', body: fd });
      const j = await r.json();
      if (!j.mensaje) throw new Error(j.error || 'Error al guardar');
      location.reload();
    } catch (err) {
      alert('Error: ' + err.message);
      restore();
    }

    /* helper interno */
    function restore(){
      saveCopyBt.disabled = false;
      saveCopyBt.textContent = orig;
    }
  });
});
