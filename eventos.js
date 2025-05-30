document.addEventListener('DOMContentLoaded', () => {
  let firstInvalid = null;
  const rs = id => document.getElementById(id);
  
  // Modal Editar
  protectDateTimeInput(document.getElementById('edit-start'));
  protectDateTimeInput(document.getElementById('edit-end'));
  
  // Modal Duplicar
  protectDateTimeInput(document.getElementById('copy-start'));
  protectDateTimeInput(document.getElementById('copy-end'));
  // Modal Crear
  protectDateTimeInput(document.getElementById('create-start'));
  protectDateTimeInput(document.getElementById('create-end'));

  // Modal Solicitar
  protectDateTimeInput(document.getElementById('req-start'));
  protectDateTimeInput(document.getElementById('req-end'));

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
      // ─── Mostrar siempre el título si el usuario tiene permiso ───
      const obsRow = modalDetail.querySelector('#row-observacion');
      const canSee = btn.dataset.canSeeObservacion === '1';
      obsRow.style.display = canSee ? '' : 'none';
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
    { inp: 'edit-start', req: 'err-required-start', range: 'err-range-start' },
    { inp: 'edit-end',   req: 'err-required-end',   range: 'err-range-end'   }
  ];
  const allowedRE = /^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u;
  const modalBody = modalEdit.querySelector('.card-body');   // ← tu clase real

/**
 * Comprueba que una cadena “YYYY-MM-DDTHH:MM” sea
 * una fecha-hora válida real (p.ej. descarta “2025-02-29T…”).
 */
  function isValidDateTimeLocal(value) {
    // debe tener el formato exacto
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) return false;
    const [date, time] = value.split('T');
    const [Y, M, D]    = date.split('-').map(n=>+n);
    const [h, m]       = time.split(':').map(n=>+n);
    const dt = new Date(Y, M-1, D, h, m);
    return dt.getFullYear()  === Y
        && dt.getMonth()     === M-1
        && dt.getDate()      === D
        && dt.getHours()     === h
        && dt.getMinutes()   === m;
  }

  /** true si start y end están el mismo YYYY-MM-DD */
  const isSameDay = (a, b) => a.slice(0,10) === b.slice(0,10);

  /** true si la diferencia entre a y b ≥ n minutos  */
  const gapOK = (a, b, n = 15) =>
    (new Date(b) - new Date(a)) >= n * 60 * 1000;

  function protectDateTimeInput(el) {
    if (!el) return;
    let lastValid = el.value;               // recuerda el valor correcto

    // cuando el valor sea válido lo actualizamos
    el.addEventListener('input', () => {
      if (el.validity.valid) lastValid = el.value;
    });

    // Al salir del campo, si lo que quedó es inválido, lo restauramos
    el.addEventListener('blur', () => {
      if (!el.validity.valid) el.value = lastValid;
    });
  }

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
  const projectChks = Array.from(modalEdit.querySelectorAll('.edit-project-chk'));
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
      firstInvalid = null;
      modalEdit.querySelectorAll('.err-inline').forEach(el => el.style.display = 'none');
      projectsErr.style.display = 'none';
      
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
      const rawStart = btn.dataset.start.replace(' ', 'T'); // "2025-05-01T01:00:00"
      const rawEnd   = btn.dataset.end  .replace(' ', 'T'); // "2025-05-27T01:15:00"

      // 3) Recortamos a 16 caracteres: "YYYY-MM-DDTHH:MM"
      startInp.value = rawStart.slice(0, 16);
      endInp.value   = rawEnd  .slice(0, 16);
      endInp.min     = startInp.value;

      // 4) Validación inline de fechas
      function validateDate() {
        // tu validación de “término < inicio”
        if (endInp.value && endInp.value < startInp.value) {
          errEnd.style.display = 'block';
        } else {
          errEnd.style.display = 'none';
        }

        // ——— NUEVO: validación de rango 1970–2037 ———
        const rangoErr = document.getElementById('err-range-end');
        if (endInp.value &&
            (endInp.value < endInp.min || endInp.value > endInp.max)
        ) {
          rangoErr.style.display = 'inline';
        } else {
          rangoErr.style.display = 'none';
        }

        const minDiffErr = document.getElementById('edit-mindiff-error');

        if (endInp.value && startInp.value &&
            isSameDay(startInp.value, endInp.value) &&
            !gapOK(startInp.value, endInp.value, 15)
        ){
          minDiffErr.style.display = 'block';
        } else {
          minDiffErr.style.display = 'none';
        }
      }

      // engancha validateDate a todos los eventos relevantes:
      endInp.addEventListener('input',  validateDate);
      endInp.addEventListener('change', validateDate);
      endInp.addEventListener('blur',   validateDate);

      // Y también cuando cambie la fecha de inicio, para que recalcule bien:
      startInp.addEventListener('change', validateDate);

      ['input','change','blur'].forEach(ev => {
        endInp.addEventListener(ev, () => {
          document.getElementById('err-range-end').style.display = 'none';
        });
      });

      startInp.onchange = () => {
        endInp.min = startInp.value;
        if (endInp.value < endInp.min) endInp.value = endInp.min;
        validateDate();
      };
      endInp.oninput = validateDate;

      // 5) Marcar checkboxes de proyectos
      const eqRaw = btn.dataset.equipos || '';
      const eqArr = cleanList(eqRaw);
      modalEdit.querySelectorAll('.checkbox-item input').forEach(chk => {
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
    { inp:'copy-start', req:'copy-err-required-start', range:'copy-err-range-start' },
    { inp:'copy-end',   req:'copy-err-required-end',   range:'copy-err-range-end'   }
  ];
  const copyAllowedRE = allowedRE;
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
    const el = document.getElementById(id);
    // text inputs -----------------------------------------------------------
    const txt = copyTxtRules.find(r=>r.inp===id);
    if(txt){
      const v = document.getElementById(id).value.trim();
      if(txt.req) (v ? copyHide(txt.req) : copyShow(txt.req));
      if(v && !copyAllowedRE.test(v)) { copyShow(txt.rgx); } else { copyHide(txt.rgx); }
    }
    // datetime inputs -------------------------------------------------------
    const dt = copyDtRules.find(r => r.inp === id);
    if (dt) {
      const el   = document.getElementById(id);

      //  extra: fecha término < inicio
      if (id === 'copy-end') {
        const orderErr = document.getElementById('copy-end-error');
        if (el.value && el.value < document.getElementById('copy-start').value) {
          orderErr.style.display = 'block';
          copyFirstInvalid ??= orderErr;
        } else {
          orderErr.style.display = 'none';
        }
      }

      const v    = el.value;

      if (!v) {                    // vacío
        copyShow(dt.req);
        if (dt.range) copyHide(dt.range);
        return;
      }

      const outOfBound = (v < el.min) || (v > el.max);
      if (outOfBound) {            // fuera del rango 1970–2037
        copyHide(dt.req);
        if (dt.range) copyShow(dt.range);
      } else {                     // dentro de rango ⇒ sin error
        copyHide(dt.req);
        if (dt.range) copyHide(dt.range);
      }
    }

    const minErr = document.getElementById('copy-mindiff-error');

    const isEndField = id.endsWith('end');            // ← NUEVO

    if (isEndField &&
        rs('copy-start').value &&
        isSameDay(rs('copy-start').value, el.value) &&
        !gapOK(rs('copy-start').value, el.value, 15)
    ){
      minErr.style.display = 'block';
      copyFirstInvalid ??= minErr;                       // ajusta tu variable de scroll
    } else {
      minErr.style.display = 'none';
    }
  }

  function copyValidateAll(){
    copyFirstInvalid = null;
    modalCopy.querySelectorAll('.err-inline').forEach(el=>el.style.display='none');
    copyProjectsErr.style.display='none';
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
  function filterEncargadosCopy(selectedProjects, selectedEnc) {
    const selSet = new Set(selectedProjects.map(String));
    let keepCurrent = false;

    Array.from(copyEncSelect.options).forEach(opt => {
      if (!opt.value) return;
      const optProjects = cleanList(opt.dataset.projects);
      const visible = selSet.size===0
                  || optProjects.length===0
                  || optProjects.some(p=>selSet.has(p));
      opt.hidden = !visible;
      if (opt.value === selectedEnc && visible) keepCurrent = true;
    });

    copyEncSelect.value = keepCurrent ? selectedEnc : '';
  }

  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      // 0) Limpiar errores
      modalCopy.querySelectorAll('.err-inline').forEach(el => el.style.display = 'none');
      document.getElementById('copy-projects-error').style.display='none';

      // 1) Rellenar campos
      document.getElementById('copy-nombre').value        = btn.dataset.nombre;
      document.getElementById('copy-lugar').value         = btn.dataset.lugar;
      document.getElementById('copy-descripcion').value   = btn.dataset.descripcion;
      document.getElementById('copy-observacion').value   = btn.dataset.observacion;
      document.getElementById('copy-previo').value        = btn.dataset.previo;
      document.getElementById('copy-tipo').value          = btn.dataset.tipo;
      document.getElementById('copy-final').value         = btn.dataset.final;

      // 2) Fechas ────────────────────────────────
      const st = document.getElementById('copy-start');
      const en = document.getElementById('copy-end');

      //   a) dataset → “YYYY-MM-DDTHH:MM”
      st.value = btn.dataset.start.replace(' ', 'T').slice(0,16);
      en.value = btn.dataset.end  .replace(' ', 'T').slice(0,16);

      //   b) mínimo dinámico
      en.min = st.value;

      //   c) listeners simples (igual que Crear/Solicitar)
      st.onchange = () => {
        en.min = st.value;
        if (en.value < en.min) {
          en.value = en.min;                 // ajusta término
        }
        copyValidateField('copy-start');
        copyValidateField('copy-end');
      };

      ['input','change','blur'].forEach(ev=>{
        en.addEventListener(ev, ()=> copyValidateField('copy-end'));
      });

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

  /* ——— Modal CREAR ——— */
  const modalCreate   = document.getElementById('modal-create');
  const btnNewEvent   = document.getElementById('btn-new-event');
  const closeCreate   = modalCreate.querySelector('.modal-close');
  const saveCreateBt  = document.getElementById('btn-store-evento');

  const createTxtRules = [
    {inp:'create-nombre', req:'create-err-required-nombre', rgx:'create-err-regex-nombre'},
    {inp:'create-lugar',  req:null,                         rgx:'create-err-regex-lugar'},
    {inp:'create-descripcion', req:null,                   rgx:'create-err-regex-descripcion'},
    {inp:'create-observacion', req:null,                   rgx:'create-err-regex-observacion'}
  ];
  const createDtRules = [
    { inp: 'create-start', req: 'create-err-required-start', range: 'create-start-error' },
    { inp: 'create-end',   req: 'create-err-required-end',   range: 'create-end-error'   }
  ];
  const createAllowedRE  = allowedRE;
  const createBody       = modalCreate.querySelector('.card-body');
  const createGeneralChk = document.getElementById('create-general');
  const createProjChks   = Array.from(document.querySelectorAll('.create-project-chk'));
  const createProjErr    = document.getElementById('create-projects-error');
  const createEncSel     = document.getElementById('create-encargado');

  /* —— Validación modal-create —— */
  let createFirstInvalid = null;
  function showCreate(id){
    const errEl = document.getElementById(id);
    errEl.style.display = 'inline';
    // si aún no hay primer invalid, guárdame su id
    createFirstInvalid ??= errEl;
  }
  function hideCreate(id){ document.getElementById(id).style.display='none'; }

  function scrollCreate(el){
    const y = el.getBoundingClientRect().top
            - createBody.getBoundingClientRect().top
            + createBody.scrollTop - 40;
    createBody.scrollTo({top:y,behavior:'smooth'});
    el.focus();
  }

  function validateCreateField(id){
    const el = document.getElementById(id);
    const txt = createTxtRules.find(r=>r.inp===id);
    if (txt){
      const v = document.getElementById(id).value.trim();
      if (txt.req) (v ? hideCreate(txt.req) : (showCreate(txt.req), createFirstInvalid ??= id));
      if (v && !createAllowedRE.test(v)) showCreate(txt.rgx); else hideCreate(txt.rgx);
    }
    // datetime inputs — ahora con validación real
    const dt = createDtRules.find(r => r.inp === id);
    if (dt) {
      const el = document.getElementById(id);

      const minErr     = document.getElementById('create-mindiff-error');
      const isEndField = id.endsWith('end');           // ← NUEVO

      if (isEndField &&
          el.value && rs('create-start').value &&
          isSameDay(rs('create-start').value, el.value) &&
          !gapOK(rs('create-start').value, el.value, 15)
      ){
          minErr.style.display = 'block';
          createFirstInvalid ??= minErr;
      } else {
          minErr.style.display = 'none';
      }

      const v  = el.value;
      const tooLow  = el.min && v < el.min;
      const tooHigh = el.max && v > el.max;

      if (!v || !isValidDateTimeLocal(v)) {
        showCreate(dt.req);
        if (dt.range) hideCreate(dt.range);
      }
      else if (tooLow || tooHigh) {
        hideCreate(dt.req);
        showCreate(dt.range);
      }
      else {
        hideCreate(dt.req);
        if (dt.range) hideCreate(dt.range);
      }
    }
  }

  function validateCreateAll(){
    createFirstInvalid = null;
    createTxtRules.forEach(r=>validateCreateField(r.inp));
    createDtRules .forEach(r=>validateCreateField(r.inp));
    return !createFirstInvalid;
  }
  function validateCreateProjects(){
    const ok = createGeneralChk.checked || createProjChks.some(c=>c.checked);
    createProjErr.style.display = ok ? 'none' : 'block';
    return ok;
  }

  /* Filtrar encargados según equipos */
  function filterEncCreate(selProjects){
    const selSet = new Set(selProjects.map(String));
    let keep = false;
    Array.from(createEncSel.options).forEach(opt=>{
      if(!opt.value) return;
      const optProj = cleanList(opt.dataset.projects);
      const vis = selSet.size===0 || optProj.length===0 || optProj.some(p=>selSet.has(p));
      opt.hidden = !vis;
      if(opt.value===createEncSel.value && vis) keep=true;
    });
    if(!keep) createEncSel.value='';
  }

  /* disparar validación inmediata */
  [...createTxtRules, ...createDtRules].forEach(({inp})=>{
    const el = document.getElementById(inp);
    const ev = el.type === 'datetime-local' ? 'change' : 'input';
    el.addEventListener(ev , ()=>validateCreateField(inp));
    el.addEventListener('blur', ()=>validateCreateField(inp));
  });

  const createStart = document.getElementById('create-start');
  const createEnd   = document.getElementById('create-end');

  /* abrir modal vacío */
  if (btnNewEvent) {
    btnNewEvent.addEventListener('click', ()=>{
      document.getElementById('form-create-evento').reset();
      // ISO completo → cortamos a "YYYY-MM-DDTHH:MM"
      const now16 = new Date().toISOString().slice(0, 16);

      // Ponemos fecha/hora actual
      createStart.value = now16;
      createEnd  .value = now16;

      // Después de poner createStart y createEnd…
      createStart.addEventListener('change', () => {
        createEnd.min = createStart.value;
        if (createEnd.value < createEnd.min) {
          createEnd.value = createEnd.min;
        }
      });

      // Y bloqueamos el mínimo
      createEnd.min = now16;

      // <-- Limpiar TODOS los mensajes inline -->
      document.querySelectorAll('#modal-create .err-inline')
              .forEach(el => el.style.display = 'none');
      createProjErr.style.display = 'none';
      createBody.scrollTop = 0;
      modalCreate.style.display = 'flex';
    });
  }

  /* cerrar */
  closeCreate.addEventListener('click', ()=> modalCreate.style.display='none');
  modalCreate.addEventListener('click', e=>{
    if(e.target===modalCreate) modalCreate.style.display='none';
  });

  /* sincronizar checks y encargado */
  createGeneralChk.onchange = ()=>{
    if(createGeneralChk.checked){
      createProjChks.forEach(c=>c.checked=false);
      filterEncCreate([]);
      validateCreateProjects();
    }
  };
  createProjChks.forEach(chk=>chk.onchange=()=>{
    const sel = createProjChks.filter(c=>c.checked).map(c=>c.value);
    createGeneralChk.checked = sel.length===0;
    filterEncCreate(sel);
    validateCreateProjects();
  });

  // ——— Guardar CREAR EVENTO ———
  saveCreateBt.addEventListener('click', async () => {
    const origText = saveCreateBt.textContent;
    // 1) Ocultar errores previos
    document.querySelectorAll('#modal-create .err-inline')
            .forEach(el => el.style.display = 'none');
    const errEnd = document.getElementById('create-end-error');
    errEnd.style.display = 'none';

    // ── Scroll al primer mensaje de error de fecha (req ó range)
    const firstCreateError = createDtRules
      .flatMap(r => [r.req, r.range])            // tanto el campo “req” (servidor) como el “range” (cliente)
      .map(id => document.getElementById(id))
      .find(el => el && el.style.display === 'inline');

    if (firstCreateError) {
      scrollCreate(firstCreateError);
      saveCreateBt.disabled   = false;
      saveCreateBt.textContent = origText;
      return;
    }

    // 2) Validación client-side
    if (!validateCreateAll() || !validateCreateProjects()) {
      scrollCreate(createFirstInvalid || createProjErr);
      return;
    }
    const st = document.getElementById('create-start'),
          en = document.getElementById('create-end');
    if (en.value && en.value < st.value) {
      errEnd.style.display = 'block';
      scrollCreate(errEnd);
      return;
    }

    // 3) Envío al servidor
    saveCreateBt.disabled = true;
    saveCreateBt.textContent = 'Guardando…';

    // 3) Preparar FormData
    const form = document.getElementById('form-create-evento');
    const fd = new FormData(form);

    // —— Paso 2: Normalizar las fechas para MySQL ——  
    ['fecha_hora_inicio', 'fecha_hora_termino'].forEach(field => {
      if (fd.has(field)) {
        // fd.get(field) viene como "YYYY-MM-DDTHH:MM"
        const v = fd.get(field);
        // lo convertimos a "YYYY-MM-DD HH:MM:00"
        fd.set(field, v.replace('T', ' ') + ':00');
      }
    });
    try {
      const res  = await fetch('crear_evento.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.mensaje) {
        // 3.1) Si PHP devuelve un objeto de errores, lo mostramos inline
        if (data.error && typeof data.error === 'object') {
          const fieldToErrId = {
            nombre_evento:      'create-err-required-nombre',
            lugar:              'create-err-regex-lugar',
            descripcion:        'create-err-regex-descripcion',
            observacion:        'create-err-regex-observacion',
            fecha_hora_inicio:  'create-err-required-start',
            fecha_hora_termino: 'create-err-required-end'
          };
          for (const [field, msg] of Object.entries(data.error)) {
            const errEl = document.getElementById(fieldToErrId[field]);
            if (errEl) { errEl.textContent = msg; errEl.style.display = 'inline'; }
          }
        } else {
          // 3.2) Error genérico
          alert('Error: ' + (data.error || 'Error desconocido'));
        }
        saveCreateBt.disabled   = false;
        saveCreateBt.textContent = origText;
        return;
      }

      // 4) Éxito
      location.reload();

    } catch (networkErr) {
      alert('Error de red: ' + networkErr.message);
      saveCreateBt.disabled   = false;
      saveCreateBt.textContent = origText;
    }
  });

  /* ——— Modal SOLICITAR ——— */
  const modalReq    = document.getElementById('modal-request');
  const btnReqOpen  = document.getElementById('btn-request-event');
  const closeReq    = modalReq.querySelector('.modal-close');
  const btnReqSend  = document.getElementById('btn-send-request');

  /* Reglas de validación */
  const reqTxtRules = [
    {inp:'req-nombre',      req:'req-err-required-nombre',  rgx:'req-err-regex-nombre'},
    {inp:'req-lugar',       req:null,                       rgx:'req-err-regex-lugar'},
    {inp:'req-descripcion', req:null,                       rgx:'req-err-regex-descripcion'}
  ];
  const reqDtRules = [
    { inp: 'req-start', req: 'req-err-required-start', range: 'req-err-range-start' },
    { inp: 'req-end',   req: 'req-err-required-end',   range: 'req-err-range-end'   }
  ];
  const reqAllowedRE = allowedRE;
  const reqBody      = modalReq.querySelector('.card-body');
  const reqGeneral   = document.getElementById('req-general');
  const reqProjChks  = Array.from(document.querySelectorAll('.req-project-chk'));
  const reqProjErr   = document.getElementById('req-projects-error');
  const reqEncSel    = document.getElementById('req-encargado');

  /* helpers */
  let reqFirstInvalid = null;
  function reqShow(id) {
    const el = document.getElementById(id);
    el.style.display = 'inline';
    reqFirstInvalid ??= el;
  }
  function reqHide(id) {
    document.getElementById(id).style.display = 'none';
  }
  function reqScroll(el){
    const y = el.getBoundingClientRect().top
            - reqBody.getBoundingClientRect().top
            + reqBody.scrollTop - 40;
    reqBody.scrollTo({top:y,behavior:'smooth'});
    el.focus();
  }
  function reqValidateField(id){
    const el = document.getElementById(id);
    const txt = reqTxtRules.find(r=>r.inp===id);
    if(txt){
      const v = rs(id).value.trim();
      if(txt.req) (v ? reqHide(txt.req) : (reqShow(txt.req), reqFirstInvalid ??= id));
      if(v && !reqAllowedRE.test(v)) reqShow(txt.rgx); else reqHide(txt.rgx);
    }
    // datetime inputs --------------------------------------------------------
    const dt = reqDtRules.find(r => r.inp === id);
    if (dt) {
      const el = document.getElementById(id);
      const v  = el.value;
      const tooLow  = el.min && v < el.min;
      const tooHigh = el.max && v > el.max;

      if (!v || !isValidDateTimeLocal(v)) {
        reqShow(dt.req);
        if (dt.range) reqHide(dt.range);
      }
      else if (tooLow || tooHigh) {
        reqHide(dt.req);
        reqShow(dt.range);
      }
      else {
        reqHide(dt.req);
        if (dt.range) reqHide(dt.range);
      }
    }

    const minErr      = document.getElementById('req-mindiff-error');
    const isEndField  = id.endsWith('end');          // <── NUEVO

    if (isEndField &&
        el.value && rs('req-start').value &&
        isSameDay(rs('req-start').value, el.value) &&
        !gapOK(rs('req-start').value, el.value, 15)
    ){
        minErr.style.display = 'block';
        reqFirstInvalid ??= minErr;
    } else {
        minErr.style.display = 'none';
    }
  }
  function reqValidateAll(){
    reqFirstInvalid=null;
    reqTxtRules.forEach(r=>reqValidateField(r.inp));
    reqDtRules .forEach(r=>reqValidateField(r.inp));
    return !reqFirstInvalid;
  }
  function reqValidateProjects(){
    const ok = reqGeneral.checked || reqProjChks.some(c=>c.checked);
    reqProjErr.style.display = ok ? 'none' : 'block';
    return ok;
  }
  function filterEncReq(selProjects){
    const set=new Set(selProjects.map(String)); let keep=false;
    Array.from(reqEncSel.options).forEach(opt=>{
      if(!opt.value) return;
      const optProj = cleanList(opt.dataset.projects);
      const vis = set.size===0 || optProj.length===0 || optProj.some(p=>set.has(p));
      opt.hidden=!vis;
      if(opt.value===reqEncSel.value && vis) keep=true;
    });
    if(!keep) reqEncSel.value='';
  }

  /* dispara validación inmediata */
  [...reqTxtRules,...reqDtRules].forEach(({inp})=>{
    const el = rs(inp);
    const ev = el.type==='datetime-local'?'change':'input';
    el.addEventListener(ev ,()=>reqValidateField(inp));
    el.addEventListener('blur',()=>reqValidateField(inp));
  });

  const reqStart   = document.getElementById('req-start');
  const reqEnd     = document.getElementById('req-end');

  /* abrir */
  btnReqOpen.addEventListener('click', ()=>{
    rs('form-request-evento').reset();
    const now16 = new Date().toISOString().slice(0, 16);
    reqStart.value = now16;
    reqEnd  .value = now16;
    reqStart.addEventListener('change', () => {
      reqEnd.min = reqStart.value;
      if (reqEnd.value < reqEnd.min) {
        reqEnd.value = reqEnd.min;
      }
    });
    reqEnd.min     = now16;
    reqProjErr.style.display='none';
    reqBody.scrollTop=0;
    modalReq.style.display='flex';
  });
  /* cerrar */
  closeReq.addEventListener('click', ()=> modalReq.style.display='none');
  modalReq.addEventListener('click',e=>{
    if(e.target===modalReq) modalReq.style.display='none';
  });

  /* sincronizar checks */
  reqGeneral.onchange = ()=>{
    if(reqGeneral.checked){
      reqProjChks.forEach(c=>c.checked=false);
      filterEncReq([]);
      reqValidateProjects();
    }
  };
  reqProjChks.forEach(chk=>chk.onchange=()=>{
    const sel=reqProjChks.filter(c=>c.checked).map(c=>c.value);
    reqGeneral.checked = sel.length===0;
    filterEncReq(sel);
    reqValidateProjects();
  });

  // ——— Guardar SOLICITAR EVENTO ———
  btnReqSend.addEventListener('click', async () => {
    const origText = btnReqSend.textContent;
    // 1) Ocultar errores previos
    document.querySelectorAll('#modal-request .err-inline')
            .forEach(el => el.style.display = 'none');
    reqProjErr.style.display='none';
    const errEnd = document.getElementById('req-end-error');
    errEnd.style.display = 'none';

    // ── Scroll al primer error de rango en “Solicitar”
    const firstReqRange = reqDtRules
      .map(r => document.getElementById(r.range))
      .find(el => el && el.style.display === 'inline');

    if (firstReqRange) {
      reqScroll(firstReqRange);
      btnReqSend.disabled   = false;
      btnReqSend.textContent = origText;
      return;
    }

    // 2) Validación client-side
    if (!reqValidateAll() || !reqValidateProjects()) {
      reqScroll(reqFirstInvalid || reqProjErr);
      return;
    }
    const st = rs('req-start'), en = rs('req-end');
    if (en.value && en.value < st.value) {
      errEnd.style.display = 'block';
      reqScroll(errEnd);
      return;
    }

    // 3) Envío
    btnReqSend.disabled = true;
    btnReqSend.textContent = 'Enviando…';
    const form = document.getElementById('form-request-evento');
    const fd   = new FormData(form);
    ['fecha_hora_inicio','fecha_hora_termino'].forEach(f=>{
      if (fd.has(f)) {
        const v = fd.get(f);
        fd.set(f, v.replace('T',' ') + ':00');
      }
    });
    try {
      const res  = await fetch('crear_evento.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.mensaje) {
        if (data.error && typeof data.error === 'object') {
          const fieldToErrId = {
            nombre_evento:      'req-err-required-nombre',
            lugar:              'req-err-regex-lugar',
            descripcion:        'req-err-regex-descripcion',
            fecha_hora_inicio:  'req-err-required-start',
            fecha_hora_termino: 'req-err-required-end'
          };
          for (const [field, msg] of Object.entries(data.error)) {
            const errEl = document.getElementById(fieldToErrId[field]);
            if (errEl) { errEl.textContent = msg; errEl.style.display = 'inline'; }
          }
        } else {
          alert('Error: ' + (data.error || 'Error desconocido'));
        }
        btnReqSend.disabled   = false;
        btnReqSend.textContent = origText;
        return;
      }

      // 4) Éxito
      location.reload();

    } catch (networkErr) {
      alert('Error de red: ' + networkErr.message);
      btnReqSend.disabled   = false;
      btnReqSend.textContent = origText;
    }
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
    const el = document.getElementById(id);
    // text inputs ------------------------------------------------------------
    const txt = txtRules.find(r=>r.inp===id);
    if(txt){
      const v = document.getElementById(id).value.trim();
      if(txt.req) (v ? hide(txt.req) : show(txt.req));
      if(v && !allowedRE.test(v)) { show(txt.rgx); } else { hide(txt.rgx); }
    }

    // datetime inputs --------------------------------------------------------
    const dt = dtRules.find(r => r.inp === id);
    if (dt) {
      const el = document.getElementById(id);
      const v  = el.value;
      const tooLow  = el.min  && v < el.min;
      const tooHigh = el.max  && v > el.max;

      if (!v || !isValidDateTimeLocal(v)) {
        show(dt.req);
        if (dt.range) hide(dt.range);
      }
      else if (tooLow || tooHigh) {
        hide(dt.req);
        show(dt.range);
      }
      else {
        hide(dt.req);
        if (dt.range) hide(dt.range);
      }
    }

    const minErr     = document.getElementById('edit-mindiff-error');
    const isEndField = id.endsWith('end');           // ← NUEVO

    if (isEndField &&
        el.value && rs('edit-start').value &&
        isSameDay(rs('edit-start').value, el.value) &&
        !gapOK(rs('edit-start').value, el.value, 15)
    ){
      minErr.style.display = 'block';
      firstInvalid ??= minErr;
    } else {
      minErr.style.display = 'none';
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
  // ——— Guardar Cambios EDITAR EVENTO ———
  saveBtn.addEventListener('click', async () => {
    // 1) Ocultar errores previos
    document.querySelectorAll('#modal-edit .err-inline')
            .forEach(el => el.style.display = 'none');
    const errEnd = document.getElementById('end-error');
    errEnd.style.display = 'none';

    // ── Si hay un error “fuera de rango”, hacemos scroll y salimos:
    const firstRangeErr = dtRules
      .map(r => document.getElementById(r.range))
      .find(el => el && el.style.display === 'inline');

    if (firstRangeErr) {
      scrollToTarget(firstRangeErr);
      saveBtn.disabled = false;
      return;
    }

    // 2) Validación client-side
    if (!validateAll()) {
      scrollToTarget(firstInvalid);
      saveBtn.disabled = false;
      return;
    }
    if (!validateProjects()) {
      projectsErr.style.display = 'block';
      firstInvalid = projectsErr;
      scrollToTarget(projectsErr);
      saveBtn.disabled = false;
      return;
    }
    const startInp = document.getElementById('edit-start'),
          endInp   = document.getElementById('edit-end');
    if (endInp.value && endInp.value < startInp.value) {
      errEnd.style.display = 'block';
      scrollToTarget(errEnd);
      saveBtn.disabled = false;
      return;
    }

    // justo después de comprobar “end < start”
    const mindiffErr = document.getElementById('edit-mindiff-error');

    if (endInp.value && startInp.value &&
        isSameDay(startInp.value, endInp.value) &&
        !gapOK(startInp.value, endInp.value, 15)
    ) {
      mindiffErr.style.display = 'block';   // el <div> recién creado
      scrollToTarget(mindiffErr);
      return;
    }

    // 3) Envío
    saveBtn.disabled = true;
    const origText = saveBtn.textContent;
    saveBtn.textContent = 'Guardando…';
    const form = document.getElementById('form-edit-evento');
    const fd   = new FormData(form);
    ['fecha_hora_inicio','fecha_hora_termino'].forEach(f=>{
      if (fd.has(f)) {
        const v = fd.get(f);
        fd.set(f, v.replace('T',' ') + ':00');
      }
    });
    try {
      const res  = await fetch('actualizar_evento.php', { method: 'POST', body: fd });
      const data = await res.json();

      // UNIFICAMOS aquí data.errors y data.error
      const errs = data.errors || data.error;
      if (!data.mensaje) {
        if (errs && typeof errs === 'object') {
          const fieldToErrId = {
            nombre_evento:      'err-required-nombre',
            lugar:              'err-regex-lugar',
            descripcion:        'err-regex-descripcion',
            observacion:        'err-regex-observacion',
            fecha_hora_inicio:  'err-required-start',
            fecha_hora_termino: 'err-required-end'
          };
          for (const [field, msg] of Object.entries(errs)) {
            const errEl = document.getElementById(fieldToErrId[field]);
            if (errEl) {
              errEl.textContent = msg;
              errEl.style.display = 'inline';
            }
          }
        } else {
          // fallback genérico
          alert('Error: ' + (data.error || data.errors || 'Error desconocido'));
        }
        saveBtn.disabled   = false;
        saveBtn.textContent = origText;
        return;
      }

      // éxito…
      location.reload();
    } catch (networkErr) {
      alert('Error de red: ' + networkErr.message);
      saveBtn.disabled   = false;
      saveBtn.textContent = origText;
    }
  });

  // ——— Guardar DUPLICAR EVENTO ———
  saveCopyBt.addEventListener('click', async () => {
    // 1) Ocultar errores previos
    modalCopy.querySelectorAll('.err-inline')
            .forEach(el => el.style.display = 'none');
    const errEnd = document.getElementById('copy-end-error');
    errEnd.style.display = 'none';

    // ── Scroll al primer error de rango en “Duplicar”
    const firstCopyRange = copyDtRules
      .map(r => document.getElementById(r.range))
      .find(el => el && el.style.display === 'inline');

    if (firstCopyRange) {
      copyScrollToTarget(firstCopyRange);
      saveCopyBt.disabled = false;
      return;
    }

    // 2) Validación client-side
    if (!copyValidateAll() || !copyValidateProjects()) {
      copyScrollToTarget(copyFirstInvalid || copyProjectsErr);
      return;
    }
    const st = document.getElementById('copy-start'),
          en = document.getElementById('copy-end');
    if (en.value && en.value < st.value) {
      errEnd.style.display = 'block';
      copyScrollToTarget(errEnd);
      return;
    }

    // 3) Envío al servidor
    saveCopyBt.disabled   = true;
    const origText = saveCopyBt.textContent;
    saveCopyBt.textContent = 'Guardando…';
    const form = document.getElementById('form-copy-evento');
    const fd   = new FormData(form);
    ['fecha_hora_inicio','fecha_hora_termino'].forEach(f=>{
      if (fd.has(f)) {
        const v = fd.get(f);
        fd.set(f, v.replace('T',' ') + ':00');
      }
    });
    try {
      const res  = await fetch('crear_evento.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.mensaje) {
        // Si devuelve validaciones, las mostramos inline
        if (data.error && typeof data.error === 'object') {
          const mapErr = {
            nombre_evento:      'copy-err-required-nombre',
            lugar:              'copy-err-regex-lugar',
            descripcion:        'copy-err-regex-descripcion',
            observacion:        'copy-err-regex-observacion',
            fecha_hora_inicio:  'copy-err-required-start',
            fecha_hora_termino: 'copy-err-required-end'
          };
          for (const [field, msg] of Object.entries(data.error)) {
            const errEl = document.getElementById(mapErr[field]);
            if (errEl) { errEl.textContent = msg; errEl.style.display = 'inline'; }
          }
        } else {
          alert('Error: ' + (data.error || 'Error desconocido'));
        }
        saveCopyBt.disabled   = false;
        saveCopyBt.textContent = origText;
        return;
      }

      // 4) Éxito
      location.reload();

    } catch (networkErr) {
      alert('Error de red: ' + networkErr.message);
      saveCopyBt.disabled   = false;
      saveCopyBt.textContent = origText;
    }
  });

  // ─── Toggle botón “Aprobar eventos” ───
  const btnAprob = document.getElementById('btn-aprobar-eventos');
  if (btnAprob) {
    btnAprob.addEventListener('click', () => {
      const params = new URLSearchParams(window.location.search);
      // Conservar busqueda, filtro, mes, etc., y toggle aprobas
      if (params.get('aprobados') === '1') {
        params.delete('aprobados');
      } else {
        params.set('aprobados', '1');
      }
      // Recargar con la nueva query string
      window.location.search = params.toString();
    });
  }

  // ——————— VALIDACIÓN BUSCADOR ————————
  const searchInput  = document.getElementById('search-input');
  const btnSearch    = document.getElementById('btn-search');
  const searchError  = document.getElementById('search-error');
  const searchRE = /^[\p{L}\p{N} .,#¿¡!?()\/\-]*$/u;

  function validateSearch() {
    const ok = searchRE.test(searchInput.value);
    searchError.style.display = ok ? 'none' : 'block';
    btnSearch.disabled = !ok;
    return ok;
  }

  searchInput.addEventListener('input', validateSearch);
  // validar al cargar
  validateSearch();


  // ——————— VALIDACIÓN DESCARGA ————————
  const mesStart       = document.getElementById('mesStart');
  const mesEnd         = document.getElementById('mesEnd');
  const btnDownload    = document.getElementById('btn-download');
  const errStart       = document.getElementById('mesStart-error');
  const errEnd         = document.getElementById('mesEnd-error');
  const errOrder       = document.getElementById('dateOrder-error');
  const errRange       = document.getElementById('dateRange-error');

  const YEAR_MIN = 1970;
  const YEAR_MAX = 2037;

  function yyyymmToDate(str){          // '2025-07' → Date(2025,6,1)
    const [y,m] = str.split('-').map(Number);
    return new Date(y, m-1, 1);
  }

  function validateDownload() {
    let ok = true;

    const vStart = mesStart.value;
    const vEnd   = mesEnd.value;

    // 1) No vacíos
    if (!vStart) { errStart.style.display = 'block'; ok = false; }
    else         { errStart.style.display = 'none'; }

    if (!vEnd)   { errEnd.style.display   = 'block'; ok = false; }
    else         { errEnd.style.display   = 'none'; }

    if (!vStart || !vEnd) {
      // si falta alguno, no seguimos con resto
      errOrder.style.display = 'none';
      errRange.style.display = 'none';
      const yS = +vStart.slice(0,4), yE = +vEnd.slice(0,4);
      if (yS<YEAR_MIN || yS>YEAR_MAX) { errStart.style.display='block'; ok=false; }
      if (yE<YEAR_MIN || yE>YEAR_MAX) { errEnd  .style.display='block'; ok=false; }
      btnDownload.disabled = !ok;
      return ok;
    }

    // convertir a Date (usamos día 1 de cada mes)
    const [ys, ms] = vStart.split('-').map(Number);
    const [ye, me] = vEnd.split('-').map(Number);
    const dStart = new Date(ys, ms-1, 1);
    const dEnd   = new Date(ye, me-1, 1);

    // 2) vEnd >= vStart
    if (dEnd < dStart) {
      errOrder.style.display = 'block';
      ok = false;
    } else {
      errOrder.style.display = 'none';
    }

    // 3) no más de 2 años de diferencia
    const twoYearsLater = new Date(ys + 2, ms-1, 1);
    if (dEnd > twoYearsLater) {
      errRange.style.display = 'block';
      ok = false;
    } else {
      errRange.style.display = 'none';
    }

    btnDownload.disabled = !ok;
    return ok;
  }

  mesStart.addEventListener('change', validateDownload);
  mesEnd.addEventListener('change', validateDownload);
  // validar al cargar
  validateDownload();
});
