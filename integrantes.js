/* ═══════════════════════════════════════════════════════════
   Integrantes  –  Front-end
   Versión adaptada al endpoint único  integrantes_api.php
   © Evangelio Creativo · 2025
════════════════════════════════════════════════════════════ */

const API = 'integrantes_api.php';          // ← punto único de entrada

/* ------------------------------------------------ utilidades */
const $  = sel => document.querySelector(sel);
const $$ = sel => document.querySelectorAll(sel);

const overlay   = document.getElementById('overlay');
const spinOn  = ()=> overlay.classList.remove('hidden');
const spinOff = ()=> overlay.classList.add('hidden');

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

let SEARCH = '';                       // texto actual del buscador
const DEBOUNCE = 300;                  // ms

/* ————————————————————————————————
   Sanitiza la búsqueda  (máx 100 caracteres)
   — solo letras (cualquier idioma), números,
     espacio y . , # ¿ ¡ ! ? ( ) / - @ + _ %
————————————————————————————————*/
const ALLOWED_RE = /[^\p{L}\p{N} .,#¿¡!?()\/\-@+_%\n\r]+/gu;
function limpiaBusqueda(raw){
  return raw
          .replace(ALLOWED_RE, '')   // quita lo no permitido
          .replace(/\s+/g, ' ')      // colapsa espacios
          .trim()
          .slice(0, 100);            // límite duro
}

let tSearch;                           // id del timer

const searchBox = $('#search-box');

/* ——— mensaje de error inline para el buscador ——— */
const searchErr = document.createElement('small');
searchErr.id          = 'search-err';
searchErr.className   = 'err-msg';
searchErr.style.marginLeft = '1rem';
searchErr.style.display    = 'none';
searchBox.after(searchErr);

searchBox.addEventListener('input', () => {
  clearTimeout(tSearch);

  /* ── TOPE DURO ────────────────────────────────────────────────
      Si el usuario pega o escribe más de 100 caracteres
      recortamos inmediatamente el exceso y avisamos.             */
  if (searchBox.value.length > 100) {
      searchBox.value = searchBox.value.slice(0, 100);      // corta al límite

      /* muestra la alerta y marca el campo                                       */
      searchErr.textContent =
        'Máx 100 caracteres. Solo letras, números, espacio y . , # ¿ ¡ ! ? ( ) / - @ + _ %';
      searchErr.style.display = 'block';
      searchBox.classList.add('invalid');

      /* cancela cualquier búsqueda que estuviera activa                          */
      SEARCH = '';
      PAGE   = 1;
      refreshTable();
      buildPager();

      return;                                // ← no ejecuta nada más
  }

  const raw = searchBox.value;               // ya ≤100 caracteres

  ALLOWED_RE.lastIndex = 0;

  /* ► chequeo instantáneo */
  const tieneProhibido  = ALLOWED_RE.test(raw);
  const sobreLongitud   = false;                 /* siempre false: límite físico */

  if (tieneProhibido || sobreLongitud) {
      searchErr.textContent =
        'Máx 100 caracteres. Solo letras, números, espacio y . , # ¿ ¡ ! ? ( ) / - @ + _ %';
      searchErr.style.display = 'block';
      searchBox.classList.add('invalid');

      /* ── NUEVO: cancela por completo la búsqueda anterior ── */
      SEARCH = '';                // vacía el término activo
      PAGE   = 1;                 // resetea paginación
      refreshTable();             // solo se mantiene el filtro del sidebar
      buildPager();

      return;                     // nada se envía al back-end
  }

  /* todo OK → oculta el mensaje y continúa */
  searchErr.textContent   = '';
  searchErr.style.display = 'none';
  searchBox.classList.remove('invalid');

  const val = limpiaBusqueda(raw);   // ya sanitizado

  tSearch = setTimeout(() => {
    SEARCH = normaliza(val.trim());
    PAGE   = 1;
    refreshTable();
    buildPager();
  }, DEBOUNCE);
});

/* ========= TOAST ligero (sin librerías externas) ========= */
function toast (msg, ms = 3000){
  const box = document.createElement('div');
  box.className = 'toast-box';               // ← marca identificable
  box.textContent = msg;

  /* estilo base */
  Object.assign(box.style,{
      position   :'fixed',
      right      :'20px',
      zIndex     : 2000,
      background :'var(--primary)',
      color      :'#fff',
      padding    :'10px 14px',
      borderRadius:'8px',
      boxShadow  :'0 4px 14px rgba(0,0,0,.15)',
      font       :'500 14px/1 Poppins,sans-serif',
      opacity    : 0,
      transition :'opacity .25s'
  });

  /* ── desplazamiento: 20 px + alto de cada toast visible + 10 px ── */
  let offset = 20;
  document.querySelectorAll('.toast-box').forEach(t => {
      offset += t.offsetHeight + 10;         // 10 px de separación
  });
  box.style.top = offset + 'px';

  document.body.appendChild(box);

  /* fade-in */
  requestAnimationFrame(()=> box.style.opacity = 1);

  /* fade-out y limpieza */
  setTimeout(()=>{
      box.style.opacity = 0;
      box.addEventListener('transitionend',()=> box.remove());
  }, ms);
}

async function fetchJSON(url, opts = {}, timeout = 30000){       // 30 s
  const ctrl = new AbortController();
  const tId  = setTimeout(()=>ctrl.abort(), timeout);
  try{
    spinOn();
    const res = await fetch(url, {...opts, signal: ctrl.signal});
    const j   = await res.json();
    return j;
  }catch(err){
    if(err.name === 'AbortError')
      throw new Error('Timeout');
    throw err;
  }finally{
    clearTimeout(tId);
    spinOff();
  }
}

function handleError (err){
  if (err.message === 'Timeout'){
      toast('El servidor está ocupado. Intenta de nuevo en un momento.');
  } else if (/1205/.test(err.message)){          // ER_LOCK_WAIT_TIMEOUT
      toast('Otro usuario está actualizando este registro. Prueba más tarde.');
  } else {
      toast('Error: ' + err.message);
  }
}

/* ------------------------------------------------ catálogo de estados */
let C_ESTADOS = [];
fetch(`${API}?accion=estados`)
  .then(r => r.json()).then(j => C_ESTADOS = j.estados);

let C_EST_ADM = [];
fetch(`${API}?accion=estados_admision`)
  .then(r=>r.json())
  .then(j=> C_EST_ADM = j.estados);

/* ─── color por id_estado_admision ─── */
const ADM_COLORS = {
  1: '#ffff00',   // Contactado
  2: '#ff9900',   // Agendado
  3: '#00ff00',   // Ingresado
  4: '#ff00ff',   // Pendiente
  5: '#ff0000',   // No llegó
  6: '#00ffff',   // Reagendar
  7: '#800000'    // No ingresa
};

let CURR_YEAR = new Date().getFullYear();
let YEAR_MIN = null;   // primer año con registros
let YEAR_MAX = null;   // último año con registros

/* ─── paginación y orden ─────────────────────────────── */
let PAGE    = 1;          // página actual
const PER   = 50;         // 50 registros por página
let TOTAL   = 0;          // total de filas que devuelve la API
let SORT_BY = 'nombre';   // columna por la que se ordena
let DIR     = 'ASC';      // ASC | DESC

/*  lista de equipos (válidos) precargada una sola vez  */
let   EQUI_COMBO_HTML = '';
const EQUIPOS_PROMISE = fetch(`${API}?accion=equipos`)
  .then(r => r.json())
  .then(d => {
      EQUI_COMBO_HTML = '<option value=""></option>' +
        d.equipos
         .filter(e => e.id && e.id !== 'ret')           // quita General y Retirados
         .map  (e => `<option value="${e.id}">${e.nombre}</option>`)
         .join('');
  })
  /* ⬇︎ NUEVO: si la llamada falla, la promesa queda resuelta
     (no «rejected») y no bloquea el modal Editar          */
  .catch(err => {
      console.error('Catálogo equipos:', err);
      EQUI_COMBO_HTML = '<option value=""></option>';
  });

/* —— TELÉFONOS —— */
const PHONE_RE  = /^\+\d{8,15}$/;   // + y 8-15 dígitos
const PHONE_MAX = 16;               // VARCHAR(16) (+ incluido)
/*  nº máximo de DÍGITOS (sin prefijo) para móviles en países hispanohablantes */
const MOBILE_MAX_ES = {
  ar:11, bo:8, cl:9, co:10, cr:8, cu:8, do:10, ec:9, sv:8, gq:9,
  gt:8, hn:8, mx:10, ni:8, pa:8, py:9, pe:9, pr:10, es:9, uy:9, ve:10
};

const MOBILE_MIN_ES = {
  ar:11, bo:8, cl:9, co:10, cr:8, cu:8, do:10, ec:9, sv:8, gq:9,
  gt:8, hn:8, mx:10, ni:8, pa:8, py:9, pe:9, pr:10, es:9, uy:9, ve:10
};

/* —— ISO (2-letras) → nombre país en español —— */
const COUNTRY_ES = {
  ar:'Argentina', bo:'Bolivia',   cl:'Chile',      co:'Colombia',
  cr:'Costa Rica', cu:'Cuba',     do:'Rep. Dominicana', ec:'Ecuador',
  sv:'El Salvador', gq:'Guinea Ecuatorial', gt:'Guatemala', hn:'Honduras',
  mx:'México',     ni:'Nicaragua', pa:'Panamá',   py:'Paraguay',
  pe:'Perú',       pr:'Puerto Rico', es:'España', uy:'Uruguay', ve:'Venezuela'
};

function paintAdmSelect(sel){
  const col = ADM_COLORS[sel.value] || '#ffffff';
  sel.style.backgroundColor = col;

  /* texto blanco si el fondo es oscuro */
  const rgb = parseInt(col.slice(1), 16);
  const lum = 0.299*((rgb>>16)&255) + 0.587*((rgb>>8)&255) + 0.114*(rgb&255);
  sel.style.color = lum < 140 ? '#ffffff' : '#000000';
}

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
    /* ——— calcula el largo máximo dinámico para ese país ——— */            //  <<< NUEVO
    const setDynMax = () => {                                               //  <<< NUEVO
      const data = iti.getSelectedCountryData();                            //  <<< NUEVO
      const iso  = data.iso2;                                               //  <<< NUEVO
      const pref = data.dialCode || '';                                     //  <<< NUEVO
      const lim  = MOBILE_MAX_ES[iso] ?? 15;                                //  <<< NUEVO
      inp._maxLen = 1 + pref.length + lim;          /* + «+» */             //  <<< NUEVO
    };                                                                      //  <<< NUEVO
    setDynMax();                                                            //  <<< NUEVO
    inp.addEventListener('countrychange', () => {
      /* mantiene el largo máximo dinámico */
      setDynMax();

      /* ─── 🆕 1) autocompleta el prefijo seleccionado ─── */
      const data = iti.getSelectedCountryData();          // ej. {dialCode:'56', iso2:'cl', …}
      const pref = data.dialCode || '';

      /* ── Agrega el prefijo solo al campo que cambió ── */
      if (inp.value.trim() === '') {
        inp.value = '+' + pref;
      }

      /* 2) re-valida en vivo (mensaje, colores, etc.) */
      validatePhoneRows();
    });

    inp._iti = iti;

    /* máscara: solo ‘+’ al inicio y dígitos; máx 16 caracteres.
      ── Nuevo ──  ahora permite borrar el campo por completo  */
    inp.addEventListener('input', () => {
      let v = inp.value.replace(/[^\d+]/g, '');   // quita todo lo que no sea + o dígitos
      v = v.replace(/\+/g, '');                   // elimina todos los ‘+’ existentes

      if (v === '') {                             // el usuario borró todo
        inp.value = '';                           // deja el campo en blanco
        return;                                   // ← sin forzar el ‘+’
      }

      v = '+' + v;                                // antepone un único ‘+’
      const lim = inp._maxLen || PHONE_MAX;          //  <<< NUEVO
      if (v.length > lim) v = v.slice(0, lim);       //  <<< NUEVO
      inp.value = v;
    });

        /* ► validación en vivo */
    inp.addEventListener('input', () => validatePhoneRows());
    inp.addEventListener('blur',  () => validatePhoneRows());

    // —–– Asegura que NUNCA quede pendiente: si utils.js falla, la promesa se resuelve igual
    phoneInitPromises.push(
      iti.promise.catch(() => null)   // ← ya está “settled”
    );
  });
}

/* —— VALIDACIÓN nombres / apellidos —— */
const NAME_RE = /^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u;

function validateNameField (inp){
  const max = parseInt(inp.getAttribute('maxlength'),10) || 255;
  const txt = inp.value.trim();
  let msg = '';

  /* ⬇︎ NUEVO – obligatorio */
  if (!txt && inp.required){
      msg = '* Obligatorio';
  } else if (txt.length > max){
      msg = `Máx ${max} caracteres.`;
  } else if (txt && !NAME_RE.test(txt)){
      msg = '* Solo letras, números, espacios, saltos de línea y . , # ¿ ¡ ! ? ( ) / -';
  }

  const err = inp.parentElement.querySelector('.err-msg');
  if (msg){
      err.textContent   = msg;
      err.style.display = 'block';
      inp.classList.add('invalid');
  }else{
      err.textContent   = '';
      err.style.display = 'none';
      inp.classList.remove('invalid');
  }
  return !msg;
}

/* —— VALIDACIÓN fecha de nacimiento —— */
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;          // AAAA-MM-DD

/* ——— limita la entrada del <input type="date"> a dígitos y ‘-’ (máx 10) ——— */
function maskDateInput (ev){
    const inp = ev.target;
    inp.value = inp.value                       // solo 0-9 y guiones
                     .replace(/[^\d-]/g, '')
                     .slice(0, 10);             // AAAA-MM-DD = 10 caracteres
}

function validateBirthDate (inp, regDateStr = '') {
    const msgBox = inp.parentElement.querySelector('.err-msg');
    let   msg    = '';
    const raw    = inp.value.trim();

    /* 1) obligatorio + patrón --------------------------------------- */
    if (!raw){
        msg = '* Obligatorio';
    } else if (!DATE_RE.test(raw) || raw.length !== 10){
        msg = '* Formato DD-MM-AAAA';
    } else {
        /* 2) fecha válida ------------------------------------------- */
        const born = new Date(raw + 'T00:00:00');
        if (Number.isNaN(born.getTime()))       msg = '* Fecha inválida';
        else {
            const today = new Date();
            const age   = today.getFullYear() - born.getFullYear()
                         - ( today < new Date(today.getFullYear(), born.getMonth(), born.getDate()) );
            if (age < 12)        msg = '* Debe tener ≥ 12 años';
            else if (age > 200)  msg = '* ¿Seguro? más de 200 años';
            /* 3) no posterior a registro ----------------------------- */
            if (!msg && regDateStr){
                const reg = new Date(regDateStr.split('-').reverse().join('-') + 'T00:00:00');
                if (born > reg){
                    msg = `* No puede ser > fecha de registro (${regDateStr})`;
                }
            }
        }
    }

    /* feedback visual ----------------------------------------------- */
    if (msg){
        msgBox.textContent   = msg;
        msgBox.style.display = 'block';
        inp.classList.add('invalid');
    }else{
        msgBox.textContent   = '';
        msgBox.style.display = 'none';
        inp.classList.remove('invalid');
    }
    return !msg;
}

/* —— VALIDACIÓN correo electrónico —— */
/*  ⬇︎ sólo letras ASCII, números y  . _ % + -  antes de la @.
    Nada de tildes, comillas ni espacios */
const EMAIL_RE =
      /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;

function validateEmail (inp){
  const max = 320;
  const txt = inp.value.trim();
  let   msg = '';

  if (!txt){
      msg = '* Obligatorio';
  } else if (txt.length > max){
      msg = `Máx ${max} caracteres.`;
  } else if (!EMAIL_RE.test(txt)){
      msg = '* Correo no válido';
  }

  const err = inp.parentElement.querySelector('.err-msg');
  if (msg){
      err.textContent   = msg;
      err.style.display = 'block';
      inp.classList.add('invalid');
  }else{
      err.textContent   = '';
      err.style.display = 'none';
      inp.classList.remove('invalid');

      /* ─── NUEVO: consulta al servidor para garantizar unicidad ─── */
      const uid = $('#ed-id') ? $('#ed-id').value : '0';   // 0 = alta
      fetch(`integrantes_api.php?accion=checkcorreo&mail=${encodeURIComponent(txt)}&uid=${uid}`)
        .then(r => r.json())
        .then(j => {
            if (!j.ok) return;                         // error de red ⇒ ignora
            if (j.exists) {
                err.textContent   = '* Este correo ya está registrado';
                err.style.display = 'block';
                inp.classList.add('invalid');
            }
        })
        .catch(()=>{});                               // silencio en caso de fallo de red
  }
  return !msg;
}

/* —— VALIDACIÓN N° documento —— */
const DOC_MAX_LEN = 13;               // VARCHAR(13)

function rutDV(numStr){
  let sum = 0, mul = 2;
  for (let i = numStr.length - 1; i >= 0; i--){
    sum += +numStr[i] * mul;
    mul  = (mul === 7 ? 2 : mul + 1);
  }
  const res = 11 - (sum % 11);
  return res === 11 ? '0' : res === 10 ? 'K' : String(res);
}

function validateDocNumber(inp){
  const msgBox  = inp.parentElement.querySelector('.err-msg');
  const docType = $('#ed-doc-type').value;          // CL | INT
  let   raw     = inp.value.toUpperCase().replace(/[.\-]/g, '');

  /* limpia caracteres no permitidos y limita longitud */
  raw = raw.replace(/[^0-9K]/g, '').slice(0, DOC_MAX_LEN);
  inp.value = raw;

  let msg = '';

  /* ─── OBLIGATORIO ─── */
  if (!raw){
      msg = '* Obligatorio';

  } else if (!/^[0-9K]+$/.test(raw)){
      msg = '* Solo dígitos y K';

  } else if (docType === 'CL'){
      if (!/^\d{7,8}[0-9K]$/.test(raw))             msg = '* Formato RUT inválido';
      else if (rutDV(raw.slice(0, -1)) !== raw.slice(-1))
                                                   msg = '* RUT inválido';

  } else if (!/^\d{1,13}$/.test(raw)){
      msg = '* Solo dígitos (máx 13)';
  }

  /* feedback visual */
  if (msg){
      msgBox.textContent   = msg;
      msgBox.style.display = 'block';
      inp.classList.add('invalid');
  } else {
      msgBox.textContent   = '';
      msgBox.style.display = 'none';
      inp.classList.remove('invalid');
  }
  return !msg;
}

function validateLocSelect(sel){
  /* vacío = permitido */
  const val   = sel.value.trim();
  const msgBx = sel.parentElement.querySelector('.err-msg');
  let   msg   = '';

  /* ❶ — País ahora es OBLIGATORIO */
  if (sel.id === 'ed-pais' && !val){
      msg = '* Obligatorio';
  }

  /* ① el valor debe corresponder a una option existente */
  if (val && ![...sel.options].some(o => o.value === val)){
      msg = '* Opción no válida';
  }

  /* ② coherencia jerárquica básica (front-end) */
  if (!msg && sel.id === 'ed-region' && val && !$('#ed-pais').value){
      msg = '* Primero selecciona País';
  }
  if (!msg && sel.id === 'ed-ciudad' && val && !$('#ed-region').value){
      msg = '* Primero selecciona Región / Estado';
  }

  /* feedback visual */
  if (msg){
      sel.classList.add('invalid');
      msgBx.textContent   = msg;
      msgBx.style.display = 'block';
  }else{
      sel.classList.remove('invalid');
      msgBx.textContent   = '';
      msgBx.style.display = 'none';
  }
  return !msg;
}

/* —— Motivo de retiro (regex + longitud ≤255) —— */
function validateMotivoRet(inp){
  return validateNameField(inp);        // reutiliza la misma lógica
}

/* —— Select ¿Falleció?  —— */
function validateDifunto(sel){
  const err = sel.parentElement.querySelector('.err-msg');
  let msg = '';
  if (!['0','1'].includes(sel.value)){
      msg = '* Opción no válida';
  }
  if (msg){
      err.textContent = msg;  err.style.display='block';
      sel.classList.add('invalid');
      return false;
  }
  err.textContent=''; err.style.display='none';
  sel.classList.remove('invalid');
  return true;
}

/* —— Cuestionario: radios y checkboxes —— */
function validateRadioGroup(name){
  const radios = document.querySelectorAll(`input[name="${name}"]`);
  if (!radios.length) return true;

  const container =
        radios[radios.length - 1].closest('.q-field,.field')
     || radios[radios.length - 1].parentElement;

  let box = container.querySelector('.err-msg');
  if (!box){
      box = document.createElement('small');
      box.className   = 'err-msg';
      container.appendChild(box);
  }

  const ok = [...radios].some(r => r.checked);

  if (!ok){
      box.textContent   = '* Selecciona una opción';
      box.style.display = 'block';
      radios[0].classList.add('invalid');
  }else{
      box.textContent   = '';
      box.style.display = 'none';
      radios.forEach(r => r.classList.remove('invalid'));
  }
  return ok;
}

function validateCheckGroup(contId){
  const cont = document.getElementById(contId);
  const chk  = cont ? cont.querySelectorAll('input[type="checkbox"]') : [];

  let box = cont?.parentElement.querySelector('.err-msg');
  if (cont && !box){
      box = document.createElement('small');
      box.className = 'err-msg';
      cont.parentElement.appendChild(box);
  }
  const ok = [...chk].some(c=>c.checked);
  if(!ok){
      box.textContent   = '* Selecciona al menos una opción';
      box.style.display = 'block';
  }else{
      box.textContent   = '';
      box.style.display = 'none';
  }
  return ok;
}

function validatePhoneRows () {
  let ok = true;

  /* ① mapa auxiliar para detectar números repetidos en las 3 filas */
  const seen = new Map();              // num → <input>
  for (let i = 0; i < 3; i++) {
    let  rowHasError = false;
    const inp  = document.querySelector(`[name="tel${i}"]`);
    const sel  = document.querySelector(`[name="tel_desc${i}"]`);
    const val  = inp.value.trim();
    const desc = sel.value.trim();

    /*  localiza (o crea) la cajita de error  */
    let err = inp.parentElement.querySelector('.err-msg');
    if (!err) {
        err           = document.createElement('small');
        err.className = 'err-msg';
        inp.parentElement.appendChild(err);
    }
    let   msg  = '';

    /* ─── duplicados en el mismo formulario ─── */
    if (val) {
        if (seen.has(val)) {
            msg        = '* Número duplicado';
            rowHasError = true;

            /* marca también el primer input que ya tenía ese número */
            const dupInp = seen.get(val);
            const dupErr = dupInp.parentElement.querySelector('.err-msg')
                            || dupInp.parentElement.appendChild(
                                  Object.assign(document.createElement('small'),
                                                {className:'err-msg'}));
            dupErr.textContent   = '* Número duplicado';
            dupErr.style.display = 'block';
            dupInp.classList.add('invalid');
        } else {
            seen.set(val, inp);   // registra el número como visto
        }
    }

    if (val) {
      /* ① formato global */
      const digits = val.replace(/\D/g, '');   // cuenta solo los dígitos
      const iti   = inp._iti;                             //  <<< NUEVO
      const data  = iti ? iti.getSelectedCountryData() : null;   //  <<< NUEVO
      const iso   = data ? data.iso2 : '';                //  <<< NUEVO
      const pref  = data ? data.dialCode : '';            //  <<< NUEVO

      /* ─── prefijo digitado ≠ prefijo de la bandera ─── */
      if (val && !digits.startsWith(pref)) {
          msg = '* Selecciona un prefijo real';
          rowHasError = true;

      } else {
          const subscrLen = digits.length - pref.length;      //  <<< NUEVO

          /* ─── chequeo de largo de suscriptor ─── */
          const minSubscr = MOBILE_MIN_ES[iso] ?? 8;     // ← mínimo por país (o 8 global)
          const maxSubscr = MOBILE_MAX_ES[iso] ?? 15;    // ← máximo por país

          if (subscrLen < minSubscr){
              const paisNom = COUNTRY_ES[iso] || iso.toUpperCase();
              msg = `* Se requiere mínimo ${minSubscr} dígitos para ${paisNom}`;
              rowHasError = true;
          } else if (subscrLen > maxSubscr){
              msg = `* Máx ${maxSubscr} dígitos para ${iso.toUpperCase()}`;
              rowHasError = true;
          } else if (!PHONE_RE.test(val)){
              msg = '* Solo + y dígitos';
              rowHasError = true;
          }
      }

      /* ─── coherencia número ↔ descripción ─── */
      if (!msg && !desc){
          msg = '* Ingresa número o quita descripción';
      } else if (!msg && desc){
          for (let j = 0; j < i; j++) {
              if (!document.querySelector(`[name="tel${j}"]`).value.trim()) {
                  msg = `* Completa Teléfono ${j+1} antes`;
                  break;
              }
          }
      }

    } else if (desc) {                          // nº vacío → desc no permitida
      msg = '* Ingresa número o quita descripción';
    }

    /* feedback visual */
    if (msg) {
      err.textContent   = msg;
      err.style.display = 'block';
      inp.classList.add('invalid');
      sel.classList.add('invalid');
      ok = false;
    } else {
      err.textContent   = '';
      err.style.display = 'none';
      inp.classList.remove('invalid');
      sel.classList.remove('invalid');
    }
    if (rowHasError) continue;

    /* NUEVO: resalta el primer teléfono omitido si se detectó un salto */
    if (msg.startsWith('* Completa Teléfono')) {
        const faltaIdx = parseInt(msg.match(/\d+/)[0], 10) - 1;   // 0-based
        const faltaInp = document.querySelector(`[name="tel${faltaIdx}"]`);
        if (faltaInp) {
            let err2 = faltaInp.parentElement.querySelector('.err-msg');
            if (!err2) {
                err2 = document.createElement('small');
                err2.className = 'err-msg';
                faltaInp.parentElement.appendChild(err2);
            }
            err2.textContent   = '* Obligatorio';
            err2.style.display = 'block';
            faltaInp.classList.add('invalid');
        }
    }
  }
  return ok;
}

/* —— VALIDACIÓN Equipos / Proyectos —— */
function validateEqRows () {
  let ok = true;

  document.querySelectorAll('#eq-container .eq-row').forEach(row => {

      /* los dos únicos <select> que hay en la fila: 0 = Equipo, 1 = Rol */
      const [selEq, selRol] = row.querySelectorAll('select');

      /* caja de error (se crea solo la 1.ª vez) */
      let err = row.querySelector('.err-msg');
      if (!err) {
          err = document.createElement('small');
          err.className = 'err-msg';
          row.appendChild(err);
      }

      let msg = '';
      if (selEq.value && !selRol.value)          msg = '* Selecciona un rol';
      if (!selEq.value &&  selRol.value)         msg = '* Falta seleccionar equipo';

      /* feedback visual */
      if (msg) {
          err.textContent   = msg;
          err.style.display = 'block';
          selEq.classList.add ('invalid');
          selRol.classList.add('invalid');
          ok = false;
      } else {
          err.textContent   = '';
          err.style.display = 'none';
          selEq.classList.remove ('invalid');
          selRol.classList.remove('invalid');
      }
  });

  return ok;
}

/* +++++++++ VALIDAR Y NORMALIZAR TELÉFONOS +++++++++ */
async function validateAndNormalizePhones () {
  /* Esperamos a que TODAS las promesas terminen,
     pero sin abortar si alguna se rechaza  */
  await Promise.allSettled(
    phoneInitPromises.map(p => p.catch(() => null))
  );

  if (!validatePhoneRows()) {
    /* localiza el primer campo con error                                */
    const bad = document.querySelector('#phone-container .invalid');
    if (bad) {
      /* ─── 1) centra el campo *dentro* del modal ─── */
      const box = document.querySelector('#modal-edit .modal-box');
      if (box) {
        const y = bad.getBoundingClientRect().top            // posición real
                - box.getBoundingClientRect().top            // relativo al contenedor
                + box.scrollTop                              // más desplazamiento actual
                - box.clientHeight / 2;                      // lo deja ± centrado
        box.scrollTo({ top: y, behavior: 'smooth' });
      }

      /* ─── 2) foco sin saltos extra ─── */
      setTimeout(() => bad.focus({ preventScroll: true }), 400);
    }
    return false;                     // ← aborta el submit
  }

  /* normaliza a formato E.164 */
  for (const inp of document.querySelectorAll('#phone-container input.tel')) {
    const val = inp.value.trim();
    if (!val) continue;
    const iti = inp._iti;
    /*  Si utils.js no está disponible o la validación lanza un error,
        dejamos el número tal cual y seguimos  */
    let e164 = null;
    try {
        if (iti && iti.isValidNumber()) {
            e164 = iti.getNumber(intlTelInputUtils.numberFormat.E164);
        }
    } catch (_) { /* ignora error y continúa */ }

    if (e164) inp.value = e164;         // guarda con ‘+’
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
  const aside = document.querySelector('.sidebar');   // contenedor con scroll
  const yPos  = aside.scrollTop;                     // ① memoriza posición

  const ul = $('#equipos-list');
  ul.innerHTML = '<li>Cargando…</li>';

  /* Trae el catálogo – ya no necesitamos la constante `source` */
  const { equipos } = await fetch(`${API}?accion=equipos`).then(r => r.json());
  ul.innerHTML = '';

  /*  ── inserta “Nuevos integrantes” justo después de General ── */
  const nuevos = {id:'new', nombre:'Nuevos integrantes'};
  const posGen = equipos.findIndex(e => String(e.id)==='0');
  if(posGen!==-1) equipos.splice(posGen+1,0,nuevos);

  equipos.forEach(e => {
    const li = document.createElement('li');
    li.textContent = e.nombre;
    li.dataset.id  = e.id;
    li.onclick     = () => selectTeam(String(e.id), li);

    /* ② si es el equipo actualmente seleccionado, mantén la clase .sel */
    if (String(e.id) === TEAM) li.classList.add('sel');

    ul.appendChild(li);
  });

  /* ③ restaura el desplazamiento una vez pintada la lista */
  requestAnimationFrame(() => { aside.scrollTop = yPos; });
}

/* ------------------------------------------------ tabla */
let DATA = [], TEAM = '0';

function visibleCols () {
  /* columnas marcadas en el pop-up */
  const cols = [...$$('#cols-menu input')]
                 .filter(c => c.checked)
                 .map   (c => c.dataset.key);

  /* estados: sólo en equipos/proyectos */
  const base = (TEAM === '0' || TEAM === 'ret')
               ? cols.filter(k => !/^est[123]$/.test(k))
               : cols;

  if (TEAM === 'new'){
      /* no queremos la columna numérica 'estado_adm' */
      return cols.filter(k => !/^est[123]$/.test(k) && k !== 'estado_adm');
  }

  /* columnas exclusivas de Retirados */
  return (TEAM === 'ret')
         ? base
         : base.filter(k => !['fecha_retiro','ex_equipo','es_difunto'].includes(k));
}

async function selectTeam (id, li, page = 1) {
  /* normalizamos el identificador */
  id       = (id === 'ret') ? 'ret' : id.toString();
  const cambioSeccion = (id !== TEAM);

  /* ➊  si salimos de Retirados, reseteamos el orden incompatible */
  const RET_ONLY = ['ex_equipo', 'es_difunto', 'fecha_retiro_fmt'];
  if (id !== 'ret' && RET_ONLY.includes(SORT_BY)) {
      SORT_BY = 'nombre';
      DIR     = 'ASC';
  }

  TEAM     = id;                 // ← guarda “0”, “5”, “ret”…
  PAGE     = page;
  updateColsMenu();

  /* ► marca el ítem del sidebar */
  if (li) {
      $('#equipos-list li.sel')?.classList.remove('sel');
      li.classList.add('sel');
  }

  const url = `${API}?accion=lista&team=${TEAM}` +
              `&page=${PAGE}&per=${PER}`         +
              `&sort=${SORT_BY}&dir=${DIR}`;
  const j   = await (await fetch(url)).json();
  if (!j.ok) {               // ← respuesta con error → aborta
      toast(j.error || 'Error inesperado');
      return;
  }
  TOTAL     = j.total;
  DATA      = j.integrantes;

  refreshTable();
  buildPager();

  /* ------------- AHORA sí podemos resetear el scroll ------------- */
  if (cambioSeccion) {
    const cont = document.getElementById('section-table');
    cont.scrollLeft = 0;                     // 1ª pasada
    requestAnimationFrame(() => cont.scrollLeft = 0); // 2ª tras el repintado
  }
}

/* quita tildes, baja a minúsculas, elimina signos */
const normaliza = txt => (txt||'')
  .toLowerCase()
  .normalize("NFD").replace(/[\u0300-\u036f]/g,'')  // tildes
  .replace(/[^\w\s@.+-]/g,' ')                      // limpia rarezas
  .trim();

/* ——— decide si la fila pasa el filtro del buscador ——— */
function coincideFila (row) {
  if (!SEARCH) return true;          // sin texto → todo coincide

  /* 1. Estados de actividad (id → nombre legible) */
  const estadosTxt = [row.est1, row.est2, row.est3]      // ids
                       .map(estadoNom)                   // «Nuevo», «Activo», …
                       .join(' ');

  /* 2. Campos extra que ahora también deben buscarse */
  const extras = [
      row.dia_mes || '',          // «DD-MM»
      String(row.edad ?? ''),     // número a texto
      row.razon   || ''           // solo existe en Retirados
  ].join(' ');

  /* 3. Texto base + lo nuevo */
  const base = [
    row.nombre, row.rut_dni_fmt, row.correo,
    row.telefonos, row.ubicacion, row.direccion,
    row.profesion_oficio_estudio, row.iglesia_ministerio,
    row.ingreso, row.ultima_act,
    row.fecha_retiro, row.ex_equipo,
    estadosTxt,                   // ← NUEVO
    extras                        // ← NUEVO
  ].join(' ').toLowerCase();

  const txt = normaliza(base);     // quita tildes y signos raros

  /* todas las palabras del buscador deben aparecer */
  return SEARCH.split(/\s+/).every(p => txt.includes(p));
}

function selAdmHTML(val, uid){
  const opts = C_EST_ADM
                 .map(e => `<option value="${e.id}" ${e.id==val?'selected':''}>${e.nom}</option>`);
  return `<select class="sel-estado-adm" data-uid="${uid}">${opts.join('')}</select>`;
}

function refreshTable () {
  const cols  = visibleCols();
  const thead = $('#tbl-integrantes thead');
  const tbody = $('#tbl-integrantes tbody');
  const showStates = (TEAM !== '0' && TEAM !== 'ret' && TEAM !== 'new');

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

  if (TEAM === 'new') {                 // ⬅︎ nuevo bloque
      headHTML += '<th>Estado admisión</th>';
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
  const rows = DATA.filter(coincideFila);

  tbody.innerHTML = rows.map(r => {
    const tdCols = cols.map(k => {
        const cell = document.createElement('td');
        cell.textContent = (r[k] ?? '');
        return cell.outerHTML;
    }).join('');

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
    let acciones = `
      <button class="btn-det"  data-id="${r.id_usuario}">👁️</button>
      <button class="btn-edit" data-id="${r.id_usuario}">✏️</button>`;

    if (TEAM==='ret'){
      acciones += `
        <button class="btn-rein" data-id="${r.id_usuario}">🡒 Reingresar</button>
        <button class="btn-delusr" data-id="${r.id_usuario}">🗑️ Borrar</button>`;
    }

    if (TEAM==='new'){
        acciones+=`
            <button class="btn-rein" data-id="${r.id_usuario}">🡒 Ingresar</button>
            <button class="btn-delusr" data-id="${r.id_usuario}">🗑️ Borrar</button>`;
    }

    /* «Nuevos integrantes» NO lleva este basurero extra */
    if (TEAM !== '0' && TEAM !== 'ret' && TEAM !== 'new') {
      acciones +=
        `<button class="btn-del-eq"
                title="Eliminar de este equipo/proyecto"
                data-iep="${r.id_integrante_equipo_proyecto}"
                data-uname="${r.nombre}">🗑️</button>`;
    }

    if (TEAM === 'new'){
        fila += `<td>${selAdmHTML(r.estado_adm,r.id_usuario)}</td>`;
    }
    fila += `<td class="sticky-right">${acciones}</td></tr>`;

    return fila;
  }).join('');

  if (TEAM === 'new') {                               // ⬅︎ nuevo
      $$('.sel-estado-adm').forEach(sel => {
          paintAdmSelect(sel);                  // color inicial
          sel.onchange = e => {                 // actualiza color + guarda
              paintAdmSelect(e.target);
              updateEstadoAdm(e);
          };
      });
  }

  /* listeners */
  $$('.sel-estado').forEach(s => s.onchange = updateEstado);
  $$('.btn-det')    .forEach(b => b.onclick = openDetalle);
  $$('.btn-edit')   .forEach(b => b.onclick = openEdit);
  $$('.btn-del-eq').forEach(b => b.onclick = delVinculo);
  $$('.btn-rein').forEach(b => b.onclick = openReingreso);
  $$('.btn-delusr').forEach(b => b.onclick = eliminarDef);
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
  if (!e.target.value) return;                 // “---”  →  no hacer nada

  const fd = new FormData();
  fd.append('accion',    'estado');
  fd.append('id_iep',    e.target.dataset.iep);
  fd.append('id_estado', e.target.value);
  fd.append('id_periodo', e.target.dataset.periodo || '');

  try{
      const j = await fetchJSON(API,{method:'POST',body:fd});
      if (j.ok){
          toast('Estado guardado ✓');
      }else{
          toast(j.error || 'Error inesperado');
      }
  }catch(err){
      handleError(err);
  }
}

async function updateEstadoAdm(e){
  const fd=new FormData();
  fd.append('accion','estado_adm');
  fd.append('id_usuario',e.target.dataset.uid);
  fd.append('id_estado',e.target.value);
  try{
    const j=await fetchJSON(API,{method:'POST',body:fd});
    if(j.ok) toast('Estado guardado ✓');
    else     toast(j.error||'Error');
  }catch(err){handleError(err);}
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
  { key:'ultima_act',             label:'Última actualización',     sort:'ultima_act'},
  { key:'fecha_retiro', label:'Fecha retiro', sort:'fecha_retiro_fmt' },
  { key:'ex_equipo',    label:'Ex equipo',    sort:'ex_equipo'       },
  { key:'es_difunto',   label:'¿Difunto?',    sort:'es_difunto'      }
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
  // ⇢ solo “Nuevos integrantes” pide también los vínculos deshabilitados
  const onlyEnabled = (TEAM === 'new') ? 0 : 1;
  const j = await fetch(
          `${API}?accion=estados_anio`+
          `&id=${idUsuario}&anio=${anio}`+
          `&onlyEnabled=${onlyEnabled}`)
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

async function delVinculo (ev) {
  const iep = ev.currentTarget.dataset.iep;
  const uname = ev.currentTarget.dataset.uname;

  if (!confirm(`¿Eliminar a ${uname} de este equipo/proyecto?`)) return;

  const fd = new FormData();
  fd.append('accion', 'eliminar');
  fd.append('iep', iep);

  try{
      const j = await fetchJSON(API,{method:'POST',body:fd});
      if (j.ok){
          toast('Integrante eliminado del equipo ✓');
          selectTeam(TEAM, $('#equipos-list li.sel'), PAGE);
      }else if (j.needRetiro){
          showRetiroModal(iep, j.usuario, j.eq);
      }else{
          toast(j.error || 'Error inesperado');
      }
  }catch(err){
      handleError(err);
  }
}

function showRetiroModal (iep, nombre, exEq) {
  $('#ret-adv').innerHTML =
      `<b>${nombre}</b> ya no pertenece a ningún otro equipo. `
    + `Si continúas quedará retirado de Evangelio Creativo.`;
  $('#ret-iep').value = iep;
  /* ← reinicia campos */
  $('#form-ret textarea[name="motivo"]').value = '';
  $('#form-ret select[name="difunto"]').value  = '0';
  $('#modal-ret').classList.add('show');

  const motInp = $('#form-ret textarea[name="motivo"]');
  const difSel = $('#form-ret select[name="difunto"]');
  motInp.oninput = () => validateMotivoRet(motInp);
  difSel.onchange = () => validateDifunto(difSel);
}

$('#ret-close').onclick = ()=> hide($('#modal-ret'));
$('#ret-cancel').onclick = ()=> hide($('#modal-ret'));

$('#form-ret').onsubmit = async ev =>{
  ev.preventDefault();

  const motInp = $('#form-ret textarea[name="motivo"]');
  const difSel = $('#form-ret select[name="difunto"]');

  const motOK = validateMotivoRet(motInp);
  const difOK = validateDifunto(difSel);

  if (!motOK || !difOK){
      (motOK ? difSel : motInp).focus();
      return;                         // -- aborta envío
  }

  /* >>> CREA EL OBJETO FormData CON TODOS LOS CAMPOS DEL FORMULARIO */
  const fd = new FormData(ev.target);

  fd.append('accion','eliminar');

  try{
      const j = await fetchJSON(API,{method:'POST',body:fd});
      if (j.ok){
          toast('Integrante retirado ✓');
          hide($('#modal-ret'));
          selectTeam(TEAM, $('#equipos-list li.sel'), PAGE);
      }else{
          toast(j.error || 'Error inesperado');
      }
  }catch(err){
      handleError(err);
  }
};

async function openDetalle (e) {
  const id = e.currentTarget.dataset.id;
  const only = (TEAM === 'new') ? '&onlyEnabled=0' : '';
  const j  = await (await fetch(`${API}?accion=detalles&id=${id}${only}`)).json();
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

  /* ─── campos de admisión ─── */
  const admBox = md.querySelector('#adm-extra');
  const dl = admBox.querySelector('#det-adm');
  if (TEAM === 'new' && j.adm) {
      dl.innerHTML =
        `<dt>Experiencia liderazgo</dt><dd>${j.adm.liderazgo || '-'}</dd>
         <dt>¿Cómo nos conoció?</dt><dd>${j.adm.nos_conoces || '-'}</dd>
         <dt>Propósito</dt><dd>${j.adm.proposito || '-'}</dd>
         <dt>Motivación (1 a 5)</dt><dd>${j.adm.motivacion || '-'}</dd>`;
      admBox.style.display = 'block';
  } else {
      admBox.style.display = 'none';
      dl.innerHTML = '';
  }

  // ─── helper para escapar HTML ───
  const esc = s => String(s)
    .replace(/&/g,  '&amp;')
    .replace(/</g,  '&lt;')
    .replace(/>/g,  '&gt;')
    .replace(/"/g,  '&quot;')
    .replace(/'/g,  '&#39;');

  // array con objetos {num, desc, prim}
  const telHTML = (u.telefonos_arr || [])
    .map(t => `<div>${esc(t.num)}
                <small style="color:var(--text-muted)">
                  ${t.desc ? ' · ' + descNom(t.desc) : ''}
                  ${t.prim == 1 ? ' · <b>Principal</b>' : ''}
                </small>
              </div>`).join('');

  md.querySelector('#det-tels').innerHTML = telHTML || '-';
  md.querySelector('#det-ocup').textContent = u.ocupaciones || '-';

  if (!C_ESTADOS.length) {
    await fetch(`${API}?accion=estados`)
          .then(r=>r.json())
          .then(j=>C_ESTADOS = j.estados);
  }

  if (TEAM==='ret' || (TEAM==='new' && j.ret)){
      const ret = j.ret;            // el bloque original no cambia
      $('#det-razon').textContent     = ret.razon     || '-';
      $('#det-fallecido').textContent = ret.es_difunto ? 'Sí' : 'No';
      $('#det-exeq').textContent      = ret.ex_equipo || '-';
      const [y,m,d] = ret.fecha_retiro.split('-');
      $('#det-fretiro').textContent   = `${d}-${m}-${y}`;

      $('#retired-extra').style.display='block';
  }else{
      $('#retired-extra').style.display='none';
  }

  /* ── Tabla de estados (tres últimos periodos) ── */
  const tb      = $('#det-tab-estados tbody');
  const filasEq = Array.isArray(j.equipos) ? j.equipos : [];

  if (filasEq.length) {
      tb.innerHTML = filasEq.map(x => `
          <tr>
            <td>${x.nombre_equipo_proyecto} (${x.nombre_rol})</td>
            <td>${estadoNom(x.est3)}</td>
            <td>${estadoNom(x.est2)}</td>
            <td>${estadoNom(x.est1)}</td>
          </tr>`).join('');
      $('#estados-wrap').style.display = 'block';
  } else {
      tb.innerHTML =
          '<tr><td colspan="4" style="padding:.5rem">Sin registros</td></tr>';
      /*  ► En “Nuevos integrantes” mantenemos visible el contenedor      */
      $('#estados-wrap').style.display = (TEAM === 'new') ? 'block' : 'none';
  }

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
let IS_RET = false;

/* ------------------------------------------------ modal: editar */
async function openEdit (e) {
  try {
      const id = e.currentTarget.dataset.id;

      /* ── datos del usuario ── */
      const res = await fetch(`${API}?accion=detalles&id=` + id);
      const j   = await res.json();
      if (!j.ok) { alert(j.error || 'Error'); return; }

      /* ── catálogo de equipos (si falla no detiene el flujo) ── */
      await EQUIPOS_PROMISE.catch(() => {});

      j.user.equip_now = j.equip_now;               // conserva compatibilidad
      CURR_USER   = j;
      EQUIP_TAKEN = new Set((j.user.equip_now || []).map(r => String(r.eq)));

      fillEditForm(j.user, j.adm);   // ← pasa también los datos de admisión

      /* ── botones 🗑️ para borrar UNA respuesta ── */
      document.querySelectorAll('.btn-del-adm').forEach(btn => {
        btn.onclick = async () => {
          if (!confirm('¿Eliminar esta respuesta?')) return;

          const campo = btn.dataset.field;              // liderazgo | nos_conoces | …
          const uid   = document.getElementById('ed-id').value;

          const fd = new FormData();
          fd.append('accion',    'del_adm_campo');
          fd.append('id_usuario', uid);
          fd.append('campo',      campo);

          try{
              const j = await fetchJSON(API,{method:'POST',body:fd});
              if (j.ok){
                  toast('Respuesta eliminada ✓');
                  // ids de los <span> en el <dl>
                  const spanIds = {
                    liderazgo   : 'adm-liderazgo',
                    nos_conoces : 'adm-nosconoces',
                    proposito   : 'adm-proposito',
                    motivacion  : 'adm-motivacion'
                  };

                  document.getElementById(spanIds[campo]).textContent = '-';
              }else{
                  toast(j.error || 'Error');
              }
          }catch(err){ handleError(err); }
        };
      });

      show($('#modal-edit'));                       // ← abre el modal
  } catch (err) {
      handleError(err);                             // toast genérico
  }
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
function fillEditForm (u, adm = null) {
  /* ── determina si estamos en la sección “Nuevos integrantes” ── */
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

  const docInp = $('#ed-rut');
  docInp.setAttribute('maxlength', DOC_MAX_LEN.toString());
  docInp.oninput  = () => validateDocNumber(docInp);
  $('#ed-doc-type').onchange = () => validateDocNumber(docInp);
  validateDocNumber(docInp);                 // 1ª pasada

  /* dirección / extra */
  $('#ed-dir').value    = u.direccion              ?? '';
  $('#ed-ig').value     = u.iglesia_ministerio     ?? '';
  $('#ed-pro').value    = u.profesion_oficio_estudio ?? '';
  $('#ed-correo').value = u.correo_electronico     ?? '';

  /* cascada País → Región → Ciudad */
  populatePaises()
    .then(() => {
      $('#ed-pais').value = u.id_pais ?? '';
      return populateRegiones($('#ed-pais').value);   // ← cambio ①
    })
    .then(() => {
      $('#ed-region').value = u.id_region_estado ?? '';
      return populateCiudades($('#ed-region').value); // ← idem
    })
    .then(() => {
      $('#ed-ciudad').value = u.id_ciudad_comuna ?? '';
    });

  ['ed-pais','ed-region','ed-ciudad'].forEach(id=>{
    const s = document.getElementById(id);
    s.onchange = null;                         // ← cambio ②
    s.onchange = () => {
      validateLocSelect(s);
      if(id==='ed-pais'){
        validateLocSelect($('#ed-region'));
        validateLocSelect($('#ed-ciudad'));    // ← cambio ③
      }
      if(id==='ed-region'){
        validateLocSelect($('#ed-ciudad'));
      }
    };
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

  /* ——— sección Retirados ——— */
  const isRet = !!u.ret;
  IS_RET = isRet;
  const isAdm = (TEAM === 'new');
  $('#fs-retirados').style.display = isRet ? 'block' : 'none';
  const fsAdm = $('#fs-adm-show');
  if (fsAdm) fsAdm.style.display   = isAdm ? 'block' : 'none';
  /* ─── precarga Información de admisión ─── */
  if (isAdm) {
    const setAdm = (idSpan, val) => {
      let txt = '-';

      if (Array.isArray(val)) {                    // listas: Propósito
          txt = val.length ? val.join('; ') : '-';
      } else if (typeof val === 'string') {        // cadenas normales
          txt = val.trim() ? val : '-';
      } else if (typeof val === 'number') {        // ← NUEVO: admite números
          txt = val.toString();
      }

      document.getElementById(idSpan).textContent = txt;
    };


    setAdm('adm-liderazgo',   adm && adm.liderazgo    );
    setAdm('adm-nosconoces',  adm && adm.nos_conoces  );
    setAdm('adm-proposito',   adm && adm.proposito    );
    setAdm('adm-motivacion',  adm && adm.motivacion   );
  }

  const showEq = IS_SUPER && !isRet && !isAdm;            // MOD ADM
  $('#fs-equipos').style.display = showEq ? 'block' : 'none';
  $('#btn-add-eq').style.display  = showEq ? '' : 'none';

  ['ed-razon-ret','ed-exeq-ret','ed-difunto-ret'].forEach(id=>{
    const el = document.getElementById(id);

    if (isRet){
        el.removeAttribute('disabled');
        el.setAttribute   ('required','required');
    }else{
        el.setAttribute   ('disabled','disabled');
        el.removeAttribute('required');
    }
  });

  if (isRet){
    $('#ed-razon-ret'  ).value = u.ret.razon      || '';
    $('#ed-exeq-ret'   ).value = u.ret.ex_equipo  || '';
    $('#ed-difunto-ret').value = u.ret.es_difunto || '0';
  }

  if (isRet){
    const razInp  = $('#ed-razon-ret');
    const exeqInp = $('#ed-exeq-ret');

    razInp.oninput  = () => validateNameField(razInp);
    exeqInp.oninput = () => validateNameField(exeqInp);

    /* primera pasada */
    validateNameField(razInp);
    validateNameField(exeqInp);
  }

  /* —— listeners de validación en vivo —— */
  ['ed-nom','ed-ap','ed-am','ed-dir','ed-ig','ed-pro'].forEach(id=>{
    const el=document.getElementById(id);
    el.oninput=()=>validateNameField(el);
  });
  ['ed-nom','ed-ap','ed-am','ed-dir','ed-ig','ed-pro'].forEach(id=>
    validateNameField(document.getElementById(id))
  );

  /* —— correo —— */
  const mailInp = $('#ed-correo');
  mailInp.oninput = () => validateEmail(mailInp);
  validateEmail(mailInp);                      // primera pasada

  /* guardar la fecha de registro para el chequeo */
  const regDateStr = u.fecha_registro_fmt || '';   // «dd-mm-aaaa»

  const fnacInp = $('#ed-fnac');
  fnacInp.oninput = () => validateBirthDate(fnacInp, regDateStr);
  fnacInp.addEventListener('input', maskDateInput);   // ← NUEVO
  validateBirthDate(fnacInp, regDateStr);          // valida valor precargado
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

    /* ❶ — si dejamos “— país —” muestra el error enseguida */
    validateLocSelect(selPais);
  });

  /* País  ⇒ Tipo  +  reseteo de cascada */
  selPais.addEventListener('change', () => {
    /* sincroniza tipo de documento */
    if (selPais.value === '1' && selDoc.value !== 'CL')  selDoc.value = 'CL';
    if (selPais.value && selPais.value !== '1' && selDoc.value !== 'INT')
        selDoc.value = 'INT';

    /* si queda en blanco, vacía los descendientes */
    if (!selPais.value) {
        $('#ed-region').innerHTML = '<option value=""></option>';
        $('#ed-ciudad').innerHTML = '<option value=""></option>';
    }

    /* ← NUEVO: (re)carga siempre las regiones del país actual,
                incluso si acaba de volver de “— país —”            */
    populateRegiones(selPais.value);
  });
}

/* catálogo Regiones según país ---------------------------------------- */
async function populateRegiones (idPais) {
  const selReg = $('#ed-region');
  const selCiu = $('#ed-ciudad');

  /* país vacío ⇒ limpia y sal  */
  if (!idPais){
      selReg.innerHTML = '<option value=""></option>';
      selCiu.innerHTML = '<option value=""></option>';
      validateLocSelect($('#ed-pais'));   // ❷ — marca “* Obligatorio”
      return;
  }

  /* capturamos el país que *disparó* esta petición  */
  const paisSolicitado = idPais;

  const j = await (await fetch(`${API}?accion=regiones&pais=`+idPais)).json();

  /* si el usuario YA cambió otra vez de país, abortamos */
  if ($('#ed-pais').value !== paisSolicitado) return;

  selReg.innerHTML =
      '<option value="">— región —</option>' +
      j.regiones.map(r => `<option value="${r.id}">${r.nom}</option>`).join('');
  selReg.value = '';
  selCiu.innerHTML = '<option value=""></option>';

  validateLocSelect($('#ed-pais'));   // ❸ — borra el error al corregir

  /* handler solo una vez */
  selReg.onchange = e => populateCiudades(e.target.value);
}

/* catálogo Ciudades según región -------------------------------------- */
async function populateCiudades (idRegion) {
  const sel = $('#ed-ciudad');

  if (!idRegion){
      sel.innerHTML = '<option value="">—</option>';
      return;
  }

  const regionSolicitada = idRegion;

  const j = await (await fetch(`${API}?accion=ciudades&region=`+idRegion)).json();

  /* si el usuario cambió de región antes de que llegara la respuesta, ignora */
  if ($('#ed-region').value !== regionSolicitada) return;

  sel.innerHTML =
      '<option value="">— ciudad —</option>' +
      j.ciudades.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
}

/* descripción teléfonos ------------------------------------------------ */
async function populatePhoneDescs () {
  /* evita volver a cargar si ya están los <option> */
  if ($('[name="tel_desc0"]').options.length) return;

  const j    = await (await fetch(`${API}?accion=desc_telefonos`)).json();
  const opts = j.descs
                 .map(d => `<option value="${d.id}">${d.nom}</option>`)
                 .join('');

  ['tel_desc0','tel_desc1','tel_desc2'].forEach(n => {
      const sel = $(`[name="${n}"]`);

      /* lista de opciones */
      sel.innerHTML =
          '<option value="">— descripción —</option>' + opts;

      /* ── NUEVO: re-valida la fila al cambiar la descripción ── */
      sel.onchange = () => validatePhoneRows();
  });

  /* ── NUEVO: chequeo inmediato por si ya había datos cargados ── */
  validatePhoneRows();
}

/* ocupaciones ---------------------------------------------------------- */
async function populateOcupaciones () {
  const j   = await (await fetch(`${API}?accion=ocupaciones`)).json();
  const list = j.ocupaciones.slice();                // copia editable

  /* ── fuerza que “Sin ocupación actual” quede al final ── */
  const idxNone = list.findIndex(o => /^Sin ocupación/i.test(o.nom));
  if (idxNone !== -1) {
      const [none] = list.splice(idxNone, 1);        // lo quitamos
      list.push(none);                               // …y lo añadimos al final
  }

  /* — id real de “Sin ocupación actual” — */
  if (window.NONE_OCUP_ID === undefined) {
      const none = list.find(o => /^Sin ocupación/i.test(o.nom));
      /*  Si aún no existe la fila creamos el chip sin id; el back-end
          la insertará automáticamente al guardar.                      */
      window.NONE_OCUP_ID = none ? none.id : null;      // ← sin “plan B” local
  }

  /* este bloque se ejecutará **una sola vez** */
  const cont = $('#ocup-container');
  if (!cont._listenerAdded) {
      cont.addEventListener('change', e => {
          const chk = e.target;
          if (chk.type !== 'checkbox') return;

          const noneInp = cont.querySelector(
                           `input[name="ocup_${window.NONE_OCUP_ID}"]`);

          if (!noneInp) return;

          if (chk === noneInp && chk.checked) {          // se marcó “Sin ocupación”
              cont.querySelectorAll('input[type="checkbox"]').forEach(c => {
                  if (c !== noneInp) c.checked = false;
              });
          } else if (chk !== noneInp && chk.checked) {   // se marcó otra cualquiera
              noneInp.checked = false;
          }
      });
      cont._listenerAdded = true;
  }

  return list;               //  ← ¡IMPORTANTE!
}

async function addEqRow () {
  if (!IS_SUPER) return;

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

  // error placeholder (necesario para inline)
  const err = document.createElement('small');
  err.className = 'err-msg';
  row.appendChild(err);

  /* listeners que re-validan de inmediato */
  selEq.addEventListener ('change', () => {
      loadRolesInto(selRol, selEq.value);
      validateEqRows();                 // ← NUEVO
  });
  selRol.addEventListener('change', validateEqRows);   // ← NUEVO

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

async function openReingreso(e){
   const uid = e.currentTarget.dataset.id;
   $('#modal-rein').dataset.uid = uid;

   /* ─── NUEVO: título y mensaje según la sección de origen ─── */
   const fromNew = (TEAM === 'new');             // true = “Nuevos integrantes”
   $('#modal-rein h2').textContent = fromNew
       ? 'Ingresar usuario'
       : 'Reingresar usuario';

   $('#modal-rein p').textContent = fromNew
       ? '¿Seguro que quieres ingresar a este usuario?'
       : '¿Seguro que quieres reingresar a este usuario?';
   /* ────────────────────────────────────────────────────────── */

   /* carga combos */
   const d  = await fetchJSON(API+'?accion=equipos');
   /*  solo equipos reales  (es_equipo = 1)  */
   $('#rein-eq').innerHTML = d.equipos
        .filter(x=>x.id && x.id!=='ret' && x.es_equipo==1)
        .map(x=>`<option value="${x.id}">${x.nombre}</option>`).join('');
   $('#rein-rol').innerHTML='';         // vacía primero
   $('#rein-eq').onchange = () =>
        loadRolesInto($('#rein-rol'), $('#rein-eq').value,'',false);

   $('#rein-eq').dispatchEvent(new Event('change'));
   show($('#modal-rein'));
}

$('#rein-close').onclick=$('#rein-cancel').onclick=()=>hide($('#modal-rein'));

$('#rein-ok').onclick = async ()=>{
   const uid  = $('#modal-rein').dataset.uid;
   const eq   = $('#rein-eq').value;
   const rol  = $('#rein-rol').value;
   if(!eq||!rol) return alert('Debes escoger equipo y rol');

   const fd = new FormData();
   fd.append('accion', TEAM==='new' ? 'ingresar' : 'reingresar');
   fd.append('id_usuario',uid);
   fd.append('id_equipo',eq);
   fd.append('id_rol',rol);
   const j = await fetchJSON(API,{method:'POST',body:fd});
   if (j.ok) {
      /* ¿desde qué sección se disparó la acción? ----------------------- */
      const wasNew = (TEAM === 'new');   // true → “Nuevos”, false → “Retirados”

      toast(wasNew ? 'Usuario ingresado ✓' : 'Usuario reingresado ✓');
      hide($('#modal-rein'));

      /* refresca el panel lateral (contadores + posibles cambios) ------ */
      await loadSidebar();

      /* vuelve a la misma sección en la que estabas -------------------- */
      const targetId = wasNew ? 'new' : 'ret';
      const liTarget = document.querySelector(`#equipos-list li[data-id="${targetId}"]`);
      if (liTarget) selectTeam(targetId, liTarget, 1);  // recarga tabla (pág. 1)
   } else {
      toast(j.error || 'Error');
   }
};

let DEL_UID=0;
function eliminarDef(e){
   DEL_UID = e.currentTarget.dataset.id;
   show($('#modal-del'));
}
$('#del-close').onclick=$('#del-cancel').onclick=()=>hide($('#modal-del'));
$('#del-ok').onclick=async()=>{
   const fd=new FormData();
   fd.append('accion','delete_user');
   fd.append('id_usuario',DEL_UID);
   const j=await fetchJSON(API,{method:'POST',body:fd});
   if(j.ok){
     toast('Usuario eliminado');
     hide($('#modal-del'));
     /* permanece en la sección donde estabas */
     selectTeam(TEAM, $(`#equipos-list li[data-id="${TEAM}"]`), PAGE);
   }else toast(j.error||'Error');
};

async function submitEdit (ev) {
  ev.preventDefault();

  /* 👉 aborta si algún teléfono no pasa la validación */
  if (!(await validateAndNormalizePhones())) return;

  // ► valida los campos de texto
  const nameOK  = validateNameField($('#ed-nom'));
  const apOK    = validateNameField($('#ed-ap'));
  const amOK    = validateNameField($('#ed-am'));
  const dirOK   = validateNameField($('#ed-dir'));
  const igOK    = validateNameField($('#ed-ig'));
  const proOK   = validateNameField($('#ed-pro'));
  const mailOK  = validateEmail       ($('#ed-correo'));

  if (!nameOK || !apOK || !amOK || !dirOK || !igOK || !proOK || !mailOK){
      const firstBad = $('.invalid');
      firstBad?.scrollIntoView({behavior:'smooth', block:'center'});
      firstBad?.focus({preventScroll:true});
      return;                                     // aborta envío
  }

  const fnacOK = validateBirthDate($('#ed-fnac'),
                                  CURR_USER.user.fecha_registro_fmt||'');

  if (!fnacOK){
      const firstBad = $('.invalid');
      if (firstBad){
          const box = $('#modal-edit .modal-box');      // contenedor con scrollbar
          /* distancia entre el campo con error y la parte superior del contenedor,
            teniendo en cuenta el desplazamiento actual (scrollTop) */
          const y = firstBad.getBoundingClientRect().top
                  - box.getBoundingClientRect().top
                  + box.scrollTop
                  - (box.clientHeight / 2);             // lo deja ± centrado

          box.scrollTo({ top: y, behavior: 'smooth' }); // ← animación real
          setTimeout(() => firstBad.focus({preventScroll:true}), 500);
      }
      return;          // ⟵ no envía
  }

  const docOK = validateDocNumber($('#ed-rut'));
  if (!docOK){
    const firstBad = $('.invalid');
    firstBad?.scrollIntoView({behavior:'smooth', block:'center'});
    firstBad?.focus({preventScroll:true});
    return;                                     // aborta envío
  }

  /* normaliza (números+K) antes de empaquetar */
  $('#ed-rut').value = $('#ed-rut').value.toUpperCase().replace(/[.\-]/g, '');

  if (IS_RET){
      const razonOK = validateNameField($('#ed-razon-ret'));  // 255 máx
      const exeqOK  = validateNameField($('#ed-exeq-ret'));   // 50 máx

      if (!razonOK || !exeqOK){
          const bad = $('#fs-retirados .invalid');
          bad?.scrollIntoView({behavior:'smooth',block:'center'});
          bad?.focus({preventScroll:true});
          return;                        // aborta el submit
      }
  }

  const paisOK   = validateLocSelect($('#ed-pais'));
  const regOK    = validateLocSelect($('#ed-region'));
  const cityOK   = validateLocSelect($('#ed-ciudad'));

  if (!paisOK || !regOK || !cityOK){
      const firstBad = $('.invalid');
      if (firstBad){
          firstBad.scrollIntoView({behavior:'smooth',block:'center'});
          firstBad.focus({preventScroll:true});
      }
      return;      // aborta el submit
  }

  /* —— confirmación si cambió el correo —— */
  const correoInp  = $('#ed-correo');
  const origCorreo = (CURR_USER?.user?.correo_electronico || '').trim();
  if (correoInp.value.trim() !== origCorreo){
      const ok = confirm(
          'Has cambiado el correo electrónico.\n' +
          'Recuerda que este dato se usa para iniciar sesión.\n\n' +
          '¿Confirmas el cambio?');
      if (!ok) return;           // el usuario cancela
  }

  const fd = new FormData(ev.target);      // ahora sí incluye "+56…"
  fd.append('accion', 'editar');

  if (!IS_RET) {
    ['razon_ret', 'ex_equipo_ret', 'es_difunto_ret']
      .forEach(k => fd.delete(k));
  }

  /* —— Equipos / Proyectos: al menos equipo + rol en cada fila —— */
  if (IS_SUPER && !validateEqRows()) {
      const firstBad = $('#eq-container .invalid');
      if (firstBad) {
          firstBad.scrollIntoView({behavior:'smooth', block:'center'});
          firstBad.focus({preventScroll:true});
      }
      return;                              // ← cancela el submit
  }

  /* empaquetar equipos nuevos (solo para super) */
  let arr = [];
  if (IS_SUPER) {
    arr = [...$$('.eq-row')].map(r => {
        const [selEq, selRol] = r.querySelectorAll('select');
        const e  = selEq ? selEq.value : '';
        const rl = selRol ? selRol.value : '';
        return e && rl ? { eq: e, rol: rl } : null;
    }).filter(Boolean);

    /* solo los super envían cambios de equipo */
    fd.append('equip', JSON.stringify(arr));
  }

  /* empaquetar ocupaciones */
  const ocIds = [...$$('#ocup-container input[type="checkbox"]')]
                    .filter(c => c.checked)
                    .map   (c => Number(c.name.replace('ocup_','')));
  fd.append('ocup', JSON.stringify(ocIds));

  // en submitEdit(), justo antes del fetch:
  if (ocIds.length === 0)
    toast('No se seleccionó ocupación: se asignará “Sin ocupación actual”.');

  try{
      const j = await fetchJSON(API,{method:'POST',body:fd});
      if (j.ok){
          toast('Cambios guardados ✓');
          hide($('#modal-edit'));
          await loadSidebar();
          selectTeam(TEAM, $(`#equipos-list li[data-id="${TEAM}"]`), 1);
      }else{
          /* ❷ — ERRORES SQL ÚNICOS → inline */
          const errTxt = j.error || '';

          /* duplicado rut_dni ─ muestra error en #ed-rut */
          if (/Duplicate entry .* for key 'rut_dni'/i.test(errTxt)){
              const inp  = $('#ed-rut');
              const box  = inp.parentElement.querySelector('.err-msg');
              box.textContent   = '* RUT / DNI ya registrado';
              box.style.display = 'block';
              inp.classList.add('invalid');

              /* scroll & foco suave */
              inp.scrollIntoView({behavior:'smooth',block:'center'});
              setTimeout(()=> inp.focus({preventScroll:true}),400);
              return;                               // ← sin toast genérico
          }

          /* falta país (id_pais NULL) ─ muestra error en #ed-pais */
          if (/foreign key constraint fails .*`id_pais`/i.test(errTxt)){
              const sel  = $('#ed-pais');
              const box  = sel.parentElement.querySelector('.err-msg');
              box.textContent   = '* Selecciona un país';
              box.style.display = 'block';
              sel.classList.add('invalid');

              sel.scrollIntoView({behavior:'smooth',block:'center'});
              setTimeout(()=> sel.focus({preventScroll:true}),400);
              return;                               // ← sin toast genérico
          }

          toast(j.error || 'Error inesperado');
      }
  }catch(err){
      handleError(err);
  }
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

/* ─── mostrar/ocultar checks según el TEAM activo ─── */
function updateColsMenu () {
  const soloRet = TEAM === 'ret';
  ['fecha_retiro', 'ex_equipo', 'es_difunto'].forEach(k => {
    const cb     = document.getElementById('chk_' + k);   // <input>
    if (!cb) return;
    const label  = cb.parentElement;                      // <label>

    if (soloRet) {
      label.style.display = '';
    } else {
      cb.checked = false;          // fuerza a quitar la selección
      label.style.display = 'none';
    }
  });

  if (!IS_SUPER){
    document.querySelector('[data-id="0"]')?.remove();   // General
    document.querySelector('[data-id="ret"]')?.remove(); // Retirados
  }
}

/* ------------------------------------------------ init */
document.addEventListener('DOMContentLoaded', () => {
  buildColMenu();
  updateColsMenu();

  loadSidebar().then(() => {
      /*  ACL  — elige la primera opción realmente visible al usuario */
      const firstLi = document.querySelector('#equipos-list li');
      if (!firstLi) return;                   // no hay secciones
      const firstId = firstLi.dataset.id;     // «0», «ret», «5», …
      selectTeam(firstId, firstLi, 1);

      /* solo usamos la precarga si esa primera sección es “0 = General” */
      if (firstId === '0' && typeof PRE_INTEGRANTES !== 'undefined'){
          DATA = PRE_INTEGRANTES;
          refreshTable();
      }
  });

  if (typeof PRE_INTEGRANTES !== 'undefined'){
      DATA = PRE_INTEGRANTES;
      refreshTable();          // pinta de inmediato
  }
  syncPaisDoc();
  /* —— habilita / deshabilita “Otros” dinámicos —— */
  otroNos   = document.getElementById('ed-nos-otro');
  otroPropo = document.getElementById('ed-prop-otro');

  [otroNos, otroPropo].forEach(inp =>
      inp?.addEventListener('input', () => validateNameField(inp)));

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
