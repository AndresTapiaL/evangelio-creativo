/* =====================================================================
   EDITAR MIS DATOS – v 6.4
   ---------------------------------------------------------------------
   • Sincroniza País ↔ Documento de identidad
   • Mantiene cascada correcta País → Región → Ciudad
   • Checkbox-ocupaciones, validaciones, teléfonos, foto, envío, logout
===================================================================== */
document.addEventListener('DOMContentLoaded', () => {
  const token = localStorage.getItem('token'); if (!token) return;

  /* ─── SELECTORES ─── */
  const $ = id => document.getElementById(id);
  const navName=$('nombre-usuario'), navPic=$('foto-perfil-nav');
  const fotoDom=$('foto_perfil'), fileInp=$('nueva_foto');

  const tipoDocSel=$('tipo_doc'), rutInp=$('rut_dni');
  const paisSel=$('pais'), regSel=$('region'), ciuSel=$('ciudad');

  const fechaInp=$('fecha_nacimiento'), dirInp=$('direccion'),
        iglInp=$('iglesia'), profInp=$('profesion'),
        mailInp=$('correo'), bolChk=$('boletin');

  const ocuWrap=$('ocupaciones-wrapper');

  const telInp=[...document.querySelectorAll("input[id^='telefono_']")];
  const telSel=[...document.querySelectorAll("select[id^='tipo_telefono_']")];
  const delBtn=[...document.querySelectorAll('.delete-telefono')];

  /* ─── HELPERS ─── */
  const jFetch=u=>fetch(u).then(r=>r.json());
  const err=(id,msg='')=>{const e=$(id); if(!e) return; e.textContent=msg; e.style.display=msg?'block':'none';};

  /* RUT */
  const cleanRut=v=>v.replace(/\./g,'').replace(/-/g,'').trim();
  const fmtRut =v=>{const n=cleanRut(v);return n.length<2?n:n.slice(0,-1).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+'-'+n.slice(-1);};
  const dv     =c=>{let s=1,m=0;for(let i=c.length-1;i>=0;i--)s=(s+c[i]*(9-(m++%6)))%11;return s?s-1+'':'K';};
  const validRut=v=>{const n=cleanRut(v);return /^\d{7,8}[0-9Kk]$/.test(n)&&dv(n.slice(0,-1))===n.slice(-1).toUpperCase();};

  /* regex */
  const onlyNum=v=>/^\d+$/.test(v);
  const safeTxt=v=>/^[\w\sÁÉÍÓÚÜÑáéíóúüñ\.\-]+$/.test(v);
  const safeDir=v=>/^[\w\s\.\,\-\#]+$/.test(v);
  const mailOK =v=>/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/.test(v);
  const imgOK  =f=>['image/jpeg','image/png','image/gif','image/webp','image/jpg'].includes(f.type);

  /* ─── INTL-TEL-INPUT ─── */
  const iti=telInp.map(el=>window.intlTelInput(el,{initialCountry:'cl',nationalMode:false,formatOnDisplay:true}));
  telInp.forEach(inp=>{
    inp.addEventListener('input',()=>{
      let v=inp.value.replace(/[^0-9\+]/g,''); const plus=v.startsWith('+'); v=v.replace(/\+/g,'');
      inp.value=plus? '+'+v : v;
    });
  });
  delBtn.forEach(btn=>{
    btn.onclick=()=>{const i=btn.dataset.indice-1; telInp[i].value=''; iti[i].setNumber(''); telSel[i].selectedIndex=0; err('error_tel'+(+i+1));};
  });

  /* ─── SELECTS UBICACIÓN ─── */
  const fillSel=(sel,list,val='')=>{
    sel.innerHTML='<option value="">Seleccione…</option>';
    list.forEach(o=>sel.innerHTML+=`<option value="${o.id}" ${String(o.id)===String(val)?'selected':''}>${o.nombre}</option>`);
  };
  const loadPaises  = async(id='')=>fillSel(paisSel, await jFetch('ubicacion.php?tipo=pais'), id);
  const loadRegs    = async(pid,rid='')=>{
    if(!pid){regSel.innerHTML=ciuSel.innerHTML='';return;}
    fillSel(regSel, await jFetch(`ubicacion.php?tipo=region&id=${pid}`), rid);
    ciuSel.innerHTML='';
  };
  const loadCiuds   = async(rid,cid='')=>{
    if(!rid){ciuSel.innerHTML='';return;}
    fillSel(ciuSel, await jFetch(`ubicacion.php?tipo=ciudad&id=${rid}`), cid);
  };

  /* ─── DOC ↔ PAÍS SINCRONIZACIÓN ─── */
  const CHILE_ID='1';
  const syncDocPais = src=>{
    if(src==='pais'){
      tipoDocSel.value = paisSel.value===CHILE_ID ? 'rut' : 'int';
      rutInp.value='';
    }else{ /* src === 'doc' */
      if(tipoDocSel.value==='rut'){
        paisSel.value=CHILE_ID; loadRegs(CHILE_ID);
      }else if(paisSel.value===CHILE_ID){
        paisSel.value=''; loadRegs('');
      }
      rutInp.value='';
    }
  };
  paisSel.addEventListener('change',()=>{syncDocPais('pais'); loadRegs(paisSel.value);});
  regSel .addEventListener('change',()=> loadCiuds(regSel.value));
  tipoDocSel.addEventListener('change',()=>syncDocPais('doc'));

  /* ─── OCUPACIONES ─── */
  const loadOcus=async(sel=[])=>{
    ocuWrap.innerHTML='';
    (await jFetch('get_ocupaciones.php')).forEach(o=>{
      ocuWrap.insertAdjacentHTML('beforeend',
        `<label><input type="checkbox" value="${o.id_ocupacion}" ${sel.includes(String(o.id_ocupacion))?'checked':''}> ${o.nombre}</label>`);
    });
  };

  /* ─── BLUR VALIDATIONS ─── */
  rutInp.onblur=()=>{
    if(tipoDocSel.value==='rut'){rutInp.value=fmtRut(rutInp.value); err('error_rut',validRut(rutInp.value)?'':'RUT inválido');}
    else err('error_rut',onlyNum(rutInp.value)?'':'Solo números');
  };
  fileInp.onchange = ()=>{const ok=fileInp.files[0]&&imgOK(fileInp.files[0]); if(!ok) fileInp.value=''; err('error_foto',ok?'':'Archivo no permitido');};
  fechaInp.onblur  = ()=>err('error_fecha',fechaInp.value && fechaInp.value<=new Date().toISOString().split('T')[0]?'':'Fecha inválida');
  dirInp .onblur   = ()=>err('error_direccion',safeDir(dirInp.value)?'':'Carácter no permitido');
  iglInp .onblur   = ()=>err('error_iglesia',safeTxt(iglInp.value)?'':'Carácter no permitido');
  profInp.onblur   = ()=>err('error_profesion',safeTxt(profInp.value)?'':'Carácter no permitido');
  mailInp.onblur   = ()=>err('error_correo',mailOK(mailInp.value)?'':'Correo inválido');
  telInp.forEach((inp,i)=>inp.addEventListener('blur',()=>{
    const n=iti[i].getNumber().replace(/[^0-9\+]/g,'');
    err('error_tel'+(i+1),(n===''||/^\+?\d+$/.test(n))?'':'Solo números y +');
  }));

  /* ─── PRECARGA ─── */
  (async()=>{
    const u=await jFetch(`get_usuario.php?token=${token}`);
    if(u.error){alert(u.error);return;}

    navName.textContent=u.nombres||'Usuario';
    navPic.src=u.foto_perfil||'uploads/fotos/default.png';
    fotoDom.src=u.foto_perfil||'uploads/fotos/default.png';

    await loadPaises(u.id_pais);
    await loadRegs  (u.id_pais,u.id_region_estado);
    await loadCiuds (u.id_region_estado,u.id_ciudad_comuna);

    await loadOcus(u.ocupaciones.map(o=>String(o.id_ocupacion)));

    tipoDocSel.value = u.id_pais==CHILE_ID ? 'rut' : 'int';
    rutInp.value     = u.id_pais==CHILE_ID ? fmtRut(u.rut_dni) : u.rut_dni;
    fechaInp.value   = u.fecha_nacimiento||'';
    dirInp.value     = u.direccion||'';
    iglInp.value     = u.iglesia_ministerio||'';
    profInp.value    = u.profesion_oficio_estudio||'';
    mailInp.value    = u.correo||'';
    bolChk.checked   = !!u.boletin;

    (u.telefonos||[]).forEach((t,i)=>{if(i<3){telInp[i].value=t.numero; iti[i].setNumber(t.numero); telSel[i].value=t.descripcion_id||'';}});
  })();

  /* ─── SUBMIT ─── */
  $('form-editar').onsubmit=async e=>{
    e.preventDefault();
    [...document.querySelectorAll('input,select')].forEach(el=>el.dispatchEvent(new Event('blur')));
    if([...document.querySelectorAll('.error-msg')].some(e=>e.style.display==='block')) return alert('Corrige los errores.');

    try{
      if(fileInp.files.length){
        const fdF=new FormData(); fdF.append('token',token); fdF.append('foto',fileInp.files[0]);
        const jf=await(await fetch('subir_foto.php',{method:'POST',body:fdF})).json();
        if(!jf.mensaje) throw new Error(jf.error||'Error foto');
      }

      const fd=new FormData();
      fd.append('token',token);
      fd.append('tipo_doc',tipoDocSel.value);
      fd.append('rut_dni',cleanRut(rutInp.value));
      fd.append('fecha_nacimiento',fechaInp.value);
      fd.append('id_pais',paisSel.value);
      fd.append('id_region_estado',regSel.value);
      fd.append('id_ciudad_comuna',ciuSel.value);
      fd.append('direccion',dirInp.value);
      fd.append('iglesia_ministerio',iglInp.value);
      fd.append('profesion_oficio_estudio',profInp.value);
      fd.append('correo',mailInp.value);
      if(bolChk.checked) fd.append('boletin',1);

      ocuWrap.querySelectorAll('input[type=checkbox]:checked').forEach(c=>fd.append('id_ocupacion[]',c.value));

      const ju=await(await fetch('actualizar_usuario.php',{method:'POST',body:fd})).json();
      if(!ju.mensaje) throw new Error(ju.error||'Error guardar datos');

      const fdT=new FormData(); fdT.append('token',token);
      telInp.forEach((inp,i)=>{fdT.append(`telefono_${i+1}`,iti[i].getNumber());fdT.append(`tipo_telefono_${i+1}`,telSel[i].value);});
      const jt=await(await fetch('actualizar_telefonos.php',{method:'POST',body:fdT})).json();
      if(!jt.mensaje) throw new Error(jt.error||'Error teléfonos');

      location.replace('ver_mis_datos.php');
    }catch(err){alert('⛔ '+err.message);}
  };

  /* ─── LOGOUT ─── */
  window.cerrarSesion=()=>{fetch(`cerrar_sesion.php?token=${token}`).finally(()=>{localStorage.clear();location.replace('login.html');});};
});
