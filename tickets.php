<?php
/*  tickets.php  –  Gestión de boletería
    © Evangelio Creativo · 2025
-------------------------------------------------------------- */
declare(strict_types=1);
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
$uid = $_SESSION['id_usuario'];

$auth = $pdo->prepare("
    SELECT 1
      FROM integrantes_equipos_proyectos
     WHERE id_usuario = ?
       AND id_equipo_proyecto = 1
       AND habilitado = 1
     LIMIT 1");
$auth->execute([$uid]);

if (!$auth->fetchColumn()) {
    http_response_code(403);
    die('Acceso restringido – solo Liderazgo Nacional');
}

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

/* ─── Listas para la UI ───────────────────────────────────── */
$inactiveEvt = $pdo->query("
      SELECT id_evento, nombre_evento,
             DATE_FORMAT(fecha_hora_inicio,'%d-%m-%Y %H:%i') AS inicia
        FROM eventos
       WHERE boleteria_activa = 0
         AND fecha_hora_inicio >= NOW()      -- ← solo eventos futuros
    ORDER BY fecha_hora_inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activeEvt = $pdo->query("
      SELECT  e.id_evento,
              e.nombre_evento,
              COALESCE(SUM(et.cupo_ocupado),0) AS ocupados,
              COALESCE(SUM(et.cupo_total),0)  AS cupo_total
        FROM  eventos            e
   LEFT JOIN  eventos_tickets   et ON et.id_evento = e.id_evento
       WHERE  e.boleteria_activa = 1
    GROUP BY  e.id_evento
    ORDER BY  e.fecha_hora_inicio DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head>
  <meta charset="utf-8"><title>Tickets | Evangelio Creativo</title>
  <link rel="stylesheet" href="styles/main.css">
</head><body>
<h1>Boletería de Eventos</h1>

<?php
/* ─── toggle “boleteria_activa” ──────────────────────────── */
if(isset($_GET['set_evt']) && isset($_GET['on'])){
    $pdo->prepare("UPDATE eventos SET boleteria_activa=? WHERE id_evento=?")
        ->execute([(int)$_GET['on'], (int)$_GET['set_evt']]);
    header('Location: tickets.php');
    exit;
}

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

  <form method="post">
    <label>Evento
      <select name="id_evento" required>
        <option value="">— Seleccionar —</option>
        <?php foreach ($inactiveEvt as $e): ?>
          <option value="<?=$e['id_evento']?>">
            <?=$e['nombre_evento']?> (<?=$e['inicia']?>)
          </option>
        <?php endforeach ?>
      </select>
    </label>

    <button name="activar_evt" value="1">Activar</button>
  </form>
</section>

<!-- ② Eventos con boletería activa -->
<section>
  <h2>Eventos con boletería activa</h2>

  <table>
    <thead>
      <tr><th>Evento</th><th>Cupo / Total</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($activeEvt as $e): ?>
        <tr>
          <td><?=htmlspecialchars($e['nombre_evento'])?></td>
          <td><?=$e['ocupados']?> / <?=$e['cupo_total']?></td>
          <td>
            <a href="ticket_detalle.php?evt=<?=$e['id_evento']?>">Gestionar</a>
            <a href="?set_evt=<?=$e['id_evento']?>&on=0">Eliminar</a>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</section>

</body></html>
