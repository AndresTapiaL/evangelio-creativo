/* reportes.js – UI 100 % vanilla */

(async () => {
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
            'Septiembre-Diciembre':  3
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
    // Ejemplo: [ { id_periodo:7, nombre_periodo:"Mayo-Abril 2025" }, … ]

    // 3) Si NO estoy en el último índice, muestro flecha “←” (para bajar a años antiguos)
    if (currentYearIndex < aniosUnicos.length - 1) {
      const btnLeft = document.createElement('button');
      btnLeft.textContent = '←';
      btnLeft.className = 'arrow-btn';
      btnLeft.title = `Ir a ${aniosUnicos[currentYearIndex + 1]}`;
      btnLeft.addEventListener('click', () => {
        currentYearIndex++;
        renderPeriodoPorAnio();
      });
      bar.appendChild(btnLeft);
    }

    // 4) Pongo la etiqueta con el año actual en el centro:
    const lblAnio = document.createElement('span');
    lblAnio.textContent = anio;
    lblAnio.className = 'anio-label';
    bar.appendChild(lblAnio);

    // 5) Si currentYearIndex > 0, muestro flecha “→” (para subir a años más nuevos)
    if (currentYearIndex > 0) {
      const btnRight = document.createElement('button');
      btnRight.textContent = '→';
      btnRight.className = 'arrow-btn';
      btnRight.title = `Ir a ${aniosUnicos[currentYearIndex - 1]}`;
      btnRight.addEventListener('click', () => {
        currentYearIndex--;
        renderPeriodoPorAnio();
      });
      bar.appendChild(btnRight);
    }

    // 6) Contenedor para los botones de los CUATRIMESTRES (del año “anio”)
    const contBotones = document.createElement('div');
    contBotones.className = 'periodos-del-anio';

    // 7) Por cada período de ese año, crear un <button class="btn-periodo">
    listaDeEseAnio.forEach(p => {
      const b = document.createElement('button');
      b.textContent = p.nombre_periodo;  // e.g. "Mayo-Abril 2025"
      b.dataset.pid = p.id_periodo;       // e.g. 7
      b.className = 'btn-periodo';
      contBotones.appendChild(b);
    });

    // 8) Insertar el contenedor de botones debajo de las flechas/año:
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

    // 6.b) Mostrar/Ocultar sidebar para “equipos” Y para “eventos_estado”
    const sidebar = document.querySelector('aside');
    const section = document.querySelector('section');

    if (curType === 'equipos' || curType === 'eventos_estado') {
      // —— ocultar sidebar y expandir el <section> a todo el ancho ——
      sidebar.style.display = 'none';
      section.classList.add('ocupandoTodoElEspacio');
      // Para ambos (equipos y eventos_estado) enviamos team=0
      curTeam = 0;
    } else {
      // —— mostrar sidebar y quitar la clase de expansión ——
      sidebar.style.display = '';
      section.classList.remove('ocupandoTodoElEspacio');
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
      host.innerHTML = '<em>Sin integrantes aún.</em>';
      return;
    }
    const jNames = [...new Set(rows.map(r => r.nombre_justificacion_inasistencia))];
    const tbl = htmlTable(['Nombre', ...jNames]);
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
    host.append(tbl);
  }

  function renderEventos(rows, host) {
    if (rows[0] && rows[0].mensaje) {
      host.innerHTML = `<em>${rows[0].mensaje}</em>`;
      return;
    }
    const jNames = [...new Set(rows.map(r => r.nombre_justificacion_inasistencia))];
    const tbl = htmlTable(['Nombre evento', 'Fecha', ...jNames]);
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
    host.append(tbl);
  }

  function renderEquipos(rows, host) {
    const hdr = ['Equipo', 'Integrantes', 'Activos', 'Semiactivos', 'Nuevos',
      'Inactivos', 'En espera', 'Retirados', 'Cambios', 'Sin estado'];
    const tbl = htmlTable(hdr);
    rows.forEach(r => {
      tbl.tBodies[0].append(tr([
        r.nombre_equipo_proyecto,
        r.total_integrantes,
        r.activos, r.semiactivos, r.nuevos,
        r.inactivos, r.en_espera, r.retirados, r.cambios, r.sin_estado
      ]));
    });
    host.append(tbl);
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

    // 2) Creamos dos <canvas> para poner las dos gráficas
    const canvasGen = document.createElement('canvas');
    const canvasOtr = document.createElement('canvas');

    // 2.a) Wrapper para Equipos
    const wrapperA = document.createElement('div');
    wrapperA.innerHTML =
      '<h3>Eventos aprobados por equipo y estado final</h3>' +
      '<h4 style="font-weight:normal;margin-top:0">Solo con estado previo = Aprobado</h4>';
    wrapperA.appendChild(canvasGen);

    // 2.b) Wrapper para Proyectos
    const wrapperB = document.createElement('div');
    wrapperB.innerHTML =
      '<h3>Eventos aprobados por proyecto y estado final</h3>' +
      '<h4 style="font-weight:normal;margin-top:0">Solo con estado previo = Aprobado</h4>';
    wrapperB.appendChild(canvasOtr);

    // 3) Insertar ambos wrappers
    host.append(wrapperA, wrapperB);

    // 4) Asegurarnos de que Chart.js está cargado
    await loadChartJs();

    /* Preset para que la escala Y sólo muestre enteros */
    const yEnteros = {
      beginAtZero : true,
      ticks : {
        stepSize  : 1,   // cada tick vale 1
        precision : 0    // nunca decimales
      }
    };

    // ─── PARTE A: “general” (Equipos, es_equipo = 1) ───
    // 4.a) Extraer lista única de nombres de equipos (por si no viene ordenada)
    const equiposGen = Array.from(
      new Set(data.general.map(r => r.nombre_equipo_proyecto))
    );

    // 4.b) Extraer lista única y ordenada de nombres de estados
    //      Como sabemos que “data.general” ya trae todas las combinaciones (equipo×estado),
    //      basta con tomar todos los “nombre_estado_final” que aparezcan allí:
    const estadosGen = Array.from(
      new Set(data.general.map(r => r.nombre_estado_final))
    );
    estadosGen.sort(); // opcional: queda alfabético; si quieres otro orden, ajústalo

    // 4.c) Construir un array de dataset por cada estado, usando su nombre real:
    const datasetsGen = estadosGen.map(nombreEstado => ({
      label : nombreEstado,
      backgroundColor : COLOR_BY_STATE[nombreEstado] || pickExtraColor(),
      data  : equiposGen.map(eq => {
                const fila = data.general.find(r =>
                  r.nombre_equipo_proyecto === eq &&
                  r.nombre_estado_final    === nombreEstado
                );
                return fila ? fila.total : 0;
              })
    }));

    // 4.d) Crear la gráfica de barras
    new Chart(canvasGen, {
      type : 'bar',
      data : { labels : equiposGen, datasets : datasetsGen },
      options : {
        responsive : true,
        plugins    : { legend : { position : 'top' } },
        scales     : {
          x : { stacked : false },
          y : yEnteros
        }
      }
    });

    // ─── PARTE B: “otros” (Proyectos, es_equipo = 0) ───
    // 5.a) Lista única de nombres de proyectos
    const equiposOtr = Array.from(
      new Set(data.otros.map(r => r.nombre_equipo_proyecto))
    );

    // 5.b) Lista única de nombres de estados (la mayoría será la misma que en “estadosGen”,
    // pero por si hubiera un estado que solo aparece en proyectos, la sacamos de “data.otros”)
    const estadosOtr = Array.from(
      new Set(data.otros.map(r => r.nombre_estado_final))
    );
    estadosOtr.sort(); // opcional

    // 5.c) Construir datasets para “otros”
    const datasetsOtr = estadosOtr.map(nombreEstado => ({
      label : nombreEstado,
      backgroundColor : COLOR_BY_STATE[nombreEstado] || pickExtraColor(),
      data  : equiposOtr.map(eq => {
                const fila = data.otros.find(r =>
                  r.nombre_equipo_proyecto === eq &&
                  r.nombre_estado_final    === nombreEstado
                );
                return fila ? fila.total : 0;
              })
    }));

    // 5.d) Crear la gráfica de barras para “otros”
    new Chart(canvasOtr, {
      type : 'bar',
      data : { labels : equiposOtr, datasets : datasetsOtr },
      options : {
        responsive : true,
        plugins    : { legend : { position : 'top' } },
        scales     : {
          x : { stacked : false },
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

})();  // /IIFE
