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
/* —— “Otros” del cuestionario ———————————————— */
let otroNos, otroPropo;            // se asignan al cargar el DOM

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
function scrollToFirstInvalid () {
  const bad = document.querySelector('.invalid');
  if (!bad) return;
  /* desplaza en vez de “saltar” */
  bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
  /* 👇  se quitó el   bad.focus()   para que el usuario no quede “atrapado” */
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
  const val   = sel.value.trim();
  const errBx = sel.parentElement.querySelector('.err-msg');
  let   msg   = '';

  /* ── ① País obligatorio ─────────────────────────── */
  if (sel.id === 'ed-pais' && val === '') {
      msg = '* Obligatorio';
  }

  /* ── ② Opción inexistente ───────────────────────── */
  if (!msg && val && ![...sel.options].some(o => o.value === val)){
      msg = '* Opción no válida';
  }

  /* ── ③ Jerarquía básica ─────────────────────────── */
  if (!msg && sel.id === 'ed-region' && val && !$('#ed-pais').value){
      msg = '* Primero selecciona País';
  }
  if (!msg && sel.id === 'ed-ciudad' && val && !$('#ed-region').value){
      msg = '* Primero selecciona Región / Estado';
  }

  /* feedback visual + scroll suave ↓↓↓ */
  if (msg){
      errBx.textContent   = msg;
      errBx.style.display = 'block';
      sel.classList.add('invalid');
      scrollToFirstInvalid();            // ya existe en tu código
      return false;
  }
  errBx.textContent   = '';
  errBx.style.display = 'none';
  sel.classList.remove('invalid');
  return true;
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

/* —— VALIDACIÓN Teléfonos (formato + duplicados) —— */
function validatePhoneRows () {
  let dupTarget = null;      // recordará la 1ª fila duplicada
  let ok      = true;
  const seen  = new Set();                      // detecta números repetidos

  for (let i = 0; i < 3; i++) {
    const row  = document.querySelectorAll('.phone-row')[i];
    const inp  = row.querySelector('input.tel');
    const sel  = row.querySelector('select');
    const val  = inp.value.trim();              // nº digitado
    const desc = sel.value.trim();              // descripción elegida

    /*  crea (o reutiliza) la cajita de error  */
    let err = row.querySelector('.err-msg');
    if (!err){
      err           = document.createElement('small');
      err.className = 'err-msg';
      row.appendChild(err);
    }
    let msg = '';

    /* ————————————————————————————————————————————————
       1) Validaciones de formato y coherencia
       ———————————————————————————————————————————————— */
    if (val){
        /* quita todo salvo dígitos para comparar duplicados */
        const digits = val.replace(/\D/g, '');

        /* A) formato internacional + prefijo coherente */
        const iti   = inp._iti;
        const data  = iti ? iti.getSelectedCountryData() : null;
        const iso   = data ? data.iso2 : '';
        const pref  = data ? data.dialCode : '';

        if (!digits.startsWith(pref)){
            msg = '* Selecciona un prefijo real';
        } else {
            const restLen   = digits.length - pref.length;
            const minSubscr = MOBILE_MIN_ES[iso] ?? 8;
            const maxSubscr = MOBILE_MAX_ES[iso] ?? 15;

            if (restLen < minSubscr){
                const paisNom = COUNTRY_ES[iso] || iso.toUpperCase();
                msg = `* Mínimo ${minSubscr} dígitos para ${paisNom}`;
            } else if (restLen > maxSubscr){
                msg = `* Máx ${maxSubscr} dígitos para ${iso.toUpperCase()}`;
            } else if (!PHONE_RE.test(val)){
                msg = '* Solo “+” y dígitos';
            }
        }

        /* B) duplicados en los tres campos */
        if (!msg && seen.has(digits)){
            msg = '* Número duplicado';
            dupTarget = dupTarget || inp;
        } else {
            seen.add(digits);
        }

        /* C) contigüidad: Teléfono 2 o 3 no pueden “saltarse” un espacio */
        if (!msg && val && i > 0) {                    // i = 1 → Teléfono 2…
            const prevVal = document
                .querySelectorAll('.phone-row')[i - 1] // fila anterior
                .querySelector('input.tel').value.trim();

            if (!prevVal) {
                /*  i es 0-based; para el mensaje lo convertimos a 1-based  */
                const ant = i;       // Teléfono 1, 2…
                const act = i + 1;   // Teléfono 2, 3…
                msg = `* Completa Teléfono ${ant} antes de Teléfono ${act}`;
            }
        }

        /* D) coherencia con descripción */
        if (!msg && !desc) msg = '* Falta descripción';

    } else if (desc){
        /* nº vacío  → descripción no permitida          */
        msg = '* Ingresa número o quita descripción';
    }

    /* ————————————————————————————————————————————————
       2) Feedback visual
       ———————————————————————————————————————————————— */
    if (msg){
        err.textContent   = msg;
        err.style.display = 'block';
        inp.classList.add('invalid');  sel.classList.add('invalid');
        ok = false;
    }else{
        err.textContent   = '';
        err.style.display = 'none';
        inp.classList.remove('invalid'); sel.classList.remove('invalid');
    }
  }

  /* desplaza suavemente al primer error */
  if (!ok) scrollToFirstInvalid();
  if (!ok && dupTarget){
      dupTarget.scrollIntoView({behavior:'smooth', block:'center'});
  }
  return ok;
}

/* —— Cuestionario —— */
function validateRadioGroup(name){
  const radios = document.querySelectorAll(`input[name="${name}"]`);
  if (!radios.length) return true;                   // nada que validar

  /* ── contenedor seguro ───────────────────────────────────────────── */
  const container =                                   // preferible ⇩
        radios[radios.length - 1].closest('.q-field,.field')
     || radios[radios.length - 1].parentElement;      // plan-B seguro

  /* cajita de error — la crea si no existe */
  let box = container.querySelector('.err-msg');
  if (!box){
      box = document.createElement('small');
      box.className   = 'err-msg';
      container.appendChild(box);
  }

  const ok = [...radios].some(r => r.checked);        // ¿hay alguna marcada?

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
  const chk  = cont.querySelectorAll('input[type="checkbox"]');

  /* crea la cajita de error sólo si no existe */
  let box = cont.parentElement.querySelector('.err-msg');
  if (!box){
      box = document.createElement('small');
      box.className = 'err-msg';
      cont.parentElement.appendChild(box);
  }
  const ok   = [...chk].some(c=>c.checked);
  if(!ok){
      box.textContent   = '* Selecciona al menos una opción';
      box.style.display = 'block';
  }else{
      box.textContent   = '';
      box.style.display = 'none';
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
      !validateNameField(ev.target.direccion)         ||
      !validateNameField(ev.target.iglesia_ministerio)||
      !validateNameField(ev.target.profesion_oficio_estudio) ||
      !validateLocSelect (ev.target.id_pais)          ||
      !validateLocSelect (ev.target.id_region_estado) ||
      !validateLocSelect (ev.target.id_ciudad_comuna) ||
      !validateNameField(ev.target.liderazgo)         ||
      !validateRadioGroup('nos_conoces')              ||
      !validateCheckGroup('q-proposito')              ||
      !validateRadioGroup('motivacion')               ||
      !(otroNos.disabled   || validateNameField(otroNos))   ||
      !(otroPropo.disabled || validateNameField(otroPropo))
  ){
      scrollToFirstInvalid();
      return;
  }

  // teléfonos normalizados (+E.164)
  if (!(await validateAndNormalizePhones())){
      scrollToFirstInvalid();
      return;
  }

  /* —— compila respuestas del cuestionario —— */
  const nosSel = document.querySelector('input[name="nos_conoces"]:checked');
  let   nosVal = nosSel ? nosSel.value : '';
  if(nosVal==='Otros') nosVal = (otroNos.value.trim() || 'Otros');

  const propositos = [...document.querySelectorAll('#q-proposito input[type="checkbox"]:checked')]
                      .map(c=> c.value==='Otros'
                              ? (otroPropo.value.trim() || 'Otros')
                              : c.value)
                      .join('; ');

  const motivVal = document.querySelector('input[name="motivacion"]:checked')?.value || '';

  /* crea/actualiza campos ocultos para enviarlos */
  [['nos_conoces',nosVal],['proposito',propositos],['motivacion',motivVal]]
    .forEach(([name,val])=>{
        let h = ev.target.querySelector(`input[type="hidden"][name="${name}"]`);
        if(!h){
            h = document.createElement('input');
            h.type = 'hidden';
            h.name = name;
            ev.target.appendChild(h);
        }
        h.value = val;
    });

  const fd = new FormData(ev.target);
  /* ocupaciones marcadas → JSON */
  const ocupIds = [...document.querySelectorAll('#ocup-container input[type="checkbox"]:checked')]
                  .map(c=>parseInt(c.value,10));
  fd.append('ocup', JSON.stringify(ocupIds));

  fd.append('accion','nuevo');      // ← acción del API

  try{
    const res = await fetch('admision_api.php',{method:'POST',body:fd});

    /* —── leemos texto bruto —── */
    const raw = await res.text();
    let j;
    try{
        j = JSON.parse(raw);
    }catch(parseErr){
        console.error('Respuesta no-JSON ►', raw);
        toast('Respuesta del servidor no válida');
        return;
    }

    /* ────────────────────────────────
       ► SECCIÓN MODIFICADA ◄
    ──────────────────────────────── */
    if (j.ok){
        toast('¡Gracias! Registro recibido ✓',4000);
        ev.target.reset();

    } else {

        /* A) error inline “país” (obligatorio / inválido) */
        if (j.error && j.error.toLowerCase().includes('país')){
            const selPais = document.getElementById('ed-pais');
            const errBx   = selPais.parentElement.querySelector('.err-msg');

            errBx.textContent   = j.error;      // «El país es obligatorio», etc.
            errBx.style.display = 'block';
            selPais.classList.add('invalid');

            scrollToFirstInvalid();             // desplazamiento suave
            return;                             // ← SIN toast
        }

        /* B) error inline “correo ya en uso” */
        if (j.error &&
            j.error.toLowerCase().includes('correo') &&
            j.error.toLowerCase().includes('uso')){
            const mailInp = document.querySelector('[name="correo"]');
            const errBx   = mailInp.parentElement.querySelector('.err-msg');

            errBx.textContent   = j.error;   // «Ese correo electrónico ya está en uso»
            errBx.style.display = 'block';
            mailInp.classList.add('invalid');

            scrollToFirstInvalid();          // desplazamiento suave
            return;                          // ← SIN toast
        }

        /* C) error inline “Teléfono …” (contigüidad o descripción) */
        if (j.error && /Tel[eé]fono\s+(\d+)/i.test(j.error)){
            /*  Puede haber dos números en el mensaje (“…Teléfono 1 … Teléfono 2”).
                Tomamos la ÚLTIMA ocurrencia para apuntar al campo que el usuario
                estaba editando (normalmente el que disparó el error).             */
            const mAll = [...j.error.matchAll(/Tel[eé]fono\\s+(\\d+)/gi)];
            const idx  = mAll.length
                          ? parseInt(mAll[mAll.length - 1][1], 10) - 1   // 0-based
                          : 0;
            const row = document.querySelectorAll('.phone-row')[idx];
            if (row){
                const inp = row.querySelector('input.tel');
                /* cajita de error (crea solo si no existe) */
                let err = row.querySelector('.err-msg');
                if (!err){
                    err = document.createElement('small');
                    err.className = 'err-msg';
                    row.appendChild(err);
                }
                err.textContent   = j.error;      // mensaje del back-end
                err.style.display = 'block';
                inp.classList.add('invalid');

                scrollToFirstInvalid();           // desliza suave al campo
            }
            return;                               // ← SIN toast
        }

        /* D) documento duplicado (se mantiene toast) */
        if (j.error && j.error.includes('ya está registrado')){
            const doc = document.getElementById('rut');
            if (doc){
                doc.classList.add('invalid');
                scrollToFirstInvalid();
            }
            toast(j.error);
            return;
        }

        /* E) cualquier otro error genérico */
        toast(j.error || 'Error inesperado');
    }
    /* ────────────────────────────────
       ► FIN SECCIÓN MODIFICADA ◄
    ──────────────────────────────── */

  }catch(err){
    toast('Error de red: '+err.message);
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

  /* ——— refresca la validación cuando el usuario elige la descripción ——— */
  document.querySelectorAll('#phone-container select')
          .forEach(sel => sel.addEventListener('change', () => validatePhoneRows()));

  /* ─── validación inmediata mientras el usuario escribe ─── */

  /* 1. selects de ubicación  */
  ['ed-pais','ed-region','ed-ciudad'].forEach(id=>{
    const sel = document.getElementById(id);
    if (sel) sel.addEventListener('change', ()=> validateLocSelect(sel));
  });

  /* 2. campos de texto generales */
  ['nombres','apellido_paterno','apellido_materno',
   'direccion','iglesia_ministerio','profesion_oficio_estudio','liderazgo']
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

  /* ——— habilita / deshabilita “Otros” dinámicos ——— */
  otroNos   = document.getElementById('nos_conoces_otro');
  otroPropo = document.getElementById('propo_otro');

  /* ① valida en vivo los textos “Otros” */        // ← NUEVO
  [otroNos, otroPropo].forEach(inp =>              // ← NUEVO
      inp.addEventListener('input', () => validateNameField(inp))); // ← NUEVO

  document.querySelectorAll('input[name="nos_conoces"]').forEach(r=>{
    r.addEventListener('change',()=>{
        if(r.value==='Otros'){
            otroNos.disabled = !r.checked;
            if(r.checked) otroNos.focus();
        }else{
            otroNos.value   = '';
            otroNos.disabled= true;
        }
        validateRadioGroup('nos_conoces');
    });
  });

  document.getElementById('propo_otro_chk')
          .addEventListener('change',e=>{
              otroPropo.disabled = !e.target.checked;
              if(!e.target.checked) otroPropo.value = '';
              validateCheckGroup('q-proposito');
          });

  /* validación viva */
  ['motivacion'].forEach(n=>{
    document.querySelectorAll(`input[name="${n}"]`)
            .forEach(r=>r.addEventListener('change',
                      ()=>validateRadioGroup(n)));
  });
  document.querySelectorAll('#q-proposito input[type="checkbox"]')
          .forEach(c=>c.addEventListener('change',
                    ()=>validateCheckGroup('q-proposito')));
});

/* —— Color dinámico para <select> sin atributo required —— */
document.querySelectorAll('select').forEach(sel=>{
  const setColor=()=>{          // negro ↔ color normal
    sel.style.color = sel.value ? '#000' : 'var(--ph-light)';
  };
  setColor();                   // inicial
  sel.addEventListener('change', setColor);
});
