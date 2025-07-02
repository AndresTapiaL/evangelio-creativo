<?php
/*  tickets.php  –  Gestión de boletería
    © Evangelio Creativo · 2025
-------------------------------------------------------------- */
declare(strict_types=1);
date_default_timezone_set('UTC');
session_start();
require 'conexion.php';

/* ─── 0) Seguridad  ─────────────────────────────────────────────
   Solo usuarios que pertenezcan al equipo_proyecto #1 (Liderazgo
   Nacional) y estén habilitados pueden abrir cualquier pantalla
   de boletería, incluida la pistola de escaneo.               */
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');  // no logeado
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

$auth = $pdo->prepare("
    SELECT 1
      FROM integrantes_equipos_proyectos
     WHERE id_usuario = ?
       AND id_equipo_proyecto = 1
       AND habilitado = 1
     LIMIT 1");
$auth->execute([$id_usuario]);

if (!$auth->fetchColumn()) {
    http_response_code(403);
    die('Acceso restringido – solo Liderazgo Nacional');
}

/* — Trae nombre y foto para el menú — */
$stmtUser = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
$stmtUser->execute(['id' => $id_usuario]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

/* ─── SOLO los eventos marcados como “boleteria_activa = 1” ─── */
$evt = $pdo->query("
      SELECT id_evento, nombre_evento,
             DATE_FORMAT(fecha_hora_inicio,'%d-%m-%Y %H:%i') AS inicia
        FROM eventos
       WHERE boleteria_activa = 1
  ORDER BY fecha_hora_inicio
")->fetchAll(PDO::FETCH_ASSOC);


/* ─── SI EL FORM “crear ticket” SE ENVÍA ────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['crear_ticket'])){

    $nombre   = trim($_POST['nombre_ticket']);
    $idevento = (int)$_POST['id_evento'];

    if ($nombre==='')  die('Nombre requerido');
    if (!$idevento)    die('Evento inválido');

    $stmt = $pdo->prepare("
        INSERT INTO eventos_tickets(id_evento,nombre_ticket,descripcion,
                                    precio_clp,cupo_total)
        VALUES (?,?,?,?,?)
    ");
    $stmt->execute([
        $idevento,
        $nombre,
        trim($_POST['descripcion']),
        (int)$_POST['precio'],
        (int)$_POST['cupo']
    ]);
    header("Location: tickets.php?ok=1");
    exit;
}

/* ─── Habilitar evento (POST) ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['activar_evt'])) {
    $idEvt = (int)$_POST['id_evento'];
    if ($idEvt) {
        $pdo->prepare("UPDATE eventos SET boleteria_activa = 1 WHERE id_evento = ?")
            ->execute([$idEvt]);
    }
    header('Location: tickets.php');
    exit;
}

/* ─── toggle “boleteria_activa” ──────────────────────────── */
if(isset($_GET['set_evt']) && isset($_GET['on'])){
    $pdo->prepare("UPDATE eventos SET boleteria_activa=? WHERE id_evento=?")
        ->execute([(int)$_GET['on'], (int)$_GET['set_evt']]);
    header('Location: tickets.php');
    exit;
}

/* ─── Listas para la UI ───────────────────────────────────── */
$inactiveEvt = $pdo->query("
      SELECT id_evento, nombre_evento,
             DATE_FORMAT(fecha_hora_inicio,'%d-%m-%Y %H:%i') AS inicia
        FROM eventos
       WHERE boleteria_activa = 0
         AND fecha_hora_inicio >= NOW()      -- ← solo eventos futuros
    ORDER BY fecha_hora_inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Eventos con boletería activa (cupos SIN duplicar) ─── */
$activeEvt = $pdo->query("
      SELECT  e.id_evento,
              e.nombre_evento,

              /* inscritos reales */
              COALESCE(ocu.ocupados,0)   AS ocupados,

              /* suma de cupos de todos los tickets del evento */
              COALESCE(cap.cupo_total,0) AS cupo_total

        FROM  eventos e

   /* ---- cupo total por evento (una sola vez por ticket) ---- */
   LEFT JOIN (
        SELECT id_evento, SUM(cupo_total) AS cupo_total
          FROM eventos_tickets
         GROUP BY id_evento
   ) cap ON cap.id_evento = e.id_evento

   /* ---- total de inscritos por evento ---- */
   LEFT JOIN (
        SELECT et.id_evento, COUNT(tu.id_ticket_usuario) AS ocupados
          FROM eventos_tickets et
          JOIN ticket_usuario tu
                ON tu.id_evento_ticket = et.id_evento_ticket
         GROUP BY et.id_evento
   ) ocu ON ocu.id_evento = e.id_evento

       WHERE  e.boleteria_activa = 1
    ORDER BY  e.fecha_hora_inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head>
  <meta charset="utf-8"><title>Tickets | Evangelio Creativo</title>
  <link rel="stylesheet" href="styles/main.css">
  <!-- ==== NAV: css + validación de token ==== -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Choices.js (buscador dentro del <select>) -->
  <link  href="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/styles/choices.min.css"
        rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/scripts/choices.min.js"
          defer></script>

  <style>
  /* ═══════════ 1. FUENTE POPPINS (latin) ═══════════ */
  @font-face{font-family:"Poppins";src:url("styles/poppins-v23-latin-400.woff2") format("woff2");font-weight:400;font-style:normal;font-display:swap;}
  @font-face{font-family:"Poppins";src:url("styles/poppins-v23-latin-500.woff2") format("woff2");font-weight:500;font-style:normal;font-display:swap;}
  @font-face{font-family:"Poppins";src:url("styles/poppins-v23-latin-600.woff2") format("woff2");font-weight:600;font-style:normal;font-display:swap;}
  @font-face{font-family:"Poppins";src:url("styles/poppins-v23-latin-700.woff2") format("woff2");font-weight:700;font-style:normal;font-display:swap;}

  /* ═══════════ 2. PALETA Y VARIABLES ═══════════ */
  :root{
    /* corporativo EC */
    --negro:#2e292c;
    --naranjo:#ff4200;       /* primario refinado */
    --naranjo-dark:#d63800;  /* hover */
    --blanco:#ffffff;
    --gris:#6d7280;
    --verde:#198754;
    --rojo:#d62828;

    /* semánticas */
    --bg-main:#f6f7fb;
    --bg-card:#ffffff;

    --primary:var(--naranjo);
    --primary-hover:var(--naranjo-dark);
    --success:var(--verde);
    --danger:var(--rojo);

    --radius:12px;
    --shadow:0 6px 20px rgba(0,0,0,.08);
    --transition:.2s ease;
    --w-acciones: 0px;          /* ancho visible de la columna Acciones */
  }

  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{
    font:400 15px/1.55 "Poppins",system-ui,sans-serif;
    background:var(--bg-main);
    color:var(--negro);
    min-height:100vh;
  }

  /* ═══════════ 3. ENCABEZADOS ═══════════ */
  h1{font-size:1.65rem;margin:2.2rem 0 .8rem;text-align:center}
  h2{font-size:1.3rem;margin:2rem 0 1rem;color:var(--primary)}

  /* ═══════════ 4. CONTENEDOR DE SECCIONES ═══════════ */
  section{
    max-width:1200px;                  /* centrado y respiración */
    margin:0 auto 2rem;
    padding:2rem 2.2rem;
    background:var(--bg-card);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
  }
  @media(max-width:600px){
    section{padding:1.2rem;}
  }

  /* ═══════════ 5. TABLAS ═══════════ */

  /* ░░░  Columna Acciones fija en viewport  ░░░ */
  .tbl-scroll {
    overflow-x: auto;          /* crea el scroll solo para la tabla */
    position: relative;        /* referencia para el sticky */
    padding-right:var(--w-acciones);
  }

  .tbl-scroll table{
    min-width: 100%;           /* asegura necesidad de scroll */
  }

  /* hace que la tabla “invada” el padding-right */
  .tbl-scroll > table{
      margin-right: calc(-1 * var(--w-acciones));
  }

  /* ---------- CELDA STICKY ------------------------------------ */
  .tbl-scroll th:last-child,
  .tbl-scroll td:last-child{
    position:sticky;
    right: -.5px;
    width:var(--w-acciones);
    min-width:var(--w-acciones);
    background:#fff;
    box-shadow:-4px 0 6px -4px rgba(0,0,0,.12);
    z-index:5;
  }

  /* ---------- CORTINA QUE TAPA EL DESBORDE -------------------- */
  .tbl-scroll::after{
    content:'';
    position:absolute;
    top:0;
    right:0;
    width:var(--w-acciones);      /* mismo ancho que la columna sticky   */
    height:100%;
    background:#fff;              /* mismo color que las celdas          */
    pointer-events:none;          /* para no interceptar clics            */
    z-index:4;                    /* por debajo de la celda sticky        */
  }

  section table{
    width:100%;min-width:100%;         /* permite scroll */
    border-collapse:collapse;
    font-size:.9rem;
    background:#fff;
    border:1px solid #e5e7f0;
    border-radius:10px;
  }
  thead{
    background:#f8f9fe;
  }
  th,td{padding:.75rem 1rem;text-align:left;white-space:nowrap}
  th{color:var(--gris);font-weight:600;border-bottom:2px solid #eef0f8}
  tbody tr:nth-child(odd){background:#fcfcff}
  tbody tr:hover{background:#f2f4ff}

  /* columna de acciones pegada a la derecha */
  td:last-child,th:last-child{
    position:sticky;right:0;
    background:#fff;
    box-shadow:-4px 0 6px -4px rgba(0,0,0,.12);
  }

  .tbl-scroll td:last-child,
  .tbl-scroll th:last-child{
    z-index:2;                 /* asegura que se vea encima */
  }

  /* ═══════════ 6. BOTONES GENERALES ═══════════ */
  button,a.btn,.btn-prim{
    display:inline-flex;align-items:center;gap:.4rem;
    padding:.45rem .9rem;
    border:0;border-radius:8px;
    background:var(--primary);
    color:#fff;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    transition:background var(--transition);
  }
  button:hover,a.btn:hover,.btn-prim:hover{background:var(--primary-hover)}

  /* +Añadir, Activar, etc. (ya usan <button>) no necesitan extra CSS */

  /* botones de acción dentro de tablas */
  .action-btn, td a, td button{
    background:#f5f6fa;
    border:1px solid #dfe1ea;
    color:var(--primary);
    border-radius:8px;
    padding:.35rem .7rem;
    font-size:.78rem;
    font-weight:600;
    display:inline-flex;align-items:center;gap:.35rem;
    transition:all var(--transition);
  }
  .action-btn:hover, td a:hover, td button:hover{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
  }
  td a[href*="del"], td a[href*="del_"],
  td button[class*="del"],
  td a i.fa-trash{color:var(--danger);}
  td button.edit-ticket i,
  td button.edit-hor i,
  td .edit-usr i{color:var(--success);}

  /* ═══════════ 7. SELECT BONITO (ya usado) ═══════════ */
  select.nice{
    appearance:none;-webkit-appearance:none;-moz-appearance:none;
    padding:.55rem 2.2rem .55rem .8rem;
    border:1px solid #d8dbe7;border-radius:8px;
    background:var(--blanco) url("data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2010%206'%20fill='%236d7280'%3e%3cpath%20d='M0%200l5%206%205-6z'/%3e%3c/svg%3e") no-repeat right .75rem center/10px 6px;
    cursor:pointer;transition:border-color var(--transition);
  }
  select.nice:focus{border-color:var(--primary);box-shadow:0 0 0 2px #dfe2ff}

  /* ═══════════ 8. DIALOGOS HTML5 ═══════════ */
  dialog{
    border:0;border-radius:var(--radius);
    max-width:clamp(320px,90vw,600px);
    width:100%;
    box-shadow:var(--shadow);
    padding:0;
  }
  dialog::backdrop{background:rgba(0,0,0,.45)}
  dialog form{padding:1.6rem}
  dialog h3{margin:0 0 1rem}
  dialog label{display:flex;flex-direction:column;font-size:.85rem;margin-bottom:1rem}
  dialog input,dialog textarea,dialog select{
    font:inherit;padding:.55rem .8rem;border:1px solid #d8dbe7;border-radius:8px;
    resize:vertical;min-width:0;
  }
  .dlg-btns{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1rem}
  .dlg-btns button:first-child{background:var(--primary)}
  .dlg-btns button[type=button]{background:#e0e3ee;color:var(--negro)}
  .dlg-btns button[type=button]:hover{background:#cfd3e1}

  /* ═══════════ 9. FLASH OK ═══════════ */
  .ok{
    background:#dff5e5;border:1px solid #9bdfaf;color:#117d3e;
    padding:.8rem 1rem;border-radius:var(--radius);
    max-width:420px;margin:1rem auto;text-align:center;font-weight:600;
  }

  /* ═══════════ 10. RESPONSIVE EXTRA ═══════════ */
  @media(max-width:850px){
    nav .menu{display:none}           /* si el nav lo incluye */
    section{padding:1rem}
    th,td{padding:.55rem .6rem}
    h1{font-size:1.45rem}
  }

  /* ═══════════ 11. SCROLLBAR PERSONALIZADO ═══════════ */
  ::-webkit-scrollbar{height:8px;width:8px;}
  ::-webkit-scrollbar-thumb{background:#c5c9d6;border-radius:8px;}
  ::-webkit-scrollbar-thumb:hover{background:#a9afc4;}

  /* Choices – igualamos con .nice */
  .choices,
  .choices__inner{
    width:100%;
    border-radius:8px;
    font:inherit;
  }

  .choices__inner{
    padding:.55rem .8rem;
    border:1px solid #d8dbe7;
  }

  .is-focused .choices__inner{
    border-color:var(--primary);
    box-shadow:0 0 0 2px #dfe2ff;
  }

  /* barra de búsqueda interna */
  .choices__input--cloned{
    padding:.3rem .4rem;
  }
  </style>

  <!-- ═════════ Validación única al cargar la página ═════════ -->
  <script>
  (() => {
    const token = localStorage.getItem('token');
    if (!token) { location.replace('login.html'); return; }
    const ctrl = new AbortController();
    window.addEventListener('beforeunload', ()=> ctrl.abort());

    validarToken(ctrl.signal)
      .catch(err => {
        if (err.message === 'TokenNoValido') {
          localStorage.clear();
          location.replace('login.html');
        }
      });

    async function validarToken(signal) {
      let res;
      try {
        res = await fetch('validar_token.php', {
          headers: { 'Authorization': 'Bearer ' + token },
          signal
        });
      } catch(e) {
        if (e.name === 'AbortError') throw e;
        throw new Error('NetworkFail');
      }
      if (res.status === 401) throw new Error('TokenNoValido');
      const data = await res.json();
      if (!data.ok) throw new Error('TokenNoValido');
    }
  })();
  </script>
</head><body>
<?php require_once 'navegador.php'; ?>
<h1>Boletería de Eventos</h1>

<?php
/* lista completa para el switch  */
$allEvt = $pdo->query("
      SELECT id_evento, nombre_evento, boleteria_activa
        FROM eventos
    ORDER BY fecha_hora_inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- ① Habilitar evento para boletería -->
<section>
  <h2>Habilitar evento para boletería</h2>

  <form method="post" style="max-width:420px">
    <label style="display:block">
      Evento
      <select id="sel-evento"
              name="id_evento"
              class="nice js-choice"
              required>
        <option value="">— Seleccionar —</option>
        <?php foreach ($inactiveEvt as $e): ?>
          <option value="<?=$e['id_evento']?>">
            <?=$e['nombre_evento']?> (<?=$e['inicia']?>)
          </option>
        <?php endforeach ?>
      </select>
    </label>

    <button name="activar_evt" value="1" style="margin-top:1rem">
      Activar
    </button>
  </form>
</section>

<!-- ② Eventos con boletería activa -->
<section>
  <h2>Eventos con boletería activa</h2>

  <div class="tbl-scroll">
    <table>
      <thead>
        <tr><th>Evento</th><th>Cupo / Total</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($activeEvt as $e): ?>
          <tr>
            <td><?=htmlspecialchars($e['nombre_evento'])?></td>
          <td><?= isset($e['ocupados']) ? $e['ocupados'] : 0 ?> /
              <?= isset($e['cupo_total']) ? $e['cupo_total'] : 0 ?></td>
            <td>
              <a href="ticket_detalle.php?evt=<?=$e['id_evento']?>" class="action-btn">
                <i class="fa-solid fa-gear"></i> Gestionar
              </a>
              <a href="?set_evt=<?=$e['id_evento']?>&on=0"
                class="action-btn"
                onclick="return confirm('¿Eliminar evento de boletería?')">
                <i class="fa-solid fa-trash"></i> Eliminar
              </a>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.querySelector('.js-choice');   // nuestro <select>

  if (!sel) return;

  /* instanciamos Choices */
  const ch = new Choices(sel, {
     searchEnabled         : true,
     shouldSort            : false,   // mantiene el orden original
     itemSelectText        : '',      // quita texto “Press to select”
     placeholderValue      : '— Seleccionar —',
     searchPlaceholderValue: 'Buscar…',
     position              : 'bottom',   // lista debajo
     classNames            : {           // conservamos tu estilo “nice”
        containerInner : 'choices__inner nice'
     }
  });
});
</script>

<script src="heartbeat.js"></script>
<script>
document.getElementById('logout').addEventListener('click', async e => {
  e.preventDefault();
  const token = localStorage.getItem('token');
  if (!token){ localStorage.clear(); return location.replace('login.html'); }
  try{
    const res  = await fetch('cerrar_sesion.php', {
      method:'POST',
      headers:{'Authorization':'Bearer '+token}
    });
    const j = await res.json();
    if(j.ok){ localStorage.clear(); location.replace('login.html'); }
    else     alert('No se pudo cerrar sesión: '+(j.error||'')); 
  }catch(err){
    console.error(err);
    localStorage.clear();
    location.replace('login.html');
  }
});
</script>

</body></html>
