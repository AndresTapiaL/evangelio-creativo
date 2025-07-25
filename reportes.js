/* reportes.js – UI 100 % vanilla */

(async () => {
  const AUTH = window.REP_AUTH;        // viene desde PHP

  /* ── Seguridad de interfaz: oculta botones que el usuario no puede usar ── */
  const TABS = {
    integr: document.getElementById('tab-integrantes'),
    event:  document.getElementById('tab-eventos'),
    equip:  document.getElementById('tab-equipos'),
    est:    document.getElementById('tab-eventos_estado')
  };

  const main = document.getElementById('reportes-main');
  const card = document.getElementById('reportes-card');

  /* Líder nacional  **o**  al menos un equipo con rol 4/6
    (→ `allowed.length > 0` porque el PHP solo llena ese array
        cuando el usuario tiene rol 4 u 6 en algún equipo)         */
  const CAN_EXT_TABS = window.REP_AUTH.isLN ||
                      window.REP_AUTH.allowed.length > 0;

  if (!CAN_EXT_TABS) {
    TABS.equip.style.display = 'none';
    TABS.est.style.display   = 'none';
  }

  /* Colores fijos por nombre de estado  (mismos en ambos gráficos) */
  const COLOR_BY_STATE = {
    'Realizado'   : '#8BC34A',  // verde claro
    'Suspendido'  : '#F44336',  // rojo brillante
    'Postergado'  : '#FFEB3B',  // amarillo
    'En pausa'    : '#FF9800',  // naranjo
    'Organizando' : '#8E1B3A',  // burdeo
    'Preparado'   : '#2196F3'   // azul
  };

  /* Paleta de respaldo para los demás estados */
  const EXTRA_COLORS = [
    '#03A9F4', '#BA68C8', '#FF9800', '#4DD0E1',
    '#E91E63', '#CDDC39', '#9E9E9E'
  ];
  let extraIdx = 0;
  function pickExtraColor () {
    const c = EXTRA_COLORS[extraIdx % EXTRA_COLORS.length];
    extraIdx++;
    return c;
  }

  /**
   * Devuelve true si `textoPeriodo` (e.g. "Mayo-Agosto 2025")
   * corresponde al cuatrimestre al que pertenece hoy.
   */
  function esPeriodoActual(textoPeriodo) {
    const hoy = new Date();
    const mes = hoy.getMonth() + 1;         // enero=1...diciembre=12
    const añoHoy = hoy.getFullYear().toString();

    if (textoPeriodo.startsWith('Anual')) {
      return textoPeriodo.endsWith(añoHoy);
    }

    // Extraer año final y texto anterior (tramo)
    const partes = textoPeriodo.trim().split(' ');
    if (partes.length < 2) return false;

    const anioPeriodo = partes[partes.length - 1];            // e.g. "2025"
    const tramo = partes.slice(0, partes.length - 1).join(' '); // e.g. "Mayo-Agosto"

    if (anioPeriodo !== añoHoy) return false;

    // Comparar tramo vs mes actual
    if (tramo === 'Enero-Abril'        && mes >= 1  && mes <= 4 ) return true;
    if (tramo === 'Mayo-Agosto'        && mes >= 5  && mes <= 8 ) return true;
    if (tramo === 'Septiembre-Diciembre' && mes >= 9  && mes <= 12) return true;
    if (tramo === 'Anual') return true;

    return false;
  }

  /* ═════════ 1) Variables globales para los periodos “Equipos” ═════════ */
  const bar = document.getElementById('period-buttons');
  let periodos = [];          // array completo de { id_periodo, nombre_periodo }
  let aniosUnicos = [];       // array de strings, p.ej. ["2025","2024",…]
  let periodosPorAnio = {};   // objeto: periodosPorAnio["2025"] = [ {id_periodo, nombre_periodo}, … ]
  let currentYearIndex = 0;   // índice dentro de aniosUnicos (0 = año más reciente)

  /**
   * Carga y renderiza los botones de período para el reporte según el tipo indicado.
   * - Si type === 'equipos', usamos periodos_equipos_api.php
   * - Si type === 'eventos_estado', usamos periodos_api.php pero también agrupamos por año
   * - En los demás casos (integrantes, eventos), usamos periodos_api.php y los pintamos planos
   */
  async function loadPeriodosPara(type) {
    // 1) Limpiamos cualquier botón de período previo:
    bar.innerHTML = '';

    // Marcar/Desmarcar estilo de “year-nav” según el tipo
    if (type === 'equipos' || type === 'eventos_estado') {
      bar.classList.add('with-year-nav');
    } else {
      bar.classList.remove('with-year-nav');
    }

    // 2) Elegir el endpoint adecuado:
    const endpoint = (type === 'equipos')
          ? 'periodos_equipos_api.php'
          : (type === 'eventos_estado'
              ? 'periodos_eventos_estado_api.php'
              : 'periodos_api.php');

    // 3) Hacemos fetch al endpoint correspondiente:
    const rPer = await fetch(endpoint);
    const jPer = await rPer.json();
    if (!jPer.ok) {
      alert(jPer.error);
      return;
    }

    // 4) Guardamos en "periodos" la lista de { id_periodo, nombre_periodo }
    periodos = jPer.data; 
    // Ejemplo de periodos: 
    // [ { id_periodo:6, nombre_periodo:"Enero-Abril 2025" }, 
    //   { id_periodo:7, nombre_periodo:"Mayo-Agosto 2025" }, … ]

    // 5) Decidimos si pintamos en "lista plana" o “navegación por años”
    const isNavPorAnios = (type === 'equipos' || type === 'eventos_estado');

    if (!isNavPorAnios) {
      // --- (A) Caso “lista plana”: integrantes, eventos ---
      periodos.forEach(p => {
        const btn = document.createElement('button');
        btn.textContent = p.nombre_periodo;   // "Enero-Abril 2025"
        btn.dataset.pid = p.id_periodo;       // ID del período
        btn.className   = 'btn-periodo';
        bar.appendChild(btn);
      });
      return;
    }

    // ──────────────────────────────────────────────────────────────────
    // --- (B) Caso “navegación por años”: equipos y eventos_estado ---
    // ──────────────────────────────────────────────────────────────────

    // 6.a) Extraer el año de cada nombre_periodo y sacar lista única de años
    //     (p.ej. ["2025","2024","1970", ...], ordenada descendente)
    const añosTodos = periodos.map(p => p.nombre_periodo.slice(-4));
    aniosUnicos = Array.from(new Set(añosTodos))
                      .sort((a, b) => parseInt(b, 10) - parseInt(a, 10));

    // 6.b) Agrupar los períodos POR cada año en un objeto:
    //     periodosPorAnio["2025"] = [ { id_periodo:7,nombre:"Mayo-Abril 2025" }, … ]
    periodosPorAnio = {};
    aniosUnicos.forEach(anio => {
      periodosPorAnio[anio] = periodos
        .filter(p => p.nombre_periodo.endsWith(anio))
        .sort((p1, p2) => {
          // Queremos el orden “Enero-Abril”, “Mayo-Agosto”, “Septiembre-Diciembre”
          const trimestres = {
            'Enero-Abril':           1,
            'Mayo-Agosto':           2,
            'Septiembre-Diciembre':  3,
            'Anual'                : 4
          };
          const clave1 = p1.nombre_periodo.replace(` ${anio}`, ''); // e.g. "Enero-Abril"
          const clave2 = p2.nombre_periodo.replace(` ${anio}`, '');
          return (trimestres[clave1] || 0) - (trimestres[clave2] || 0);
        });
    });

    // 6.c) Empezamos mostrando sólo el año más reciente (índice 0 en aniosUnicos)
    currentYearIndex = 0;
    renderPeriodoPorAnio();
  }

  /**
   * Dibuja el bloque de botones correspondiente a un solo año
   * junto con las flechas para navegar entre años.
   * (Se llama desde loadPeriodosPara cuando type==='equipos' o ==='eventos_estado')
   */
  function renderPeriodoPorAnio() {
    // 1) Limpiar el contenedor “bar”:
    bar.innerHTML = '';

    // 2) Año que toca pintar:
    const anio = aniosUnicos[currentYearIndex]; // e.g. "2025"
    const listaDeEseAnio = periodosPorAnio[anio];

    // === NUEVO: contenedor para flechas + año ===
    const yearNav = document.createElement('div');
    yearNav.className = 'year-nav';

    // ← Flecha izquierda (años más antiguos)
    if (currentYearIndex < aniosUnicos.length - 1) {
      const btnLeft = document.createElement('button');
      btnLeft.textContent = '←';
      btnLeft.className = 'arrow-btn';
      btnLeft.title = `Ir a ${aniosUnicos[currentYearIndex + 1]}`;
      btnLeft.addEventListener('click', () => {
        currentYearIndex++;
        renderPeriodoPorAnio();
      });
      yearNav.appendChild(btnLeft);
    }

    // Etiqueta del año
    const lblAnio = document.createElement('span');
    lblAnio.textContent = anio;
    lblAnio.className = 'anio-label';
    yearNav.appendChild(lblAnio);

    // → Flecha derecha (años más nuevos)
    if (currentYearIndex > 0) {
      const btnRight = document.createElement('button');
      btnRight.textContent = '→';
      btnRight.className = 'arrow-btn';
      btnRight.title = `Ir a ${aniosUnicos[currentYearIndex - 1]}`;
      btnRight.addEventListener('click', () => {
        currentYearIndex--;
        renderPeriodoPorAnio();
      });
      yearNav.appendChild(btnRight);
    }

    // Insertamos el year-nav arriba
    bar.appendChild(yearNav);

    // 6) Contenedor para los botones de los CUATRIMESTRES (del año “anio”)
    const contBotones = document.createElement('div');
    contBotones.className = 'periodos-del-anio';

    // 7) Botones por período
    listaDeEseAnio.forEach(p => {
      const b = document.createElement('button');
      b.textContent = p.nombre_periodo;
      b.dataset.pid = p.id_periodo;
      b.className = 'btn-periodo';
      contBotones.appendChild(b);
    });

    // 8) Insertar los botones debajo del year-nav
    bar.appendChild(contBotones);
  }

  /* ═════════ 4) Listeners “globales” ═════════ */

  // 4.a) Clic en cualquier botón de periodo (.btn-periodo)
  bar.addEventListener('click', e => {
    if (!e.target.matches('.btn-periodo')) return;

    // 1) Desmarcar todos los .btn-periodo
    document.querySelectorAll('.btn-periodo').forEach(b => {
      b.classList.remove('active');
    });
    // 2) Marcar la que acabamos de presionar
    e.target.classList.add('active');

    // 3) Guardar el periodo elegido y recargar
    curPer = +e.target.dataset.pid;
    loadReport();
  });

  // 4.b) Clic en las pestañas (Integrantes, Eventos, Equipos, …)
  document.querySelectorAll('[data-report]').forEach(btn => btn.addEventListener('click', () => {
    curType = btn.dataset.report;

    loadPeriodosPara(curType).then(() => {
      // Igual que en Equipos: 
      // – buscar periodo actual solo si es “equipos” **o** “eventos_estado”
      const botonesPeriodos = Array.from(document.querySelectorAll('.btn-periodo'));

      if (curType === 'equipos' || curType === 'eventos_estado') {
        let botonActual = null;
        for (const b of botonesPeriodos) {
          if (esPeriodoActual(b.textContent)) {
            botonActual = b;
            break;
          }
        }
        if (botonActual) {
          botonesPeriodos.forEach(b => b.classList.remove('active'));
          botonActual.classList.add('active');
          curPer = +botonActual.dataset.pid;
        } else if (botonesPeriodos.length) {
          botonesPeriodos.forEach(b => b.classList.remove('active'));
          botonesPeriodos[0].classList.add('active');
          curPer = +botonesPeriodos[0].dataset.pid;
        } else {
          curPer = 0;
        }
      } else {
        if (botonesPeriodos.length) {
          botonesPeriodos.forEach(b => b.classList.remove('active'));
          botonesPeriodos[0].classList.add('active');
          curPer = +botonesPeriodos[0].dataset.pid;
        } else {
          curPer = 0;
        }
      }

      // Para “equipos” y “eventos_estado”, el parámetro team=0
      curTeam = +document.querySelector('.team-btn.active')?.dataset.id || 0;
      loadReport();
    });
  }));

  // 4.c) Clic en el sidebar de equipos (botones .team-btn)
  document.getElementById('team-list')
    .addEventListener('click', e => {
      const btn = e.target.closest('.team-btn');
      if (!btn) return;

      // 1) Marcar activo
      document.querySelectorAll('.team-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      // 2) Actualizar curTeam y recargar reporte
      curTeam = +btn.dataset.id;
      loadReport();
    });


  // ═════════ 5) Estado global y carga inicial del reporte ═════════
  let curType = 'integrantes';
  let curPer = 0;
  let curTeam = +document.querySelector('.team-btn.active').dataset.id;

  // Inicialmente, cargo los periodos para “integrantes”
  await loadPeriodosPara('integrantes');

  // ❶ Seleccionar y marcar el primer período disponible
  const primerBotonInicial = document.querySelector('.btn-periodo');
  if (primerBotonInicial) {
    primerBotonInicial.classList.add('active');
    curPer = +primerBotonInicial.dataset.pid;
  }

  loadReport();

  /* ═════════ 6) loadReport() (con la corrección) ═════════ */
  async function loadReport() {
    // 6.a) Marcar la pestaña activa visualmente
    document.querySelectorAll('#tabs button').forEach(b => {
      b.classList.remove('active');
    });
    const botonActivo = document.querySelector(`#tab-${curType}`);
    if (botonActivo) botonActivo.classList.add('active');

    // 6.b) Mostrar/Ocultar sidebar y ocupar ancho total para "equipos" y "eventos_estado"
    const sidebar = document.querySelector('aside');

    const IS_WIDE = (curType === 'equipos' || curType === 'eventos_estado');

    if (IS_WIDE) {
      sidebar.style.display = 'none';
      main.classList.add('fullwidth');
      card.classList.add('fullwidth');
      card.classList.add('pad-host');   // << NUEVO: aplica padding interno diferenciado
      curTeam = 0; // ambos envían team=0
    } else {
      sidebar.style.display = '';
      main.classList.remove('fullwidth');
      card.classList.remove('fullwidth');
      card.classList.remove('pad-host'); // << NUEVO
    }

    // 6.c) Llamada al API de reportes
    const res = await fetch(
      `reportes_api.php?type=${encodeURIComponent(curType)}&periodo=${curPer}&team=${curTeam}`
    );
    const j = await res.json();
    if (!j.ok) { alert(j.error); return; }

    const host = document.getElementById('report-container');
    host.innerHTML = '';

    switch (curType) {
      case 'integrantes':
        renderIntegrantes(j.data, host);
        break;
      case 'eventos':
        renderEventos(j.data, host);
        break;
      case 'equipos':
        renderEquipos(j.data, host);
        break;
      case 'eventos_estado':
        renderEventosEstado(j, host);
        break;
    }
  }

  /* ═════════ 7) Renders del API (idénticos a los tuyos) ═════════ */
  function renderIntegrantes(rows, host) {
    if (rows[0] && rows[0].mensaje) {
      host.innerHTML = `<em>${rows[0].mensaje}</em>`;
      return;
    }
    if (rows.length === 0) {
      host.innerHTML = '<div class="table-shell"><table class="dt -compact table-empty"><tbody><tr><td>Sin integrantes aún.</td></tr></tbody></table></div>';
      return;
    }

    const jNames = [...new Set(rows.map(r => r.nombre_justificacion_inasistencia))];
    const tbl = htmlTable(['Nombre', ...jNames]);

    // Pivot por usuario
    const byU = {};
    rows.forEach(r => {
      const u = (byU[r.id_usuario] ??= { nombre: r.nombre_completo, vals: {} });
      u.vals[r.nombre_justificacion_inasistencia] = r.porcentaje;
    });

    Object.values(byU).forEach(u => {
      tbl.tBodies[0].append(tr([
        u.nombre,
        ...jNames.map(j => `${u.vals[j] ?? 0}%`)
      ]));
    });

    // 1 columna fija (Nombre)
    compactHeaders(tbl, 1);   // no tocar la 1a col. (“Nombre”)
    const wrap = prettifyAndWrap(tbl, 1);
    host.append(wrap);
    decoratePercentages(wrap);
    addPaginationIfNeeded(wrap, 50);
  }

  function renderEventos(rows, host) {
    if (rows[0] && rows[0].mensaje) {
      host.innerHTML = '<div class="table-shell"><table class="dt -compact table-empty"><tbody><tr><td>Sin eventos aún.</td></tr></tbody></table></div>';
      return;
    }
    if (rows.length === 0) {
      host.innerHTML = '<div class="table-shell"><table class="dt -compact table-empty"><tbody><tr><td>Sin eventos aún.</td></tr></tbody></table></div>';
      return;
    }

    const jNames = [...new Set(rows.map(r => r.nombre_justificacion_inasistencia))];
    const tbl = htmlTable(['Nombre evento', 'Fecha', ...jNames]);
    // ❌ tbl.classList.add('table-just');  <-- QUÍTALA

    // Pivot por evento
    const byE = {};
    rows.forEach(r => {
      const o = (byE[r.id_evento] ??= { nom: r.nombre_evento, f: r.fecha_evento, vals: {} });
      o.vals[r.nombre_justificacion_inasistencia] = r.porcentaje;
    });

    Object.values(byE).forEach(ev => {
      tbl.tBodies[0].append(tr([
        ev.nom, ev.f,
        ...jNames.map(j => `${ev.vals[j] ?? 0}%`)
      ]));
    });

    // 1 columna fija (como Integrantes)
    compactHeaders(tbl, 1);
    const wrap = prettifyAndWrap(tbl, 1);
    host.append(wrap);
    decoratePercentages(wrap);
    addPaginationIfNeeded(wrap, 50);
  }

  function renderEquipos(rows, host) {
    const hdr = ['Equipo', 'Integrantes', 'Activos', 'Semiactivos', 'Nuevos',
      'Inactivos', 'En espera', 'Retirados', 'Cambios', 'Sin estado'];
    const tbl = htmlTable(hdr);

    if (!rows || rows.length === 0) {
      host.innerHTML = '<div class="table-shell"><table class="dt -compact table-empty"><tbody><tr><td>Sin datos.</td></tr></tbody></table></div>';
      return;
    }

    rows.forEach(r => {
      tbl.tBodies[0].append(tr([
        r.nombre_equipo_proyecto,
        r.total_integrantes,
        r.activos, r.semiactivos, r.nuevos,
        r.inactivos, r.en_espera, r.retirados, r.cambios, r.sin_estado
      ]));
    });

    const wrap = prettifyAndWrap(tbl, 1); // fijamos 1 (Equipo) porque suele ser larga
    host.append(wrap);
    // (aquí no hay % que pintar, así que no llamamos decoratePercentages)
  }

  /**
   * Dibuja tres gráficos, uno debajo del otro:
   *  A) Para equipos (es_equipo=1) → j.data.general
   *  B) Para proyectos (es_equipo=0) → j.data.otros
   *  C) Timeseries (todos los equipos y todos los períodos) → j.data.timeseries
   */
  /**
   * Ahora “data” tiene algo así:
   * {
   *   ok: true,
   *   general: [
   *     { id_equipo_proyecto: 4, nombre_equipo_proyecto: "Biobío",
   *       id_estado_final: 1, nombre_estado_final: "Activo", total: 0 },
   *     { id_equipo_proyecto: 4, nombre_equipo_proyecto: "Biobío",
   *       id_estado_final: 2, nombre_estado_final: "Semiactivo", total: 0 },
   *     { id_equipo_proyecto: 4, nombre_equipo_proyecto: "Biobío",
   *       id_estado_final: 3, nombre_estado_final: "Inactivo", total: 3 },
   *     { id_equipo_proyecto: 4, nombre_equipo_proyecto: "Biobío",
   *       id_estado_final: 4, nombre_estado_final: "En espera", total: 0 },
   *       … // y así, todos los estados para Biobío, incluso si total=0
   *   ],
   *   otros: [
   *     { id_equipo_proyecto: 5, nombre_equipo_proyecto: "Los Lagos",
   *       id_estado_final: 1, nombre_estado_final: "Activo", total: 3 },
   *     { id_equipo_proyecto: 5, nombre_equipo_proyecto: "Los Lagos",
   *       id_estado_final: 2, nombre_estado_final: "Semiactivo", total: 0 },
   *     { id_equipo_proyecto: 5, nombre_equipo_proyecto: "Los Lagos",
   *       id_estado_final: 3, nombre_estado_final: "Inactivo", total: 2 },
   *     { id_equipo_proyecto: 5, nombre_equipo_proyecto: "Los Lagos",
   *       id_estado_final: 4, nombre_estado_final: "En espera", total: 1 },
   *       … // todos los estados para “Los Lagos”
   *   ]
   * }
   *
   * Entonces podemos construir las gráficas sabiendo que:
   *  – Cada equipo aparecerá repetido N veces (una por cada estado).
   *  – Cada estado aparecerá repetido M veces (una por cada equipo).
   */
  async function renderEventosEstado(data, host) {
    // 1) Limpiar contenedor
    host.innerHTML = '';

    // 2) Grid contenedor
    const grid = document.createElement('div');
    grid.className = 'chart-grid';
    host.appendChild(grid);

    // 3) Asegurarnos de que Chart.js está cargado
    await loadChartJs();

    /* Preset para que la escala Y sólo muestre enteros */
    const yEnteros = {
      beginAtZero : true,
      ticks : {
        stepSize  : 1,
        precision : 0
      }
    };

    // Helper: crea card+canvas
    function createChartCard(title, subtitle){
      const card = document.createElement('div');
      card.className = 'chart-card';

      const h3 = document.createElement('h3');
      h3.textContent = title;

      const sub = document.createElement('p');
      sub.className = 'chart-sub';
      sub.textContent = subtitle || '';

      const canvas = document.createElement('canvas');
      canvas.height = 280;     // más pequeño

      card.append(h3, sub, canvas);
      grid.appendChild(card);
      return { card, canvas };
    }

    /* ====== PARTE A: general (Equipos) ====== */
    const equiposGen = Array.from(new Set(data.general.map(r => r.nombre_equipo_proyecto)));
    const estadosGen = Array.from(new Set(data.general.map(r => r.nombre_estado_final))).sort();

    const datasetsGen = estadosGen.map(nombreEstado => ({
      label : nombreEstado,
      backgroundColor : COLOR_BY_STATE[nombreEstado] || pickExtraColor(),
      maxBarThickness : 28,
      data  : equiposGen.map(eq => {
        const fila = data.general.find(r =>
          r.nombre_equipo_proyecto === eq &&
          r.nombre_estado_final    === nombreEstado
        );
        return fila ? fila.total : 0;
      })
    }));

    const { canvas: c1 } = createChartCard(
      'Eventos aprobados por equipo y estado final',
      'Solo con estado previo = Aprobado'
    );

    new Chart(c1, {
      type : 'bar',
      data : { labels : equiposGen, datasets : datasetsGen },
      options : {
        responsive : true,
        maintainAspectRatio : false,
        layout : { padding : { top: 8, right: 8, bottom: 0, left: 8 } },
        plugins : {
          legend : { position : 'bottom', labels: { boxWidth: 12, boxHeight: 12 } },
          tooltip: { mode: 'index', intersect: false }
        },
        scales : {
          x : { stacked : false, ticks:{ autoSkip:false, maxRotation:45, minRotation:0 }},
          y : yEnteros
        }
      }
    });

    /* ====== PARTE B: otros (Proyectos) ====== */
    const equiposOtr = Array.from(new Set(data.otros.map(r => r.nombre_equipo_proyecto)));
    const estadosOtr = Array.from(new Set(data.otros.map(r => r.nombre_estado_final))).sort();

    const datasetsOtr = estadosOtr.map(nombreEstado => ({
      label : nombreEstado,
      backgroundColor : COLOR_BY_STATE[nombreEstado] || pickExtraColor(),
      maxBarThickness : 28,
      data  : equiposOtr.map(eq => {
        const fila = data.otros.find(r =>
          r.nombre_equipo_proyecto === eq &&
          r.nombre_estado_final    === nombreEstado
        );
        return fila ? fila.total : 0;
      })
    }));

    const { canvas: c2 } = createChartCard(
      'Eventos aprobados por proyecto y estado final',
      'Solo con estado previo = Aprobado'
    );

    new Chart(c2, {
      type : 'bar',
      data : { labels : equiposOtr, datasets : datasetsOtr },
      options : {
        responsive : true,
        maintainAspectRatio : false,
        layout : { padding : { top: 8, right: 8, bottom: 0, left: 8 } },
        plugins : {
          legend : { position : 'bottom', labels: { boxWidth: 12, boxHeight: 12 } },
          tooltip: { mode: 'index', intersect: false }
        },
        scales : {
          x : { stacked : false, ticks:{ autoSkip:false, maxRotation:45, minRotation:0 }},
          y : yEnteros
        }
      }
    });
  }

  function htmlTable(headers) {
    const t = document.createElement('table');
    t.innerHTML = '<thead><tr>' +
      headers.map(h => `<th>${h}</th>`).join('') +
      '</tr></thead><tbody></tbody>';
    return t;
  }
  const tr = vals => {
    const r = document.createElement('tr');
    r.innerHTML = vals.map(v => `<td>${v}</td>`).join('');
    return r;
  };

  async function loadChartJs() {
    if (window.Chart) return;
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    document.head.append(s);
    await new Promise(r => s.onload = r);
  }

  /* ========= helpers de paginación ========= */
  const DEFAULT_PAGE_SIZE = 50;

  /**
   * Agrega paginación a la tabla dentro de `wrap` si supera `pageSize` filas.
   * - No altera headers ni estilos sticky.
   * - Valida límites de página en frontend.
   */
  function addPaginationIfNeeded(wrap, pageSize = DEFAULT_PAGE_SIZE){
    const table = wrap.querySelector('table');
    if (!table || !table.tBodies[0]) return;

    const tbody = table.tBodies[0];
    const rows  = Array.from(tbody.rows);
    const totalRows = rows.length;

    if (totalRows <= pageSize) return; // nada que paginar

    let currentPage = 1;
    const totalPages = Math.ceil(totalRows / pageSize);

    // Crea controles
    const pager = document.createElement('div');
    pager.className = 'pager';

    const btnFirst = document.createElement('button');
    btnFirst.textContent = '« Primero';

    const btnPrev = document.createElement('button');
    btnPrev.textContent = '‹ Anterior';

    const info = document.createElement('span');
    info.className = 'page-info';

    const btnNext = document.createElement('button');
    btnNext.textContent = 'Siguiente ›';

    const btnLast = document.createElement('button');
    btnLast.textContent = 'Último »';

    pager.append(btnFirst, btnPrev, info, btnNext, btnLast);

    function clampPage(p){
      if (isNaN(p) || p < 1) return 1;
      if (p > totalPages) return totalPages;
      return p;
    }

    function renderPage(p){
      currentPage = clampPage(p);
      const start = (currentPage - 1) * pageSize;
      const end   = start + pageSize;

      rows.forEach((tr, idx) => {
        tr.style.display = (idx >= start && idx < end) ? '' : 'none';
      });

      info.textContent = `Página ${currentPage} / ${totalPages}`;

      btnFirst.disabled = btnPrev.disabled = (currentPage === 1);
      btnLast.disabled  = btnNext.disabled = (currentPage === totalPages);
    }

    btnFirst.addEventListener('click', () => renderPage(1));
    btnPrev .addEventListener('click', () => renderPage(currentPage - 1));
    btnNext .addEventListener('click', () => renderPage(currentPage + 1));
    btnLast .addEventListener('click', () => renderPage(totalPages));

    // Insertar controles DESPUÉS del wrapper de la tabla
    wrap.after(pager);

    // Pintar primera página
    renderPage(1);
  }

  /* ========= helpers de look de tabla ========= */

  /**
   * Reemplaza los valores "N%" por un span .pct con una barrita de progreso.
   * - Baja:   0–33  → rojo
   * - Media: 34–66  → naranjo
   * - Alta:  67–100 → verde
   */
  function decoratePercentages(root){
    const tds = root.querySelectorAll('td');
    tds.forEach(td => {
      const txt = td.textContent.trim();
      const m = txt.match(/^(\d+(?:\.\d+)?)%$/);
      if(!m) return;
      const p = parseFloat(m[1]);
      const span = document.createElement('span');
      span.className = 'pct';
      span.style.setProperty('--p', p);
      if (p <= 33)  span.dataset.low  = '1';
      else if (p <= 66) span.dataset.mid  = '1';
      else span.dataset.high = '1';
      span.textContent = `${Math.round(p)}%`;
      td.innerHTML = '';
      td.appendChild(span);
    });
  }

  // ── Tooltip global para los <th> ─────────────────────────────
  const TH_TIP = document.getElementById('th-tooltip');
  function showThTip(html, x, y){
    if(!TH_TIP) return;
    TH_TIP.innerHTML = html;
    TH_TIP.style.left = (x + 14) + 'px';   // un poco a la derecha del cursor
    TH_TIP.style.top  = (y + 14) + 'px';   // un poco abajo del cursor
    TH_TIP.style.display = 'block';
    TH_TIP.setAttribute('aria-hidden','false');
  }
  function hideThTip(){
    if(!TH_TIP) return;
    TH_TIP.style.display = 'none';
    TH_TIP.setAttribute('aria-hidden','true');
  }

  // === Overrides opcionales por columna (clave = header original “normalizado”) ===
  // OJO: la clave va **sin tildes, en MAYÚSCULAS y normalizada** exactamente como
  // la genera tu función `normalize()`.
  const HEADER_OVERRIDES = {
    'SALUD': {
      short: 'SALUD',
      full : 'Salud',
      desc : 'Problemas de salud propios o de un familiar directo.'
    },
    'LABORAL': {
      short: 'LABORAL',
      full : 'Laboral',
      desc : 'Incompatibilidad con horarios o exigencias del trabajo.'
    },
    'ECONOMICO': {
      short: 'ECON.',
      full : 'Económico',
      desc : 'Dificultades económicas que impiden la participación.'
    },
    'NO SABIA': {
      short: 'NO SABÍA',
      full : 'No sabía',
      desc : 'El integrante indica que desconocía la actividad o la cita.'
    },
    'SE AVISO CON POCA ANTERIORIDAD': {
      short: 'A. tarde',
      full : 'Se avisó con poca anterioridad',
      desc : 'El integrante indica que se le informó con poca anterioridad.'
    },
    'ACADEMICO': {
      short: 'ACAD.',
      full : 'Académico',
      desc : 'Compromisos académicos (clases, evaluaciones, etc.).'
    },
    'COMPROMISO IMPORTANTE': {
      short: 'C. IMP.',
      full : 'Compromiso importante',
      desc : 'Motivo declarado como de alta prioridad personal o familiar.'
    },
    'LEJANIA': {
      short: 'LEJANÍA',
      full : 'Lejanía',
      desc : 'Distancia geográfica o dificultades de traslado.'
    },
    'OTROS': {
      short: 'OTROS',
      full : 'Otros',
      desc : 'Motivos no categorizados en las opciones anteriores.'
    },
    'SI PARTICIPO': {
      short: 'SÍ PART.',
      full : 'Sí participó',
      desc : 'El integrante asistió/participó efectivamente.'
    },
    'NO PARTICIPO': {
      short: 'NO PART.',
      full : 'No participó',
      desc : 'El integrante no asistió/participó.'
    },
  };

  /**
   * Compacta los encabezados de una tabla:
   * – Reemplaza el texto largo por una abreviatura corta y deja el completo en un tooltip custom global.
   * – skipFirstCols: cuántas primeras columnas NO tocar (porque son “Nombre”, “Fecha”, etc.).
   */
  function compactHeaders(tbl, skipFirstCols = 1){
    const DICT = {
      'SALUD'                         : 'SALUD',
      'LABORAL'                       : 'LABORAL',
      'ECONÓMICO'                     : 'ECON.',
      'ECONOMICO'                     : 'ECON.',
      'NO SABÍA'                      : 'NO SABÍA',
      'NO SABIA'                      : 'NO SABÍA',
      'SE AVISÓ CON POCA ANTERIORIDAD': 'AVISO TARDE',
      'SE AVISO CON POCA ANTERIORIDAD': 'AVISO TARDE',
      'ACADÉMICO'                     : 'ACAD.',
      'ACADEMICO'                     : 'ACAD.',
      'COMPROMISO IMPORTANTE'         : 'COMP. IMP.',
      'LEJANÍA'                       : 'LEJANÍA',
      'LEJANIA'                       : 'LEJANÍA',
      'OTROS'                         : 'OTROS',
      'SI PARTICIPÓ'                  : 'SÍ PARTICIPÓ',
      'SI PARTICIPO'                  : 'SÍ PARTICIPÓ',
      'NO PARTICIPÓ'                  : 'NO PARTICIPÓ',
      'NO PARTICIPO'                  : 'NO PARTICIPÓ',
      'HISTÓRICO'                     : 'HISTÓRICO',
      'HISTORICO'                     : 'HISTÓRICO'
    };

    const normalize = s => s
      .toUpperCase()
      .normalize('NFD').replace(/\p{Diacritic}/gu,'')
      .replace(/\s+/g,' ')
      .trim();

    const ths = Array.from(tbl.tHead.rows[0].cells).slice(skipFirstCols);
    ths.forEach(th => {
      const fullOriginal = th.textContent.trim();
      const key          = normalize(fullOriginal);

      // 1) Buscar override
      const ov   = HEADER_OVERRIDES[key];
      let short  = ov?.short ?? DICT[key];
      const full = ov?.full  ?? fullOriginal;
      const desc = ov?.desc  ?? '';

      if (!short){
        // fallback: acrónimo (primeras letras de cada palabra) máx 6 chars
        const words = fullOriginal.split(/\s+/);
        if (words.length === 1){
          short = (words[0].length > 8 ? words[0].slice(0,6) + '.' : words[0]);
        } else {
          short = words.map(w => w[0]).join('').slice(0,6).toUpperCase();
        }
      }

      // 2) Guardar en data-* (por si quieres reutilizar)
      th.dataset.full = full;
      if (desc) th.dataset.desc = desc;

      // 3) Quitar el tooltip nativo
      th.removeAttribute('title');

      // 4) HTML interno (abreviatura visible)
      th.innerHTML = `<span class="hshort">${short}</span>`;

      // 5) Tooltip custom (global) – listeners
      const htmlTip = `
        <strong>${full}</strong>
        ${desc ? `<small>${desc}</small>` : ''}
      `;
      th.addEventListener('mouseenter', e => {
        showThTip(htmlTip, e.clientX, e.clientY);
      });
      th.addEventListener('mousemove', e => {
        showThTip(htmlTip, e.clientX, e.clientY);
      });
      th.addEventListener('mouseleave', hideThTip);
    });
  }

  /**
   * Envuelve la tabla en un contenedor con scroll y aplica el “skin” .dt.
   * Luego fija las primeras `count` columnas.
   * Devuelve el wrapper para que lo insertes en el DOM.
   */
  function prettifyAndWrap(tbl, count){
    tbl.classList.add('dt', '-compact');

    const wrap = document.createElement('div');
    wrap.className = 'table-shell';
    wrap.appendChild(tbl);

    // Esperar al próximo frame para calcular anchos reales
    requestAnimationFrame(() => lockFirstColumns(tbl, count));

    return wrap;
  }

  /**
   * Fija las primeras `count` columnas calculando el offset left acumulado.
   * Similar a tu versión anterior, pero añade la clase .locked-col.
   */
  function lockFirstColumns(table, count){
    const thead = table.tHead;
    if (!thead) return;

    const firstRowThs = Array.from(thead.rows[0].cells);
    let accLeft = 0;

    for (let c = 0; c < count; c++){
      const th = firstRowThs[c];
      if (!th) break;

      const colWidth = th.getBoundingClientRect().width;

      const selector = `thead th:nth-child(${c+1}), tbody td:nth-child(${c+1})`;
      table.querySelectorAll(selector).forEach(cell => {
        cell.classList.add('locked-col');
        cell.style.left = accLeft + 'px';
      });

      accLeft += colWidth;
    }
  }
  /* ========= /helpers de look de tabla ========= */

})();  // /IIFE
