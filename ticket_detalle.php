<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('UTC');
require 'conexion.php';

/* ─── helper: aborta mostrando JSON si llega vía AJAX ─── */
function abort(string $msg, int $http = 400): never {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    http_response_code($http);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        echo $msg;        // comportamiento anterior (fallback)
    }
    exit;
}

/* ─── NUEVO: verificación de GD ─────────────────────────────── */
if (!extension_loaded('gd')) {
    http_response_code(500);          // error interno
    abort('La extensión GD no está habilitada. '
       .'Edite php.ini →  extension=gd  y reinicie Apache.');
}

/* ─── utilidades QR ─────────────────────────────────────────── */
require_once __DIR__.'/lib/phpqrcode/qrlib.php';   // <— librería
function generarQR(string $hash,string $dest):void{
    /* El QR contendrá la URL completa.
       – El escáner solo leerá ?code=…                     */
    $url = 'http://localhost/PW%20EC_Antes/scan_qr.php?code='.$hash;

    // crea /qr si no existe
    $dir = dirname($dest);
    if (!is_dir($dir)) mkdir($dir,0775,true);

    /* nivel Q, tamaño 4, margen 2 */
    QRcode::png($url,$dest, QR_ECLEVEL_Q, 4, 2);
}

if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

$auth = $pdo->prepare("
     SELECT 1 FROM integrantes_equipos_proyectos
      WHERE id_usuario=? AND id_equipo_proyecto=1 AND habilitado=1
      LIMIT 1");
$auth->execute([$id_usuario]);

/* — Trae nombre y foto para el menú — */
$stmtUser = $pdo->prepare("
  SELECT nombres, foto_perfil
    FROM usuarios
   WHERE id_usuario = :id
");
$stmtUser->execute(['id' => $id_usuario]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$auth->fetchColumn()){
    http_response_code(403);
    abort('Acceso restringido');
}

$idTicket = (int)($_GET['id'] ?? 0);

/* ─── Determinar evento a gestionar  ───────────────────────────── */
$idEvento = (int)($_GET['evt'] ?? 0);   // NUEVA URL canónica

/* Si llegó ?id=… (p.e. al editar un ticket) ⇒ averiguamos su evento
   y redirigimos a ?evt=… para unificar la ruta.                    */
if (!$idEvento && isset($_GET['id'])) {
    $tmp = $pdo->prepare("SELECT id_evento
                            FROM eventos_tickets
                           WHERE id_evento_ticket = ?");
    $tmp->execute([(int)$_GET['id']]);
    $idEvento = (int)$tmp->fetchColumn();
    if ($idEvento) {
        /* ─── si vino vía AJAX devolvemos éxito ─── */
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        }

        header("Location: ticket_detalle.php?evt=$idEvento");
        exit;
    }
}

if (!$idEvento) abort('Evento no especificado');

/* ─── Datos del evento (siempre existen) ──────────────────────── */
$evtRow = $pdo->prepare("
        SELECT id_evento,
               nombre_evento,
               boleteria_activa
          FROM eventos
         WHERE id_evento = ?
         LIMIT 1");
$evtRow->execute([$idEvento]);
$evtRow = $evtRow->fetch(PDO::FETCH_ASSOC) ?: abort('Evento no encontrado');

/* ─── Tickets del evento (puede estar vacío) ─────────────────── */
$ticketStmt = $pdo->prepare("
        SELECT et.*,
               (SELECT COUNT(*) FROM ticket_usuario tu
                 WHERE tu.id_evento_ticket = et.id_evento_ticket) AS ocupados
          FROM eventos_tickets et
         WHERE et.id_evento = ?
      ORDER BY et.id_evento_ticket DESC");
$ticketStmt->execute([$idEvento]);
$tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

/* ------ Determinar ticket “activo” para inscripciones ---------
   – Si la URL trae ?tkt=… usaremos ese.
   – De lo contrario tomamos el primero de la lista (si existe).  */
$idTicket = isset($_GET['tkt'])
          ? (int)$_GET['tkt']
          : ($tickets[0]['id_evento_ticket'] ?? 0);

/*  Ticket actualmente seleccionado (o arreglo vacío) */
$currentTicket = [];
foreach ($tickets as $t){
    if ($t['id_evento_ticket'] === $idTicket){ $currentTicket = $t; break; }
}

/* “Ticket fantasma” si aún no hay ninguno */
if (!$currentTicket){
    $currentTicket = [
        'id_evento_ticket'=>0,'nombre_ticket'=>'',
        'cupo_total'=>0,'ocupados'=>0,'activo'=>1
    ];
}
$ticket = $currentTicket + [
    'id_evento'        => $evtRow['id_evento'],
    'nombre_evento'    => $evtRow['nombre_evento'],
    'boleteria_activa' => $evtRow['boleteria_activa']
];

/* ---------- Alta / Edición inscripción ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_usr'])) {

    $idUsr = (int)($_POST['id_ticket_usuario'] ?? 0);     // 0 ⇒ alta
    $idTicketSel = (int)($_POST['id_evento_ticket'] ?? 0);
    /* --- capacidad disponible --- */
    $cap = $pdo->prepare("
          SELECT cupo_total,
                  (SELECT COUNT(*) FROM ticket_usuario
                    WHERE id_evento_ticket = ?) AS usados
            FROM eventos_tickets
            WHERE id_evento_ticket = ? LIMIT 1");
    $cap->execute([$idTicketSel,$idTicketSel]);
    list($cupoTotal,$usados) = $cap->fetch(PDO::FETCH_NUM);

    if ($idUsr==0 && $usados >= $cupoTotal){
        abort('No quedan cupos disponibles para este ticket.');
    }
    if (!$idTicketSel) $idTicketSel = $idTicket;   // respaldo
    $data  = [
        'email'  => filter_input(INPUT_POST,'correo',FILTER_VALIDATE_EMAIL),
        'nombre' => trim($_POST['nombre']),
        'cel'    => trim($_POST['cel']),
        'edad'   => (int)$_POST['edad'],
        'equipo' => trim($_POST['equipo'])
    ];
    if (!$data['email'] || $data['nombre']==='' || $data['cel']==='') {
        abort('Campos obligatorios vacíos');
    }

    if ($idUsr) {                      /* ---- UPDATE ---- */

        /* ①- obtener a qué ticket estaba ligado antes  */
        $oldTk = $pdo->prepare("
                SELECT id_evento_ticket
                  FROM ticket_usuario
                 WHERE id_ticket_usuario = ?
                 LIMIT 1");
        $oldTk->execute([$idUsr]);
        $oldTicket = (int)$oldTk->fetchColumn();

        /* ②- actualizar datos, incluido el nuevo id_evento_ticket  */
        $pdo->prepare("
           UPDATE ticket_usuario SET
              id_evento_ticket       = ?,                          -- ← NUEVO
              correo_electronico     = ?, nombre_completo       = ?, contacto = ?, edad = ?,
              equipo                 = ?, alimentacion          = ?, hospedaje = ?, enfermedad = ?,
              alergia                = ?, medicamentos          = ?, alimentacion_especial = ?,
              contacto_emergencia    = ?, credencial            = ?, acompanantes = ?, extras = ?
         WHERE id_ticket_usuario = ?")
        ->execute([
            $idTicketSel,                                            // ← NUEVO
            $data['email'],$data['nombre'],$data['cel'],$data['edad'],$data['equipo'],
            $_POST['alimento'],$_POST['hospedaje'],$_POST['enfermedad'],
            $_POST['alergia'],$_POST['medicamentos'],$_POST['alim_esp'],
            $_POST['contacto_emerg'],$_POST['credencial'],$_POST['acompanantes'],
            $_POST['extras'],$idUsr
        ]);

    } else {                           /* ---- INSERT ---- */
        $hash = hash('sha256', uniqid($data['email'],true));

        $pdo->prepare("
           INSERT INTO ticket_usuario(
             id_evento_ticket, correo_electronico, nombre_completo, contacto, edad, equipo,
             alimentacion, hospedaje, enfermedad, alergia, medicamentos,
             alimentacion_especial, contacto_emergencia,
             credencial, acompanantes, extras, qr_codigo)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?)")
        ->execute([
            $idTicketSel, $data['email'], $data['nombre'], $data['cel'], $data['edad'], $data['equipo'],
            $_POST['alimento'], $_POST['hospedaje'], $_POST['enfermedad'], $_POST['alergia'],
            $_POST['medicamentos'], $_POST['alim_esp'], $_POST['contacto_emerg'],
            $_POST['credencial'], $_POST['acompanantes'], $_POST['extras'], $hash
        ]);
    }

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento&tkt=$idTicketSel&ok=1");
    exit;
}

/* ─── eliminar ticket ------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_ticket'])){
    $pdo->prepare("
        DELETE FROM eventos_tickets
         WHERE id_evento_ticket = ? AND id_evento = ?")
        ->execute([(int)$_POST['del_ticket'],$idEvento]);
    
    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento"); exit;
}

/* ─── crear / actualizar ticket ------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_ticket'])) {

    /* 1 – recoger y sanear --------------------- */
    $idET     = (int)$_POST['id_evento_ticket'];
    $nom      = trim($_POST['nombre_ticket'] ?? '');
    $desc     = trim($_POST['descripcion'] ?? '');
    $precio   = (int)($_POST['precio_clp']  ?? 0);
    $cupo     = (int)($_POST['cupo_total']  ?? 0);
    $activo   = (int)($_POST['activo']      ?? 1);

    /* 2 – regex de seguridad ------------------- */
    $pat = '/^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u';
    if ($nom==='' || mb_strlen($nom)>100 || !preg_match($pat,$nom))
        abort('Nombre de ticket no válido.');
    if ($desc!=='' && !preg_match($pat,$desc))
        abort('Descripción no válida.');

    if ($precio<0 || $cupo<0) abort('Valores numéricos incorrectos.');

    /* 3 – regla cupo ≥ inscritos --------------- */
    if ($idET){
        $stmt = $pdo->prepare("
              SELECT COUNT(*) FROM ticket_usuario
               WHERE id_evento_ticket = ?");
        $stmt->execute([$idET]);
        $ocupados = (int)$stmt->fetchColumn();
        if ($cupo < $ocupados)
            abort("El cupo total ($cupo) no puede ser menor que los inscritos ($ocupados).");
    }

    /* 4 – INSERT o UPDATE ---------------------- */
    if ($idET){
        $pdo->prepare("
           UPDATE eventos_tickets
              SET nombre_ticket=?, descripcion=?, precio_clp=?,
                  cupo_total=?,  activo=?
            WHERE id_evento_ticket=? AND id_evento=?")
        ->execute([$nom,$desc,$precio,$cupo,$activo,$idET,$idEvento]);
    }else{
        $pdo->prepare("
           INSERT INTO eventos_tickets(id_evento,nombre_ticket,descripcion,
                                        precio_clp,cupo_total,activo)
           VALUES(?,?,?,?,?,?)")
        ->execute([$idEvento,$nom,$desc,$precio,$cupo,$activo]);
    }

    /* ─── si vino vía AJAX devolvemos éxito en JSON ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
      && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento"); exit;
}

/* ─── CRUD horarios ---------------------------------------- */
// // BEGIN MOD 3 : CRUD horarios ligado al evento
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_hor'])) {
    $idH   = (int)$_POST['id_ticket_horario'];
    $nom   = trim($_POST['nombre_horario']);
    $ini   = $_POST['fecha_inicio'];
    $fin   = $_POST['fecha_fin'];

    if ($nom===''||!$ini||!$fin) abort('Datos de horario incompletos');

    if ($idH) {                      // UPDATE
        $pdo->prepare("
           UPDATE ticket_horarios
              SET nombre_horario=?, fecha_inicio=?, fecha_fin=?
            WHERE id_ticket_horario=? AND id_evento=?")
            ->execute([$nom,$ini,$fin,$idH,$idEvento]);
    } else {                         // INSERT
        $pdo->prepare("
           INSERT INTO ticket_horarios(id_evento,nombre_horario,fecha_inicio,fecha_fin)
           VALUES(?,?,?,?)")
           ->execute([$idEvento,$nom,$ini,$fin]);
    }

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

if(isset($_GET['del_hor'])){
    $pdo->prepare("DELETE FROM ticket_horarios WHERE id_ticket_horario=?")
        ->execute([(int)$_GET['del_hor']]);

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* ─── admins ------------------------------------------------ */
/* alta de admin buscando por id_pais + rut_dni */
if (isset($_POST['add_admin'])) {
    $idPais = (int)$_POST['id_pais'];
    $rut    = trim($_POST['rut_dni']);

    /* localizar usuario */
    $uidStmt = $pdo->prepare("
        SELECT id_usuario
          FROM usuarios
         WHERE id_pais = ? AND rut_dni = ?
         LIMIT 1");
    $uidStmt->execute([$idPais, $rut]);
    $uid = (int)$uidStmt->fetchColumn();

    if (!$uid) abort('Usuario no encontrado');

    $pdo->prepare("
        INSERT IGNORE INTO ticket_admins(id_evento,id_usuario)
        VALUES(?,?)")->execute([$idEvento,$uid]);

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento"); exit;

    /* asignarlo a TODOS los tickets del evento */
    foreach ($tickets as $t) {
        $pdo->prepare("
            INSERT IGNORE INTO ticket_admins(id_evento_ticket,id_usuario)
            VALUES(?,?)")
            ->execute([$t['id_evento_ticket'], $uid]);
    }

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* baja de admin (se sigue pasando id_usuario) */
if (isset($_GET['del_admin'])) {
    $pdo->prepare("
        DELETE FROM ticket_admins
         WHERE id_evento = ? AND id_usuario = ?")
        ->execute([$idEvento,(int)$_GET['del_admin']]);

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento"); exit;
}

/* delete usuario */
if(isset($_GET['del_usr'])){
    $pdo->prepare("
        DELETE FROM ticket_usuario WHERE id_ticket_usuario=?")
        ->execute([(int)$_GET['del_usr']]);

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

if (isset($_GET['del_usr'])){
    $stmt = $pdo->prepare("
            SELECT qr_codigo FROM ticket_usuario
             WHERE id_ticket_usuario = ?");
    $stmt->execute([(int)$_GET['del_usr']]);
    $hash = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM ticket_usuario WHERE id_ticket_usuario=?")
        ->execute([(int)$_GET['del_usr']]);

    /* ─── si vino vía AJAX devolvemos éxito ─── */
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8">
<title>Boletería – <?=htmlspecialchars($evtRow['nombre_evento'])?></title>
<link rel="stylesheet" href="styles/main.css">
  <!-- ==== NAV: css + validación de token ==== -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- JSZip – necesario antes de cualquier script que lo use -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"
          crossorigin="anonymous" referrerpolicy="no-referrer"></script>

  <style>
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
    --w-fijo-r: -100px;      /* ancho fijo de la columna Acciones */
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

  /* ═══════════ Columna Acciones fija a la derecha ═══════════ */
  .tbl-scroll{
    overflow-x:auto;      /* scroll solo cuando haga falta */
    position:relative;
  }

  /* la tabla crece lo necesario para abarcar todas las columnas,
    pero nunca será menor al 100 % del contenedor */
  .tbl-scroll table{
    width:max-content;          /* ancho natural = suma de columnas  */
    min-width:100%;             /* …salvo que el padre sea más ancho */
  }

  /* celda sticky */
  .tbl-scroll th:last-child,
  .tbl-scroll td:last-child{
    position:sticky;
    right:0;
    width:var(--w-fijo-r);
    min-width:var(--w-fijo-r);
    background:inherit;   /* mantiene las franjas zebra */
    z-index:2;
  }

  /* mismo fondo zebra que el resto de la fila */
  .tbl-scroll thead th:last-child               {background:#f8f9fe;}
  .tbl-scroll tbody tr:nth-child(odd)  td:last-child{background:#fcfcff;}
  .tbl-scroll tbody tr:nth-child(even) td:last-child{background:#fff;}
  .tbl-scroll tfoot tr:nth-child(odd)  td:last-child{background:#fcfcff;}
  .tbl-scroll tfoot tr:nth-child(even) td:last-child{background:#fff;}

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
  /* ─── cabecera sin texto para la columna QR ─── */
  th.no-title{background:#f8f9fe;border-bottom:2px solid #eef0f8}

  /* mismo fondo pegado para el <thead> de la columna sticky */
  .tbl-scroll th:last-child{background:#f8f9fe}

  /* pie de tabla (en caso de agregar <tfoot>) */
  .tbl-scroll tfoot tr:nth-child(odd)  td:first-child{background:#fcfcff;}
  .tbl-scroll tfoot tr:nth-child(even) td:first-child{background:#fff;}

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

  dialog[open]{
    position:fixed;
    top:50%; left:50%;
    transform:translate(-50%,-50%);
  }

  /* evita que celdas ocultas creen patrones raros (bug de algunos navegadores) */
  table.paginated tbody tr[style*="display: none"] td{
    background:transparent!important;
  }

  /* mismo archivo <style> */
  label{position:relative}
  label small.err{
    position:absolute;bottom:-1.3rem;left:.1rem;
    color:var(--danger);font-size:.7rem;font-weight:600;opacity:0;
    transition:opacity .15s;
  }
  label.error small.err{opacity:1}

  /* ─── añade este bloque al final del <style> principal ─── */
  form.inline-del{
    display:inline-block;   /* o inline; ambas funcionan */
    margin:0;
  }

  /* ─── MARK: inputs y textareas inválidos ─── */
  label.error input,
  label.error textarea {
    border: 1px solid var(--danger);
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

<!-- ░░ cabecera con botón Volver + título ░░ -->
<div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:1.6rem">
  <a href="tickets.php" class="action-btn" style="font-size:.85rem">
    <i class="fa-solid fa-arrow-left"></i> Volver
  </a>
  <h1 style="margin:0"><?=htmlspecialchars($evtRow['nombre_evento'])?></h1>
</div>

<?php
/* ─── inscritos del evento (todos los tickets) ──────────────── */
$ins = $pdo->prepare("
      SELECT  tu.*,                                -- todos los campos de la inscripción
              DATE_FORMAT(tu.fecha_inscripcion,'%d-%m-%Y %H:%i') AS fh,
              et.nombre_ticket                     -- nombre del tipo de ticket
        FROM  ticket_usuario tu
        JOIN  eventos_tickets et
              ON et.id_evento_ticket = tu.id_evento_ticket
       WHERE  et.id_evento = ?
    ORDER BY  et.nombre_ticket, tu.fecha_inscripcion DESC");
$ins->execute([$idEvento]);
$ins = $ins->fetchAll(PDO::FETCH_ASSOC);
/* ─── Totales globales de inscritos / cupos del evento ───────── */
$totalIns  = array_sum(array_column($tickets,'ocupados'));
$totalCupo = array_sum(array_column($tickets,'cupo_total'));
?>

<!-- Tabla de tickets + modales -->
<section>
  <h2>Tickets</h2>

  <button id="btnAddTicket">➕ Añadir ticket</button>

  <div class="tbl-scroll">
    <table id="tblTickets" class="paginated sortable" data-per-page="10">
      <thead>
        <tr>
          <th>Ticket</th>
          <th>Descripción</th>
          <th>Precio CLP</th>
          <th>Creados / Total</th>
          <th>Activo</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?=htmlspecialchars($t['nombre_ticket'])?></td>
          <td><?=htmlspecialchars($t['descripcion'])?></td>
          <td>$<?=number_format($t['precio_clp'],0,',','.')?></td>
          <td><?= isset($t['ocupados']) ? $t['ocupados'] : 0 ?> /
              <?= isset($t['cupo_total']) ? $t['cupo_total'] : 0 ?></td>
          <td><?=$t['activo']?'Sí':'No'?></td>
          <td>
            <button class="edit-ticket" data-json='<?=json_encode($t,JSON_HEX_APOS)?>'>
              <i class="fa-solid fa-edit"></i> Editar
            </button>
            <form method="post" action="?evt=<?=$idEvento?>" class="inline-del"
                  onsubmit="return confirm('¿Eliminar ticket definitivamente?')">
              <input type="hidden" name="del_ticket" value="<?=$t['id_evento_ticket']?>">
              <button class="action-btn"><i class="fa-solid fa-trash"></i> Eliminar</button>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <div id="pager-tblTickets" class="table-pager"></div>
  </div>
</section>

<!-- Modal CREAR / EDITAR ticket -->
<dialog id="dlgTicket">
  <form method="post" id="fTicket" novalidate>
    <h3 id="dlgTicketTitle"></h3>
    <input type="hidden" name="save_ticket" value="1">
    <input type="hidden" name="id_evento_ticket" id="t_id">

    <label>Nombre
      <input  name="nombre_ticket" id="t_nom"
              maxlength="100" required
              pattern="^[A-Za-z0-9\u00C0-\u024F .,#¿¡!?()\/\-]+$"
              title="Solo letras, números y . , # ¿ ¡ ! ? ( ) / -"
              oninput="valTicket()">
      <small class="err"></small>
    </label>

    <label>Descripción
      <textarea name="descripcion" id="t_desc" rows="2"
                maxlength="255"
                pattern="^[A-Za-z0-9\u00C0-\u024F .,#¿¡!?()\/\-]+$"
                title="Solo letras, números y . , # ¿ ¡ ! ? ( ) / -"
                oninput="valTicket()"></textarea>
      <small class="err"></small>
    </label>

    <label>Precio CLP
      <input type="number" name="precio_clp" id="t_prec"
            min="0" step="1" required oninput="valTicket()">
      <small class="err"></small>
    </label>

    <label>Cupo total
      <input type="number" name="cupo_total" id="t_cupo"
            min="0" step="1" required oninput="valTicket()">
      <small class="err"></small>
    </label>

    <label>Activo
      <select name="activo" id="t_activo">
        <option value="1">Sí</option><option value="0">No</option>
      </select>
    </label>

    <div class="dlg-btns">
      <button type="submit">Guardar</button>
      <button type="button" id="t_cancel">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
// ===== tickets: abrir modal crear =====
btnAddTicket.onclick = () => {
  fTicket.reset();
  t_id.value = '';
  dlgTicketTitle.textContent = 'Nuevo ticket';
  dlgTicket.showModal();
  setTimeout(valTicket, 0);    // <─ disparar validación al vuelo
};
// ===== tickets: abrir modal editar =====
document.querySelectorAll('.edit-ticket').forEach(btn=>{
  btn.onclick = () => {
    const d = JSON.parse(btn.dataset.json);
    dlgTicketTitle.textContent = 'Editar ticket';
    t_id.value    = d.id_evento_ticket;
    t_nom.value   = d.nombre_ticket;
    t_desc.value  = d.descripcion||'';
    t_prec.value  = d.precio_clp;
    t_cupo.value  = d.cupo_total;
    t_activo.value= d.activo;
    dlgTicket.showModal();
    setTimeout(valTicket, 0);  // <─ disparar validación al vuelo
  };
});
// ===== cancelar =====
t_cancel.onclick = ()=> dlgTicket.close();
</script>

<?php
$horarios = $pdo->prepare("
      SELECT id_ticket_horario,
             nombre_horario,
             fecha_inicio,
             fecha_fin
        FROM ticket_horarios
       WHERE id_evento = ?
    ORDER BY fecha_inicio");
$horarios->execute([$idEvento]);
$horarios = $horarios->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- // BEGIN MOD 4 : tabla horarios + modal -->
<section>
  <h2>Horarios de acreditación</h2>

  <button id="btnAddHor">➕ Añadir horario</button>

  <div class="tbl-scroll">
    <table id="tblHorarios" class="paginated sortable" data-per-page="10">
      <thead><tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($horarios as $h): ?>
          <tr>
            <td><?=htmlspecialchars($h['nombre_horario'])?></td>

            <!-- Muestra DD‑MM‑AAAA HH:MM (sin segundos) -->
            <td><?=htmlspecialchars(
                  date('d-m-Y H:i', strtotime($h['fecha_inicio']))
                )?></td>
            <td><?=htmlspecialchars(
                  date('d-m-Y H:i', strtotime($h['fecha_fin']))
                )?></td>

            <td>
              <button class="edit-hor" data-json='<?=json_encode($h,JSON_HEX_APOS)?>'>
                <i class="fa-solid fa-edit"></i> Editar
              </button>
              <a href="?evt=<?=$idEvento?>&del_hor=<?=$h['id_ticket_horario']?>" 
                onclick="return confirm('¿Eliminar horario?')">
                <i class="fa-solid fa-trash"></i> Eliminar
              </a>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <div id="pager-tblHorarios" class="table-pager"></div>
  </div>
</section>

<dialog id="dlgHor">
  <form method="post" id="fHor" novalidate>
    <h3 id="dlgHorTitle"></h3>
    <input type="hidden" name="save_hor" value="1">
    <input type="hidden" name="id_ticket_horario" id="h_id">

    <label>Nombre <input name="nombre_horario" id="h_nom" required></label>
    <label>Inicio  <input type="datetime-local" name="fecha_inicio" id="h_ini" required></label>
    <label>Fin     <input type="datetime-local" name="fecha_fin"    id="h_fin" required></label>

    <div class="dlg-btns">
      <button type="submit">Guardar</button>
      <button type="button" id="h_cancel">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
// ===== horarios: nuevo =====
btnAddHor.onclick = ()=>{
  fHor.reset(); h_id.value='';
  dlgHorTitle.textContent='Nuevo horario';
  dlgHor.showModal();
};
// ===== horarios: editar =====
document.querySelectorAll('.edit-hor').forEach(btn=>{
  btn.onclick=()=>{
    const d=JSON.parse(btn.dataset.json);
    dlgHorTitle.textContent='Editar horario';
    h_id.value = d.id_ticket_horario;
    h_nom.value= d.nombre_horario;
    h_ini.value= d.fecha_inicio.replace(' ','T');
    h_fin.value= d.fecha_fin.replace(' ','T');
    dlgHor.showModal();
  };
});
h_cancel.onclick = ()=> dlgHor.close();
</script>

<?php
// // BEGIN MOD 5 : query admins con nombre de país
$admins = $pdo->prepare("
   SELECT  ta.id_usuario,
           u.rut_dni,
           u.nombres,
           u.apellido_paterno,
           p.nombre_pais
     FROM  ticket_admins ta
     JOIN  usuarios      u USING(id_usuario)
     JOIN  paises        p ON p.id_pais = u.id_pais
    WHERE  ta.id_evento = ?
 ORDER BY u.nombres");
$admins->execute([$idEvento]);
$admins = $admins->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- // BEGIN MOD 6 : admins -->
<section>
  <h2>Administradores</h2>

  <button id="btnAddAdm">➕ Añadir administrador</button>

  <div class="tbl-scroll">
    <table id="tblAdmins" class="paginated sortable" data-per-page="10">
      <thead><tr><th>Nombre completo</th><th>RUT / DNI</th><th>País</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td><?=htmlspecialchars($a['nombres'].' '.$a['apellido_paterno'])?></td>
          <td><?=htmlspecialchars($a['rut_dni'])?></td>
          <td><?=htmlspecialchars($a['nombre_pais'])?></td>
          <td>
            <a href="?evt=<?=$idEvento?>&del_admin=<?=$a['id_usuario']?>"
              onclick="return confirm('¿Quitar privilegio de boletería?')">
              <i class="fa-solid fa-trash"></i> Eliminar
            </a>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <div id="pager-tblAdmins" class="table-pager"></div>
  </div>
</section>

<!-- modal admin -->
<dialog id="dlgAdm">
  <form method="post" id="fAdm" novalidate>
    <h3>Nuevo administrador</h3>
    <input type="hidden" name="add_admin" value="1">

    <label>País
      <select name="id_pais" id="a_pais" required>
        <option value="">— Seleccionar —</option>
        <?php
          $paises = $pdo->query("SELECT id_pais,nombre_pais FROM paises ORDER BY nombre_pais")->fetchAll(PDO::FETCH_ASSOC);
          foreach($paises as $p){
            echo "<option value='{$p['id_pais']}'>".htmlspecialchars($p['nombre_pais'])."</option>";
          }
        ?>
      </select>
    </label>

    <label>RUT / DNI
      <input name="rut_dni" id="a_rut" required pattern=".{3,20}">
    </label>

    <p id="a_preview" class="msg"></p>

    <div class="dlg-btns">
      <button type="submit" id="a_save" disabled>Guardar</button>
      <button type="button" id="a_cancel">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
// ===== mostrar modal =====
btnAddAdm.onclick = ()=>{ fAdm.reset(); a_preview.textContent=''; a_save.disabled=true; dlgAdm.showModal(); };
// ===== verifica usuario vía fetch simple (JSON) =====
async function checkUser(){
  a_save.disabled=true; a_preview.textContent='';
  if(!a_pais.value||!a_rut.value.trim()) return;
  const resp = await fetch('ajax_check_user.php', { // crea este endpoint si no existe
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({id_pais:a_pais.value,rut:a_rut.value.trim()})
  });
  const j = await resp.json();
  if(j.ok){
     a_preview.textContent = '✔ '+j.nombre;
     a_save.disabled=false;
  }else{
     a_preview.textContent = '✖ Usuario no encontrado';
  }
}
a_pais.onchange = a_rut.oninput = checkUser;
a_cancel.onclick = ()=> dlgAdm.close();
</script>

<script>
function editHor(h){
  id_hor.value     = h.id_ticket_horario;
  sel_ticket.value = h.id_evento_ticket;
  nom_hor.value    = h.nombre_horario;
  ini_hor.value    = h.fecha_inicio.replace(' ','T');
  fin_hor.value    = h.fecha_fin.replace(' ','T');
  window.scrollTo(0,0);
}
</script>

<script>
function editTicket(t){
  id_ticket.value   = t.id_evento_ticket;
  nom_ticket.value  = t.nombre_ticket;
  desc_ticket.value = t.descripcion||'';
  precio_ticket.value = t.precio_clp;
  cupo_ticket.value = t.cupo_total;
  activo_ticket.value = t.activo;
  window.scrollTo(0,0);
}
</script>

<!-- mensaje flash -->
<?php if(isset($_GET['ok'])): ?>
<p class="ok">✅ Inscripción guardada</p>
<?php endif; ?>

<section>
 <h2>Inscritos (<?=$totalIns?>/<?=$totalCupo?>)</h2>
 <button id="btnAddUsr">➕ Añadir inscripción</button>
 <a href="ticket_resumen.php?evt=<?=$idEvento?>"  class="action-btn" style="margin-left:.6rem">
    <i class="fa-solid fa-table-list"></i> Resumen
 </a>
 <a href="ticket_escaneados.php?evt=<?=$idEvento?>" class="action-btn" style="margin-left:.6rem">
    <i class="fa-solid fa-barcode"></i> Escaneados
 </a>
 <button id="btnZip" class="action-btn" style="margin-left:.6rem">
   <i class="fa-solid fa-file-zipper"></i> Descargar QRs
 </button>
  <div class="tbl-scroll">
    <table id="tblInscritos" class="paginated sortable" data-per-page="50">
      <thead>
        <tr>
          <th>ID</th><th>Ticket</th><th>Nombre</th><th>Correo electrónico</th><th>Fecha y hora de inscripción</th>
          <th>Contacto</th><th>Edad</th><th>Alimentación</th><th>Hospedaje</th>
          <th>Enfermedades</th><th>Alergia</th><th>Medicamentos</th>
          <th>Alimentación especial</th><th>Contacto de emergencia</th>
          <th data-type="text">Credencial</th>
          <th data-type="text">Acompañantes</th>
          <th data-type="text">Extras</th>
          <th></th>                      <!--  ← Acciones (sticky)-->
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ins as $u): ?>
        <tr>
          <td><?=$u['id_ticket_usuario']?></td>
          <td><?=htmlspecialchars($u['nombre_ticket'])?></td>
          <td><?=htmlspecialchars($u['nombre_completo'])?></td>
          <td><?=htmlspecialchars($u['correo_electronico'])?></td>
          <td><?=$u['fh']?></td>
          <td><?=htmlspecialchars($u['contacto'])?></td>
          <td><?=$u['edad']?></td>
          <td><?=htmlspecialchars($u['alimentacion'])?></td>
          <td><?=htmlspecialchars($u['hospedaje'])?></td>
          <td><?=htmlspecialchars($u['enfermedad'])?></td>
          <td><?=htmlspecialchars($u['alergia'])?></td>
          <td><?=htmlspecialchars($u['medicamentos'])?></td>
          <td><?=htmlspecialchars($u['alimentacion_especial'])?></td>
          <td><?=htmlspecialchars($u['contacto_emergencia'])?></td>
          <td><?=htmlspecialchars($u['credencial'])?></td>
          <td><?=htmlspecialchars($u['acompanantes'])?></td>
          <td><?=htmlspecialchars($u['extras'])?></td>
          <td>
            <button type="button" class="show-qr action-btn" data-code="<?=$u['qr_codigo']?>">
              <i class="fa-solid fa-qrcode"></i> QR
            </button>
            <button class="edit-usr action-btn"
                    data-json='<?=json_encode($u,JSON_HEX_APOS)?>'>
              <i class="fa-solid fa-edit"></i> Editar
            </button>
            <a href="?evt=<?=$idEvento?>&del_usr=<?=$u['id_ticket_usuario']?>"
              class="action-btn"
              onclick="return confirm('¿Eliminar inscripción?')">
              <i class="fa-solid fa-trash"></i> Eliminar
            </a>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <div id="pager-tblInscritos" class="table-pager"></div>
  </div>
</section>
<!-- Modal CREAR / EDITAR inscripción -->
<dialog id="dlgUsr">
  <form method="post" id="fUsr" novalidate>
    <h3 id="dlgUsrTitle"></h3>
    <input type="hidden" name="save_usr" value="1">
    <input type="hidden" name="id_ticket_usuario" id="u_id">

    <label>Ticket
      <select name="id_evento_ticket" id="u_ticket" required>
        <?php foreach ($tickets as $tk): ?>
          <option value="<?=$tk['id_evento_ticket']?>"
                  <?=$tk['id_evento_ticket']==$idTicket?'selected':''?>>
            <?=htmlspecialchars($tk['nombre_ticket'])?>
          </option>
        <?php endforeach ?>
      </select>
    </label>

    <label>Correo                   <input type="email" name="correo"          id="u_correo" required></label>
    <label>Nombre completo          <input type="text"  name="nombre"          id="u_nom"    maxlength="120" required></label>
    <label>Contacto                 <input type="tel"   name="cel"             id="u_cel"    maxlength="16"  required></label>
    <label>Edad                     <input type="number"name="edad"            id="u_edad"   min="0"></label>
    <label>Equipo                   <input type="text"  name="equipo"          id="u_eq"     maxlength="100"></label>

    <label>Alimentación             <input type="text"  name="alimento"        id="u_alim"></label>
    <label>Hospedaje                <input type="text"  name="hospedaje"       id="u_hosp"></label>
    <label>Enfermedades             <input type="text"  name="enfermedad"      id="u_enf"></label>
    <label>Alergia                  <input type="text"  name="alergia"         id="u_aler"></label>
    <label>Medicamentos             <input type="text"  name="medicamentos"    id="u_meds"></label>
    <label>Alimentación especial    <input type="text"  name="alim_esp"        id="u_aliE"></label>
    <label>Contacto emergencia      <input type="text"  name="contacto_emerg"  id="u_emerg"></label>

    <label>Credencial               <input type="text"  name="credencial"      id="u_cred"  maxlength="100"></label>
    <label>Acompañantes             <input type="text"  name="acompanantes"    id="u_acom"  maxlength="255"></label>
    <label>Extras                   <input type="text"  name="extras"          id="u_extras"maxlength="255"></label>

    <div class="dlg-btns">
      <button type="submit">Guardar</button>
      <button type="button" id="u_cancel">Cancelar</button>
    </div>
  </form>
</dialog>

<!-- Dialog QR -->
<dialog id="dlgQR" style="text-align:center;padding:1.6rem">
  <h3 style="margin:0 0 1rem">Código QR</h3>
  <img id="qrImg" alt="QR" style="max-width:320px;width:100%;height:auto">
  <div class="dlg-btns" style="justify-content:center;margin-top:1rem">
    <a  id="qrDownload" class="action-btn" download>
        <i class="fa-solid fa-download"></i> Descargar
    </a>
    <button type="button" id="qrClose" class="action-btn">
        <i class="fa-solid fa-xmark"></i> Cerrar
    </button>
  </div>
</dialog>

<script>
// ===== NUEVA inscripción =====
btnAddUsr.onclick = () => {
  fUsr.reset(); u_id.value = '';
  u_ticket.value = '<?=$idTicket?>';
  dlgUsrTitle.textContent = 'Nueva inscripción';
  dlgUsr.showModal();
};

// ===== EDITAR inscripción =====
document.querySelectorAll('.edit-usr').forEach(btn => {
  btn.onclick = () => {
    const d = JSON.parse(btn.dataset.json);
    dlgUsrTitle.textContent = 'Editar inscripción';
    u_id.value      = d.id_ticket_usuario;
    u_correo.value  = d.correo_electronico;
    u_nom.value     = d.nombre_completo;
    u_cel.value     = d.contacto;
    u_edad.value    = d.edad;
    u_eq.value      = d.equipo;
    u_alim.value    = d.alimentacion;
    u_hosp.value    = d.hospedaje;
    u_enf.value     = d.enfermedad;
    u_aler.value    = d.alergia;
    u_meds.value    = d.medicamentos;
    u_aliE.value    = d.alimentacion_especial;
    u_emerg.value   = d.contacto_emergencia;
    u_cred.value    = d.credencial;
    u_acom.value    = d.acompanantes;
    u_extras.value  = d.extras;
    u_ticket.value  = d.id_evento_ticket;
    dlgUsr.showModal();
  };
});
u_cancel.onclick = () => dlgUsr.close();
</script>

<script>
(() => {
  const dlg   = document.getElementById('dlgQR');
  const img   = document.getElementById('qrImg');
  const down  = document.getElementById('qrDownload');
  const close = document.getElementById('qrClose');

  document.querySelectorAll('.show-qr').forEach(btn => {
    btn.addEventListener('click', async () => {
      const code = btn.dataset.code;
      let urlObj = null;

      try {
        const res = await fetch('get_qr.php', {
          method: 'POST',
          headers: {
            'Content-Type'   : 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'                // <- lo validamos en PHP
          },
          body: new URLSearchParams({ code })
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const svgText = await res.text();                 // ← SVG como texto

        /* ① mostramos el SVG */
        const svgBlob = new Blob([svgText], {type:'image/svg+xml'});
        const svgUrl  = URL.createObjectURL(svgBlob);
        urlObj        = svgUrl;           // Lo usaremos luego para revocar
        img.src       = svgUrl;

        /* ② nombre original (.svg) que mandó PHP */
        const cd = res.headers.get('Content-Disposition') || '';
        let fileName = '';
        /* 1) Preferencia: filename*=UTF-8''…  (RFC 5987) */
        let m = cd.match(/filename\*=UTF-8''([^;]+)/i);
        if (m) {
          fileName = decodeURIComponent(m[1]);
        }
        /* 2) Fallback legacy: filename="…" */
        else if (m = cd.match(/filename="?([^";]+)/i)) {
          fileName = m[1];
        }

        /* ③ cuando la imagen SVG esté cargada → rasterizamos a PNG */
        const tmpImg = new Image();
        tmpImg.onload = () => {
           const cvs   = document.createElement('canvas');
           cvs.width   = tmpImg.naturalWidth;
           cvs.height  = tmpImg.naturalHeight;
           const ctx   = cvs.getContext('2d');
           ctx.drawImage(tmpImg, 0, 0);

           cvs.toBlob(blob => {
               down.href = URL.createObjectURL(blob);          // ← PNG listo
               down.download = (fileName||'qr.svg')
                                 .replace(/\.svg$/i,'.png');   // *.png
           }, 'image/png');
        };
        tmpImg.src = svgUrl;

        dlg.showModal();
      } catch (e) {
        console.error(e);
        alert('No se pudo cargar el QR');
      }

      /* limpieza ─ revoca URL al cerrar */
      close.onclick = () => {
        dlg.close();
        if (urlObj) URL.revokeObjectURL(urlObj);
        img.removeAttribute('src');
      };
    });
  });
})();
</script>

<script>
/* Convierte un SVG (string) a Blob-PNG 1080×1080 px */
function svgToPng(svgText, size = 1080){
  return new Promise((resolve,reject)=>{
    const svgBlob = new Blob([svgText], {type:'image/svg+xml'});
    const url     = URL.createObjectURL(svgBlob);
    const img     = new Image();
    img.onload = ()=> {
        const cvs = document.createElement('canvas');
        cvs.width  = cvs.height = size;
        const ctx  = cvs.getContext('2d');
        ctx.imageSmoothingEnabled = false;      // evita blur
        ctx.drawImage(img, 0, 0, size, size);   // escala
        cvs.toBlob(blob=>{
            URL.revokeObjectURL(url);
            blob ? resolve(blob) : reject(new Error('toBlob fail'));
        }, 'image/png');
    };
    img.onerror = ()=> reject(new Error('SVG load error'));
    img.src = url;
  });
}

/* ░░ Descargar ZIP de QRs (cliente) ░░ */
btnZip.addEventListener('click', async () => {

  /* 1. Reúne los códigos */
  const codes = Array.from(document.querySelectorAll('.show-qr'))
                     .map(el => el.dataset.code)
                     .filter(Boolean);

  if (!codes.length){
      alert('No hay QRs para descargar.');
      return;
  }

  /* 2. Overlay */
  const ov = document.createElement('div');
  ov.style.cssText =
     'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(0,0,0,.45);z-index:3000';
  ov.innerHTML =
     '<div style="background:#fff;padding:1.2rem 2rem;border-radius:10px;'
    +'box-shadow:0 6px 18px rgba(0,0,0,.25);font-weight:600;'
    +'font-family:Poppins,system-ui,sans-serif">Descargando QRs…</div>';
  document.body.appendChild(ov);

  try{
      const zip = new JSZip();
      const dup = new Set();      // evita nombres repetidos

      /* 3. Procesa en lotes de 100 para no colapsar RAM */
      const CHUNK = 100;
      for (let i = 0; i < codes.length; i += CHUNK){
          const slice = codes.slice(i, i + CHUNK);

          /* texto de progreso simple */
          ov.firstChild.textContent =
              `Descargando QRs…  ${Math.min(i+CHUNK,codes.length)} / ${codes.length}`;

          await Promise.all(slice.map(async (code,idx) => {
              /* --- a) baja el SVG --- */
              const res = await fetch('get_qr.php', {
                  method:'POST',
                  headers:{
                    'Content-Type':'application/x-www-form-urlencoded',
                    'X-Requested-With':'XMLHttpRequest'
                  },
                  body: new URLSearchParams({code})
              });
              if(!res.ok) throw new Error('QR '+code+' HTTP '+res.status);

              /* --- b) nombre seguro --- */
              let base = '';
              const cd = res.headers.get('Content-Disposition') || '';
              let m = cd.match(/filename\*=UTF-8''([^;]+)/i);
              if(m) base = decodeURIComponent(m[1]).replace(/\.svg$/i,'');
              else if(m = cd.match(/filename="?([^";]+)/i))
                       base = m[1].replace(/\.svg$/i,'');
              if(!base) base = 'qr_'+code;

              let fn = base+'.png';
              while(zip.file(fn)) fn = base+'_'+Date.now()+'.png';

              /* --- c) rasteriza y agrega --- */
              const pngBlob = await svgToPng(await res.text());
              zip.file(fn, pngBlob);
          }));
      }

      /* 4. Genera el ZIP y dispara descarga */
      const blob = await zip.generateAsync({type:'blob'});
      const url  = URL.createObjectURL(blob);
      const a    = Object.assign(document.createElement('a'), {
          href:url,
          download:'QRs_'+new Date().toISOString()
                     .slice(0,19).replace(/[:T]/g,'')+'.zip'
      });
      a.click();
      URL.revokeObjectURL(url);

  }catch(err){
      console.error(err);
      alert('Fallo al generar el ZIP:\n'+err.message);
  }finally{
      ov.remove();
  }
});
</script>

<script>
/* ░░ Cerrar cualquier <dialog> al clicar fuera ░░ */
document.querySelectorAll('dialog').forEach(dlg=>{
  dlg.addEventListener('click',e=>{
    if(e.target===dlg) dlg.close();
  });
});
</script>

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

<script>
/* ===========================================================
   Paginar + ordenar tablas  (versión 2025-07-06)
   =========================================================== */
(() => {
  /* evita doble inicialización */
  if (window.__tblEnhancerInitV2) return;
  window.__tblEnhancerInitV2 = true;

  document.addEventListener('DOMContentLoaded', () => {

    /* ══════════ 1)  P A G I N A C I Ó N ══════════ */
    document.querySelectorAll('table.paginated').forEach(tbl=>{
      const perPage = +tbl.dataset.perPage || 10;
      const tbody   = tbl.tBodies[0];
      if (!tbody) return;

      /* contenedor de botones (1 sola vez) */
      const pid   = 'pager-'+tbl.id;
      let pager   = document.getElementById(pid);
      if (!pager){
        pager = document.createElement('div');
        pager.id = pid;
        pager.className = 'table-pager';
        tbl.after(pager);
      } else pager.innerHTML = '';

      /* helpers dinámicos → siempre usan el orden actual del DOM */
      const rowsAll   = () => Array.from(tbody.rows);
      const pages     = Math.ceil(rowsAll().length / perPage);
      let   current   = 1;

      const mk = (txt,fn,cls='') => {
        const b=document.createElement('button');
        b.textContent=txt;
        if(cls) b.classList.add(cls);
        b.addEventListener('click',fn);
        return b;
      };

      const prev = mk('‹',()=>show(current-1));
      const next = mk('›',()=>show(current+1));
      pager.append(prev);
      for(let i=1;i<=pages;i++){ pager.append(mk(i,()=>show(i),'page')); }
      pager.append(next);

      function show(n){
        current = n;
        rowsAll().forEach((tr,i)=>{
          tr.style.display = (i>= (n-1)*perPage && i< n*perPage)?'':'none';
        });
        pager.querySelectorAll('button.page').forEach((b,i)=>{
          b.classList.toggle('active',i+1===n);
        });
        prev.disabled = (n===1);
        next.disabled = (n===pages);
      }
      show(1);

      /* API expuesta para el ordenamiento */
      tbl._pager = { show, page:()=>current };
    });

    /* ══════════ 2)  O R D E N A M I E N T O ══════════ */
    document.querySelectorAll('table.sortable thead th').forEach(th=>{
      if(th.classList.contains('no-title')) return;   /* columna vacía */
      th.style.cursor='pointer';
      let dir = 1;                                    /* 1 asc  | -1 desc */

      th.addEventListener('click',()=>{
        const tbl  = th.closest('table');
        const idx  = [...th.parentNode.children].indexOf(th);
        const tb   = tbl.tBodies[0];
        const type = th.dataset.type || guess(tb.rows[0].cells[idx].innerText);
        const rows = [...tb.rows];

        rows.sort((a,b)=>cmp(a.cells[idx].innerText,
                             b.cells[idx].innerText,type)*dir)
            .forEach(r=>tb.appendChild(r));

        dir *= -1;
        /* refresca la página visible si la tabla está paginada */
        if(tbl._pager) tbl._pager.show(tbl._pager.page());
      });
    });

    /* ---------- helpers ---------- */
    const num   = v=>+v.replace(/[^0-9,.-]/g,'').replace(',','.');
    const toISO = d=>d.replace(/(\d{2})-(\d{2})-(\d{4})/,'$3-$2-$1').replace(' ','T');
    function cmp(a,b,t){
      if(t==='number') return num(a)-num(b);
      if(t==='date')   return new Date(toISO(a))-new Date(toISO(b));
      return a.localeCompare(b,'es',{numeric:true,sensitivity:'base'});
    }
    function guess(v){
      v=v.trim();
      return /^-?\d+(?:[.,]\d+)?$/.test(v)?'number':
             /^\d{2}-\d{2}-\d{4}/.test(v)?'date':'text';
    }
  });
})();

// ─── referencias globales a los inputs y al formulario ───
const dlgTicket = document.getElementById('dlgTicket');
const fTicket   = document.getElementById('fTicket');
const t_nom     = document.getElementById('t_nom');
const t_desc    = document.getElementById('t_desc');
const t_prec    = document.getElementById('t_prec');
const t_cupo    = document.getElementById('t_cupo');

/* pega este bloque **después** de las otras funciones JS */
function valTicket(){
  // ── Defino todas las reglas de validación ──
  const rules = [
    {
      el: t_nom,
      test: () => /^[A-Za-z0-9\u00C0-\u024F .,#¿¡!?()\/\-]+$/.test(t_nom.value),
      msg: 'Solo letras, números y . , # ¿ ¡ ! ? ( ) / -'
    },
    {
      el: t_desc,
      test: () => t_desc.value === '' 
                 || /^[A-Za-z0-9\u00C0-\u024F .,#¿¡!?()\/\-]+$/.test(t_desc.value),
      msg: 'Solo letras, números y . , # ¿ ¡ ! ? ( ) / -'
    },
    {
      el: t_prec,
      test: () => t_prec.checkValidity(),  // type=number, min="0"
      msg: 'Debe ser un número ≥ 0'
    },
    {
      el: t_cupo,
      test: () => t_cupo.checkValidity(),  // type=number, min="0"
      msg: 'Debe ser un número ≥ 0'
    }
  ];

  let ok = true;

  // ── Recorro cada regla y muestro/oculto error inline ──
  rules.forEach(({el, test, msg})=>{
    const lbl = el.parentElement;
    if (!test()) {
      lbl.classList.add('error');
      lbl.querySelector('.err').textContent = msg;
      ok = false;
    } else {
      lbl.classList.remove('error');
      lbl.querySelector('.err').textContent = '';
    }
  });

  // ── Validación extra: cupo ≥ ocupados ──
  const used = +dlgTicket.dataset.ocupados || 0;
  if (+t_cupo.value < used) {
    const lbl = t_cupo.parentElement;
    lbl.classList.add('error');
    lbl.querySelector('.err').textContent = `No puede ser menor que ${used}`;
    ok = false;
  }

  // ── Activo/desactivo botón Guardar ──
  fTicket.querySelector('button[type="submit"]').disabled = !ok;
}

fTicket.addEventListener('submit', async e=>{
  /* si el cliente aún ve errores puntualizamos arriba */
  const btn = fTicket.querySelector('button[type="submit"]');
  if(btn.disabled) return;          // validación de cliente ya bloqueó
  e.preventDefault();

  const fd  = new FormData(fTicket);
  btn.disabled = true; btn.textContent = 'Guardando…';

  try{
      const res = await fetch(location.href, {
          method: 'POST',
          body  : fd,
          headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const j = await res.json();

      if(j.ok){
         /* recarga normal para refrescar la tabla */
         location.href = 'ticket_detalle.php?evt=<?=$idEvento?>';
      }else{
         const msg = j.error || 'Error desconocido';
         if (/nombre/i.test(msg)) {
           showFieldError(t_nom, msg);
         } else if (/descripción/i.test(msg)) {
           showFieldError(t_desc, msg);
         } else {
           showFormError(msg);
         }
      }
  }catch(err){
      console.error(err);
      showServerError('Fallo de red');
  }finally{
      btn.disabled = false;
      btn.textContent = 'Guardar';
  }
});

function showServerError(msg){
   let box = dlgTicket.querySelector('.form-error');
   if(!box){
       box = document.createElement('p');
       box.className = 'form-error';
       box.style.cssText =
          'color:var(--danger);font-weight:600;margin:0 0 1rem;';
       dlgTicket.querySelector('form').prepend(box);
   }
   box.textContent = msg;
   /* scroll suave hasta el mensaje */
   box.scrollIntoView({behavior:'smooth',block:'center'});
}

/* ─── nueva: resalta error bajo un control ────────────────── */
function showFieldError(el, msg) {
  const lbl = el.parentElement;
  lbl.classList.add('error');
  lbl.querySelector('.err').textContent = msg;
  el.focus();
}

/* bloquea envío si el botón está deshabilitado */
fTicket.addEventListener('submit', e => {
  const btn = fTicket.querySelector('button[type="submit"]');

  /* ➊  Solo se cancela el envío cuando el form aún tiene errores   */
  if (btn.disabled) {
      e.preventDefault();                       // ← evita recarga
      const firstBad = fTicket.querySelector(
         'label.error input, label.error textarea, label.error select');
      if (firstBad) {
          firstBad.focus({preventScroll:true});
          firstBad.scrollIntoView({behavior:'smooth',block:'center'});
      }
  }
});

/* ─── botón “Añadir ticket” ───────────────────────── */
btnAddTicket.onclick = () => {
  dlgTicket.removeAttribute('data-ocupados');   // limpia dataset
  fTicket.reset();                              // limpia campos
  t_id.value = '';
  dlgTicketTitle.textContent = 'Nuevo ticket';
  setTimeout(valTicket);                        // recalcula validación
  dlgTicket.showModal();                        // ⬅️ vuelve a abrir
};

/* ─── botones “Editar ticket” ─────────────────────── */
document.querySelectorAll('.edit-ticket').forEach(btn => {
  btn.onclick = () => {
    const d = JSON.parse(btn.dataset.json);
    dlgTicket.dataset.ocupados = d.ocupados;

    /* rellena los campos del formulario */
    t_id.value     = d.id_evento_ticket;
    t_nom.value    = d.nombre_ticket;
    t_desc.value   = d.descripcion || '';
    t_prec.value   = d.precio_clp;
    t_cupo.value   = d.cupo_total;
    t_activo.value = d.activo;
    dlgTicketTitle.textContent = 'Editar ticket';

    setTimeout(valTicket);                      // recalcula validación
    dlgTicket.showModal();                      // ⬅️ vuelve a abrir
  };
});
</script>

<script>
/* ══════════ AJAX genérico para TODOS los diálogos ══════════ */
['fHor','fUsr','fAdm'].forEach(initDlgAjax);

function initDlgAjax(id){
  const f   = document.getElementById(id);
  if(!f) return;
  const dlg = f.closest('dialog');

  f.addEventListener('submit', async e => {
    const btn = f.querySelector('button[type="submit"]');
    if (btn.disabled) return;          // el live-validation ya bloqueó
    e.preventDefault();

    const fd  = new FormData(f);
    btn.disabled = true;
    const txtBtn = btn.textContent;
    btn.textContent = 'Guardando…';

    try{
        const res = await fetch(location.href, {
            method : 'POST',
            body   : fd,
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        const j = await res.json();

        if (j.ok){
            location.reload();               // éxito → refresca tablas
        }else{
            showFormError(f, j.error || 'Error desconocido');
        }
    }catch(err){
        console.error(err);
        showFormError(f, 'Fallo de red');
    }finally{
        btn.disabled  = false;
        btn.textContent = txtBtn;
    }
  });

  /* helper reutilizable */
  function showFormError(form, msg){
      let box = form.querySelector('.form-error');
      if(!box){
          box = document.createElement('p');
          box.className = 'form-error';
          box.style.cssText =
             'color:var(--danger);font-weight:600;margin:0 0 1rem;';
          form.prepend(box);
      }
      box.textContent = msg;
      box.scrollIntoView({behavior:'smooth',block:'center'});
  }
}
</script>

<!-- ══ VALIDACIÓN EN VIVO (genérica) ═════════════════════════ -->
<script>
(() => {
  /* aplica a TODOS los formularios de diálogos, excepto #fTicket que ya
     tiene reglas extra de cupo, etc. */
  document.querySelectorAll('dialog form:not(#fTicket)').forEach(initLive);

  function initLive(form){
    const ctrls = [...form.querySelectorAll('input,textarea,select')];

    /* crea <small class="err"> si falta (p.e. fHor, fUsr, fAdm) */
    ctrls.forEach(c=>{
      const lbl = c.parentElement;
      if(!lbl.querySelector('.err')){
        const s = document.createElement('small');
        s.className='err'; lbl.appendChild(s);
      }
      ['input','change','blur'].forEach(ev =>
        c.addEventListener(ev, ()=> validateCtrl(c,form)));
    });

    form.addEventListener('submit', e=>{
      const firstBad = ctrls.find(c=> !validateCtrl(c,form));
      if(firstBad){
        e.preventDefault();
        firstBad.focus({preventScroll:true});
        firstBad.scrollIntoView({behavior:'smooth',block:'center'});
      }
    });

    /* estado inicial por si el modal abre con datos */
    toggleSubmit(form);
  }

  function validateCtrl(ctrl,form){
    const ok  = ctrl.checkValidity();
    const lbl = ctrl.parentElement;
    const err = lbl.querySelector('.err');
    if(ok){
      lbl.classList.remove('error');
      err.textContent = '';
    }else{
      lbl.classList.add('error');
      /* usa title si lo hay; si no, el mensaje nativo del navegador */
      err.textContent = ctrl.title || ctrl.validationMessage;
    }
    toggleSubmit(form);
    return ok;
  }

  function toggleSubmit(form){
    const btn = form.querySelector('button[type="submit"]');
    if(btn) btn.disabled = !form.checkValidity();
  }
})();
</script>

<!-- ░░░░ Heartbeat automático cada 10 min ░░░░ -->
<script src="heartbeat.js"></script>

</body></html>
