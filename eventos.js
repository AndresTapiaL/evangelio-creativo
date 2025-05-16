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

    if(!validateAll()){                       // hay errores
      firstInvalid.scrollIntoView({behavior:'smooth',block:'center'});
      saveBtn.disabled = false;               // re-habilita botón
      saveBtn.textContent = orig;
      return;                                 // ⁂ aborta envío ⁂
    }

    // Validación de proyectos
    if (!validateProjects()) {
      projectsErr.scrollIntoView({behavior:'smooth', block:'center'});
      projectsErr.focus();
      saveBtn.disabled = false;      // <-- restauro
      saveBtn.textContent = orig;     // <-- restauro
      return;
    }

    // Validación final de fechas
    const startInp = document.getElementById('edit-start');
    const endInp   = document.getElementById('edit-end');
    if (endInp.value && endInp.value < startInp.value) {
      errEnd.style.display = 'block';
      endInp.focus();
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
      return;
    }

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
});
