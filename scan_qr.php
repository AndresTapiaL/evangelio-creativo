<?php
declare(strict_types=1);
session_start();
require 'conexion.php';

/* ─── Seguridad: solo Liderazgo Nacional ───────────────────── */
if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    exit('Sesión no iniciada');
}
$uid = $_SESSION['id_usuario'];
$ok  = $pdo->prepare("
       SELECT 1 FROM integrantes_equipos_proyectos
        WHERE id_usuario=? AND id_equipo_proyecto=1 AND habilitado=1");
$ok->execute([$uid]);
if (!$ok->fetchColumn()){
    http_response_code(403);
    exit('Sin permiso');
}

/* ─── Parámetros ───────────────────────────────────────────── */
$code = $_GET['code'] ?? '';              // hash del QR
$ing  = isset($_GET['ing']) ? (int)$_GET['ing'] : null;  // 1=ingreso,0=salida

if ($code==='')  die('QR faltante');

/* ① localiza ticket + horario válido en este momento ------------- */
$stmt = $pdo->prepare("
   SELECT tu.id_ticket_usuario,
          th.id_ticket_horario,
          tu.nombre_completo,
          tu.correo_electronico,
          tu.contacto,
          et.nombre_ticket,
          e.nombre_evento
     FROM ticket_usuario tu
     JOIN eventos_tickets et   USING(id_evento_ticket)
     JOIN eventos         e    USING(id_evento)
     JOIN ticket_horarios th   ON th.id_evento = e.id_evento
    WHERE tu.qr_codigo = ?
      AND et.activo = 1
      AND e.boleteria_activa = 1
      AND NOW() BETWEEN th.fecha_inicio AND th.fecha_fin
    LIMIT 1");
$stmt->execute([$code]);
$info = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$info)  die('QR no válido para este horario / evento');
$idUsr      = (int)$info['id_ticket_usuario'];
$idHorario  = (int)$info['id_ticket_horario'];

/* ─── ③ Si aún no se indicó ingreso / salida → pantalla de opciones ─── */
if ($ing===null){
    /* mini-UI con la info de la persona  */
    ?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><title>Escaneo QR</title>
<style>
body{font-family:sans-serif;margin:2rem}
h1{font-size:1.2rem;margin:0 0 .8rem}
a.btn{display:inline-block;padding:.6rem 1.2rem;margin:.4rem 0;
      border:1px solid #333;text-decoration:none;border-radius:.3rem}
.ing{background:#dff0d8} .sal{background:#f2dede}
</style></head><body>
<h1><?=$info['nombre_evento']?> – <?=$info['nombre_ticket']?></h1>
<ul>
  <li><strong>Nombre:</strong> <?=$info['nombre_completo']?></li>
  <li><strong>Correo:</strong> <?=$info['correo_electronico']?></li>
  <li><strong>Celular:</strong> <?=$info['contacto']?></li>
</ul>
<p>¿Qué desea registrar?</p>
<a class="btn ing" href="?code=<?=$code?>&ing=1">✅ Ingreso</a>
<a class="btn sal" href="?code=<?=$code?>&ing=0">🚪 Salida</a>
</body></html>
<?php
    exit;
}

/* ─── ④ validación de secuencia (trigger ya la refuerza) ──── */
$last = $pdo->prepare("
   SELECT es_ingreso
     FROM ticket_scans
    WHERE id_ticket_horario=? AND id_ticket_usuario=?
 ORDER BY scan_at DESC
    LIMIT 1");
$last->execute([$idHorario,$idUsr]);
$prev = $last->fetchColumn();

if ($prev!==null && (int)$prev === $ing){
    $msg = $ing ? 'Ya se registró INGRESO; primero marque salida.'
                : 'No hay ingreso abierto para marcar salida.';
    die($msg);
}

/* ─── ⑤ insertar el scan ───────────────────────────────────── */
$pdo->prepare("
    INSERT INTO ticket_scans(id_ticket_horario,id_ticket_usuario,es_ingreso)
    VALUES (?,?,?)")->execute([$idHorario,$idUsr,$ing]);

/* ─── ⑥ respuesta final: popup simple con la info ──────────── */
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><title>OK</title>
<style>body{font-family:sans-serif;text-align:center;margin-top:4rem}</style>
</head><body>
<h2><?=$ing ? '✔ INGRESO registrado' : '✔ SALIDA registrada'?></h2>
<p><?=$info['nombre_completo']?></p>
<p><?=$info['correo_electronico']?> – <?=$info['contacto']?></p>
</body></html>
