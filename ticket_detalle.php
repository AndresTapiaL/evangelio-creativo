<?php
declare(strict_types=1);
session_start();
require 'conexion.php';

/* ─── NUEVO: verificación de GD ─────────────────────────────── */
if (!extension_loaded('gd')) {
    http_response_code(500);          // error interno
    die('La extensión GD no está habilitada. '
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
$uid = $_SESSION['id_usuario'];
$auth = $pdo->prepare("
     SELECT 1 FROM integrantes_equipos_proyectos
      WHERE id_usuario=? AND id_equipo_proyecto=1 AND habilitado=1
      LIMIT 1");
$auth->execute([$uid]);
if (!$auth->fetchColumn()){
    http_response_code(403);
    die('Acceso restringido');
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
        header("Location: ticket_detalle.php?evt=$idEvento");
        exit;
    }
}

if (!$idEvento) die('Evento no especificado');

/* ─── Datos del evento (siempre existen) ──────────────────────── */
$evtRow = $pdo->prepare("
        SELECT id_evento,
               nombre_evento,
               boleteria_activa
          FROM eventos
         WHERE id_evento = ?
         LIMIT 1");
$evtRow->execute([$idEvento]);
$evtRow = $evtRow->fetch(PDO::FETCH_ASSOC) ?: die('Evento no encontrado');

/* ─── Tickets del evento (puede estar vacío) ─────────────────── */
/* ─── Tickets del evento (puede estar vacío) ─────────────────── */
$ticketStmt = $pdo->prepare("
        SELECT *
          FROM eventos_tickets
         WHERE id_evento = ?
      ORDER BY id_evento_ticket DESC");
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
        'cupo_total'=>0,'cupo_ocupado'=>0,'activo'=>1
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
    if (!$idTicketSel) $idTicketSel = $idTicket;   // respaldo
    $data  = [
        'email'  => filter_input(INPUT_POST,'correo',FILTER_VALIDATE_EMAIL),
        'nombre' => trim($_POST['nombre']),
        'cel'    => trim($_POST['cel']),
        'edad'   => (int)$_POST['edad'],
        'equipo' => trim($_POST['equipo'])
    ];
    if (!$data['email'] || $data['nombre']==='' || $data['cel']==='') {
        die('Campos obligatorios vacíos');
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

        /* ③- si cambió de ticket, ajustar contadores  */
        if ($oldTicket && $oldTicket !== $idTicketSel) {
            $pdo->prepare("UPDATE eventos_tickets
                              SET cupo_ocupado = cupo_ocupado-1
                            WHERE id_evento_ticket = ?")
                ->execute([$oldTicket]);

            $pdo->prepare("UPDATE eventos_tickets
                              SET cupo_ocupado = cupo_ocupado+1
                            WHERE id_evento_ticket = ?")
                ->execute([$idTicketSel]);
        }

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

        /* QR y contador */
        generarQR($hash, __DIR__."/qr/{$hash}.png");

        $pdo->prepare("UPDATE eventos_tickets
                          SET cupo_ocupado = cupo_ocupado+1
                        WHERE id_evento_ticket=?")
            ->execute([$idTicketSel]);
    }

    header("Location: ticket_detalle.php?evt=$idEvento&tkt=$idTicketSel&ok=1");
    exit;
}

/* ─── crear / actualizar ticket ------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_ticket'])){
    $idET = (int)$_POST['id_evento_ticket'];
    if($idET){   // update
        $pdo->prepare("
           UPDATE eventos_tickets
              SET nombre_ticket = ?, descripcion = ?, precio_clp = ?,
                  cupo_total = ?, activo = ?
            WHERE id_evento_ticket = ? AND id_evento = ?")
            ->execute([
                trim($_POST['nombre_ticket']),
                trim($_POST['descripcion']),
                (int)$_POST['precio_clp'],
                (int)$_POST['cupo_total'],
                (int)$_POST['activo'],
                $idET,$idEvento
            ]);
    }else{       // insert
        $stmt = $pdo->prepare("
           INSERT INTO eventos_tickets(id_evento,nombre_ticket,descripcion,
                                        precio_clp,cupo_total,activo)
           VALUES(?,?,?,?,?,?)")
           ->execute([
               $idEvento,
               trim($_POST['nombre_ticket']),
               trim($_POST['descripcion']),
               (int)$_POST['precio_clp'],
               (int)$_POST['cupo_total'],
               (int)$_POST['activo']
           ]);
    }
    $newId = (int)$pdo->lastInsertId();
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* ─── eliminar ticket ----------------------------------------- */
if(isset($_GET['del_ticket'])){
    $pdo->prepare("
        DELETE FROM eventos_tickets
         WHERE id_evento_ticket = ? AND id_evento = ?")
        ->execute([(int)$_GET['del_ticket'],$idEvento]);
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* ─── CRUD horarios ---------------------------------------- */
// // BEGIN MOD 3 : CRUD horarios ligado al evento
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_hor'])) {
    $idH   = (int)$_POST['id_ticket_horario'];
    $nom   = trim($_POST['nombre_horario']);
    $ini   = $_POST['fecha_inicio'];
    $fin   = $_POST['fecha_fin'];

    if ($nom===''||!$ini||!$fin) die('Datos de horario incompletos');

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
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

if(isset($_GET['del_hor'])){
    $pdo->prepare("DELETE FROM ticket_horarios WHERE id_ticket_horario=?")
        ->execute([(int)$_GET['del_hor']]);
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

    if (!$uid) die('Usuario no encontrado');

    /* asignarlo a TODOS los tickets del evento */
    foreach ($tickets as $t) {
        $pdo->prepare("
            INSERT IGNORE INTO ticket_admins(id_evento_ticket,id_usuario)
            VALUES(?,?)")
            ->execute([$t['id_evento_ticket'], $uid]);
    }
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* baja de admin (se sigue pasando id_usuario) */
if (isset($_GET['del_admin'])) {
    foreach ($tickets as $t) {
        $pdo->prepare("
            DELETE FROM ticket_admins
             WHERE id_evento_ticket = ? AND id_usuario = ?")
            ->execute([$t['id_evento_ticket'], (int)$_GET['del_admin']]);
    }
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

/* delete usuario */
if(isset($_GET['del_usr'])){
    $pdo->prepare("
        DELETE FROM ticket_usuario WHERE id_ticket_usuario=?")
        ->execute([(int)$_GET['del_usr']]);
    header("Location: ticket_detalle.php?evt=$idEvento");
    exit;
}

?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8">
<title>Boletería – <?=htmlspecialchars($evtRow['nombre_evento'])?></title>
<link rel="stylesheet" href="styles/main.css">
</head><body>
<h1><?=htmlspecialchars($evtRow['nombre_evento'])?></h1>

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
$totalIns  = array_sum(array_column($tickets,'cupo_ocupado'));
$totalCupo = array_sum(array_column($tickets,'cupo_total'));
?>

<!-- Tabla de tickets + modales -->
<section>
  <h2>Tickets</h2>

  <button id="btnAddTicket">➕ Añadir ticket</button>

  <table>
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
        <td><?=$t['cupo_ocupado']?> / <?=$t['cupo_total']?></td>
        <td><?=$t['activo']?'Sí':'No'?></td>
        <td>
          <button class="edit-ticket" data-json='<?=json_encode($t,JSON_HEX_APOS)?>'>✏</button>
          <a  href="?evt=<?=$idEvento?>&del_ticket=<?=$t['id_evento_ticket']?>" 
              onclick="return confirm('¿Eliminar ticket definitivamente?')">🗑</a>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<!-- Modal CREAR / EDITAR ticket -->
<dialog id="dlgTicket">
  <form method="post" id="fTicket">
    <h3 id="dlgTicketTitle"></h3>
    <input type="hidden" name="save_ticket" value="1">
    <input type="hidden" name="id_evento_ticket" id="t_id">

    <label>Nombre
      <input name="nombre_ticket" id="t_nom" maxlength="100" required>
    </label>

    <label>Descripción
      <textarea name="descripcion" id="t_desc" rows="2"></textarea>
    </label>

    <label>Precio CLP
      <input type="number" name="precio_clp" id="t_prec" min="0">
    </label>

    <label>Cupo total
      <input type="number" name="cupo_total" id="t_cupo" min="0" required>
    </label>

    <label>Activo
      <select name="activo" id="t_activo">
        <option value="1">Sí</option><option value="0">No</option>
      </select>
    </label>

    <div class="dlg-btns">
      <button>Guardar</button>
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

  <table>
    <thead><tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($horarios as $h): ?>
      <tr>
        <td><?=htmlspecialchars($h['nombre_horario'])?></td>
        <td><?=$h['fecha_inicio']?></td>
        <td><?=$h['fecha_fin']?></td>
        <td>
          <button class="edit-hor" data-json='<?=json_encode($h,JSON_HEX_APOS)?>'>✏</button>
          <a href="?evt=<?=$idEvento?>&del_hor=<?=$h['id_ticket_horario']?>" 
             onclick="return confirm('¿Eliminar horario?')">🗑</a>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<dialog id="dlgHor">
  <form method="post" id="fHor">
    <h3 id="dlgHorTitle"></h3>
    <input type="hidden" name="save_hor" value="1">
    <input type="hidden" name="id_ticket_horario" id="h_id">

    <label>Nombre <input name="nombre_horario" id="h_nom" required></label>
    <label>Inicio  <input type="datetime-local" name="fecha_inicio" id="h_ini" required></label>
    <label>Fin     <input type="datetime-local" name="fecha_fin"    id="h_fin" required></label>

    <div class="dlg-btns">
      <button>Guardar</button>
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
     JOIN  eventos_tickets et USING(id_evento_ticket)
    WHERE  et.id_evento = ?
 GROUP BY ta.id_usuario
 ORDER BY u.nombres");
$admins->execute([$idEvento]);
$admins = $admins->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- // BEGIN MOD 6 : admins -->
<section>
  <h2>Acceso de usuarios a boletería</h2>

  <button id="btnAddAdm">➕ Añadir administrador</button>

  <table>
    <thead><tr><th>Nombre completo</th><th>RUT / DNI</th><th>País</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
      <tr>
        <td><?=htmlspecialchars($a['nombres'].' '.$a['apellido_paterno'])?></td>
        <td><?=htmlspecialchars($a['rut_dni'])?></td>
        <td><?=htmlspecialchars($a['nombre_pais'])?></td>
        <td>
          <a href="?evt=<?=$idEvento?>&del_admin=<?=$a['id_usuario']?>"
             onclick="return confirm('¿Quitar privilegio de boletería?')">🗑</a>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<!-- modal admin -->
<dialog id="dlgAdm">
  <form method="post" id="fAdm">
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
      <button id="a_save" disabled>Guardar</button>
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
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Ticket</th><th>Nombre</th><th>Correo electrónico</th><th>Fecha / Hora</th>
        <th>Contacto</th><th>Edad</th><th>Alimentación</th><th>Hospedaje</th>
        <th>Enfermedades</th><th>Alergia</th><th>Medicamentos</th>
        <th>Alimentación esp.</th><th>Contacto emerg.</th>
        <th>Credencial</th><th>Acompañantes</th><th>Extras</th><th>QR</th><th></th>
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
          <?php
            $png = "qr/{$u['qr_codigo']}.png";
            echo file_exists(__DIR__.'/'.$png)
                ? "<a href=\"".htmlspecialchars($png)."\" target=\"_blank\">QR</a>"
                : '—';
          ?>
        </td>
        <td>
          <button class="edit-usr"
                  data-json='<?=json_encode($u,JSON_HEX_APOS)?>'>✏</button>
          <a href="?evt=<?=$idEvento?>&del_usr=<?=$u['id_ticket_usuario']?>"
            onclick="return confirm('¿Eliminar inscripción?')">🗑</a>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<!-- Modal CREAR / EDITAR inscripción -->
<dialog id="dlgUsr">
  <form method="post" id="fUsr">
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
      <button>Guardar</button>
      <button type="button" id="u_cancel">Cancelar</button>
    </div>
  </form>
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

</body></html>
