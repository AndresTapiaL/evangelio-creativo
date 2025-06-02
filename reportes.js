/* reportes.js – UI 100 % vanilla */

(async () => {

  /* ═════════ 1) Periodos dinámicos ═════════ */
  const rPer   = await fetch('periodos_api.php');
  const jPer   = await rPer.json();
  if (!jPer.ok) { alert(jPer.error); return; }

  const periodos = jPer.data;                 // 3 últimos
  const bar = document.getElementById('period-buttons');
  periodos.forEach(p => {
    const b = document.createElement('button');
    b.textContent = p.nombre_periodo;
    b.dataset.pid = p.id_periodo;
    b.className   = 'btn-periodo';
    bar.appendChild(b);
  });

  /* ═════════ 2) Estado global ═════════ */
  let curType   = 'integrantes';
  let curPer    = periodos[0].id_periodo;
  let curTeam = +document.querySelector('.team-btn').dataset.id;

  /* cuando llega data, mostrar “Sin eventos aún” si todos 0 */
  function renderIntegrantes(rows, host){
    if (rows[0] && rows[0].mensaje){
        host.innerHTML = `<em>${rows[0].mensaje}</em>`;
        return;
    }

    if (rows.length === 0) {
      host.innerHTML = '<em>Sin eventos aún.</em>';
      return;
    }

    const jNames=[...new Set(rows.map(r=>r.nombre_justificacion_inasistencia))];
    const tbl = htmlTable(['Nombre',...jNames]);
    const byU={};
    rows.forEach(r=>{
      const u=(byU[r.id_usuario]??={nombre:r.nombre_completo,vals:{}});
      u.vals[r.nombre_justificacion_inasistencia]=r.porcentaje;
    });
    Object.values(byU).forEach(u=>{
      tbl.tBodies[0].append(tr([
        u.nombre,
        ...jNames.map(j=>`${u.vals[j]??0}%`)
      ]));
    });
    host.append(tbl);
  }


  /* ═════════ 3) Listeners ═════════ */
  bar.addEventListener('click', e => {
    if (!e.target.matches('.btn-periodo')) return;
    curPer = +e.target.dataset.pid;
    loadReport();
  });

  document.querySelectorAll('[data-report]')
          .forEach(btn => btn.addEventListener('click', () => {
            curType = btn.dataset.report;
            loadReport();
          }));

  /* ═════════ 3-bis)  Clic en el sidebar de equipos ═════════ */
  document.getElementById('team-list')   // <ul id="team-list"> que creamos en PHP
          .addEventListener('click', e=>{
    const btn = e.target.closest('.team-btn');
    if (!btn) return;

    /* 1) resaltar activo */
    document.querySelectorAll('.team-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');

    /* 2) estado y recarga */
    curTeam = +btn.dataset.id;          // “+” lo pasa a número
    loadReport();
  });


  /* ═════════ 4) Render principal ═════════ */
  async function loadReport() {
    const res = await fetch(
      `reportes_api.php?type=${encodeURIComponent(curType)}&periodo=${curPer}&team=${curTeam}`
    );
    const j   = await res.json();
    if (!j.ok) { alert(j.error); return; }

    const host = document.getElementById('report-container');
    host.innerHTML = '';

    switch (curType) {
      case 'integrantes':   renderIntegrantes(j.data, host); break;
      case 'eventos':       renderEventos(j.data, host);     break;
      case 'equipos':       renderEquipos(j.data, host);     break;
      case 'eventos_estado':renderGrafico(j.data, host);     break;
    }
  }

  /* ─── helpers de render ───────────────────────────────────── */

  function renderEventos(rows, host) {
    /* si viene el mensaje especial, lo mostramos y salimos */
    if (rows[0] && rows[0].mensaje){
        host.innerHTML = `<em>${rows[0].mensaje}</em>`;
        return;
    }

    const jNames = [...new Set(
          rows.map(r=>r.nombre_justificacion_inasistencia)
    )];   
    const tbl = htmlTable(['Nombre evento','Fecha',...jNames]);
    const byE = {};
    rows.forEach(r=>{
      const o = (byE[r.id_evento] ??= {nom:r.nombre_evento, f:r.fecha_evento, vals:{}});
      o.vals[r.nombre_justificacion_inasistencia] = r.porcentaje;
    });
    Object.values(byE).forEach(ev=>{
      tbl.tBodies[0].append(tr([
        ev.nom, ev.f,
        ...jNames.map(j=>`${ev.vals[j]??0}%`)
      ]));
    });
    host.append(tbl);
  }

  function renderEquipos(rows, host) {
    const hdr=['Equipo','Integrantes','Activos','Semiactivos','Nuevos',
               'Inactivos','En espera','Retirados','Cambios'];
    const tbl = htmlTable(hdr);
    rows.forEach(r=>{
      tbl.tBodies[0].append(tr([
        r.nombre_equipo_proyecto,
        r.total_integrantes,
        r.activos,r.semiactivos,r.nuevos,
        r.inactivos,r.en_espera,r.retirados,r.cambios
      ]));
    });
    host.append(tbl);
  }

  async function renderGrafico(rows, host) {
    await loadChartJs();
    const equipos=[...new Set(rows.map(r=>r.nombre_equipo_proyecto))];
    const estados=[...new Set(rows.map(r=>r.id_estado_final))];
    const ds=estados.map(id=>({
      label:`Estado ${id}`,
      data:equipos.map(eq=>{
        const x=rows.find(r=>r.nombre_equipo_proyecto===eq && r.id_estado_final===id);
        return x?x.total:0;
      })
    }));
    const cv=document.createElement('canvas');
    host.append(cv);
    new Chart(cv,{type:'bar',
      data:{labels:equipos,datasets:ds},
      options:{responsive:true,plugins:{legend:{position:'top'}}}});
  }

  function htmlTable(headers){
    const t=document.createElement('table');
    t.innerHTML='<thead><tr>'+headers.map(h=>`<th>${h}</th>`).join('')+'</tr></thead><tbody></tbody>';
    return t;
  }
  const tr=vals=>{
    const r=document.createElement('tr');
    r.innerHTML=vals.map(v=>`<td>${v}</td>`).join('');
    return r;
  };

  async function loadChartJs(){
    if (window.Chart) return;
    const s=document.createElement('script');
    s.src='https://cdn.jsdelivr.net/npm/chart.js';
    document.head.append(s);
    await new Promise(r=>s.onload=r);
  }

  /* carga inicial */
  loadReport();
})();
