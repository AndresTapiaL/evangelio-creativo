<?php
/*  scan_qr.php  –  Acreditación de tickets por QR
    © Evangelio Creativo · 2025
------------------------------------------------------------------ */
declare(strict_types=1);
session_start();
date_default_timezone_set('UTC');
require 'conexion.php';

/* —————————————————  utilidades de salida  ————————————————— */
function page(string $html,int $http=200):void{
    http_response_code($http);
    echo <<<HTML
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Escaneo QR</title>
<style>
:root{
  --negro:#2e292c;--naranjo:#ff4200;--naranjo-dark:#d63800;
  --verde:#198754;--rojo:#d62828;--bg:#f6f7fb;--card:#fff;
  --radius:14px;--shadow:0 6px 18px rgba(0,0,0,.12);
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);font:400 16px/1.45 "Poppins",system-ui,sans-serif;color:var(--negro)}
main{max-width:560px;margin:6vh auto;padding:1.8rem;background:var(--card);
     border-radius:var(--radius);box-shadow:var(--shadow)}
h1{font-size:1.8rem;margin:.2rem 0 1.1rem;color:var(--naranjo)}
.bigName{font-size:2.3rem;font-weight:700;margin:.3rem 0 1.1rem;line-height:1.15}
h2{font-size:1.18rem;margin:0 0 .9rem;color:#555}
p{margin:.45rem 0}
.err{border-left:6px solid var(--rojo);background:#ffe8e8;padding:1.2rem;border-radius:var(--radius);font-weight:600}
.ok{border-left:6px solid var(--verde);background:#e6f8e6;padding:1.2rem;border-radius:var(--radius);font-weight:600}
.datos{font-size:.9rem;margin-top:1.2rem;display:grid;grid-template-columns:1fr 2fr;gap:.25rem .6rem}
.datos dt{font-weight:600}
.actions{display:flex;flex-wrap:wrap;gap:.7rem;margin-top:2rem}
button{flex:1 1 140px;padding:.75rem 1rem;border:0;border-radius:var(--radius);
       font:600 1rem/1 "Poppins";cursor:pointer;color:#fff;transition:.2s}
.ing{background:var(--naranjo)}  .ing:hover{background:var(--naranjo-dark)}
.out{background:var(--negro)}    .out:hover{background:#000}
.info{background:#6d7280}        .info:hover{background:#4f5563}
@media(max-width:420px){.bigName{font-size:1.7rem}}
</style>
</head><body><main>
$html
</main></body></html>
HTML;
    exit;
}
function errorPage(string $msg,int $http=400):void{
    page("<div class='err'>❌  ".$msg."</div>",$http);
}

/* ————————————————— 1) sesión iniciada ————————————————— */
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');      // vuelve al login
    exit;
}
$idUser = (int)$_SESSION['id_usuario'];

/* ————————————————— 2) POST → registrar / ver  ————————————————— */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tid'] ?? 0);      // id_ticket_usuario
    $hid    = (int)($_POST['hid'] ?? 0);      // id_ticket_horario

    if (!in_array($action,['in','out','info'],true))
        errorPage('Acción no permitida');

    /* ··· datos completos de la inscripción + horario ··· */
    $sql = "
      SELECT tu.*, et.id_evento_ticket, et.nombre_ticket, et.activo,
             e.id_evento,  e.nombre_evento,
             th.id_ticket_horario, th.nombre_horario,
             th.fecha_inicio, th.fecha_fin
        FROM ticket_usuario tu
   JOIN eventos_tickets  et  ON et.id_evento_ticket = tu.id_evento_ticket
   JOIN eventos          e   ON e.id_evento        = et.id_evento
   JOIN ticket_horarios  th  ON th.id_ticket_horario = :hid
                             AND th.id_evento       = e.id_evento
       WHERE tu.id_ticket_usuario = :tid
       LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tid'=>$tid,'hid'=>$hid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: errorPage('Registro no encontrado',404);

    /* a)  solo administradores del evento */
    $adm = $pdo->prepare("SELECT 1 FROM ticket_admins
                           WHERE id_evento=? AND id_usuario=? LIMIT 1");
    $adm->execute([$u['id_evento'],$idUser]);
    if (!$adm->fetchColumn())
        errorPage('No tiene privilegios para acreditar en este evento',403);

    /* b)  ticket activo */
    if (!$u['activo']) errorPage('El ticket asociado está inactivo');

    /* c)  dentro del horario */
    $now = date('Y-m-d H:i:s');
    if (!($now >= $u['fecha_inicio'] && $now <= $u['fecha_fin']))
        errorPage('Fuera del horario autorizado');

    /* d-1) solo consulta */
    if ($action==='info'){
        page( renderFicha($u) );
    }

    /* d-2) registrar ingreso / salida */
    $esIngreso = $action==='in' ? 1 : 0;
    try{
        $pdo->prepare("INSERT INTO ticket_scans
                       (id_ticket_horario,id_ticket_usuario,es_ingreso)
                       VALUES(?,?,?)")
            ->execute([$hid,$tid,$esIngreso]);

        $msg = $esIngreso ? 'Ingreso registrado ✅' : 'Salida registrada ✅';
        page( "<div class='ok'>$msg</div>".renderFicha($u) ,200 );

    }catch(PDOException $e){
        /* mensajes del trigger BEFORE INSERT → mostrar bonitos */
        errorPage('⚠  '.$e->getMessage(),409);
    }
}

/* ————————————————— 3) GET → llega ?code=HASH ————————————————— */
$hash = $_GET['code'] ?? '';
if (!preg_match('/^[a-f\d]{64}$/i',$hash))
    errorPage('Código QR inválido');

$sql = "
  SELECT tu.*, et.id_evento_ticket, et.nombre_ticket, et.activo,
         e.id_evento, e.nombre_evento
    FROM ticket_usuario tu
    JOIN eventos_tickets et ON et.id_evento_ticket = tu.id_evento_ticket
    JOIN eventos         e  ON e.id_evento        = et.id_evento
   WHERE tu.qr_codigo = ?
   LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([$hash]);
$u = $stmt->fetch(PDO::FETCH_ASSOC) ?: errorPage('QR no reconocido',404);

/* ① admin del evento */
$adm = $pdo->prepare("SELECT 1 FROM ticket_admins
                       WHERE id_evento=? AND id_usuario=? LIMIT 1");
$adm->execute([$u['id_evento'],$idUser]);
if (!$adm->fetchColumn())
    errorPage('No tiene privilegios para acreditar en este evento',403);

/* ② ticket activo */
if (!$u['activo']) errorPage('El ticket asociado está inactivo');

/* ③ horario vigente */
$hor = $pdo->prepare("
    SELECT id_ticket_horario,nombre_horario,fecha_inicio,fecha_fin
      FROM ticket_horarios
     WHERE id_evento = ?
       AND NOW() BETWEEN fecha_inicio AND fecha_fin
     LIMIT 1");
$hor->execute([$u['id_evento']]);
$h = $hor->fetch(PDO::FETCH_ASSOC) ?: errorPage('No existe un horario de acreditación activo',403);

/* ④ Pantalla de opciones  */
$page = "
<h1 class='bigName'>".htmlspecialchars($u['nombre_completo'])."</h1>
<h2>".htmlspecialchars($u['nombre_evento'])." / ".htmlspecialchars($h['nombre_horario'])."</h2>
<p><strong>ID Ticket:</strong> {$u['id_ticket_usuario']}</p>
<p><strong>Tipo de ticket:</strong> ".htmlspecialchars($u['nombre_ticket'])."</p>

<form method='post' class='actions'>
  <input type='hidden' name='tid' value='{$u['id_ticket_usuario']}'>
  <input type='hidden' name='hid' value='{$h['id_ticket_horario']}'>
  <button name='action' value='in'  class='ing'>Ingreso</button>
  <button name='action' value='out' class='out'>Salida</button>
  <button name='action' value='info' class='info'>Ver datos</button>
</form>
";
page($page);

/* ————————————————— helper : ficha completa ————————————————— */
function renderFicha(array $d):string{
    $fh = (new DateTime($d['fecha_inscripcion']))->format('d-m-Y H:i');
    $safe = fn(string $x)=>htmlspecialchars($x,ENT_QUOTES,'UTF-8');
    $out = "
    <h1 class='bigName'>{$safe($d['nombre_completo'])}</h1>
    <h2>{$safe($d['nombre_evento'])} / {$safe($d['nombre_horario']??'')}</h2>

    <dl class='datos'>
      <dt>ID Ticket</dt><dd>{$d['id_ticket_usuario']}</dd>
      <dt>Tipo ticket</dt><dd>{$safe($d['nombre_ticket'])}</dd>
      <dt>Correo</dt><dd>{$safe($d['correo_electronico'])}</dd>
      <dt>Inscrito el</dt><dd>$fh</dd>
      <dt>Contacto</dt><dd>{$safe($d['contacto'])}</dd>
      <dt>Edad</dt><dd>{$d['edad']}</dd>
      <dt>Alimentación</dt><dd>{$safe($d['alimentacion'])}</dd>
      <dt>Hospedaje</dt><dd>{$safe($d['hospedaje'])}</dd>
      <dt>Enfermedades</dt><dd>{$safe($d['enfermedad'])}</dd>
      <dt>Alergia</dt><dd>{$safe($d['alergia'])}</dd>
      <dt>Medicamentos</dt><dd>{$safe($d['medicamentos'])}</dd>
      <dt>Alim. especial</dt><dd>{$safe($d['alimentacion_especial'])}</dd>
      <dt>Emergencia</dt><dd>{$safe($d['contacto_emergencia'])}</dd>
      <dt>Credencial</dt><dd>{$safe($d['credencial'])}</dd>
      <dt>Acompañantes</dt><dd>{$safe($d['acompanantes'])}</dd>
      <dt>Extras</dt><dd>{$safe($d['extras'])}</dd>
    </dl>";
    return $out;
}
?>
