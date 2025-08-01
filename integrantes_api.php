<?php
/* ───────────────────────────────────────────────────────────
   API única Integrantes   –   responde JSON               (UTF-8)
   Param  accion = lista | equipos | roles | detalles | estado | editar
   © Evangelio Creativo – 2025
----------------------------------------------------------------*/
require 'conexion.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

/* ─────── Helpers e-mail (PHPMailer) ───────────────────────────── */
require_once __DIR__.'/phpmailer/Exception.php';
require_once __DIR__.'/phpmailer/PHPMailer.php';
require_once __DIR__.'/phpmailer/SMTP.php';

/**
 * Devuelve un array asociativo con TODA la información que hay que
 * mostrar de la persona ($uid) según las tablas indicadas en el enunciado.
 * Si está en admision o retirados añade también esos datos.
 */
function getUserFullInfo(int $uid, PDO $pdo): array {
    /* — datos básicos + catálogo de nombres — */
    $sql = "
      SELECT
        CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) AS nombre_completo,
        DATE_FORMAT(u.fecha_nacimiento,'%d-%m-%Y') AS fecha_nacimiento,
        u.rut_dni,
        p.nombre_pais,
        re.nombre_region_estado,
        cc.nombre_ciudad_comuna,
        u.direccion,
        u.iglesia_ministerio,
        u.profesion_oficio_estudio
      FROM usuarios u
      LEFT JOIN paises        p  ON p.id_pais           = u.id_pais
      LEFT JOIN region_estado re ON re.id_region_estado = u.id_region_estado
      LEFT JOIN ciudad_comuna cc ON cc.id_ciudad_comuna = u.id_ciudad_comuna
      WHERE u.id_usuario = ?";
    $row = $pdo->prepare($sql);
    $row->execute([$uid]);
    $info = $row->fetch(PDO::FETCH_ASSOC) ?: [];

    /* teléfonos (el principal primero) */
    $tels = $pdo->prepare("
        SELECT t.telefono,
               dt.nombre_descripcion_telefono     AS descripcion,
               t.es_principal
          FROM telefonos t
          LEFT JOIN descripcion_telefonos dt USING(id_descripcion_telefono)
         WHERE t.id_usuario = ?
      ORDER BY t.es_principal DESC");
    $tels->execute([$uid]);
    $info['telefonos'] = $tels->fetchAll(PDO::FETCH_ASSOC);

    /* ocupaciones */
    $oc = $pdo->prepare("
        SELECT o.nombre
          FROM usuarios_ocupaciones uo
          JOIN ocupaciones o USING(id_ocupacion)
         WHERE uo.id_usuario = ?
      ORDER BY o.nombre");
    $oc->execute([$uid]);
    $info['ocupaciones'] = $oc->fetchAll(PDO::FETCH_COLUMN);

    /* correo */
    $mailStmt = $pdo->prepare("
            SELECT correo_electronico
            FROM correos_electronicos
            WHERE id_usuario = ?
            LIMIT 1");
    $mailStmt->execute([$uid]);
    $info['correo_electronico'] = (string)$mailStmt->fetchColumn();

    /* datos Admision, si existen */
    $adm = $pdo->prepare("
        SELECT liderazgo,nos_conoces,proposito,motivacion
          FROM admision WHERE id_usuario=? LIMIT 1");
    $adm->execute([$uid]);
    if ($admRow = $adm->fetch(PDO::FETCH_ASSOC)) $info['admision'] = $admRow;

    /* datos Retirados, si existen */
    $ret = $pdo->prepare("
        SELECT razon FROM retirados WHERE id_usuario=? LIMIT 1");
    $ret->execute([$uid]);
    if ($retRow = $ret->fetch(PDO::FETCH_ASSOC)) $info['retirado'] = $retRow;

    return $info;
}

/**
 * Envía la notificación a todos los miembros (rol 4 ó 6, habilitados)
 * del equipo/proyecto $eqId.
 *
 * @param string $tipo   'alta' | 'baja'
 * @param array  $userInfo  resultado de getUserFullInfo()
 * @param array  $extra     ['eqNom'=>, 'es_equipo'=>, 'razon'=>null|cadena]
 */
function notificarEquipo(string $tipo,int $eqId,array $userInfo,array $extra,PDO $pdo): void{

  /* destinatarios ------------------------------------------------- */
  $dest = $pdo->prepare("
      SELECT ce.correo_electronico
        FROM integrantes_equipos_proyectos iep
        JOIN correos_electronicos ce USING(id_usuario)
       WHERE iep.id_equipo_proyecto = ?
         AND iep.habilitado = 1
         AND iep.id_rol IN (4,6)");
  $dest->execute([$eqId]);
  $toList = $dest->fetchAll(PDO::FETCH_COLUMN);
  if(!$toList) return;                     // no hay a quién enviar

  $esEquipo = ($extra['es_equipo']==1);
  $asunto = ($tipo === 'alta')
      ? "Tienes un nuevo integrante en tu ".($esEquipo ? 'Equipo' : 'Proyecto')
          ." ({$extra['eqNom']})"
      : "Integrante eliminado de tu ".($esEquipo ? 'Equipo' : 'Proyecto')
          ." ({$extra['eqNom']})";

  /* ---- cuerpo de correo (texto simple) ---- */
  $u = $userInfo;                     // alias corto
  $txt  = "Detalle del usuario:\n";
  $txt .= "Nombre completo : {$u['nombre_completo']}\n";
  $txt .= "Fecha nacimiento: {$u['fecha_nacimiento']}\n";
  $txt .= "RUT / DNI       : {$u['rut_dni']}\n";
  $txt .= "País / Región / Ciudad: {$u['nombre_pais']} / ".
          "{$u['nombre_region_estado']} / {$u['nombre_ciudad_comuna']}\n";
  $txt .= "Dirección       : {$u['direccion']}\n";
  $txt .= "Iglesia/Ministerio : {$u['iglesia_ministerio']}\n";
  $txt .= "Profesión/Oficio  : {$u['profesion_oficio_estudio']}\n";
  $txt .= "Ocupaciones       : ".implode(', ',$u['ocupaciones'])."\n";

  /* teléfonos */
  foreach($u['telefonos'] as $t){
     $tag = $t['es_principal']?' (principal)':'';
     $txt.="Teléfono         : {$t['telefono']}".
           ($t['descripcion']?" ({$t['descripcion']})":'').$tag."\n";
  }
  $txt .= "Correo electrónico: {$u['correo_electronico']}\n";

  /* admisión extra (solo nuevas altas)  */
  if($tipo==='alta' && isset($u['admision'])){
      $a=$u['admision'];
      $txt.="\n--- Información admisión ---\n";
      $txt.="Experiencia liderazgo : ".$a['liderazgo']."\n";
      $txt.="¿Cómo nos conoció?    : ".$a['nos_conoces']."\n";
      $txt.="Propósito             : ".$a['proposito']."\n";
      $txt.="Motivación (1-5)      : ".$a['motivacion']."\n";
  }

  /* retirados extra (solo bajas) */
  if($tipo==='baja' && isset($extra['razon'])){
      $txt.="\nRazón del retiro: ".$extra['razon']."\n";
  }

    /* ---------- versión HTML responsiva ---------- */
    // ——— variables auxiliares ———
    $ocupacionesStr = implode(', ', $u['ocupaciones']);
    $anioActual     = date('Y');

    /* 1-a) filas básicas ------------------------------------------------ */
    $rows = [
    ['Nombre completo:',          $u['nombre_completo']],
    ['Fecha nacimiento:',         $u['fecha_nacimiento']],
    ['RUT / DNI:',                $u['rut_dni']],
    ['Ubicación:',                "{$u['nombre_pais']} / {$u['nombre_region_estado']} / {$u['nombre_ciudad_comuna']}"],
    ['Dirección:',                $u['direccion']],
    ['Iglesia / Ministerio:',     $u['iglesia_ministerio']],
    ['Profesión / Oficio:',       $u['profesion_oficio_estudio']],
    ['Ocupaciones:',              $ocupacionesStr],
    ];

    /* 1-b) teléfonos ---------------------------------------------------- */
    foreach ($u['telefonos'] as $t){
        $tag = $t['es_principal'] ? ' (principal)' : '';
        $rows[] = [
            'Teléfono:',
            $t['telefono'] .
            ($t['descripcion'] ? " ({$t['descripcion']})" : '') .
            $tag
        ];
    }

    /* 1-c) render a <tr> … --------------------------------------------- */
    $rowsHtml = '';
    foreach ($rows as [$lbl,$val]){
        $rowsHtml .= "<tr><td class=\"label\">$lbl</td><td>$val</td></tr>";
    }

    /* ─── Información de admisión (se convierte en filas de la misma tabla) ─── */
    if ($tipo === 'alta' && isset($u['admision'])){
        $a = $u['admision'];
        $admRows = [
            ['Experiencia liderazgo:', $a['liderazgo']],
            ['¿Cómo nos conoció?:',    $a['nos_conoces']],
            ['Propósito:',             $a['proposito']],
            ['Motivación (1-5):',      $a['motivacion']],
        ];
        foreach ($admRows as [$lbl,$val]){
            $rowsHtml .= "<tr><td class=\"label\">$lbl</td><td>$val</td></tr>";
        }
    }

    $retHtml = ($tipo === 'baja' && isset($extra['razon']))
            ? "<p style=\"margin-top:22px\"><strong>Razón del retiro:</strong> {$extra['razon']}</p>"
            : '';

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$asunto}</title>
    <style>
    body{margin:0;padding:0;background:#f6f8fc;font-family:'Poppins',Arial,Helvetica,sans-serif;color:#111;}
    .container{max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;}
    .header{background:#ff6b00;color:#fff;padding:22px 18px;text-align:center;font-size:1.15rem;font-weight:600;}
    .content{padding:26px;font-size:.92rem;line-height:1.45}
    .info{width:100%;border-collapse:collapse;font-size:.92rem;line-height:1.45}
    .info td{padding:4px 0;vertical-align:top}
    .info tr:not(:last-child) td{padding-bottom:6px}
    .label{width:165px;font-weight:600;color:#ff6b00;padding-right:8px}
    .footer{background:#fff4e6;font-size:.8rem;color:#555;text-align:center;padding:14px}
    @media(max-width:500px){
        .label{width:auto;display:block;padding-right:0;margin-bottom:2px}
    }
    </style>
    </head>
    <body>
    <div class="container">
        <div class="header">{$asunto}</div>

        <div style="text-align:center">
            <img src="cid:logoEC" alt="Evangelio Creativo" style="max-width:140px;margin:18px auto 0;display:block">
        </div>

        <div class="content">
        <p><strong>Detalle del usuario</strong></p>

        <table class="info" role="presentation" cellpadding="0" cellspacing="0" border="0">
            {$rowsHtml}
        </table>

        {$retHtml}
        </div>

        <div class="footer">© Evangelio Creativo – {$anioActual}</div>
    </div>
    </body>
    </html>
    HTML;

  /* ---- envío con una única conexión SMTP ---- */
  $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
  /* ── NUEVO ── */
  $mail->CharSet  = 'UTF-8';      // asunto y cuerpo en UTF-8
  $mail->Encoding = 'base64';     // codificación sugerida para textos largos
  try{
     $mail->isSMTP();
     $mail->Host       = 'smtp.gmail.com';
     $mail->SMTPAuth   = true;
     $mail->Username   = 'actividades.evangeliocreativo@gmail.com';
     $mail->Password   = 'vwsgpbigmyqaknpc';
     $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
     $mail->Port       = 587;

     $mail->setFrom('actividades.evangeliocreativo@gmail.com','Evangelio Creativo');
     foreach($toList as $dir) $mail->addAddress($dir);

     $mail->addEmbeddedImage(__DIR__.'/images/LogoEC.png', 'logoEC');

     $mail->isHTML(true);

     $mail->Subject = $asunto;
     $mail->Body    = $html;   // versión HTML responsiva
     $mail->AltBody = $txt;    // texto plano de respaldo
     $mail->send();
  }catch(\PHPMailer\PHPMailer\Exception $e){
     error_log('[Mail] '.$mail->ErrorInfo);   // no interrumpe la API
  }
}

/* ─────────────────────────  Clave temporal + e‑mail  ───────────────────────── */
function generarClaveTemporal(int $len = 8): string {
    // A–Z y 0–9 sin caracteres confusos (O/0, I/1) si quieres: cámbialo
    $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    $bytes = random_bytes($len);
    for ($i = 0; $i < $len; $i++) {
        $out .= $alf[ord($bytes[$i]) % strlen($alf)];
    }
    return $out;
}

/**
 * Guarda la clave temporal hasheada, registra en password_reset_log
 * y envía el correo al usuario.
 *
 * @param int    $uid    id_usuario
 * @param int    $eqId   id_equipo_proyecto al que fue agregado
 * @param string $eqNom  nombre del equipo/proyecto
 * @param PDO    $pdo
 */
function setPassAndMail(int $uid, int $eqId, string $eqNom, PDO $pdo, bool $registrarLog = true): void {
    // 1) correo + nombre
    $st = $pdo->prepare("
        SELECT u.nombres,
               (SELECT correo_electronico FROM correos_electronicos
                 WHERE id_usuario = u.id_usuario LIMIT 1) AS mail
        FROM usuarios u
        WHERE u.id_usuario = ?");
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['mail']) return; // nada que hacer

    $clave = generarClaveTemporal(8);
    $hash  = password_hash($clave, PASSWORD_DEFAULT);

    // 2) actualiza password
    $upd = $pdo->prepare("UPDATE usuarios SET password = :h WHERE id_usuario = :id");
    $upd->execute([':h'=>$hash, ':id'=>$uid]);

    // 3) log (si tienes trigger limitará 1/día)
    if ($registrarLog) {
        $ins = $pdo->prepare("
            INSERT INTO password_reset_log (id_usuario, correo_electronico)
            VALUES (:id, :mail)");
        $ins->execute([':id'=>$uid, ':mail'=>$row['mail']]);
    }

    // 4) correo
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try{
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'actividades.evangeliocreativo@gmail.com';
        $mail->Password   = 'vwsgpbigmyqaknpc';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('actividades.evangeliocreativo@gmail.com', 'Evangelio Creativo');
        $mail->addAddress($row['mail'], $row['nombres']);
        $mail->addEmbeddedImage(__DIR__.'/images/LogoEC.png', 'logoec', 'LogoEC.png');
        $mail->isHTML(true);
        $mail->Subject = 'Acceso a Intranet - Evangelio Creativo';
        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
        <style>
        body{margin:0;background:#f3f4f8;font-family:Poppins,Arial,sans-serif;color:#374151;}
        .wrapper{max-width:520px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;
                 box-shadow:0 8px 24px rgba(0,0,0,.08);}
        .bar{height:6px;background:linear-gradient(90deg,#ff7a33 0%,#ff5614 100%);}
        .content{padding:28px 24px;}
        h1{font-size:22px;margin:0 0 12px;color:#ff5614;font-weight:600;}
        p{margin:0 0 14px;line-height:1.5;font-size:15px;}
        .code{display:inline-block;padding:12px 18px;border-radius:10px;background:#ffe2d5;color:#d90429;
              font-size:20px;font-weight:700;letter-spacing:2px;}
        .small{font-size:12px;color:#6b7280;}
        .logo{display:block;margin:0 auto 18px;width:84px;}
        a.btn{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:10px;
              background:linear-gradient(90deg,#ff7a33 0%,#ff5614 100%);color:#fff;font-weight:600;
              text-decoration:none;}
        </style>
        </head>
        <body>
        <div class="wrapper">
          <div class="bar"></div>
          <div class="content">
            <img class="logo" src="cid:logoec" alt="Logo EC">
            <h1>¡Bienvenido/a a Evangelio Creativo!</h1>
            <p>Hola <strong>'.htmlspecialchars($row['nombres'],ENT_QUOTES,'UTF-8').'</strong>,</p>
            <p>Desde ahora tienes acceso a la intranet de Evangelio Creativo.<br>
               Fuiste agregado/a al equipo: <strong>'.htmlspecialchars($eqNom,ENT_QUOTES,'UTF-8').'</strong>.</p>
            <p>Tu clave temporal es:</p>
            <div class="code">'.$clave.'</div>
            <p>Ingresa con ella y cámbiala inmediatamente desde tu perfil.</p>
            <p><a class="btn" href="'.htmlspecialchars((isset($_SERVER['HTTPS'])?'https://':'http://').$_SERVER['HTTP_HOST'].'/PW%20EC_Antes/login.html',ENT_QUOTES,'UTF-8').'">Iniciar sesión</a></p>
            <p class="small">Si no solicitaste este acceso, ignora este mensaje.</p>
          </div>
        </div>
        </body></html>';
        $mail->AltBody = "Hola {$row['nombres']},\n\n".
                         "Fuiste agregado al equipo/proyecto: {$eqNom}.\n".
                         "Tu clave temporal es: {$clave}\n\n".
                         "Ingresa y cámbiala de inmediato: /login.html\n\nEvangelio Creativo";
        $mail->send();
    }catch(\PHPMailer\PHPMailer\Exception $e){
        error_log('[MailTempPass] '.$mail->ErrorInfo);
        // No lanzamos excepción para no romper el flujo principal
    }
}

define('UPLOADS_FOTOS_DIR', realpath(__DIR__.'/uploads/fotos'));

/* Privilegios solicitante ───────────────────── */
$uid = $_SESSION['id_usuario'] ?? 0;      // puede ser 0 si la sesión caducó
$acl = $pdo->prepare("
      SELECT id_equipo_proyecto, id_rol, habilitado
        FROM integrantes_equipos_proyectos
       WHERE id_usuario = :u AND habilitado = 1");
$acl->execute([':u'=>$uid]);
$aclRows  = $acl->fetchAll(PDO::FETCH_ASSOC);

$reqSuper = false;
$reqTeams = [];           // equipos que puede ver
foreach ($aclRows as $r){
    if ($r['id_equipo_proyecto'] == 1)      $reqSuper = true;
    if (in_array($r['id_rol'], [4,6], true))
        $reqTeams[] = $r['id_equipo_proyecto'];
}

$accion = $_GET['accion']    ?? $_POST['accion'] ?? 'lista';   // default »lista«
$metodo = $_SERVER['REQUEST_METHOD'];          // GET | POST
$hoy    = date('Y-m-d');

try {
  switch ("$metodo:$accion") {

  /* ════════════════ 0) CATÁLOGO ESTADOS DE ACTIVIDAD ════════════════ */
  case 'GET:estados': {
    $rows = $pdo->query("
        SELECT id_tipo_estado_actividad AS id,
               nombre_tipo_estado_actividad AS nom
          FROM tipos_estados_actividad
      ORDER BY id_tipo_estado_actividad
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'estados' => $rows]);
    break;
  }

  /* ═════════ 0-bis-A)   ESTADOS ADMISIÓN  ═════════ */
  case 'GET:estados_admision': {
    $rows=$pdo->query("
        SELECT id_estado_admision AS id,
               nombre_estado_admision AS nom
          FROM estados_admision
      ORDER BY id_estado_admision")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'estados'=>$rows]);
    break;
  }

  /* ════════════════ 0-bis) CATÁLOGOS GENERALES ════════════════ */
  case 'GET:paises': {
      $rows = $pdo->query("
          SELECT id_pais   AS id,
                nombre_pais AS nom
            FROM paises
        ORDER BY nombre_pais
      ")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=>true,'paises'=>$rows]);
      break;
  }

  case 'GET:regiones': {                  // ?accion=regiones&pais=1
      $pais = (int)($_GET['pais'] ?? 0);
      $st = $pdo->prepare("
          SELECT id_region_estado AS id,
                nombre_region_estado AS nom
            FROM region_estado
          WHERE id_pais = :pais
        ORDER BY nombre_region_estado");
      $st->execute([':pais'=>$pais]);
      echo json_encode(['ok'=>true,'regiones'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
      break;
  }

  case 'GET:ciudades': {                  // ?accion=ciudades&region=6
      $reg = (int)($_GET['region'] ?? 0);
      $st = $pdo->prepare("
          SELECT id_ciudad_comuna  AS id,
                nombre_ciudad_comuna AS nom
            FROM ciudad_comuna
          WHERE id_region_estado = :reg
        ORDER BY nombre_ciudad_comuna");
      $st->execute([':reg'=>$reg]);
      echo json_encode(['ok'=>true,'ciudades'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
      break;
  }

  case 'GET:desc_telefonos': {
      $rows = $pdo->query("
          SELECT id_descripcion_telefono AS id,
                nombre_descripcion_telefono AS nom
            FROM descripcion_telefonos
        ORDER BY id_descripcion_telefono
      ")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=>true,'descs'=>$rows]);
      break;
  }

  case 'GET:ocupaciones': {
      $rows = $pdo->query("
          SELECT id_ocupacion AS id,
                nombre        AS nom
            FROM ocupaciones
        ORDER BY nombre
      ")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=>true,'ocupaciones'=>$rows]);
      break;
  }

  /* ═════════ 0-ter) VALIDAR CORREO ÚNICO (AJAX) ═════════ */
  case 'GET:checkcorreo': {
      $mail = trim($_GET['mail'] ?? '');
      $uid  = (int)($_GET['uid'] ?? 0);      // id del usuario que edita (0 si alta)

      if ($mail === '') {
          echo json_encode(['ok'=>false,'error'=>'mail']); break;
      }

      $st = $pdo->prepare("
            SELECT 1
              FROM correos_electronicos
             WHERE correo_electronico = :c
               AND id_usuario <> :id      /* excluye al que está editando */
             LIMIT 1");
      $st->execute([':c'=>$mail, ':id'=>$uid]);
      $existe = (bool)$st->fetchColumn();

      echo json_encode(['ok'=>true,'exists'=>$existe]);
      break;
  }

  /* ════════════════ 1) LISTA INTEGRANTES ════════════════ */
  case 'GET:lista': {
      $team   = $_GET['team'] ?? '0';
      /*  ACL  acceso a la sección solicitada */
      if ($team === '0' || $team === 'ret' || $team === 'new'){
          if (!$reqSuper){ http_response_code(403); exit; }
      }else{
          if (!$reqSuper && !in_array((int)$team,$reqTeams,true)){
              http_response_code(403); exit;
          }
      }
      $page   = max(1,(int)($_GET['page']??1));
      $per    = max(1,min(200,(int)($_GET['per']??50)));   // 50 por defecto

      $sort = $_GET['sort'] ?? 'nombre';              // alias pedido desde el front
      $dir  = (strtoupper($_GET['dir']??'ASC')==='DESC') ? 'DESC' : 'ASC';

      /* ————————————————————————————————
      Buscador seguro
      ————————————————————————————————*/
      $rawSearch = $_GET['search'] ?? '';

      /* 1) limpiamos cualquier carácter no permitido */
      $sanitized = preg_replace(
                      '/[^\p{L}\p{N} .,#¿¡!?()\/\-@+_%\r\n]+/u',
                      '',
                      $rawSearch
                  );

      /* 2) máximo 100 caracteres                          */
      $sanitized = mb_substr($sanitized, 0, 100);

      /* 3) SI la limpieza alteró el texto original,
          desechamos la búsqueda por completo            */
      if ($sanitized !== $rawSearch) {
          $search = '';            // ← solo filtra por el sidebar
      } else {
          $search = trim($sanitized);
      }

      $extraWhere = '';
      $params     = [ ':hoy' => $hoy ];

      if ($search !== '') {
          /* elimina tildes para un LIKE “insensible” */
          $map = [
              'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
              'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'
          ];
          $norm = strtr($search, $map);        // «José» → «Jose»

          $extraWhere           = " AND nombre_norm LIKE :q ";
          $params[':q'] = '%'.$norm.'%';
      }

      /*  mapa alias ⇒ expresión SQL real  */
      $orderMap = [
      'nombre'                     => 'nombre',
      'edad'                       => 'edad',
      'ingreso'                    => 'u.fecha_registro',
      'ultima_act'                 => 'u.ultima_actualizacion',
      'correo'                     => 'ce.correo_electronico',
      'dia_mes'                    => 'MONTH(u.fecha_nacimiento), DAY(u.fecha_nacimiento)',
      'nacimiento'                 => 'u.fecha_nacimiento',
      'telefonos'                  => 'telefonos',
      'rut_dni_fmt'                => 'rut_dni_fmt',
      'ubicacion'                  => 'ubicacion',
      'direccion'                  => 'direccion',
      'iglesia_ministerio'         => 'u.iglesia_ministerio',
      'profesion_oficio_estudio'   => 'u.profesion_oficio_estudio',
      'fecha_retiro_fmt'           => 'r.fecha_retiro',
      'ex_equipo'                  => 'r.ex_equipo',
      'es_difunto'                 => 'r.es_difunto',
      'estado_adm' => 'estado_adm'
      ];

      // columnas que solo existen en Retirados
      $retOnly = ['ex_equipo','es_difunto','fecha_retiro_fmt'];

      if ($team !== 'ret' && in_array($sort, $retOnly, true)) {
          $sort = 'nombre';
      }

      /* ───── columna (o expresión) a ordenar ───── */
      switch ($sort) {
      case 'dia_mes':          // «Día-Mes»
          /* mes y día por separado, ambos con la misma dirección */
          $orderBy = "MONTH(u.fecha_nacimiento) $dir,
                      DAY(u.fecha_nacimiento)   $dir";
          $dir = '';          // ← ya está incluido
          break;

      case 'rut_dni_fmt':      // «RUT / DNI»
          /* quita todo lo que no sea dígito y ordénalo numéricamente        */
          /* MySQL 8  → REGEXP_REPLACE;   MySQL 5  → REPLACE encadenados     */
          $orderByClean = "CAST(REGEXP_REPLACE(u.rut_dni,'[^0-9]','') AS UNSIGNED)";
          $orderBy = "$orderByClean $dir";
          $dir = '';          // dirección incluida
          break;

      default:
          if (isset($orderMap[$sort])) {
              $orderBy = $orderMap[$sort];          // expresión mapeada
          } elseif (preg_match('/^[a-z_]+$/i', $sort)) {
              $orderBy = $sort;                     // alias simple
          } else {
              $orderBy = 'nombre';                  // fallback seguro
          }
      }

      /* ────── regla especial SOLO la 1.ª vez que se carga “Nuevos integrantes” ────── */
      if ($team === 'new' && $sort === 'nombre') {          // el front siempre envía
          /*  0 ⇒ Pendiente (id_estado_admision = 4) va primero, luego los demás
              y dentro de cada grupo ordena por la fecha de registro más reciente      */
          $orderBy = "CASE WHEN a.id_estado_admision = 4 THEN 0 ELSE 1 END,
                  u.fecha_registro DESC";
          $dir = '';                                        // ya incluido arriba
      }

      /* util para RUT chileno                                                */
      $fmt_rut = function($rut){
         if(!preg_match('/^\d{7,9}$/',$rut)) return $rut;
         $dv=substr($rut,-1); $num=substr($rut,0,-1);
         return number_format($num,0,'','.').'-'.$dv;
      };
      /* ---------- columnas / joins de estados según el equipo ------------- */
      $selectEstados = '';
      $joinEstados   = '';

      /* ---------- para lista de ADMISIÓN ---------- */
      $selectAdm = $joinAdm = '';
      if ($team === 'new'){
          $selectAdm = "
              ea.id_estado_admision                    AS estado_adm,
              ea.id_estado_admision                    AS est1,   /* reutiliza interfaz */
              NULL AS est2,NULL AS est3,
              NULL AS per1_id,NULL AS per2_id,NULL AS per3_id,
          ";
          $joinAdm = "
              JOIN admision a     ON a.id_usuario = u.id_usuario
              LEFT JOIN estados_admision ea ON ea.id_estado_admision = a.id_estado_admision
          ";
          /*  fuerza que los estados «normales» queden vacíos **/
          $selectEstados = '';
          $joinEstados   = '';
      }

      /* ‘new’ NO necesita los JOIN de estados */
      if ($team !== '0' && $team !== 'ret' && $team !== 'new') {
          $selectEstados = "
              lep1.id_tipo_estado_actividad                         AS est1,
              lep2.id_tipo_estado_actividad                         AS est2,
              lep3.id_tipo_estado_actividad                         AS est3,
              COALESCE( lep1.id_periodo,
                        (SELECT id_periodo FROM periodos
                        WHERE fecha_termino >= :hoy
                        ORDER BY fecha_termino LIMIT 1) )          AS per1_id,
              COALESCE( lep2.id_periodo,
                        (SELECT id_periodo FROM periodos
                        WHERE fecha_termino <  :hoy
                        ORDER BY fecha_termino DESC LIMIT 1) )     AS per2_id,
              COALESCE( lep3.id_periodo,
                        (SELECT id_periodo FROM periodos
                        WHERE fecha_termino <  :hoy
                        ORDER BY fecha_termino DESC LIMIT 1 OFFSET 1) ) AS per3_id,
          ";

          $joinEstados = "
              LEFT JOIN v_last_estado_periodo lep1
                    ON lep1.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
                    AND lep1.id_periodo = (SELECT id_periodo FROM periodos
                                            WHERE fecha_termino >= :hoy
                                        ORDER BY fecha_termino LIMIT 1)

              LEFT JOIN v_last_estado_periodo lep2
                    ON lep2.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
                    AND lep2.id_periodo = (SELECT id_periodo FROM periodos
                                            WHERE fecha_termino <  :hoy
                                        ORDER BY fecha_termino DESC LIMIT 1)

              LEFT JOIN v_last_estado_periodo lep3
                    ON lep3.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
                    AND lep3.id_periodo = (SELECT id_periodo FROM periodos
                                            WHERE fecha_termino <  :hoy
                                        ORDER BY fecha_termino DESC LIMIT 1 OFFSET 1)
          ";
      }else if ($team === '0' || $team === 'ret'){        // “General” o “Retirados”
          $selectEstados = "
              NULL AS est1,NULL AS est2,NULL AS est3,
              NULL AS per1_id,NULL AS per2_id,NULL AS per3_id,
          ";
          /* $joinEstados queda vacío */
      }

      /* ===== join exclusivos para Retirados  ===== */
      $selectRet = $joinRet = '';
      if ($team === 'ret') {
          $selectRet = "
              r.razon,
              r.es_difunto,
              r.ex_equipo,
              DATE_FORMAT(r.fecha_retiro,'%d-%m-%Y') AS fecha_retiro_fmt,
          ";
          $joinRet = "JOIN retirados r ON r.id_usuario = u.id_usuario";
      }

      /* ——— query base ——— */
      $sql = "
              SELECT
                  u.id_usuario,
                  CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) AS nombre,
                  DATE_FORMAT(u.fecha_nacimiento,'%d-%m-%Y')  AS nacimiento,
                  DATE_FORMAT(u.fecha_nacimiento,'%d-%m')     AS dia_mes,
                  TIMESTAMPDIFF(YEAR,u.fecha_nacimiento,:hoy) AS edad,
                  u.id_pais                                   AS id_pais,
                  u.rut_dni                                   AS rut_fmt,
                  GROUP_CONCAT(DISTINCT CONCAT(t.telefono,' (',dt.nombre_descripcion_telefono,')')
                              ORDER BY t.es_principal DESC SEPARATOR ' / ')    AS telefonos,
                  ce.correo_electronico                                         AS correo,
                  CONCAT_WS(' / ',cc.nombre_ciudad_comuna,re.nombre_region_estado,p.nombre_pais) AS ubicacion,
                  u.direccion,
                  u.iglesia_ministerio,
                  u.profesion_oficio_estudio,
                  DATE_FORMAT(u.fecha_registro,'%d-%m-%Y')                       AS ingreso,
                  TIMESTAMPDIFF(MONTH ,u.ultima_actualizacion,:hoy)              AS meses_desde_update,
                  DATE_FORMAT(u.ultima_actualizacion,'%d-%m-%Y')                 AS ultima_act,
                  {$selectRet}
                  {$selectAdm}
                  $selectEstados
                  iep.id_integrante_equipo_proyecto
              FROM usuarios u
              {$joinRet}
              {$joinAdm}
              LEFT JOIN paises p   ON p.id_pais=u.id_pais
              LEFT JOIN ciudad_comuna cc ON cc.id_ciudad_comuna=u.id_ciudad_comuna
              LEFT JOIN region_estado re ON re.id_region_estado=u.id_region_estado
              LEFT JOIN correos_electronicos ce ON ce.id_usuario=u.id_usuario
              LEFT JOIN telefonos t ON t.id_usuario=u.id_usuario
              LEFT JOIN descripcion_telefonos dt ON dt.id_descripcion_telefono=t.id_descripcion_telefono
              /* tres últimos periodos */
              LEFT JOIN integrantes_equipos_proyectos iep
                    ON iep.id_usuario = u.id_usuario
                    AND iep.habilitado = 1
              $joinEstados
              WHERE 1";

              if ($team === 'ret') {
                  $sql .= " AND u.id_usuario IN (SELECT id_usuario FROM retirados) ";

              } elseif ($team === 'new') {        // ← NUEVO
                  /* solo quienes están en la tabla admision y sin equipos activos */
                  $sql .= " AND EXISTS (SELECT 1
                                          FROM admision a2     /* alias distinto para evitar duplicidad */
                                      WHERE a2.id_usuario = u.id_usuario)
                          AND NOT EXISTS (SELECT 1
                                          FROM integrantes_equipos_proyectos ie2
                                      WHERE ie2.id_usuario = u.id_usuario
                                          AND ie2.habilitado = 1)";

              } elseif ($team !== '0') {          /* equipos / proyectos reales */
                  $sql .= " AND iep.id_equipo_proyecto = :team
                          AND iep.habilitado = 1 ";

              } else {                            /* “General” */
                  $sql .= " AND u.id_usuario NOT IN (SELECT id_usuario FROM retirados)
                          AND EXISTS (SELECT 1
                                      FROM integrantes_equipos_proyectos ie2
                                      WHERE ie2.id_usuario = u.id_usuario
                                          AND ie2.habilitado = 1)";
              }

      /* total antes del LIMIT → para paginación */
      $sqlCnt = 'SELECT COUNT(*) FROM ('.$sql.' GROUP BY u.id_usuario) x';
      $tot = $pdo->prepare($sqlCnt);
      if ($team != 0 && $team !== 'ret' && $team !== 'new')
          $tot->bindValue(':team', $team, PDO::PARAM_INT);
      $tot->bindValue(':hoy',$hoy); $tot->execute();
      $totalRows = (int)$tot->fetchColumn();

      $sql .= $extraWhere;

      $sql .= " GROUP BY u.id_usuario
              ORDER BY $orderBy $dir
              LIMIT :off,:per";

      $st=$pdo->prepare($sql);
      $st->bindValue(':hoy', $hoy);
      if ($search !== '')            $st->bindValue(':q', $params[':q']);
      if ($team != 0 && $team !== 'ret' && $team !== 'new')
          $st->bindValue(':team', $team, PDO::PARAM_INT);
      $st->bindValue(':off', ($page - 1) * $per, PDO::PARAM_INT);
      $st->bindValue(':per', $per, PDO::PARAM_INT);
      if ($team != 0 && $team !== 'ret' && $team !== 'new')
          $st->bindValue(':team', $team, PDO::PARAM_INT);
      $st->execute();
      $rows=$st->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as &$r) {

        /* Chile (id_pais = 1) → 12.345.678-K
        Otros          → solo dígitos                           */
        if ($r['id_pais'] == 1) {
            $r['rut_dni_fmt'] = $fmt_rut($r['rut_fmt']);
        } else {
            $r['rut_dni_fmt'] = preg_replace('/\D/', '', $r['rut_fmt']);
        }

        /* limpia columnas internas que no viajan al front */
        unset($r['rut_fmt'], $r['id_pais']);

        if ($team === 'ret') {
            $r['es_difunto']   = $r['es_difunto'] ? 'Sí' : 'No';
            $r['fecha_retiro'] = $r['fecha_retiro_fmt'];
            unset($r['fecha_retiro_fmt']);     // ya no se envía al front
        }
      }
      echo json_encode([
            'ok'=>true,
            'integrantes'=>$rows,
            'total'=>$totalRows,
            'page'=>$page,
            'per'=>$per
      ]);
      break;
  }

  /* ════════════════ 2) LISTA EQUIPOS ════════════════ */
  case 'GET:equipos': {
    if ($reqSuper){                // ve todo
        $items = [['id'=>0,'nombre'=>'General','es_equipo'=>null]];
        $items = array_merge($items,
            $pdo->query("SELECT id_equipo_proyecto AS id,
                                nombre_equipo_proyecto AS nombre,
                                es_equipo
                           FROM equipos_proyectos
                       ORDER BY es_equipo DESC,nombre_equipo_proyecto")
                 ->fetchAll(PDO::FETCH_ASSOC));
        $items[] = ['id'=>'ret','nombre'=>'Retirados','es_equipo'=>null];
    }else{
        if (!$reqTeams){ echo json_encode(['ok'=>true,'equipos'=>[]]); break; }
        $place = implode(',', array_fill(0,count($reqTeams),'?'));
        $st = $pdo->prepare("
              SELECT id_equipo_proyecto AS id,
                     nombre_equipo_proyecto AS nombre,
                     es_equipo
                FROM equipos_proyectos
               WHERE id_equipo_proyecto IN ($place)
            ORDER BY es_equipo DESC,nombre_equipo_proyecto");
        $st->execute($reqTeams);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true,'equipos'=>$items]);
    break;
  }

  /* ════════════════ 3) ROLES POR EQUIPO ════════════════ */
  case 'GET:roles': {
      $eq = $_GET['eq'] ?? null;
      if ($eq === 'null') {
          $sql = "SELECT id_rol AS id, nombre_rol AS nom
                  FROM roles
                  WHERE id_equipo_proyecto IS NULL
              ORDER BY nombre_rol";
          $st  = $pdo->query($sql);
      } else {
          $sql = "SELECT id_rol AS id, nombre_rol AS nom
                  FROM roles
                  WHERE id_equipo_proyecto IS NULL
                      OR id_equipo_proyecto = :eq
              ORDER BY nombre_rol";
          $st  = $pdo->prepare($sql);
          $st->execute([':eq'=>$eq]);
      }
      echo json_encode(['ok'=>true,'roles'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
      break;
  }

  /* ════════════════ 4) DETALLES USUARIO ════════════════ */
  case 'GET:detalles': {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('id');

    /* ► datos básicos --------------------------------------------------- */
    $sqlUser = "
      SELECT u.*,

             /* nombre completo y edad ---------------------------------- */
             CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno)          AS nombre_completo,
             DATE_FORMAT(u.fecha_nacimiento,'%d-%m-%Y')                              AS nacimiento_fmt,
             DATE_FORMAT(u.fecha_nacimiento,'%d-%m')                                 AS dia_mes,
             TIMESTAMPDIFF(YEAR,u.fecha_nacimiento,CURDATE())                        AS edad,
             DATE_FORMAT(u.fecha_registro,'%d-%m-%Y')                                AS fecha_registro_fmt,
             TIMESTAMPDIFF(MONTH,u.ultima_actualizacion,CURDATE())                   AS meses_upd,

             /* datos geográficos ---------------------------------------- */
             p.nombre_pais,
             re.nombre_region_estado,
             cc.nombre_ciudad_comuna,

             /* correo principal ---------------------------------------- */
             ce.correo_electronico,

             /* teléfonos → construimos un array JSON textual ------------ */
             CONCAT(
                   '[',
                   GROUP_CONCAT(
                       CONCAT(
                         '{\"num\":\"',      REPLACE(t.telefono,'\"','\\\\\"'),
                         '\",\"desc\":',     IFNULL(t.id_descripcion_telefono,'null'),
                         ',\"prim\":',       IFNULL(t.es_principal,0),
                         '}'
                       )
                       ORDER BY t.es_principal DESC SEPARATOR ','
                   ),
                   ']'
             )                                                   AS telefonos_arr,

             /* ids de ocupaciones en array JSON textual ---------------- */
             ( SELECT CONCAT('[', GROUP_CONCAT(id_ocupacion), ']')
                   FROM usuarios_ocupaciones
                  WHERE id_usuario = u.id_usuario )              AS ocup_ids,

             /* nombres de ocupaciones (texto plano) -------------------- */
             ( SELECT GROUP_CONCAT(o.nombre ORDER BY o.nombre SEPARATOR ', ')
                   FROM usuarios_ocupaciones uo
                   JOIN ocupaciones o ON o.id_ocupacion = uo.id_ocupacion
                  WHERE uo.id_usuario = u.id_usuario )           AS ocupaciones_txt
          FROM usuarios u
          LEFT JOIN paises                p  ON p.id_pais            = u.id_pais
          LEFT JOIN region_estado         re ON re.id_region_estado  = u.id_region_estado
          LEFT JOIN ciudad_comuna         cc ON cc.id_ciudad_comuna  = u.id_ciudad_comuna
          LEFT JOIN correos_electronicos  ce ON ce.id_usuario        = u.id_usuario
          LEFT JOIN telefonos             t  ON t.id_usuario         = u.id_usuario
          LEFT JOIN descripcion_telefonos dt ON dt.id_descripcion_telefono = t.id_descripcion_telefono
         WHERE u.id_usuario = :id
         GROUP BY u.id_usuario";

    $stmt = $pdo->prepare($sqlUser);
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    $u['telefonos_arr'] = json_decode($u['telefonos_arr'] ?? '[]', true);
    $u['ocup_ids']      = json_decode($u['ocup_ids']      ?? '[]', true);
    $u['ocupaciones']   = $u['ocupaciones_txt'] ?? '';
    unset($u['ocupaciones_txt']);

    // '1', 'true', 1  → true  |  '0', 'false', 0, ''  → false
    $onlyEnabled = isset($_GET['onlyEnabled'])
                    ? ($_GET['onlyEnabled'] === '1' ||
                    strtolower($_GET['onlyEnabled']) === 'true')
                    : true;

    /* ► equipos + roles + estados (3 últimos periodos) ------------------ */
    $sqlEquip = "
        SELECT ep.nombre_equipo_proyecto,
               r.nombre_rol,
               p1.nombre_periodo                    AS per1,
               le1.id_tipo_estado_actividad         AS est1,
               p2.nombre_periodo                    AS per2,
               le2.id_tipo_estado_actividad         AS est2,
               p3.nombre_periodo                    AS per3,
               le3.id_tipo_estado_actividad         AS est3
          FROM integrantes_equipos_proyectos iep
          JOIN equipos_proyectos            ep ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
          JOIN roles                        r  ON r.id_rol              = iep.id_rol
          /* ─── Estados de los tres últimos periodos ─── */
          LEFT JOIN v_last_estado_periodo le1
              ON le1.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
              AND le1.id_periodo = (           -- período “corriente”
                      SELECT id_periodo
                      FROM periodos
                      WHERE fecha_termino >= CURDATE()
                  ORDER BY fecha_termino
                      LIMIT 1
              )
          LEFT JOIN periodos p1  ON p1.id_periodo = le1.id_periodo

          LEFT JOIN v_last_estado_periodo le2
              ON le2.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
              AND le2.id_periodo = (           -- inmediatamente anterior
                      SELECT id_periodo
                      FROM periodos
                      WHERE fecha_termino <  CURDATE()
                  ORDER BY fecha_termino DESC
                      LIMIT 1 OFFSET 0
              )
          LEFT JOIN periodos p2  ON p2.id_periodo = le2.id_periodo

          LEFT JOIN v_last_estado_periodo le3
              ON le3.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
              AND le3.id_periodo = (           -- dos antes
                      SELECT id_periodo
                      FROM periodos
                      WHERE fecha_termino <  CURDATE()
                  ORDER BY fecha_termino DESC
                      LIMIT 1 OFFSET 1
              )
          LEFT JOIN periodos p3  ON p3.id_periodo = le3.id_periodo
         WHERE iep.id_usuario = :id
           ".($onlyEnabled ? 'AND iep.habilitado = 1' : '')."";

    $stmt = $pdo->prepare($sqlEquip);
    $stmt->execute([':id' => $id]);
    $equip = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---- equipos actuales (para edición) ---- */
    $equipNowStmt = $pdo->prepare("
        SELECT id_equipo_proyecto AS eq,
            id_rol             AS rol
        FROM integrantes_equipos_proyectos
        WHERE id_usuario = ?
        AND habilitado = 1");
    $equipNowStmt->execute([$id]);
    $equip_now = $equipNowStmt->fetchAll(PDO::FETCH_ASSOC);

    /*  ─── Información RETIRADOS (si corresponde) ─── */
    $esRetirado = (bool)$pdo->query("
                    SELECT 1 FROM retirados WHERE id_usuario = $id")->fetchColumn();

    $ret = null;
    if ($esRetirado){
        $ret = $pdo->query("
                SELECT razon, es_difunto, ex_equipo, fecha_retiro
                FROM retirados
                WHERE id_usuario = $id")->fetch(PDO::FETCH_ASSOC);
        $u['ret'] = $ret;                   // Anida dentro de “user”
    }

    /* ► campos específicos de admisión (si existen) */
    $adm = $pdo->prepare("
            SELECT liderazgo, nos_conoces, proposito, motivacion
            FROM admision
            WHERE id_usuario = :uid
            LIMIT 1");
    $adm->execute([':uid'=>$id]);
    $admRow = $adm->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'ok'        => true,
        'user'      => $u,
        'equipos'   => $equip,        // siempre viaja, incluso si está retirado
        'equip_now' => $equip_now,
        'adm'       => $admRow,
        'ret'       => $ret           // null si no está en “retirados”
    ]);
    break;
  }

  /* ════════════════ 4-bis) ESTADOS POR AÑO ════════════════ */
  case 'GET:estados_anio': {
   $id  = (int)($_GET['id'] ?? 0);          // id_usuario
   $yr  = (int)($_GET['anio'] ?? date('Y'));
   if(!$id) throw new Exception('id');

   // '1', 'true', 1  → true  |  '0', 'false', 0, ''  → false
   $onlyEnabled = isset($_GET['onlyEnabled'])
                  ? ($_GET['onlyEnabled'] === '1' ||
                     strtolower($_GET['onlyEnabled']) === 'true')
                  : true;

   /* trae los tres periodos de ese año (T1,T2,T3)          */
   $sql = "
     SELECT ep.nombre_equipo_proyecto,
            r.nombre_rol,
            p.id_periodo,
            p.nombre_periodo,
            le.id_tipo_estado_actividad
       FROM integrantes_equipos_proyectos iep
       JOIN equipos_proyectos ep ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
       JOIN roles           r  ON r.id_rol  = iep.id_rol
       JOIN v_last_estado_periodo le ON le.id_integrante_equipo_proyecto = iep.id_integrante_equipo_proyecto
       JOIN periodos p        ON p.id_periodo = le.id_periodo
      WHERE iep.id_usuario = :id
        AND p.nombre_periodo RLIKE CONCAT('^', :yr, '-T[123]$')
        ".($onlyEnabled ? 'AND iep.habilitado = 1' : '')."
      ORDER BY ep.nombre_equipo_proyecto, p.nombre_periodo";
   $st=$pdo->prepare($sql);
   $st->execute([':id'=>$id,':yr'=>$yr]);
   echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
   break;
  }

  /* ════════════════ 5) UPDATE ESTADO (inline) ════════════════ */
  case 'POST:estado': {
    $idIEP = (int)($_POST['id_iep']     ?? 0);
    $idEst = (int)($_POST['id_estado']  ?? 0);
    $idPer = (int)($_POST['id_periodo'] ?? 0);
    if (!$idIEP || !$idEst || !$idPer) throw new Exception('params');

    /* último día del período enviado */
    $fec = $pdo->prepare("SELECT fecha_termino FROM periodos WHERE id_periodo=?");
    $fec->execute([$idPer]);
    $fecha = $fec->fetchColumn();
    if (!$fecha) throw new Exception('periodo');

    $sql = "INSERT INTO historial_estados_actividad
              (id_integrante_equipo_proyecto,id_tipo_estado_actividad,id_periodo,fecha_estado_actividad)
            VALUES (:iep,:est,:per,:fec)
            ON DUPLICATE KEY UPDATE
              id_tipo_estado_actividad = VALUES(id_tipo_estado_actividad),
              fecha_estado_actividad   = VALUES(fecha_estado_actividad)";
    $pdo->prepare($sql)->execute([
        ':iep'=>$idIEP, ':est'=>$idEst, ':per'=>$idPer, ':fec'=>$fecha
    ]);
    echo json_encode(['ok'=>true]);
    break;
  }

  /* ═══════ 5-bis) UPDATE estado ADMISIÓN ═══════ */
  case 'POST:estado_adm': {
    $idU  = (int)($_POST['id_usuario'] ?? 0);
    $idEsRaw = trim($_POST['id_estado'] ?? '');
    $idEs    = ($idEsRaw === '') ? null : (int)$idEsRaw;
    if(!$idU) throw new Exception('uid');

    $pdo->prepare("
            UPDATE admision
            SET id_estado_admision = :e
            WHERE id_usuario = :u")
        ->execute([':e'=>$idEs, ':u'=>$idU]);
    echo json_encode(['ok'=>true]);
    break;
  }

  /* ════════════════ 6) EDITAR USUARIO ════════════════ */
  case 'POST:editar': {
      $id=(int)($_POST['id']??0);
      if(!$id) throw new Exception('id');

      /* ── 1. VALIDACIÓN de nombres / apellidos ─────────────────────── */
      $nombres          = trim($_POST['nombres']          ?? '');
      $apellido_paterno = trim($_POST['apellido_paterno'] ?? '');
      $apellido_materno = trim($_POST['apellido_materno'] ?? '');

      /* —— back-end mirror de la validación —— */
      $reNombre = '/^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u';

      $chk = [
        ['val' => $nombres,          'max' => 60],
        ['val' => $apellido_paterno, 'max' => 30],
        ['val' => $apellido_materno, 'max' => 30],
      ];

      foreach ($chk as $c) {
          if ($c['val'] === '') continue;              // materno puede venir vacío
          if (mb_strlen($c['val']) > $c['max'] ||
              !preg_match($reNombre, $c['val'])) {
              echo json_encode([
                  'ok'    => false,
                  'error' => 'Formato de nombre/apellido no válido'
              ]);
              exit;
          }
      }

      /* —— VALIDACIÓN Dirección / Iglesia / Profesión —— */
      $dir = trim($_POST['direccion']                ?? '');
      $ig  = trim($_POST['iglesia_ministerio']       ?? '');
      $pro = trim($_POST['profesion_oficio_estudio'] ?? '');

      $reGeneral = '/^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u';

      foreach ([
              ['v'=>$dir,'et'=>'Dirección'],
              ['v'=>$ig ,'et'=>'Iglesia / Ministerio'],
              ['v'=>$pro,'et'=>'Profesión / Oficio / Estudio']
          ] as $f){

          /* ⬇︎  ahora son obligatorios */
          if ($f['v'] === ''){
              echo json_encode(['ok'=>false,
                  'error'=>"{$f['et']} es obligatoria"]); exit;
          }
          if (mb_strlen($f['v']) > 255 || !preg_match($reGeneral,$f['v'])){
              echo json_encode(['ok'=>false,
                  'error'=>"{$f['et']} no válida (formato o longitud)"]); exit;
          }
      }

      /* —— VALIDACIÓN fecha de nacimiento —— */
      $fnac = trim($_POST['fecha_nacimiento'] ?? '');

      if ($fnac === '') {
          echo json_encode(['ok'=>false,'error'=>'La fecha de nacimiento es obligatoria']); exit;
      }
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fnac)) {
          echo json_encode(['ok'=>false,'error'=>'Formato de fecha AAAA-MM-DD no válido']); exit;
      }
      $dtFnac = DateTime::createFromFormat('Y-m-d', $fnac);
      if (!$dtFnac) {
          echo json_encode(['ok'=>false,'error'=>'Fecha de nacimiento inválida']); exit;
      }
      $today = new DateTime('today');
      $edad  = $dtFnac->diff($today)->y;
      if ($edad < 12) {
          echo json_encode(['ok'=>false,'error'=>'El integrante debe tener al menos 12 años']); exit;
      }
      if ($edad > 200) {
          echo json_encode(['ok'=>false,'error'=>'La edad no puede superar los 200 años']); exit;
      }

      /* -- comparar contra fecha_registro -- */
      $regStmt = $pdo->prepare("SELECT fecha_registro FROM usuarios WHERE id_usuario=:id");
      $regStmt->execute([':id'=>$id]);
      $fechaReg = $regStmt->fetchColumn();
      if ($fechaReg && $fnac > $fechaReg){
          $regFmt = DateTime::createFromFormat('Y-m-d', $fechaReg)->format('d-m-Y');
          echo json_encode(['ok'=>false,
              'error'=>"La fecha de nacimiento no puede ser posterior a la fecha de registro ($regFmt)"]);
          exit;
      }

      /* —— VALIDACIÓN N° documento —— */
      $rutRaw = trim($_POST['rut_dni'] ?? '');
      if ($rutRaw === '') {
          echo json_encode(['ok'=>false,'error'=>'El N° documento es obligatorio']); exit;
      } {

          if (mb_strlen($rutRaw) > 13) {
              echo json_encode(['ok'=>false,'error'=>'N° documento excede 13 caracteres']); exit;
          }

          $rutSan = strtoupper(preg_replace('/[^0-9K]/i', '', $rutRaw));

          if (strpos($rutSan,'K') !== false && substr($rutSan,-1) !== 'K'){
              echo json_encode(['ok'=>false,'error'=>'La K solo puede ir al final']); exit;
          }

          if (!empty($_POST['id_pais']) && $_POST['id_pais'] == 1){   // Chile
              if (!preg_match('/^\d{7,8}[0-9K]$/', $rutSan)){
                  echo json_encode(['ok'=>false,'error'=>'Formato RUT chileno inválido']); exit;
              }
              $num = substr($rutSan, 0, -1);
              $dv  = substr($rutSan, -1);
              $sum = 0; $mul = 2;
              for ($i = strlen($num) - 1; $i >= 0; $i--){
                  $sum += intval($num[$i]) * $mul;
                  $mul  = ($mul == 7 ? 2 : $mul + 1);
              }
              $rest   = 11 - ($sum % 11);
              $dvCalc = $rest == 11 ? '0' : ($rest == 10 ? 'K' : strval($rest));
              if ($dvCalc !== $dv){
                  echo json_encode(['ok'=>false,'error'=>'RUT chileno inválido']); exit;
              }
          } elseif (!preg_match('/^\d{1,13}$/', $rutSan)){
              echo json_encode(['ok'=>false,'error'=>'Documento: solo dígitos']); exit;
          }
      }

      /* —— VALIDACIÓN Correo electrónico —— */
      $email = trim($_POST['correo'] ?? '');
      if ($email === '') {
          echo json_encode(['ok'=>false,'error'=>'El correo electrónico es obligatorio']); exit;
      }
      if (mb_strlen($email) > 320) {
          echo json_encode(['ok'=>false,'error'=>'Correo excede 320 caracteres']); exit;
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          echo json_encode(['ok'=>false,'error'=>'Correo electrónico no válido']); exit;
      }

      /* —— correo duplicado —— */
      $dup = $pdo->prepare("
            SELECT 1
                FROM correos_electronicos
            WHERE correo_electronico = :c
                AND id_usuario <> :id
            LIMIT 1");
      $dup->execute([':c'=>$email, ':id'=>$id]);
      if ($dup->fetchColumn()){
          echo json_encode(['ok'=>false,'error'=>'El correo ya está registrado']); exit;
      }

      /* ─── Ubicación ─── */
      $pais   = trim($_POST['id_pais']           ?? '');
      $region = trim($_POST['id_region_estado']  ?? '');
      $ciudad = trim($_POST['id_ciudad_comuna']  ?? '');

      /* A) País */
      if ($pais !== '') {
          $ok = (bool)$pdo->query("SELECT 1 FROM paises WHERE id_pais = $pais")->fetchColumn();
          if (!$ok) { echo json_encode(['ok'=>false,'error'=>'País inválido']); exit; }
      }

      /* B) Región → debe existir y pertenecer al país (si se indicó país) */
      if ($region !== '') {
          $st = $pdo->prepare("SELECT id_pais FROM region_estado WHERE id_region_estado = ?");
          $st->execute([$region]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if (!$row)                 { echo json_encode(['ok'=>false,'error'=>'Región/Estado inválido']); exit; }
          if ($pais && $row['id_pais'] != $pais){
              echo json_encode(['ok'=>false,'error'=>'Región no pertenece al país']); exit;
          }
      }

      /* C) Ciudad → debe existir y pertenecer a la región (si se indicó región) */
      if ($ciudad !== '') {
          $st = $pdo->prepare("SELECT id_region_estado FROM ciudad_comuna WHERE id_ciudad_comuna = ?");
          $st->execute([$ciudad]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if (!$row) { echo json_encode(['ok'=>false,'error'=>'Ciudad/Comuna inválida']); exit; }
          if ($region && $row['id_region_estado'] != $region){
              echo json_encode(['ok'=>false,'error'=>'Ciudad no pertenece a la región']); exit;
          }
      }

      /* Normaliza a NULL los vacíos antes del UPDATE */
      $pais   = $pais   === '' ? null : $pais;
      $region = $region === '' ? null : $region;
      $ciudad = $ciudad === '' ? null : $ciudad;

      /* ¿Está actualmente en la tabla Retirados?  */
      $esRetirado = (bool)$pdo
          ->query("SELECT 1 FROM retirados WHERE id_usuario = $id")
          ->fetchColumn();

      /* ——  VALIDACIÓN Retirados  — solo si el usuario YA está retirado —— */
      $razonRet    = trim($_POST['razon_ret']       ?? '');
      $exeqRet     = trim($_POST['ex_equipo_ret']   ?? '');
      $difuntoRet  = $_POST['es_difunto_ret']       ?? '0';

      if ($esRetirado) {

        if ($razonRet === ''){
            echo json_encode(['ok'=>false,'error'=>'La razón de retiro es obligatoria']); exit;
        }
        if (mb_strlen($razonRet) > 255 || !preg_match($reGeneral,$razonRet)){
            echo json_encode(['ok'=>false,'error'=>'Razón de retiro inválida']); exit;
        }

        if ($exeqRet === ''){
            echo json_encode(['ok'=>false,'error'=>'El ex-equipo es obligatorio']); exit;
        }
        if (mb_strlen($exeqRet) > 50 || !preg_match($reGeneral,$exeqRet)){
            echo json_encode(['ok'=>false,'error'=>'Ex-equipo inválido']); exit;
        }

        if (!in_array($difuntoRet,['0','1'],true)){
            echo json_encode(['ok'=>false,'error'=>'Valor de “Fallecido” no válido']); exit;
        }
      }

      $pdo->beginTransaction();

      /* ---- helper local ---- */
      function insertar_historial(PDO $pdo,int $iepId): void {
          $per = $pdo->query("SELECT get_period_id(CURDATE())")->fetchColumn();
          $pdo->prepare("INSERT INTO historial_estados_actividad
                  (id_integrante_equipo_proyecto,id_tipo_estado_actividad,id_periodo,fecha_estado_actividad)
                  VALUES (:iep,5,:per,CURDATE())
              ON DUPLICATE KEY UPDATE
                  id_tipo_estado_actividad = 5,
                  fecha_estado_actividad   = CURDATE()")
              ->execute([':iep'=>$iepId,':per'=>$per]);
      }

        /* 1) Datos personales -------------------------------------------------- */
        $sql = "UPDATE usuarios SET
                nombres                     = :nom,
                apellido_paterno            = :ap,
                apellido_materno            = :am,
                fecha_nacimiento            = :fnac,
                rut_dni                     = :rut,
                id_pais                     = :pais,
                id_region_estado            = :reg,
                id_ciudad_comuna            = :ciu,
                direccion                   = :dir,
                iglesia_ministerio          = :ig,
                profesion_oficio_estudio    = :pro,
                ultima_actualizacion        = NOW()
                WHERE id_usuario              = :id";
        $pdo->prepare($sql)->execute([
            ':id'  => $id,
            ':nom'  => $nombres,           // ← variable validada
            ':ap'   => $apellido_paterno,  // ← variable validada
            ':am'   => $apellido_materno,  // ← variable validada
            ':fnac'=> $fnac,
            ':rut'  => $rutSan,
            ':pais'=> $pais,
            ':reg' => $region,
            ':ciu' => $ciudad,
            ':dir' => $_POST['direccion']               ?? '',
            ':ig'  => $_POST['iglesia_ministerio']      ?? '',
            ':pro' => $_POST['profesion_oficio_estudio']?? ''
        ]);

        /* 1-bis) ¿quitar foto de perfil? */
        if (!empty($_POST['del_foto']) && $_POST['del_foto']==='1') {

            // ① localiza la ruta guardada
            $old = $pdo->prepare("SELECT foto_perfil
                                    FROM usuarios
                                WHERE id_usuario = :id");
            $old->execute([':id'=>$id]);
            $path = $old->fetchColumn();

            $baseDir = UPLOADS_FOTOS_DIR;   // ruta absoluta ya resuelta en la constante
            if ($path) {
                $real = realpath($path);
                /*  Abortamos si el archivo está fuera de /uploads/fotos  */
                if ($real === false || !str_starts_with($real, $baseDir)) {
                    throw new Exception('Ruta de foto no permitida');
                }
            }

            // ② borra el archivo físico (si existe y está dentro de /uploads/fotos/)
            if ($path && file_exists($path) && str_starts_with($path,'uploads/fotos/')) {
                @unlink($path);
            }

            // ③ vacía la columna
            $pdo->prepare("UPDATE usuarios
                            SET foto_perfil = NULL
                        WHERE id_usuario = :id")
                ->execute([':id'=>$id]);
        }

        /* 2) Correo electrónico ------------------------------------------------ */
        if (!empty($_POST['correo'])) {
            $pdo->prepare("UPDATE correos_electronicos
                            SET correo_electronico = :c
                            WHERE id_usuario = :id")
                ->execute([':c'=>$email, ':id'=>$id]);
        }

        /* 3-bis) Validación teléfonos (orden, descripción y formato) */
        $phones = [];
        for ($i = 0; $i < 3; $i++) {
        $phones[$i] = [
            'num'  => trim($_POST["tel$i"]       ?? ''),
            'desc' => trim($_POST["tel_desc$i"]  ?? '')
        ];
        }

        /* A) exige contigüidad: no puede haber huecos */
        for ($i = 0; $i < 2; $i++) {
        if ($phones[$i]['num'] === '' && $phones[$i+1]['num'] !== '') {
            echo json_encode(['ok'=>false,
                'error'=>"Completa Teléfono ".($i+1)." antes de Teléfono ".($i+2)]); exit;
        }
        }

        /* B) nº ⇒ descripción obligatoria y viceversa */
        foreach ($phones as $idx => $ph) {
        if ($ph['num'] !== '' && $ph['desc'] === '') {
            echo json_encode(['ok'=>false,
                'error'=>"Selecciona descripción para Teléfono ".($idx+1)]); exit;
        }
        if ($ph['num'] === '' && $ph['desc'] !== '') {
            echo json_encode(['ok'=>false,
                'error'=>"Elimina la descripción o ingresa número en Teléfono ".($idx+1)]); exit;
        }
        }

        /* 3) Teléfonos (máx 3) ------------------------------------------------- */
        $pdo->prepare("DELETE FROM telefonos WHERE id_usuario = :id")
            ->execute([':id'=>$id]);

        for ($i = 0; $i < 3; $i++) {
            if (empty($_POST["tel$i"])) continue;

            // el front end ya normaliza a +E.164
            $tel = preg_replace('/\s+/', '', $_POST["tel$i"]);

            // (opcional) validación ultra-básica: debe empezar con “+” y 8-15 dígitos
            // + y 8-15 dígitos totales
            if (!preg_match('/^\+\d{8,15}$/', $tel)) {
                throw new Exception("Teléfono $i con formato inválido");
            }

            /* longitudes máximas para móviles hispanohablantes (sin prefijo) */   //  <<< NUEVO
            $MOBILE_MAX_ES = [                                                    //  <<< NUEVO
                '54'=>11,'591'=>8,'56'=>9,'57'=>10,'506'=>8,'53'=>8,
                '1809'=>10,'1829'=>10,'1849'=>10,'593'=>9,'503'=>8,'240'=>9,
                '502'=>8,'504'=>8,'52'=>10,'505'=>8,'507'=>8,'595'=>9,
                '51'=>9,'1787'=>10,'1939'=>10,'34'=>9,'598'=>9,'58'=>10
            ];                                                                      //  <<< NUEVO

            $MOBILE_MIN_ES = [
                '54'=>11,'591'=>8,'56'=>9,'57'=>10,'506'=>8,'53'=>8,
                '1809'=>10,'1829'=>10,'1849'=>10,'593'=>9,'503'=>8,'240'=>9,
                '502'=>8,'504'=>8,'52'=>10,'505'=>8,'507'=>8,'595'=>9,
                '51'=>9,'1787'=>10,'1939'=>10,'34'=>9,'598'=>9,'58'=>10
            ];

            $digits = ltrim($tel, '+');                                             //  <<< NUEVO
            foreach ($MOBILE_MAX_ES as $cc => $lim){                                //  <<< NUEVO
                if (str_starts_with($digits, $cc)){                                 //  <<< NUEVO
                    $rest = substr($digits, strlen($cc));                           //  <<< NUEVO
                    if (strlen($rest) > $lim){                                      //  <<< NUEVO
                        throw new Exception("Teléfono $i excede la longitud para +$cc"); //  <<< NUEVO
                    }                                                               //  <<< NUEVO
                    $min = $MOBILE_MIN_ES[$cc] ?? 8;
                    if (strlen($rest) < $min){
                        throw new Exception("Teléfono $i requiere al menos $min dígitos para +$cc");
                    }
                    break;                                                          //  <<< NUEVO
                }                                                                   //  <<< NUEVO
            }                                                                       //  <<< NUEVO

            $pdo->prepare("INSERT INTO telefonos
                    (id_usuario, telefono, es_principal, id_descripcion_telefono)
                    VALUES (:id, :tel, :pri, :des)")
                ->execute([
                    ':id'  => $id,
                    ':tel' => $tel,                    //  ← lo guardamos tal cual
                    ':pri' => $i === 0 ? 1 : 0,
                    ':des' => $_POST["tel_desc$i"] ?: null
                ]);
        }

        /* Solo Super puede tocar el bloque “equip” */
        if (!$reqSuper &&
            isset($_POST['equip']) &&
            trim($_POST['equip']) !== '' &&
            trim($_POST['equip']) !== '[]') {

            echo json_encode(['ok'=>false,'error'=>'Sin permiso para modificar equipos']);
            exit;
        }

        /* 4) Equipos / proyectos */
        if (!empty($_POST['equip'])) {
            $rows = json_decode($_POST['equip'], true) ?: [];
            /* equipos activos ANTES de editar */
            $prevEq = $pdo->prepare("
                SELECT id_equipo_proyecto
                    FROM integrantes_equipos_proyectos
                WHERE id_usuario = ?
                    AND habilitado = 1");
            $prevEq->execute([$id]);
            $__eq_prev = $prevEq->fetchAll(PDO::FETCH_COLUMN);
            $__eq_newAlta = [];          // se llenará dentro del foreach

            foreach ($rows as $r) {
                $eq  = (int)$r['eq'];   $rol = (int)$r['rol'];
                if (!$eq || !$rol) continue;

                /* ¿ya existe vínculo? */
                $st  = $pdo->prepare("
                    SELECT id_integrante_equipo_proyecto   AS iep,
                        habilitado
                    FROM integrantes_equipos_proyectos
                    WHERE id_usuario = :u AND id_equipo_proyecto = :e
                    LIMIT 1");
                $st->execute([':u'=>$id, ':e'=>$eq]);
                $ex  = $st->fetch(PDO::FETCH_ASSOC);

                if ($ex) {                               // — ya existía —
                    $iepId = $ex['iep'];

                    // si estaba deshabilitado ⇒ lo re-habilitamos
                    if (!$ex['habilitado']) {
                        $pdo->prepare("UPDATE integrantes_equipos_proyectos
                                        SET habilitado = 1,
                                            id_rol     = :r
                                        WHERE id_integrante_equipo_proyecto = :iep")
                            ->execute([':r'=>$rol, ':iep'=>$iepId]);

                        insertar_historial($pdo,$iepId); // ← helper (abajo)
                    } else {
                        // sólo cambio de rol
                        $pdo->prepare("UPDATE integrantes_equipos_proyectos
                                        SET id_rol = :r
                                        WHERE id_integrante_equipo_proyecto = :iep")
                            ->execute([':r'=>$rol, ':iep'=>$iepId]);
                    }
                } else {                                // — totalmente nuevo —
                    $pdo->prepare("INSERT INTO integrantes_equipos_proyectos
                        (id_usuario,id_equipo_proyecto,id_rol)
                        VALUES (:u,:e,:r)")
                        ->execute([':u'=>$id,':e'=>$eq,':r'=>$rol]);

                    $iepId = $pdo->lastInsertId();
                    $__eq_newAlta[] = $eq;   // lo guardamos para email
                    insertar_historial($pdo,$iepId);
                }
            }
        }

        /* ═════ Ocupaciones ═════ */
        $rawOcup = $_POST['ocup'] ?? '[]';
        /* id real de “Sin ocupación actual”.
        Si no existe la creamos on-the-fly */
        $sinId = (int)$pdo->query("
                    SELECT id_ocupacion
                    FROM ocupaciones
                    WHERE nombre LIKE 'Sin ocupación%'
                    LIMIT 1
                ")->fetchColumn();

        if (!$sinId) {
            $pdo->exec("INSERT INTO ocupaciones (nombre) VALUES ('Sin ocupación actual')");
            $sinId = (int)$pdo->lastInsertId();   // id recién creado
        }

        $ocupArr = json_decode($rawOcup, true);
        if (!is_array($ocupArr)) {
            echo json_encode(['ok'=>false,'error'=>'Ocupaciones: formato inválido']); exit;
        }

        /* 1. si no marcaron nada ⇒ forzamos “Sin ocupación actual” */
        if (!$ocupArr) {
            $ocupArr = [$sinId];             // siempre el id real
        }

        /* 2. si viene “Sin ocupación” + otras ⇒ quitamos la de “Sin ocupación” */
        if (in_array($sinId, $ocupArr, true) && count($ocupArr) > 1) {
            $ocupArr = array_values(array_diff($ocupArr, [$sinId]));
        }

        /* 3. valida que todas existan en la tabla */
        $place = implode(',', array_fill(0,count($ocupArr),'?'));
        $chk   = $pdo->prepare("SELECT COUNT(*) FROM ocupaciones WHERE id_ocupacion IN ($place)");
        $chk->execute($ocupArr);
        if ($chk->fetchColumn() != count($ocupArr)){
            echo json_encode(['ok'=>false,'error'=>'Ocupación no válida']); exit;
        }

        /* 4. actualiza vínculo usuario ↔ ocupación */
        $pdo->prepare("DELETE FROM usuarios_ocupaciones WHERE id_usuario=?")
            ->execute([$id]);

        $ins = $pdo->prepare("
            INSERT INTO usuarios_ocupaciones (id_usuario,id_ocupacion)
            VALUES (?,?)");
        foreach ($ocupArr as $oc)
            $ins->execute([$id,$oc]);

        /* 5)  Si vienen campos de retiro, actualiza la tabla retirados  */
        if (
            !empty($_POST['razon_ret']) ||
            !empty($_POST['ex_equipo_ret']) ||
            (isset($_POST['es_difunto_ret']) && $_POST['es_difunto_ret'] !== '0')
        ) {
            $pdo->prepare("
                INSERT INTO retirados
                    (id_usuario, fecha_retiro, razon, ex_equipo, es_difunto)
                VALUES (:id, CURDATE(), :raz, :ex, :dif)
                ON DUPLICATE KEY UPDATE
                    razon      = VALUES(razon),
                    ex_equipo  = VALUES(ex_equipo),
                    es_difunto = VALUES(es_difunto)
            ")->execute([
                ':id'  => $id,
                ':raz' => $_POST['razon_ret']        ?: null,
                ':ex'  => $_POST['ex_equipo_ret']    ?: null,
                ':dif' => (int)($_POST['es_difunto_ret'] ?? 0)
            ]);
        }

        if (isset($_POST['adm_liderazgo'])) {
            $pdo->prepare("
                INSERT INTO admision
                    (id_usuario,liderazgo,nos_conoces,proposito,motivacion)
                VALUES (:id,:lid,:nos,:propo,:mot)
                ON DUPLICATE KEY UPDATE
                  liderazgo = VALUES(liderazgo),
                  nos_conoces = VALUES(nos_conoces),
                  proposito = VALUES(proposito),
                  motivacion = VALUES(motivacion)
            ")->execute([
                ':id'   => $id,
                ':lid'  => trim($_POST['adm_liderazgo']),
                ':nos'  => trim($_POST['adm_nosconoces']),
                ':propo'=> trim($_POST['adm_proposito']),
                ':mot'  => trim($_POST['adm_motivacion'])
            ]);
        }

      $pdo->commit();

      /* ─── envíos solo por los equipos añadidos ─── */
      $__userInfo = ($__eq_newAlta) ? getUserFullInfo($id,$pdo) : null;
      foreach($__eq_newAlta as $nEq){
      $rowEq = $pdo->query("
              SELECT nombre_equipo_proyecto, es_equipo
              FROM equipos_proyectos
              WHERE id_equipo_proyecto = $nEq")->fetch(PDO::FETCH_ASSOC);
      notificarEquipo(
              'alta',
              $nEq,
              $__userInfo,
              [
              'eqNom'     => $rowEq['nombre_equipo_proyecto'],
              'es_equipo' => $rowEq['es_equipo'],
              'razon'     => null
              ],
              $pdo
      );
      }

      echo json_encode(['ok'=>true]);
      break;
  }

/* ════════════════ 4-ter) LÍMITES MIN / MAX AÑO ════════════════ */
  case 'GET:estados_bounds': {
    $id = (int)($_GET['id'] ?? 0);
    if(!$id) throw new Exception('id');

    $sql = "
       SELECT  MIN(CAST(SUBSTRING(p.nombre_periodo,1,4) AS UNSIGNED)) AS anio_min,
               MAX(CAST(SUBSTRING(p.nombre_periodo,1,4) AS UNSIGNED)) AS anio_max
         FROM   v_last_estado_periodo le
         JOIN   periodos              p   ON p.id_periodo = le.id_periodo
         JOIN   integrantes_equipos_proyectos iep
                      ON iep.id_integrante_equipo_proyecto = le.id_integrante_equipo_proyecto
        WHERE  iep.id_usuario = :id
          AND p.nombre_periodo RLIKE '-T[123]$'
          AND iep.habilitado = 1";
        
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['anio_min'=>null,'anio_max'=>null];

    echo json_encode(['ok'=>true]+$row);
    break;
  }

    /* ════════════════ 7) ELIMINAR VÍNCULO / RETIRO ════════════════ */
  case 'POST:eliminar': {
    $iep  = (int)($_POST['iep']   ?? 0);

    /* capturamos datos para mailing ANTES de tocar nada */
    $__delDatos = $pdo->prepare("
        SELECT iep.id_usuario,
                ep.id_equipo_proyecto,
                ep.nombre_equipo_proyecto,
                ep.es_equipo
        FROM integrantes_equipos_proyectos iep
        JOIN equipos_proyectos ep USING(id_equipo_proyecto)
        WHERE iep.id_integrante_equipo_proyecto = ?");
    $__delDatos->execute([$iep]);
    $__delDatos = $__delDatos->fetch(PDO::FETCH_ASSOC);

    $forceret = (int)($_POST['force'] ?? 0);          // 1 = retiro confirmado
    $motivo   = trim($_POST['motivo'] ?? '');
    $difunto  = (int)($_POST['difunto'] ?? 0);

    /* —— validación Motivo y Falleció —— */
    $reGeneral = '/^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u';

    if ($forceret){
        if ($motivo === ''){
            echo json_encode(['ok'=>false,'error'=>'El motivo de retiro es obligatorio']); exit;
        }
        if (mb_strlen($motivo) > 255 || !preg_match($reGeneral,$motivo)){
            echo json_encode(['ok'=>false,'error'=>'Motivo de retiro inválido']); exit;
        }
        if (!in_array($difunto,[0,1],true)){
            echo json_encode(['ok'=>false,'error'=>'Valor de “Falleció” no válido']); exit;
        }
    }

    if (!$iep) throw new Exception('iep');

    $pdo->beginTransaction();

    /* 1)  datos del vínculo */
    $q = $pdo->prepare("SELECT iep.id_usuario,
                                iep.id_equipo_proyecto,
                                ep.nombre_equipo_proyecto,
                                ep.es_equipo
                            FROM integrantes_equipos_proyectos iep
                            JOIN equipos_proyectos ep
                            ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
                        WHERE iep.id_integrante_equipo_proyecto = ?");
    $q->execute([$iep]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('vinculo');

    $uid   = $row['id_usuario'];
    $eqNom = $row['nombre_equipo_proyecto'];

    /* 2) ¿tiene OTRO equipo real (es_equipo=1) habilitado? */
    $q2 = $pdo->prepare("
        SELECT COUNT(*) FROM integrantes_equipos_proyectos iep
        JOIN equipos_proyectos ep ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
        WHERE iep.id_usuario = :u
            AND iep.habilitado = 1
            AND ep.es_equipo  = 1
            AND iep.id_integrante_equipo_proyecto <> :iep");
    $q2->execute([':u'=>$uid,':iep'=>$iep]);
    $otros = (int)$q2->fetchColumn();

    /* periodo actual (crea si no existe) */
    $per = $pdo->query("SELECT get_period_id(CURDATE())")->fetchColumn();

    /* helper para insertar/actualizar historial */
    $hist = $pdo->prepare("
            INSERT INTO historial_estados_actividad
                (id_integrante_equipo_proyecto,id_tipo_estado_actividad,id_periodo,fecha_estado_actividad)
            VALUES (?,?,?,CURDATE())
            ON DUPLICATE KEY UPDATE
                id_tipo_estado_actividad = VALUES(id_tipo_estado_actividad),
                fecha_estado_actividad   = CURDATE()");

    /* ─────────── CASO A: aún le quedan otros equipos ─────────── */
    if ($otros > 0) {
            $pdo->prepare("UPDATE integrantes_equipos_proyectos
                            SET habilitado = 0
                            WHERE id_integrante_equipo_proyecto = ?")
                ->execute([$iep]);
            $hist->execute([$iep, 6, $per]);       // 6 = Retirado / inactivo
        $pdo->commit();
        echo json_encode(['ok'=>true]);
        break;
    }

    /* ─────────── CASO B: último equipo ─────────── */
    if (!$forceret) {
        /* →  el front mostrará modal confirmación */
        $un = $pdo->query("SELECT CONCAT_WS(' ',nombres,apellido_paterno,apellido_materno)
                            FROM usuarios WHERE id_usuario = $uid")->fetchColumn();

        $pdo->rollBack();

        echo json_encode([
            'ok'=>false,
            'needRetiro'=>1,
            'usuario'=>$un,
            'eq'=>$eqNom
        ]);
        break;
    }

    /*  B-2  “Continuar” → retiro definitivo  */

        /* 3.1  deshabilita TODOS los vínculos */
        $ips = $pdo->prepare("
            SELECT id_integrante_equipo_proyecto
            FROM integrantes_equipos_proyectos
            WHERE id_usuario = :u AND habilitado = 1");
        $ips->execute([':u'=>$uid]);
        while ($r = $ips->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("UPDATE integrantes_equipos_proyectos
                            SET habilitado = 0
                        WHERE id_integrante_equipo_proyecto = ?")
                ->execute([$r['id_integrante_equipo_proyecto']]);
            $hist->execute([$r['id_integrante_equipo_proyecto'],6,$per]);
        }

        /* 3.2  inserta (o actualiza) en `retirados` */
        $pdo->prepare("
            INSERT INTO retirados
                (id_usuario,fecha_retiro,razon,ex_equipo,es_difunto)
            VALUES (:u,CURDATE(),:raz,:ex,:dif)
            ON DUPLICATE KEY UPDATE
                fecha_retiro = VALUES(fecha_retiro),
                razon        = VALUES(razon),
                ex_equipo    = VALUES(ex_equipo),
                es_difunto   = VALUES(es_difunto)")
            ->execute([
                ':u'   => $uid,
                ':raz' => $motivo,
                ':ex'  => $eqNom,        // último equipo del que se retira
                ':dif' => $difunto
            ]);

    $__razonRet = $motivo;
    $pdo->commit();

    /* correo de BAJA */
    $__usrInfo = getUserFullInfo($__delDatos['id_usuario'],$pdo);
    notificarEquipo(
    'baja',
    $__delDatos['id_equipo_proyecto'],
    $__usrInfo,
    [
        'eqNom'     => $__delDatos['nombre_equipo_proyecto'],
        'es_equipo' => $__delDatos['es_equipo'],
        'razon'     => $forceret ? $__razonRet : null
    ],
    $pdo
    );

    echo json_encode(['ok'=>true,'retirado'=>1]);
    break;
  }

  case 'POST:reingresar': {
    $id     = (int)($_POST['id_usuario']??0);
    $eq     = (int)($_POST['id_equipo']??0);
    $rol    = (int)($_POST['id_rol']??0);
    if(!$id||!$eq||!$rol) throw new Exception('params');

    $pdo->beginTransaction();

    /* a)  ¿existe vínculo deshabilitado? */
    $st=$pdo->prepare("SELECT id_integrante_equipo_proyecto
                            FROM integrantes_equipos_proyectos
                        WHERE id_usuario=:u AND id_equipo_proyecto=:e
                        LIMIT 1");
    $st->execute([':u'=>$id,':e'=>$eq]);
    $iep = $st->fetchColumn();

    if($iep){
        /* rehabi­lita y cambia rol */
        $pdo->prepare("UPDATE integrantes_equipos_proyectos
                            SET habilitado=1, id_rol=:r
                        WHERE id_integrante_equipo_proyecto=:iep")
            ->execute([':r'=>$rol,':iep'=>$iep]);
    }else{
        $pdo->prepare("INSERT INTO integrantes_equipos_proyectos
                        (id_usuario,id_equipo_proyecto,id_rol,habilitado)
                        VALUES (:u,:e,:r,1)")
            ->execute([':u'=>$id,':e'=>$eq,':r'=>$rol]);
        $iep = $pdo->lastInsertId();
    }

    $__mail_accion   = 'alta';
    $__mail_eqId     = $eq;
    $__mail_eqNombre = $pdo->query("
        SELECT nombre_equipo_proyecto, es_equipo
            FROM equipos_proyectos
            WHERE id_equipo_proyecto = $eq")->fetch(PDO::FETCH_ASSOC);
    $__mail_userInfo = getUserFullInfo($id,$pdo);   // ← id del re-ingresado

    /* b)  inserta historial estado “5 = Nuevo” */
    $per = $pdo->query("SELECT get_period_id(CURDATE())")->fetchColumn();
    $pdo->prepare("INSERT INTO historial_estados_actividad
            (id_integrante_equipo_proyecto,id_tipo_estado_actividad,id_periodo,fecha_estado_actividad)
            VALUES (:iep,5,:per,CURDATE())
            ON DUPLICATE KEY UPDATE
                id_tipo_estado_actividad=5,
                fecha_estado_actividad = CURDATE()")
        ->execute([':iep'=>$iep,':per'=>$per]);

    /* c)  fecha_registro ahora */
    $pdo->prepare("UPDATE usuarios SET fecha_registro=CURDATE()
                        WHERE id_usuario=:u")->execute([':u'=>$id]);

    /* d)  remueve de retirados */
    $pdo->prepare("DELETE FROM retirados WHERE id_usuario=:u")->execute([':u'=>$id]);

    $pdo->commit();

    notificarEquipo(
        $__mail_accion,
        $__mail_eqId,
        $__mail_userInfo,
        [
        'eqNom'     => $__mail_eqNombre['nombre_equipo_proyecto'],
        'es_equipo' => $__mail_eqNombre['es_equipo'],
        'razon'     => null
        ],
        $pdo
    );

    /* === Clave temporal + correo al usuario === */
    setPassAndMail($id, $eq, $__mail_eqNombre['nombre_equipo_proyecto'], $pdo, false);

    echo json_encode(['ok'=>true]);
    break;
  }

  /* ═══════  POST:ingresar  (nuevo) ═══════ */
  case 'POST:ingresar': {
    $uid = (int)($_POST['id_usuario']??0);
    $eq  = (int)($_POST['id_equipo']??0);
    $rol = (int)($_POST['id_rol']   ??0);
    if(!$uid||!$eq||!$rol) throw new Exception('params');

    $pdo->beginTransaction();

    /* ► capturamos TODO (incluida admisión) para el e-mail */
    $__mail_userInfo = getUserFullInfo($uid, $pdo);

    /* 1. borra de admisión y (si existe) de retirados */
    $pdo->prepare("DELETE FROM admision  WHERE id_usuario=?")->execute([$uid]);
    $pdo->prepare("DELETE FROM retirados WHERE id_usuario=?")->execute([$uid]);

    /* 2. crea vínculo activo */
    $pdo->prepare("INSERT INTO integrantes_equipos_proyectos
          (id_usuario,id_equipo_proyecto,id_rol,habilitado)
          VALUES (?,?,?,1)")
        ->execute([$uid,$eq,$rol]);
    $iep = $pdo->lastInsertId();

    /* ► lista para mailing */
    $__mail_accion   = 'alta';
    $__mail_eqId     = $eq;
    $__mail_eqNombre = $pdo->query("
        SELECT nombre_equipo_proyecto, es_equipo
            FROM equipos_proyectos
            WHERE id_equipo_proyecto = $eq")->fetch(PDO::FETCH_ASSOC);

    /* 3. historial estado «5 = Nuevo» */
    $per=$pdo->query("SELECT get_period_id(CURDATE())")->fetchColumn();
    $pdo->prepare("INSERT INTO historial_estados_actividad
            (id_integrante_equipo_proyecto,id_tipo_estado_actividad,id_periodo,fecha_estado_actividad)
            VALUES (?,?,?,CURDATE())")
        ->execute([$iep,5,$per]);

    /* 4. fecha_registro = hoy */
    $pdo->prepare("UPDATE usuarios SET fecha_registro = CURDATE()
                    WHERE id_usuario=?")->execute([$uid]);

    $pdo->commit();

    /* — ENVÍO de correo una vez confirmado el commit — */
    notificarEquipo(
        $__mail_accion,
        $__mail_eqId,
        $__mail_userInfo,
        [
        'eqNom'     => $__mail_eqNombre['nombre_equipo_proyecto'],
        'es_equipo' => $__mail_eqNombre['es_equipo'],
        'razon'     => null
        ],
        $pdo
    );

    /* === Clave temporal + correo al usuario === */
    setPassAndMail($uid, $eq, $__mail_eqNombre['nombre_equipo_proyecto'], $pdo, false);

    echo json_encode(['ok'=>true]);
    break;
  }

  case 'POST:delete_user': {
    $id = (int)($_POST['id_usuario'] ?? 0);
    if(!$id) throw new Exception('id');

    /* 1) eliminamos primero las filas hijas que bloquean la FK */
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM admision
                   WHERE id_usuario = ?")
        ->execute([$id]);

    /*  (agrega aquí otros DELETE si existen más tablas hijas) */

    /* 2) ahora sí podemos borrar al usuario */
    $pdo->prepare("DELETE FROM usuarios
                   WHERE id_usuario = ?")
        ->execute([$id]);
    $pdo->commit();

    echo json_encode(['ok'=>true]);
    break;
  }

  /* ════════ eliminar SOLO un campo de admisión ════════ */
  case 'POST:del_adm_campo': {
    $uid   = (int)($_POST['id_usuario'] ?? 0);
    $campo = $_POST['campo']           ?? '';
    $allowed = ['liderazgo','nos_conoces','proposito','motivacion'];

    if (!$uid || !in_array($campo, $allowed, true)){
        throw new Exception('params');
    }

    $pdo->prepare("UPDATE admision
                    SET $campo = NULL
                    WHERE id_usuario = :u")
        ->execute([':u' => $uid]);

    echo json_encode(['ok' => true]);
    break;
  }

  /* ══════ (cualquier otra combinación) ══════ */
  default:  throw new Exception('accion');
  }

} catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
