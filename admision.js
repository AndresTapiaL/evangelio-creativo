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

if (searchBox) {
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
}

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

/* ========= Scroll suave al primer campo con error ========= */
function scrollToFirstInvalid(){
  const bad = document.querySelector('.invalid');
  if(!bad) return;
  bad.scrollIntoView({behavior:'smooth', block:'center'});
  setTimeout(()=>bad.focus({preventScroll:true}), 400);   // enfoca sin “saltos”
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
      sel.classList.toggle('invalid', !!msg);
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

function validatePhoneRows () {
  let ok = true;
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
  }
  return ok;
}

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

// ——— envío del formulario de admisión ———
document.getElementById('form-admision').onsubmit = async ev => {
  ev.preventDefault();

  // ► validaciones; re-usa exactamente las mismas que integran el modal
  if (!validateNameField(ev.target.nombres)           ||
      !validateNameField(ev.target.apellido_paterno)  ||
      !validateBirthDate (ev.target.fecha_nacimiento) ||
      !validateDocNumber (ev.target.rut_dni)          ||
      !validateEmail     (ev.target.correo)           ||
      !validatePhoneRows()                            ||
      !validateNameField(ev.target.direccion)          ||
      !validateNameField(ev.target.iglesia_ministerio) ||
      !validateNameField(ev.target.profesion_oficio_estudio) ||
      !validateLocSelect (ev.target.id_pais)          ||
      !validateLocSelect (ev.target.id_region_estado) ||
      !validateLocSelect (ev.target.id_ciudad_comuna)
  ){
      scrollToFirstInvalid();   // ← NUEVO
      return;                   // ↩ hay errores → no se envía
  }

  // telefónos normalizados (+E.164)
  if (!(await validateAndNormalizePhones())){
      scrollToFirstInvalid();   // ← NUEVO
      return;
  }

  const fd = new FormData(ev.target);
  /* ocupaciones marcadas → JSON */
  const ocupIds = [...document.querySelectorAll('#ocup-container input[type="checkbox"]:checked')]
                  .map(c=>parseInt(c.value,10));
  fd.append('ocup', JSON.stringify(ocupIds));

  fd.append('accion','nuevo');               // ← acción del API

  try{
    const res = await fetch('admision_api.php',{method:'POST',body:fd});
    const j   = await res.json();
    if(j.ok){
      toast('¡Gracias! Registro recibido ✓',4000);
      ev.target.reset();
    }else{
      toast(j.error||'Error inesperado');
    }
  }catch(err){
    toast('Servidor ocupado. Intenta de nuevo');
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
  /*  solo aborta si ya hay     *más de* la opción-placeholder        */
  if ($('#ed-pais').options.length > 1) return;
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
  if ($('[name="tel_desc0"]').options.length) return;
  const j = await (await fetch(`${API}?accion=desc_telefonos`)).json();
  const opts = j.descs.map(d => `<option value="${d.id}">${d.nom}</option>`).join('');
  ['tel_desc0','tel_desc1','tel_desc2']
    .forEach(n => $(`[name="${n}"]`).innerHTML =
        '<option value="">— descripción —</option>' + opts);
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

function renderOcupaciones(list){
  const cont = document.getElementById('ocup-container');
  cont.innerHTML = '';
  list.forEach(o=>{
    const lbl  = document.createElement('label');
    lbl.style.display = 'inline-block';
    lbl.style.marginRight = '1rem';

    const inp  = document.createElement('input');
    inp.type  = 'checkbox';
    inp.name  = `ocup_${o.id}`;
    inp.value = o.id;

    lbl.appendChild(inp);
    lbl.append(' '+o.nom);
    cont.appendChild(lbl);
  });
}

document.addEventListener('DOMContentLoaded',()=>{
  populatePaises();          // catálogo Países
  syncPaisDoc();             // país ↔ tipo doc
  populatePhoneDescs();      // descripciones de teléfono
  populateOcupaciones().then(renderOcupaciones);
  initIntlTelInputs();       // intl-tel-input

  /* ─── validación inmediata mientras el usuario escribe ─── */

  /* 1. selects de ubicación  */
  ['ed-pais','ed-region','ed-ciudad'].forEach(id=>{
    const sel = document.getElementById(id);
    if (sel) sel.addEventListener('change', ()=> validateLocSelect(sel));
  });

  /* 2. campos de texto generales */
  ['nombres','apellido_paterno','apellido_materno',
   'direccion','iglesia_ministerio','profesion_oficio_estudio']
  .forEach(name=>{
    const inp = document.querySelector(`[name="${name}"]`);
    if (inp)  inp.addEventListener('input', ()=> validateNameField(inp));
  });

  /* 3. correo electrónico */
  const mail = document.querySelector('[name="correo"]');
  if (mail) mail.addEventListener('input', ()=> validateEmail(mail));

  /* 4. fecha de nacimiento */
  const fnac = document.querySelector('[name="fecha_nacimiento"]');
  if (fnac){
    fnac.addEventListener('input', maskDateInput);          // limita la máscara
    fnac.addEventListener('input', ()=> validateBirthDate(fnac));
  }

  /* 5. Nº documento (RUT / DNI) */
  const docInp = document.getElementById('rut');
  if (docInp){
    docInp.addEventListener('input', ()=> validateDocNumber(docInp));
    document.getElementById('ed-doc-type')
            .addEventListener('change', ()=> validateDocNumber(docInp));
  }
});

/* —— Color dinámico para <select> sin atributo required —— */
document.querySelectorAll('select').forEach(sel=>{
  const setColor=()=>{          // negro ↔ color normal
    sel.style.color = sel.value ? '#000' : 'var(--ph-light)';
  };
  setColor();                   // inicial
  sel.addEventListener('change', setColor);
});
