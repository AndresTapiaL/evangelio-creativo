/* ═══════════════════════════════════════════════════════════
   Integrantes  –  Front-end
   Versión adaptada al endpoint único  integrantes_api.php
   © Evangelio Creativo · 2025
════════════════════════════════════════════════════════════ */

const API = 'integrantes_api.php';          // ← punto único de entrada

/* ------------------------------------------------ utilidades */
const $  = sel => document.querySelector(sel);
const $$ = sel => document.querySelectorAll(sel);

/* ------------------------------------------------ catálogo de estados */
let C_ESTADOS = [];
fetch(`${API}?accion=estados`)
  .then(r => r.json()).then(j => C_ESTADOS = j.estados);

let CURR_YEAR = new Date().getFullYear();
let YEAR_MIN = null;   // primer año con registros
let YEAR_MAX = null;   // último año con registros

/* ------------------------------------------------ sidebar */
async function loadSidebar () {
  const ul = $('#equipos-list');
  ul.innerHTML = '<li>Cargando…</li>';

  const j = await (await fetch(`${API}?accion=equipos`)).json();
  ul.innerHTML = '';

  j.equipos.forEach(e => {
    const li = document.createElement('li');
    li.textContent = e.nombre;
    li.dataset.id  = e.id;
    li.onclick     = () => selectTeam(e.id, li);
    ul.appendChild(li);
  });

  selectTeam(0, ul.firstChild);            // General
}

/* ------------------------------------------------ tabla */
let DATA = [], TEAM = 0;

function visibleCols () {
  return [...$$('#cols-menu input')].filter(c => c.checked)
                                    .map   (c => c.dataset.key);
}

async function selectTeam (id, li) {
  TEAM = id;
  [...$('#equipos-list').children].forEach(n => n.classList.remove('sel'));
  li.classList.add('sel');

  const j = await (await fetch(`${API}?accion=lista&team=` + id)).json();
  DATA = j.integrantes;
  refreshTable();
}

function refreshTable () {
  const cols  = visibleCols();
  const thead = $('#tbl-integrantes thead');
  const tbody = $('#tbl-integrantes tbody');

  /* encabezados */
  let headHTML = cols.map(k => `<th>${COLS.find(c => c.key === k).label}</th>`).join('');
  headHTML += ['🔸1', '🔸2', '🔸3'].map(t => `<th>${t}</th>`).join('');
  headHTML += '<th>Acciones</th>';
  thead.innerHTML = `<tr>${headHTML}</tr>`;

  /* filas */
  tbody.innerHTML = DATA.map(r => {
    const tdCols = cols.map(k => `<td>${r[k] ?? ''}</td>`).join('');
    const idPer  = [r.per1_id, r.per2_id, r.per3_id];
    const tdSel  = [r.est1, r.est2, r.est3]
                    .map((v, i) => selHTML(v,
                        r.id_integrante_equipo_proyecto,
                        idPer[i]))
                     .join('');

    return `<tr>${tdCols}${tdSel}
              <td>
                <button class="btn-det"  data-id="${r.id_usuario}">👁️</button>
                <button class="btn-edit" data-id="${r.id_usuario}">✏️</button>
              </td>
            </tr>`;
  }).join('');

  /* listeners */
  $$('.sel-estado').forEach(s => s.onchange = updateEstado);
  $$('.btn-det')    .forEach(b => b.onclick = openDetalle);
  $$('.btn-edit')   .forEach(b => b.onclick = openEdit);
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

/* ------------------------------------------------ columnas */
const COLS = [
  { key: 'nombre',   label: 'Nombre completo',         def: 1 },
  { key: 'dia_mes',  label: 'Día y Mes',               def: 1 },
  { key: 'edad',     label: 'Edad',                    def: 1 },
  { key: 'correo',   label: 'Correo electrónico',      def: 1 },
  { key: 'nacimiento',           label: 'Nacimiento' },
  { key: 'telefonos',            label: 'N° contacto' },
  { key: 'rut_dni_fmt',          label: 'RUT / DNI' },
  { key: 'ubicacion',            label: 'Ciudad/Región/País' },
  { key: 'direccion',            label: 'Dirección' },
  { key: 'iglesia_ministerio',   label: 'Iglesia/Ministerio' },
  { key: 'profesion_oficio_estudio', label: 'Profesión/Oficio/Estudio' },
  { key: 'ingreso',              label: 'Fecha de ingreso' },
  { key: 'ultima_act',           label: 'Última actualización' }
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
         <th colspan="3">
             <button id="yr-prev">◀</button>
             <span id="yr-label">${anio}</span>
             <button id="yr-next">▶</button>
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
  md.querySelector('#det-tels').innerHTML =
      (u.telefonos || '').replace(/\n/g,'<br>');
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

  md.classList.remove('hidden');
}

/* ───── Visor de imagen ➜ abrir / cerrar ───── */
$('#det-foto').onclick = () => {
  $('#viewer-img').src = $('#det-foto').src;
  $('#img-viewer').classList.remove('hidden');
};
$('#img-viewer').onclick = e => {
  if (e.target.id === 'img-viewer' || e.target.id === 'viewer-img')
      $('#img-viewer').classList.add('hidden');
};

/* cerrar al hacer clic en el fondo */
$('#modal-det').addEventListener('click', e=>{
  if(e.target.id==='modal-det') e.target.classList.add('hidden');
});
$('#det-close').onclick = () => $('#modal-det').classList.add('hidden');

/* ------------------------------------------------ modal: editar */
let CURR_USER = null;

async function openEdit (e) {
  const id = e.currentTarget.dataset.id;
  const j  = await (await fetch(`${API}?accion=detalles&id=` + id)).json();
  if (!j.ok) { alert(j.error); return; }
  CURR_USER = j;
  fillEditForm(j.user);
  $('#modal-edit').classList.remove('hidden');
}
$('#edit-close').onclick = () => $('#modal-edit').classList.add('hidden');
$('#btn-add-eq').onclick = addEqRow;
$('#form-edit').onsubmit = submitEdit;

/* ---------- COMPLETA TODOS LOS CAMPOS DEL FORM ---------- */
function fillEditForm (u) {
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

  /* teléfonos (máx 3) */
  populatePhoneDescs().then(() => {
    // limpiamos inputs
    ['tel0','tel1','tel2'].forEach(n => $(`[name="${n}"]`).value = '');
    ['tel_desc0','tel_desc1','tel_desc2']
      .forEach(n => $(`[name="${n}"]`).selectedIndex = 0);

    const arr = (u.telefonos || '').split(' / ');
    arr.forEach( (t,i) => {
      const num  = t.replace(/\s*\(.*?\)\s*$/,'').trim();
      const desc = (t.match(/\((.*?)\)/) || [,''])[1];
      $(`[name="tel${i}"]`).value = num;
      const sel = $(`[name="tel_desc${i}"]`);
      [...sel.options].forEach(o=>{
         if(o.textContent===desc) sel.value=o.value;
      });
    });
  });

  /* ocupaciones (check-list) */
  populateOcupaciones().then(list=>{
    const cont = $('#ocup-container');
    cont.innerHTML = list.map(o=>{
      const checked = (u.ocupaciones || '').split(',')
                        .map(x=>x.trim()).includes(String(o.id)) ? 'checked' : '';
      return `<label style="min-width:160px">
                <input type="checkbox" name="ocup_${o.id}" ${checked}> ${o.nom}
              </label>`;
    }).join('');
  });
}

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
  const j = await (await fetch('catalogos/paises.php')).json();
  $('#ed-pais').innerHTML =
      '<option value="">— país —</option>' +
      j.paises.map(p => `<option value="${p.id}">${p.nom}</option>`).join('');
  $('#ed-pais').onchange = e => populateRegiones(e.target.value);
}

/* catálogo Regiones según país ---------------------------------------- */
async function populateRegiones (idPais) {
  const sel = $('#ed-region');
  if (!idPais) { sel.innerHTML = '<option value="">—</option>'; return; }
  const j = await (await fetch(`catalogos/regiones.php?pais=${idPais}`)).json();
  sel.innerHTML = '<option value="">— región —</option>' +
      j.regiones.map(r => `<option value="${r.id}">${r.nom}</option>`).join('');
  sel.onchange = e => populateCiudades(e.target.value);
}

/* catálogo Ciudades según región -------------------------------------- */
async function populateCiudades (idRegion) {
  const sel = $('#ed-ciudad');
  if (!idRegion) { sel.innerHTML = '<option value="">—</option>'; return; }
  const j = await (await fetch(`catalogos/ciudades.php?region=${idRegion}`)).json();
  sel.innerHTML = '<option value="">— ciudad —</option>' +
      j.ciudades.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
}

/* descripción teléfonos ------------------------------------------------ */
async function populatePhoneDescs () {
  if ($('[name="tel_desc0"]').options.length) return;
  const j = await (await fetch('catalogos/desc_telefonos.php')).json();
  const opts = j.descs.map(d => `<option value="${d.id}">${d.nom}</option>`).join('');
  ['tel_desc0','tel_desc1','tel_desc2']
    .forEach(n => $(`[name="${n}"]`).innerHTML =
        '<option value="">— desc —</option>' + opts);
}

/* ocupaciones ---------------------------------------------------------- */
async function populateOcupaciones () {
  const j = await (await fetch('catalogos/ocupaciones.php')).json();
  return j.ocupaciones;   // [{id,nom}, …]
}

async function addEqRow () {
  const row  = document.createElement('div');
  row.className = 'eq-row';

  /* combo equipos */
  const selEq = document.createElement('select');
  const data  = await (await fetch(`${API}?accion=equipos`)).json();
  selEq.innerHTML = '<option value="">— equipo —</option>' +
       data.equipos.filter(e => e.id != 0 && e.id !== 'ret')
                   .map(e => `<option value="${e.id}">${e.nombre}</option>`)
                   .join('');

  /* combo roles */
  const selRol = document.createElement('select');

  selEq.onchange = async () => {
      const j = await (await fetch(`${API}?accion=roles&eq=` + selEq.value)).json();
      selRol.innerHTML = '<option value="">— rol —</option>' +
                         j.roles.map(r => `<option value="${r.id}">${r.nom}</option>`).join('');
  };

  row.appendChild(selEq);
  row.appendChild(selRol);
  $('#eq-container').appendChild(row);
}

async function submitEdit (ev) {
  ev.preventDefault();
  const fd = new FormData(ev.target);
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
      $('#modal-edit').classList.add('hidden');
      /* recarga tabla */
      selectTeam(TEAM, $(`#equipos-list li[data-id="${TEAM}"]`));
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
  loadSidebar();
  $('#btn-cols').onclick = () => $('#cols-menu').classList.toggle('hidden');
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
