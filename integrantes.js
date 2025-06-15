/* ═══════════════════════════════════════════════════════════
   Integrantes  –  Front-end
   Versión adaptada al endpoint único  integrantes_api.php
   © Evangelio Creativo · 2025
════════════════════════════════════════════════════════════ */

const API = 'integrantes_api.php';          // ← punto único de entrada

/* ------------------------------------------------ utilidades */
const $  = sel => document.querySelector(sel);
const $$ = sel => document.querySelectorAll(sel);

/*  👉  promesas que indica cada input cuando termina de cargar utils.js */
let phoneInitPromises = [];

/* referencias reutilizadas */
const btnCols  = $('#btn-cols');     // botón engranaje
const colsMenu = $('#cols-menu');    // pop-up de columnas

/* helpers para mostrar / ocultar modales */
const show = el =>{
  el.classList.add('show');
  el.classList.remove('hidden');
};
const hide = el =>{
  el.classList.remove('show');
  el.classList.add('hidden');
};

const DEFAULT_PHOTO = 'uploads/fotos/default.png';

/* ------------------------------------------------ catálogo de estados */
let C_ESTADOS = [];
fetch(`${API}?accion=estados`)
  .then(r => r.json()).then(j => C_ESTADOS = j.estados);

let CURR_YEAR = new Date().getFullYear();
let YEAR_MIN = null;   // primer año con registros
let YEAR_MAX = null;   // último año con registros

/* ─── paginación y orden ─────────────────────────────── */
let PAGE    = 1;          // página actual
const PER   = 50;         // 50 registros por página
let TOTAL   = 0;          // total de filas que devuelve la API
let SORT_BY = 'nombre';   // columna por la que se ordena
let DIR     = 'ASC';      // ASC | DESC

/*  lista de equipos (validos) precargada una sola vez  */
let   EQUI_COMBO_HTML = '';
const EQUIPOS_PROMISE = fetch(`${API}?accion=equipos`)
  .then(r => r.json())
  .then(d => {
      EQUI_COMBO_HTML = '<option value=""></option>' +
          d.equipos
           .filter(e => e.id && e.id !== 'ret')        // quita General y Retirados
           .map   (e => `<option value="${e.id}">${e.nombre}</option>`)
           .join('');
  });

function initIntlTelInputs () {
  phoneInitPromises = [];                       // ← reinicia el array
  document.querySelectorAll('#phone-container input[type="tel"]').forEach(inp=>{
    if (inp._iti) inp._iti.destroy();           // evita doble init

    const iti = intlTelInput(inp,{
      separateDialCode : false,
      nationalMode     : false,                 // siempre nº internacional
      initialCountry   : 'cl',
      utilsScript      : 'https://cdn.jsdelivr.net/npm/intl-tel-input@25.3.1/build/js/utils.js'
    });
    inp._iti = iti;

    /* guarda la promise para saber CUANDO el utils-script ya está listo */
    phoneInitPromises.push(iti.promise);
  });
}

/* +++++++++ VALIDAR Y NORMALIZAR TELÉFONOS +++++++++ */
async function validateAndNormalizePhones () {

  /* 1) esperamos a que se cargue utils.js en TODOS los inputs */
  await Promise.all(phoneInitPromises);

  /* 2) validador “real” ----------------------------------- */
  for (const inp of document.querySelectorAll('#phone-container input.tel')) {
    const val = inp.value.trim();
    if (!val) continue;                          // caja vacía → ok

    const iti = inp._iti;

    const e164 = iti && iti.isValidNumber()
                    ? iti.getNumber(intlTelInputUtils.numberFormat.E164) // ← con +
                    : null;
    if (e164) inp.value = e164;
  }
  return true;
}

function loadRolesInto(sel, eq, selVal='', allowBlank = true){
   if(!eq){ sel.innerHTML = allowBlank ? '<option value=""></option>' : ''; return;}
   fetch(`${API}?accion=roles&eq=`+eq)
     .then(r=>r.json())
     .then(j=>{
        sel.innerHTML = (allowBlank ? '<option value=""></option>' : '') +
           j.roles.map(r=>`<option value="${r.id}">${r.nom}</option>`).join('');
        sel.value=selVal;
     });
}

/* ------------------------------------------------ sidebar */
async function loadSidebar () {
  const ul = $('#equipos-list');
  ul.innerHTML = '';
  const source = typeof PRE_EQUIPOS !== 'undefined' ? {equipos:PRE_EQUIPOS}
                : await (await fetch(`${API}?accion=equipos`)).json();
  ul.innerHTML = '<li>Cargando…</li>';

  const j = await (await fetch(`${API}?accion=equipos`)).json();
  ul.innerHTML = '';

  j.equipos.forEach(e => {
    const li = document.createElement('li');
    li.textContent = e.nombre;
    li.dataset.id  = e.id;
    li.onclick     = () => selectTeam(String(e.id), li);
    ul.appendChild(li);
  });

  selectTeam('0', ul.firstChild, 1);
}

/* ------------------------------------------------ tabla */
let DATA = [], TEAM = '0';

function visibleCols () {
  /* Oculta est1-3 cuando se está en “General” o “Retirados” */
  const cols = [...$$('#cols-menu input')]
                 .filter(c => c.checked)
                 .map   (c => c.dataset.key);
  return (TEAM === '0' || TEAM === 'ret')
         ? cols.filter(k => !/^est[123]$/.test(k))
         : cols;
}

async function selectTeam (id, li, page = 1) {
  /* normalizamos el identificador */
  id       = (id === 'ret') ? 'ret' : id.toString();
  TEAM     = id;                 // ← guarda “0”, “5”, “ret”…
  PAGE     = page;

  /* ► marca el ítem del sidebar */
  if (li) {
      $('#equipos-list li.sel')?.classList.remove('sel');
      li.classList.add('sel');
  }

  const url = `${API}?accion=lista&team=${TEAM}` +
              `&page=${PAGE}&per=${PER}`         +
              `&sort=${SORT_BY}&dir=${DIR}`;
  const j   = await (await fetch(url)).json();
  TOTAL     = j.total;
  DATA      = j.integrantes;

  refreshTable();
  buildPager();
}

function refreshTable () {
  const cols  = visibleCols();
  const thead = $('#tbl-integrantes thead');
  const tbody = $('#tbl-integrantes tbody');
  const showStates = (TEAM !== '0' && TEAM !== 'ret');

  /* encabezados */
  let headHTML = cols.map(k=>{
    const c      = COLS.find(x=>x.key===k);
    const active = (SORT_BY===c.sort);
    const arrow  = active ? (DIR==='ASC'?' ▲':' ▼') : '';
    return `<th data-sort="${c.sort}" style="cursor:pointer">
              ${c.label}${arrow}
            </th>`;
  }).join('');
  /* columnas de estado: más antiguo → más presente */
  if (showStates) {
      const hdrs = [periodLabel(-2), periodLabel(-1), periodLabel(0)];
      headHTML  += hdrs.map(t => `<th>${t}</th>`).join('');
  }
  headHTML += '<th class="sticky-right">Acciones</th>';
  thead.innerHTML = `<tr>${headHTML}</tr>`;

  thead.querySelectorAll('th[data-sort]').forEach(th=>{
    th.onclick = ()=>{
      const s = th.dataset.sort;
      SORT_BY = (SORT_BY===s) ? s : s;            // mantiene valor
      DIR     = (SORT_BY===s) ? (DIR==='ASC'?'DESC':'ASC') : 'ASC';
      selectTeam(TEAM, $('#equipos-list li.sel'), 1);
    };
  });

  /* filas */
  tbody.innerHTML = DATA.map(r => {
    const tdCols = cols.map(k => `<td>${r[k] ?? ''}</td>`).join('');

    /*  per3 / est3 es el MÁS ANTIGUO  */
    const idPer = [r.per3_id, r.per2_id, r.per1_id];
    const tdSel = [r.est3, r.est2, r.est1]
                    .map((v, i) => selHTML(
                        v,
                        r.id_integrante_equipo_proyecto,
                        idPer[i]))
                    .join('');

    /* ─────────────–– fila final ───────────── */
    let fila = `<tr>${tdCols}`;      // columnas “normales”
    if (showStates) fila += tdSel;   // solo si corresponde mostrar estados
    fila += `
        <td>
          <button class="btn-det"  data-id="${r.id_usuario}">👁️</button>
          <button class="btn-edit" data-id="${r.id_usuario}">✏️</button>
        </td>
      </tr>`;

    return fila;
  }).join('');

  /* listeners */
  $$('.sel-estado').forEach(s => s.onchange = updateEstado);
  $$('.btn-det')    .forEach(b => b.onclick = openDetalle);
  $$('.btn-edit')   .forEach(b => b.onclick = openEdit);
}

/* ─────────────────── paginador numérico ─────────────────── */
function buildPager () {
  const pages = Math.ceil(TOTAL / PER);
  let nav = document.getElementById('pager');
  if (!nav) {
    nav = document.createElement('div');
    nav.id = 'pager';
    nav.style.margin = '1rem 0';
    $('#section-table').after(nav);
  }
  if (pages <= 1) { nav.innerHTML = ''; return; }

  nav.innerHTML = Array.from({length: pages}, (_, i) => {
      const p = i + 1;
      return `<button data-p="${p}" ${p === PAGE ? 'disabled' : ''}>${p}</button>`;
  }).join(' ');

  nav.onclick = e => {
    if (e.target.dataset.p) {
      selectTeam(TEAM, $('#equipos-list li.sel'), Number(e.target.dataset.p));
    }
  };
}

function selHTML (val, iep, perid) {
  /* primera opción → marcador ‘sin registro’ */
  const opts = [
      `<option value="" ${val == null ? 'selected' : ''}>---</option>`,
      ...C_ESTADOS.map(e =>
          `<option value="${e.id}" ${e.id == val ? 'selected' : ''}>${e.nom}</option>`)
  ];
  return `<td>
            <select class="sel-estado"
                    data-iep="${iep}"
                    data-periodo="${perid ?? ''}">
              ${opts.join('')}
            </select>
          </td>`;
}

/* actualizar estado inline */
async function updateEstado (e) {
  /* si el usuario deja ‘---’ no hacemos nada */
  if (!e.target.value) return;

  const fd = new FormData();
  fd.append('accion',   'estado');
  fd.append('id_iep',   e.target.dataset.iep);
  fd.append('id_estado', e.target.value);
  fd.append('id_periodo', e.target.dataset.periodo || '');

  const j = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (!j.ok) alert(j.error);
}

/* ─── Etiquetas legibles de los 3 últimos periodos ─── */
const QTXT = ['Enero-Abril', 'Mayo-Agosto', 'Septiembre-Diciembre'];

/* offset = 0 → periodo “corriente”; −1 → el anterior; −2 → el más antiguo */
function periodLabel(offset = 0){
  const today   = new Date();
  let   q       = Math.floor(today.getMonth() / 4);   // 0,1,2
  let   year    = today.getFullYear();

  q += offset;                         // desplazamos…
  while (q < 0){ q += 3; year--; }     // …hacia atrás
  while (q > 2){ q -= 3; year++; }     // …o hacia delante

  return `${QTXT[q]} ${year}`;         // «Enero-Abril 2025», etc.
}

/* ------------------------------------------------ columnas */
const COLS = [
  { key:'nombre',  label:'Nombre completo',          def:1, sort:'nombre' },
  { key:'dia_mes', label:'Día-Mes',                  def:1, sort:'dia_mes' },
  { key:'edad',    label:'Edad',                     def:1, sort:'edad' },
  { key:'correo',  label:'Correo electrónico',       def:1, sort:'correo' },
  { key:'nacimiento',             label:'Nacimiento',               sort:'nacimiento' },
  { key:'telefonos',              label:'Nº contacto',              sort:'telefonos' },
  { key:'rut_dni_fmt',            label:'RUT / DNI',                sort:'rut_dni_fmt'},
  { key:'ubicacion',              label:'Ciudad / Región / País',   sort:'ubicacion' },
  { key:'direccion',              label:'Dirección',                sort:'direccion' },
  { key:'iglesia_ministerio',     label:'Iglesia / Ministerio',     sort:'iglesia_ministerio'},
  { key:'profesion_oficio_estudio',label:'Profesión / Oficio / Estudio', sort:'profesion_oficio_estudio'},
  { key:'ingreso',                label:'Fecha de ingreso',         sort:'ingreso' },
  { key:'ultima_act',             label:'Última actualización',     sort:'ultima_act'}
];

function buildColMenu () {
  const cm = $('#cols-menu');
  cm.innerHTML = '';
  COLS.forEach(c => {
    const id = 'chk_' + c.key;
    cm.insertAdjacentHTML('beforeend',
      `<label><input type="checkbox" id="${id}" data-key="${c.key}"
              ${c.def ? 'checked' : ''}> ${c.label}</label>`);
    $('#' + id).onchange = refreshTable;
  });
}

/* ------------------------------------------------ modal: detalles */

async function loadEstadosYear(idUsuario, anio) {

  const j = await fetch(`${API}?accion=estados_anio&id=${idUsuario}&anio=${anio}`)
                   .then(r => r.json());
  if (!j.ok) { alert(j.error || 'Error'); return false; }

  /* --- cabecera con el año y las flechas (SIEMPRE) ------------------ */
  const HEADS = { T1:'Ene-Abr', T2:'May-Ago', T3:'Sep-Dic' };
  $('#det-tab-estados thead').innerHTML = `
      <tr>
        <th rowspan="2">Equipo / Rol</th>
        <th colspan="3" style="text-align:center">
          <button id="yr-prev" class="yr-btn"  title="Año anterior">‹</button>
          <span  id="yr-label" style="margin:0 .7rem;font-weight:600">${anio}</span>
          <button id="yr-next" class="yr-btn"  title="Año siguiente">›</button>
        </th>
      </tr>
      <tr>
         <th>${HEADS.T1}</th><th>${HEADS.T2}</th><th>${HEADS.T3}</th>
      </tr>`;

  /* listeners de las flechas */
  $('#yr-prev').onclick = () => jumpYear(-1);
  $('#yr-next').onclick = () => jumpYear(+1);

  /* --- cuerpo -------------------------------------------------------- */
  const filas = j.rows.filter(r => /T[123]$/.test(r.nombre_periodo));

  if (filas.length === 0) {          // año sin datos → fila informativa
       $('#det-tab-estados tbody').innerHTML =
           '<tr><td colspan="4" style="padding:.5rem">Sin registros</td></tr>';
       return true;                  // devolvemos true para que openDetalle() no haga nada más
  }

  const map = new Map();
  filas.forEach(r=>{
      const tri = r.nombre_periodo.match(/T\d$/)[0]; // T1/T2/T3
      const key = `${r.nombre_equipo_proyecto}|${r.nombre_rol}`;
      if(!map.has(key))
          map.set(key,{eq:r.nombre_equipo_proyecto,rol:r.nombre_rol,T1:'-',T2:'-',T3:'-'});
      map.get(key)[tri] = estadoNom(r.id_tipo_estado_actividad);
  });

  $('#det-tab-estados tbody').innerHTML =
      [...map.values()].map(o=>`
        <tr>
          <td>${o.eq} (${o.rol})</td>
          <td>${o.T1}</td><td>${o.T2}</td><td>${o.T3}</td>
        </tr>`).join('');

  return true;
}

const DESC_TEL = {1:'Solo llamadas',2:'Solo WhatsApp',3:'Llamadas y WhatsApp',4:'# Emergencia'};
const descNom = id => DESC_TEL[id] || '';

async function openDetalle (e) {
  const id = e.currentTarget.dataset.id;
  const j  = await (await fetch(`${API}?accion=detalles&id=` + id)).json();
  if (!j.ok) { alert(j.error); return; }

  const u  = j.user;
  const md = $('#modal-det');

  md.querySelector('#det-nombre').dataset.uid = id; 
  md.querySelector('#det-foto')  .src = u.foto_perfil || 'uploads/fotos/default.png';
  md.querySelector('#det-nombre').textContent           = u.nombre_completo;
  md.querySelector('#det-tiempo').textContent           = humanMonths(u.meses_upd);
  md.querySelector('#det-nac')  .textContent = u.nacimiento_fmt;
  const rutMostrar = (u.id_pais == 1) ? formatRut(u.rut_dni)       // 1 = Chile
                                      : u.rut_dni.replace(/\D/g,'');
  md.querySelector('#det-rut').textContent = rutMostrar;
  md.querySelector('#det-pais') .textContent = u.nombre_pais;
  md.querySelector('#det-region').textContent= u.nombre_region_estado;
  md.querySelector('#det-ciudad').textContent= u.nombre_ciudad_comuna;
  md.querySelector('#det-dir')  .textContent = u.direccion;
  md.querySelector('#det-iglesia').textContent = u.iglesia_ministerio;
  md.querySelector('#det-prof') .textContent = u.profesion_oficio_estudio;
  md.querySelector('#det-ingreso').textContent = u.fecha_registro_fmt;
  md.querySelector('#det-correo').textContent  = u.correo_electronico;
  md.querySelector('#det-edad').textContent = u.edad + ' años';
  // array con objetos {num,desc,prim}
  const telHTML = (u.telefonos_arr||[])
        .map(t=>`<div>${t.num}
                    <small style="color:var(--text-muted)">
                      ${t.desc ? ' · '+descNom(t.desc) : ''}
                      ${t.prim==1 ? ' · <b>Principal</b>' : ''}
                    </small>
                  </div>`).join('');
  md.querySelector('#det-tels').innerHTML = telHTML || '-';
  md.querySelector('#det-ocup').textContent = u.ocupaciones || '-';

  if (!C_ESTADOS.length) {
    await fetch(`${API}?accion=estados`)
          .then(r=>r.json())
          .then(j=>C_ESTADOS = j.estados);
  }

  /* tabla de estados últimos periodos */
  const tb = $('#det-tab-estados tbody');
  tb.innerHTML = j.equipos.map(x => `
    <tr>
      <td>${x.nombre_equipo_proyecto} (${x.nombre_rol})</td>
      <td>${estadoNom(x.est3)}</td>
      <td>${estadoNom(x.est2)}</td>
      <td>${estadoNom(x.est1)}</td>
    </tr>`).join('');

  CURR_YEAR = new Date().getFullYear();          // resetea el valor global
  if (!await loadEstadosYear(id, CURR_YEAR)) {
      $('#det-tab-estados tbody').innerHTML =
          '<tr><td colspan="4" style="padding:.5rem">Sin registros</td></tr>';
  }

  /* límites de navegación (una sola petición pequeña) */
  const yb = await (await fetch(`${API}?accion=estados_bounds&id=`+id)).json();
  if(yb.ok){
      YEAR_MIN = yb.anio_min ?? CURR_YEAR;
      YEAR_MAX = yb.anio_max ?? CURR_YEAR;
      updateArrows();            // deshabilita ▶/◀ si toca
  }

  show(md);
}

/* ——— visor full-screen de la foto ——— */
const viewer = $('#img-viewer');
const vImg   = $('#viewer-img');

$('#det-foto').onclick = ()=>{
  vImg.src = $('#det-foto').src;
  viewer.classList.add('show');      /* misma clase que usamos para modales */
};

viewer.onclick = ()=> viewer.classList.remove('show');


/* cerrar al hacer clic en el fondo */
$('#modal-det').addEventListener('click', e=>{
  if(e.target.id==='modal-det') hide(e.currentTarget);
});
$('#det-close').onclick = () => hide($('#modal-det'));

/* ------------------------------------------------ modal: editar */
let CURR_USER = null;
let EQUIP_TAKEN = new Set();

async function openEdit (e) {
  const id = e.currentTarget.dataset.id;
  const j  = await (await fetch(`${API}?accion=detalles&id=` + id)).json();
  if (!j.ok) { alert(j.error); return; }
  await EQUIPOS_PROMISE;
  j.user.equip_now = j.equip_now;
  CURR_USER = j;
  EQUIP_TAKEN = new Set((j.user.equip_now || []).map(r => String(r.eq)));
  fillEditForm(j.user);
  show($('#modal-edit'));
}
$('#edit-close').onclick = () => hide($('#modal-edit'));
/* cerrar modal Editar con click fuera o con Cancelar */
$('#modal-edit').addEventListener('click',e=>{
  if(e.target.id==='modal-edit') hide(e.currentTarget);
});
document.body.addEventListener('click',e=>{
  if(e.target.id==='btn-cancel-edit') hide($('#modal-edit'));
});
$('#btn-add-eq').onclick = addEqRow;
$('#form-edit').onsubmit = submitEdit;

/* ---------- COMPLETA TODOS LOS CAMPOS DEL FORM ---------- */
function fillEditForm (u) {
  $('#del_foto').value = '0';
  $('#btn-del-photo').textContent = '🗑️ Eliminar foto';
  $('#ed-foto').dataset.deleted = '0';
  /* limpia y vuelca equipos actuales */
  $('#eq-container').innerHTML='';
  (u.equip_now||[]).forEach(row=>{
    const div = document.createElement('div');
    div.className='eq-row';
    // selector equipo
    const se = document.createElement('select');
    se.innerHTML = EQUI_COMBO_HTML;   // ver nota abajo
    se.value = row.eq;
    se.disabled = true;
    // selector rol
    const sr = document.createElement('select');
    loadRolesInto(sr, row.eq, row.rol, false);   // sin blanco
    se.onchange = ()=> loadRolesInto(sr,se.value);
    div.append(se,sr);
    $('#eq-container').appendChild(div);
  });

  /* ids visibles */
  $('#ed-id').value     = u.id_usuario;
  $('#ed-nom').value    = u.nombres                ?? '';
  $('#ed-ap').value     = u.apellido_paterno       ?? '';
  $('#ed-am').value     = u.apellido_materno       ?? '';
  $('#ed-foto').src     = u.foto_perfil || 'uploads/fotos/default.png';
  $('#ed-fnac').value   = u.fecha_nacimiento       ?? '';
  $('#ed-rut').value    = u.rut_dni                ?? '';

  /* tipo doc ↔ país  (Chile → “CL”, otro → “INT”) */
  $('#ed-doc-type').value = (u.id_pais == 1) ? 'CL' : 'INT';

  /* dirección / extra */
  $('#ed-dir').value    = u.direccion              ?? '';
  $('#ed-ig').value     = u.iglesia_ministerio     ?? '';
  $('#ed-pro').value    = u.profesion_oficio_estudio ?? '';
  $('#ed-correo').value = u.correo_electronico     ?? '';

  /* cascada País → Región → Ciudad (helpers abajo) */
  populatePaises().then(() => {
    $('#ed-pais').value = u.id_pais ?? '';
    return populateRegiones(u.id_pais);
  }).then(() => {
    $('#ed-region').value = u.id_region_estado ?? '';
    return populateCiudades(u.id_region_estado);
  }).then(() => {
    $('#ed-ciudad').value = u.id_ciudad_comuna ?? '';
  });

  /* ----- TELÉFONOS ----- */
  populatePhoneDescs().then(()=>{
    // limpia campos
    ['tel0','tel1','tel2'].forEach(n=>$(`[name="${n}"]`).value='');
    ['tel_desc0','tel_desc1','tel_desc2'].forEach(n=>$(`[name="${n}"]`).selectedIndex=0);

    (u.telefonos_arr||[]).forEach((tel,i)=>{
      if(i>2) return;
      $(`[name="tel${i}"]`).value     = tel.num;
      $(`[name="tel_desc${i}"]`).value= tel.desc||'';
    });
  }).then(initIntlTelInputs);   /** PEGAR DESPUÉS **/

  /* ocupaciones (check-list) */
  populateOcupaciones().then(list=>{
    const cont = $('#ocup-container');
    const taken = new Set(u.ocup_ids||[]);
    cont.innerHTML = list.map(o=>{
      const checked = taken.has(o.id) ? 'checked' : '';
      return `<label style="min-width:160px">
                <input type="checkbox" name="ocup_${o.id}" ${checked}> ${o.nom}
              </label>`;
    }).join('');
  });
}

$('#btn-del-photo').onclick = () => {
  const img   = $('#ed-foto');
  const flag  = $('#del_foto');
  const btn   = $('#btn-del-photo');

  if (img.dataset.deleted === '1') {          // ↩️ Restaurar
      img.src           = img.dataset.orig;
      img.dataset.deleted = '0';
      flag.value          = '0';
      btn.textContent     = '🗑️ Eliminar foto';
  } else {                                    // 🗑️ Eliminar
      img.dataset.orig   = img.src;           // guarda la real
      img.src            = DEFAULT_PHOTO;
      img.dataset.deleted = '1';
      flag.value          = '1';
      btn.textContent     = '↩️ Restaurar foto';
  }
};

/* ========== HELPERS QUE FALTABAN ========== */

/* Chile: 99999999K  →  9.999.999-K  */
function formatRut(numString){
  if(!/^\d{7,8}[0-9kK]$/.test(numString)) return numString;     // no coincide
  const dv = numString.slice(-1).toUpperCase();
  const num = numString.slice(0,-1)
                        .split('')
                        .reverse()
                        .map((d,i)=> (i && i%3===0 ? d+'.' : d))
                        .reverse()
                        .join('');
  return num+'-'+dv;
}

/* catálogo Países ------------------------------------------------------ */
async function populatePaises () {
  if ($('#ed-pais').options.length) return;  // ya estaba
  const j = await (await fetch(`${API}?accion=paises`)).json();
  $('#ed-pais').innerHTML =
      '<option value="">— país —</option>' +
      j.paises.map(p => `<option value="${p.id}">${p.nom}</option>`).join('');
  $('#ed-pais').onchange = e => populateRegiones(e.target.value);
}

/* ---------- País ⇄ Tipo documento ---------- */
function syncPaisDoc () {
  const selDoc  = $('#ed-doc-type');   // RUT / INT
  const selPais = $('#ed-pais');       // lista de países

  /* Tipo  ⇒ País */
  selDoc.addEventListener('change', () => {
    if (selDoc.value === 'CL'  && selPais.value !== '1') selPais.value = '1';
    if (selDoc.value === 'INT' && selPais.value === '1') selPais.value = '';
    populateRegiones(selPais.value);          // mantiene la cascada viva
  });

  /* País  ⇒ Tipo */
  selPais.addEventListener('change', () => {
    if (selPais.value === '1' && selDoc.value !== 'CL')  selDoc.value = 'CL';
    if (selPais.value && selPais.value !== '1' && selDoc.value !== 'INT')
        selDoc.value = 'INT';

    /* si deja país en blanco vaciamos los dependientes            */
    if (!selPais.value) {
      $('#ed-region').innerHTML = '<option value=""></option>';
      $('#ed-ciudad').innerHTML = '<option value=""></option>';
    }
  });
}

/* catálogo Regiones según país ---------------------------------------- */
async function populateRegiones (idPais) {
  const sel = $('#ed-region');
  if (!idPais){
    $('#ed-region').innerHTML = '<option value=""></option>';
    $('#ed-ciudad').innerHTML = '<option value=""></option>'; /* ← nueva */
    return;
  }
  const j = await (await fetch(`${API}?accion=regiones&pais=`+idPais)).json();
  sel.innerHTML = '<option value="">— región —</option>' +
      j.regiones.map(r => `<option value="${r.id}">${r.nom}</option>`).join('');
  sel.value = '';                                   // reset región
  $('#ed-ciudad').innerHTML = '<option value=""></option>';  // reset ciudad
  sel.onchange = e => populateCiudades(e.target.value);
}

/* catálogo Ciudades según región -------------------------------------- */
async function populateCiudades (idRegion) {
  const sel = $('#ed-ciudad');
  if (!idRegion) { sel.innerHTML = '<option value="">—</option>'; return; }
  const j = await (await fetch(`${API}?accion=ciudades&region=`+idRegion)).json();
  sel.innerHTML = '<option value="">— ciudad —</option>' +
      j.ciudades.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
}

/* descripción teléfonos ------------------------------------------------ */
async function populatePhoneDescs () {
  if ($('[name="tel_desc0"]').options.length) return;
  const j = await (await fetch(`${API}?accion=desc_telefonos`)).json();
  const opts = j.descs.map(d => `<option value="${d.id}">${d.nom}</option>`).join('');
  ['tel_desc0','tel_desc1','tel_desc2']
    .forEach(n => $(`[name="${n}"]`).innerHTML =
        '<option value="">— descripción —</option>' + opts);
}

/* ocupaciones ---------------------------------------------------------- */
async function populateOcupaciones () {
  const j = await (await fetch(`${API}?accion=ocupaciones`)).json();
  return j.ocupaciones;   // [{id,nom}, …]
}

async function addEqRow () {

  /* 1)  calcula los equipos que ya están en filas creadas
         (puede haber varias llamadas a addEqRow)          */
  const rowsTaken = new Set(
        [...$('#eq-container').querySelectorAll('select.eq-sel')]
           .map(s => s.value).filter(Boolean)   // solo los ya elegidos
  );

  /* 2)  une los conjuntos: vínculos activos + ya elegidos */
  const blocked = new Set(
        [...EQUIP_TAKEN, ...rowsTaken].map(String)   // 🔸
  );

  /* 3)  trae catálogo completo y lo filtra                */
  const d = await (await fetch(`${API}?accion=equipos`)).json();
  const opciones = d.equipos
        .filter(e => e.id && e.id !== 'ret' && !blocked.has(String(e.id)))
        .map(e => `<option value="${e.id}">${e.nombre}</option>`)
        .join('');

  if (!opciones) {                // nada más disponible
      alert('Ya no quedan equipos/proyectos por asignar.');
      return;
  }

  /* 4)  construye la nueva fila                            */
  const row  = document.createElement('div');
  row.className = 'eq-row';

  // selector de equipo
  const selEq  = document.createElement('select');
  selEq.className = 'eq-sel';      // ← para detectarlo arriba
  selEq.innerHTML = '<option value="">— equipo —</option>' + opciones;

  // selector de rol
  const selRol = document.createElement('select');

  selEq.onchange = async () => {
      if (!selEq.value) { selRol.innerHTML=''; return; }
      if ([...$$('.eq-sel')].some(
              s => s !== selEq && s.value === selEq.value)){
          alert('Ese equipo ya está seleccionado.');
          selEq.value = '';
          selRol.innerHTML = '';
          return;
      }
      const j = await (await fetch(`${API}?accion=roles&eq=`+selEq.value)).json();
      selRol.innerHTML = '<option value="">— rol —</option>' +
                         j.roles.map(r => `<option value="${r.id}">${r.nom}</option>`).join('');
  };

  row.appendChild(selEq);
  row.appendChild(selRol);
  $('#eq-container').appendChild(row);
}

async function submitEdit (ev) {
  ev.preventDefault();

  /* 👉 aborta si algún teléfono no pasa la validación */
  if (!(await validateAndNormalizePhones())) return;

  const fd = new FormData(ev.target);      // ahora sí incluye "+56…"
  fd.append('accion', 'editar');

  /* empaquetar equipos nuevos */
  const arr = [...$$('.eq-row')].map(r => {
      const e  = r.querySelector('select:nth-child(1)').value;
      const rl = r.querySelector('select:nth-child(2)').value;
      return e && rl ? { eq: e, rol: rl } : null;
  }).filter(Boolean);
  fd.append('equip', JSON.stringify(arr));

  const j = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (j.ok) {
      alert('Guardado ✓');
      hide($('#modal-edit'));
      /* recarga tabla */
      selectTeam(TEAM, $(`#equipos-list li[data-id="${TEAM}"]`), PAGE);
  } else alert(j.error);
}

/* ------------------------------------------------ helpers */
function humanMonths (m) {
  return m < 12 ? `${m} mes(es)` :
         `${Math.floor(m / 12)} año(s) ${m % 12} mes(es)`;
}

/* Devuelve el nombre del estado a partir de su id (o “-”) */
const estadoNom = id => {
  const obj = C_ESTADOS.find(e => e.id == id);
  return obj ? obj.nom : '-';
};

/* ------------------------------------------------ init */
document.addEventListener('DOMContentLoaded', () => {
  buildColMenu();
  if (typeof PRE_INTEGRANTES !== 'undefined'){
      DATA = PRE_INTEGRANTES;
      refreshTable();          // pinta de inmediato
  }
  loadSidebar();
  syncPaisDoc();
  /* ——— selector de columnas ——— */
  btnCols.onclick = e => {
    /* 1) calcula la posición del botón en la ventana */
    const rect = btnCols.getBoundingClientRect();
    /* 2) fija coordenadas del pop-up (8 px de margen) */
    colsMenu.style.top  = (rect.bottom + 8) + 'px';
    colsMenu.style.left = rect.left + 'px';

    /* 3) muestra / oculta */
    colsMenu.classList.toggle('show');
    e.stopPropagation();              // evita cierre inmediato
  };

  /* ── NO dejes que los clics del menú lleguen al botón ── */
  colsMenu.addEventListener('click', e => e.stopPropagation());

  /* si el usuario hace scroll o redimensiona, oculta el pop-up
    (para no dejarlo flotando “en el aire”) */
  window.addEventListener('scroll', () => colsMenu.classList.remove('show'));
  window.addEventListener('resize', () => colsMenu.classList.remove('show'));

  /* cerrar si se hace click fuera */
  document.addEventListener('click',ev=>{
    if(ev.target===btnCols || colsMenu.contains(ev.target)) return;
    colsMenu.classList.remove('show');
  });
});

/* ------------------------------------------------ helpers de navegación */
function updateArrows () {
  // localiza los botones (pueden no existir si el thead aún no se ha
  // construido o si hubo un error de marcado)
  const prev = $('#yr-prev');
  const next = $('#yr-next');
  if (!prev || !next) return;   // ← evita TypeError si alguno es null

  prev.disabled = (YEAR_MIN !== null && CURR_YEAR <= YEAR_MIN);
  next.disabled = (YEAR_MAX !== null && CURR_YEAR >= YEAR_MAX);
}

async function jumpYear(dir){
   if(dir<0 && YEAR_MIN!==null && CURR_YEAR<=YEAR_MIN) return;
   if(dir>0 && YEAR_MAX!==null && CURR_YEAR>=YEAR_MAX) return;

   const uid = $('#det-nombre').dataset.uid;
   let   y   = CURR_YEAR;

   while(true){
       y += dir;
       if((YEAR_MIN!==null && y<YEAR_MIN) || (YEAR_MAX!==null && y>YEAR_MAX)) break;
       if(await loadEstadosYear(uid, y)){      // encontrado → actualiza
           CURR_YEAR = y;
           updateArrows();
           return;
       }
   }
   /* llegamos al extremo sin más datos */
}
