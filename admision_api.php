<?php
/* ───────────────────────────────────────────────────────────
   API única Integrantes   –   responde JSON               (UTF-8)
   Param  accion = lista | equipos | roles | detalles | estado | editar
   © Evangelio Creativo – 2025
----------------------------------------------------------------*/
require 'conexion.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
/* ——— endurece las cabeceras ——— */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

ob_start();                  // NUEVO: todas las salidas se bufferizan
ini_set('display_errors',0); // evita que PHP envíe warnings al cliente

/* —— helper de respuesta JSON SEGURA —— */
function reply(array $a): void{
    ob_clean();                  // descarta cualquier byte previo
    echo json_encode($a);        // imprime JSON puro
    exit;                        // corta la ejecución
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

  /* ════════════════ 6) EDITAR USUARIO ════════════════ */
  case 'POST:nuevo': {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;   // 0 = nuevo

      /* ———  Rate-limit: máx 5 formularios por **dispositivo** y día ——— */
      $deviceId = trim($_POST['device_id'] ?? '');
      $fpId     = trim($_POST['fp_id']     ?? '');   // puede venir vacío

      if ($deviceId === '' && $fpId === '') {
          reply(['ok'=>false,
              'error'=>'No pudimos identificar tu dispositivo; vuelve a intentarlo.']);
      }

      /* cuenta envíos del mismo device_id **o** del mismo fp_id */
      $limStmt = $pdo->prepare("
          SELECT COUNT(*) AS n
          FROM admision_envios
          WHERE fecha = CURDATE()
          AND (
                  device_id = :d
              OR ( :f <> '' AND fp_id = :f )
              OR (device_id IS NULL AND fp_id IS NULL AND ip = :ip) /* fallback legacy */
          )
      ");
      $limStmt->execute([
          ':d'  => $deviceId,
          ':f'  => $fpId,
          ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
      ]);

      if ($limStmt->fetchColumn() >= 5){
          reply(['ok'=>false,
              'error'=>'Límite diario de 5 formularios alcanzado desde este dispositivo; inténtalo mañana.']);
      }

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
              reply([
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
      /* —— CUESTIONARIO (obligatorio) —— */
      $liderazgo   = trim($_POST['liderazgo']   ?? '');
      $nosConoces  = trim($_POST['nos_conoces'] ?? '');
      $proposito   = trim($_POST['proposito']   ?? '');
      $motivacion  = trim($_POST['motivacion']  ?? '');

      foreach([
          ['v'=>$liderazgo , 'et'=>'Liderazgo'],
          ['v'=>$nosConoces, 'et'=>'¿Cómo nos conoces?'],
          ['v'=>$proposito , 'et'=>'Propósito'],
          ['v'=>$motivacion, 'et'=>'Motivación']
      ] as $f){
          if($f['v']===''){
              reply(['ok'=>false,'error'=>$f['et'].' es obligatorio']); exit;
          }
      }

      if(!preg_match('/^[1-5]$/',$motivacion)){
          reply(['ok'=>false,'error'=>'Motivación debe ser un número entre 1 y 5']); exit;
      }

      $reGeneral = '/^[\p{L}\p{N} .,#¿¡!?()\/\- \n\r]+$/u';

      foreach ([
              ['v'=>$dir,'et'=>'Dirección'],
              ['v'=>$ig ,'et'=>'Iglesia / Ministerio'],
              ['v'=>$pro,'et'=>'Profesión / Oficio / Estudio']
          ] as $f){

          /* ⬇︎  ahora son obligatorios */
          if ($f['v'] === ''){
              reply(['ok'=>false,
                  'error'=>"{$f['et']} es obligatoria"]); exit;
          }
          if (mb_strlen($f['v']) > 255 || !preg_match($reGeneral,$f['v'])){
              reply(['ok'=>false,
                  'error'=>"{$f['et']} no válida (formato o longitud)"]); exit;
          }
      }

      /* —— VALIDACIÓN fecha de nacimiento —— */
      $fnac = trim($_POST['fecha_nacimiento'] ?? '');

      if ($fnac === '') {
          reply(['ok'=>false,'error'=>'La fecha de nacimiento es obligatoria']); exit;
      }
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fnac)) {
          reply(['ok'=>false,'error'=>'Formato de fecha AAAA-MM-DD no válido']); exit;
      }
      $dtFnac = DateTime::createFromFormat('Y-m-d', $fnac);
      if (!$dtFnac) {
          reply(['ok'=>false,'error'=>'Fecha de nacimiento inválida']); exit;
      }
      $today = new DateTime('today');
      $edad  = $dtFnac->diff($today)->y;
      if ($edad < 12) {
          reply(['ok'=>false,'error'=>'El integrante debe tener al menos 12 años']); exit;
      }
      if ($edad > 200) {
          reply(['ok'=>false,'error'=>'La edad no puede superar los 200 años']); exit;
      }

      /* -- comparar contra fecha_registro -- */
      $regStmt = $pdo->prepare("SELECT fecha_registro FROM usuarios WHERE id_usuario=:id");
      $regStmt->execute([':id'=>$id]);
      $fechaReg = $regStmt->fetchColumn();
      if ($fechaReg && $fnac > $fechaReg){
          $regFmt = DateTime::createFromFormat('Y-m-d', $fechaReg)->format('d-m-Y');
          reply(['ok'=>false,
              'error'=>"La fecha de nacimiento no puede ser posterior a la fecha de registro ($regFmt)"]);
          exit;
      }

      /* —— VALIDACIÓN N° documento —— */
      $rutRaw = trim($_POST['rut_dni'] ?? '');
      if ($rutRaw === '') {
          reply(['ok'=>false,'error'=>'El N° documento es obligatorio']); exit;
      } {

          if (mb_strlen($rutRaw) > 13) {
              reply(['ok'=>false,'error'=>'N° documento excede 13 caracteres']); exit;
          }

          $rutSan = strtoupper(preg_replace('/[^0-9K]/i', '', $rutRaw));

          if (strpos($rutSan,'K') !== false && substr($rutSan,-1) !== 'K'){
              reply(['ok'=>false,'error'=>'La K solo puede ir al final']); exit;
          }

          if (!empty($_POST['id_pais']) && $_POST['id_pais'] == 1){   // Chile
              if (!preg_match('/^\d{7,8}[0-9K]$/', $rutSan)){
                  reply(['ok'=>false,'error'=>'Formato RUT chileno inválido']); exit;
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
                  reply(['ok'=>false,'error'=>'RUT chileno inválido']); exit;
              }
          } elseif (!preg_match('/^\d{1,13}$/', $rutSan)){
              reply(['ok'=>false,'error'=>'Documento: solo dígitos']); exit;
          }
      }

      /* —— VALIDACIÓN Correo electrónico —— */
      $email = trim($_POST['correo'] ?? '');
      if ($email === '') {
          reply(['ok'=>false,'error'=>'El correo electrónico es obligatorio']); exit;
      }
      if (mb_strlen($email) > 320) {
          reply(['ok'=>false,'error'=>'Correo excede 320 caracteres']); exit;
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          reply(['ok'=>false,'error'=>'Correo electrónico no válido']); exit;
      }

      /* ---------- ubicación preliminar (se necesita para el chequeo de duplicados) ---------- */
      $pais   = trim($_POST['id_pais']          ?? '');
      $region = trim($_POST['id_region_estado'] ?? '');
      $ciudad = trim($_POST['id_ciudad_comuna'] ?? '');

      /* ───── Duplicados por rut_dni + id_pais ───── */
      $isUpdate = false;                                       // por defecto => INSERT
      $dupStmt  = $pdo->prepare("
          SELECT id_usuario
          FROM usuarios
          WHERE rut_dni = :rut
          AND id_pais <=> :pais           /* <=> permite NULL = NULL */
          LIMIT 1");
      $dupStmt->execute([
          ':rut'  => $rutSan,
          ':pais' => ($pais === '' ? null : $pais)
      ]);
      $dupId = (int)$dupStmt->fetchColumn();

      if ($dupId){                                             // hay un homónimo
          /* ¿tiene ALGUNA fila habilitada en integrantes_equipos_proyectos ? */
          $act = $pdo->prepare("
              SELECT 1
              FROM integrantes_equipos_proyectos
              WHERE id_usuario = :u
              AND habilitado = 1
              LIMIT 1");
          $act->execute([':u'=>$dupId]);

          if ($act->fetchColumn()){                            // ≥1 fila activa
              reply([
                  'ok'   => false,
                  'error'=> 'El usuario ya está registrado'
              ]);                                              // reply() hace exit()
          }

          /* Ninguna fila activa  →  re-usamos ese id y sobre-escribimos */
          $id       = $dupId;
          $isUpdate = true;                                    // UPDATE en vez de INSERT
      }

      /* ─── Ubicación ─── */

      /* —— 1)  PAÍS OBLIGATORIO —— */
      if ($pais === '') {
          reply(['ok'=>false, 'error'=>'El país es obligatorio']);
          exit;
      }

      /* A) País */
      if ($pais !== '') {
          $ok = (bool)$pdo->query("SELECT 1 FROM paises WHERE id_pais = $pais")->fetchColumn();
          if (!$ok) { reply(['ok'=>false,'error'=>'País inválido']); exit; }
      }

      /* B) Región → debe existir y pertenecer al país (si se indicó país) */
      if ($region !== '') {
          $st = $pdo->prepare("SELECT id_pais FROM region_estado WHERE id_region_estado = ?");
          $st->execute([$region]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if (!$row)                 { reply(['ok'=>false,'error'=>'Región/Estado inválido']); exit; }
          if ($pais && $row['id_pais'] != $pais){
              reply(['ok'=>false,'error'=>'Región no pertenece al país']); exit;
          }
      }

      /* C) Ciudad → debe existir y pertenecer a la región (si se indicó región) */
      if ($ciudad !== '') {
          $st = $pdo->prepare("SELECT id_region_estado FROM ciudad_comuna WHERE id_ciudad_comuna = ?");
          $st->execute([$ciudad]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if (!$row) { reply(['ok'=>false,'error'=>'Ciudad/Comuna inválida']); exit; }
          if ($region && $row['id_region_estado'] != $region){
              reply(['ok'=>false,'error'=>'Ciudad no pertenece a la región']); exit;
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
      $razonRet   = array_key_exists('razon_ret',     $_POST)
                  ? trim($_POST['razon_ret'])     : null;
      $exeqRet    = array_key_exists('ex_equipo_ret', $_POST)
                  ? trim($_POST['ex_equipo_ret']) : null;
      $difuntoRet = array_key_exists('es_difunto_ret',$_POST)
                  ? $_POST['es_difunto_ret']      : null;

      /* ¿se pretende modificar datos de retiro? */
      $tocaRetiro = $razonRet !== null || $exeqRet !== null || $difuntoRet !== null;

      /* ─── validación solo si se van a tocar esos datos ─── */
      if ($esRetirado && $tocaRetiro) {

        if ($razonRet === '') {
            reply(['ok'=>false,'error'=>'La razón de retiro es obligatoria']); exit;
        }
        if (mb_strlen($razonRet) > 255 || !preg_match($reGeneral,$razonRet)){
            reply(['ok'=>false,'error'=>'Razón de retiro inválida']); exit;
        }

        if ($exeqRet === '') {
            reply(['ok'=>false,'error'=>'El ex-equipo es obligatorio']); exit;
        }
        if (mb_strlen($exeqRet) > 50 || !preg_match($reGeneral,$exeqRet)){
            reply(['ok'=>false,'error'=>'Ex-equipo inválido']); exit;
        }

        if (!in_array($difuntoRet,['0','1'],true)){
            reply(['ok'=>false,'error'=>'Valor de “Fallecido” no válido']); exit;
        }
      }
      /* Si $tocaRetiro es false, el bloque anterior se salta por completo
      y los datos de la tabla `retirados` quedan intactos. */

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

        if ($isUpdate) {
            /* actualización mínima */
            $pdo->prepare("
                UPDATE usuarios SET
                    nombres                  = :nom,
                    apellido_paterno         = :ap,
                    apellido_materno         = :am,
                    fecha_nacimiento         = :fnac,
                    id_region_estado         = :reg,
                    id_ciudad_comuna         = :ciu,
                    direccion                = :dir,
                    iglesia_ministerio       = :ig,
                    profesion_oficio_estudio = :pro,
                    ultima_actualizacion     = NOW()
                WHERE id_usuario = :id
            ")->execute([
                ':nom'=>$nombres, ':ap'=>$apellido_paterno, ':am'=>$apellido_materno,
                ':fnac'=>$fnac,   ':reg'=>$region, ':ciu'=>$ciudad,
                ':dir'=>$dir,     ':ig'=>$ig,      ':pro'=>$pro, ':id'=>$id
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO usuarios
                    (nombres,apellido_paterno,apellido_materno,fecha_nacimiento,
                    rut_dni,id_pais,id_region_estado,id_ciudad_comuna,direccion,
                    iglesia_ministerio,profesion_oficio_estudio,
                    fecha_registro,ultima_actualizacion)
                VALUES
                    (:nom,:ap,:am,:fnac,:rut,:pais,:reg,:ciu,:dir,:ig,:pro,CURDATE(),NOW())
            ")->execute([
                ':nom'=>$nombres, ':ap'=>$apellido_paterno, ':am'=>$apellido_materno,
                ':fnac'=>$fnac,   ':rut'=>$rutSan, ':pais'=>$pais, ':reg'=>$region, ':ciu'=>$ciudad,
                ':dir'=>$dir,     ':ig'=>$ig,      ':pro'=>$pro
            ]);
            $id = (int)$pdo->lastInsertId();          // nuevo usuario
        }

        /* 1-bis) ¿quitar foto de perfil? */
        if (!empty($_POST['del_foto']) && $_POST['del_foto']==='1') {

            // ① localiza la ruta guardada
            $old = $pdo->prepare("SELECT foto_perfil
                                    FROM usuarios
                                WHERE id_usuario = :id");
            $old->execute([':id'=>$id]);
            $path = $old->fetchColumn();

            $baseDir = realpath(__DIR__.'UPLOADS_FOTOS_DIR');   // carpeta segura
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

        /* ───── Correo duplicado ───── */
        $dupMail = $pdo->prepare("
            SELECT id_usuario
            FROM correos_electronicos
            WHERE correo_electronico = :c
            LIMIT 1");
        $dupMail->execute([':c'=>$email]);
        $mailOwner = (int)$dupMail->fetchColumn();

        if ($mailOwner && $mailOwner !== $id) {
            reply(['ok'=>false,'error'=>'Ese correo electrónico ya está en uso']);
            exit;
        }

        /* 2) Correo electrónico ------------------------------------------------
        — un solo correo por usuario —
        · si ya había uno para ese usuario se borra
        · se inserta el nuevo con la preferencia del boletín               */
        $boletin = isset($_POST['boletin']) ? 1 : 0;

        /* elimina cualquier correo previo del mismo usuario ----------------- */
        $pdo->prepare("
            DELETE FROM correos_electronicos
            WHERE id_usuario = :id
        ")->execute([':id'=>$id]);

        /* inserta el nuevo --------------------------------------------------- */
        $pdo->prepare("
            INSERT INTO correos_electronicos
                (correo_electronico, id_usuario, boletin)
            VALUES
                (:c, :id, :b)
        ")->execute([
            ':c'  => $email,
            ':id' => $id,
            ':b'  => $boletin
        ]);

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
            reply(['ok'=>false,
                'error'=>"Completa Teléfono ".($i+1)." antes de Teléfono ".($i+2)]); exit;
        }
        }

        /* B) nº ⇒ descripción obligatoria y viceversa */
        foreach ($phones as $idx => $ph) {
        if ($ph['num'] !== '' && $ph['desc'] === '') {
            reply(['ok'=>false,
                'error'=>"Selecciona descripción para Teléfono ".($idx+1)]); exit;
        }
        if ($ph['num'] === '' && $ph['desc'] !== '') {
            reply(['ok'=>false,
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

            reply(['ok'=>false,'error'=>'Sin permiso para modificar equipos']);
            exit;
        }

        /* 4) Equipos / proyectos */
        if (!empty($_POST['equip'])) {
            $rows = json_decode($_POST['equip'], true) ?: [];
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
            reply(['ok'=>false,'error'=>'Ocupaciones: formato inválido']); exit;
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
            reply(['ok'=>false,'error'=>'Ocupación no válida']); exit;
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

        /* tabla admision  (estado por defecto = 1 → “Pendiente”) */
        $pdo->prepare("
            INSERT INTO admision
                (id_usuario,id_estado_admision,liderazgo,nos_conoces,proposito,motivacion)
            VALUES
                (:u,4,:lid,:nos,:propo,:mot)
            ON DUPLICATE KEY UPDATE
                id_estado_admision = VALUES(id_estado_admision),
                liderazgo          = VALUES(liderazgo),
                nos_conoces        = VALUES(nos_conoces),
                proposito          = VALUES(proposito),
                motivacion         = VALUES(motivacion)
        ")->execute([
            ':u'   => $id,
            ':lid' => trim($_POST['liderazgo']   ?? ''),
            ':nos' => trim($_POST['nos_conoces'] ?? ''),
            ':propo'=>trim($_POST['proposito']   ?? ''),
            ':mot' => trim($_POST['motivacion']  ?? '')
        ]);

        /* registra el envío exitoso (para el rate-limit) */
        $pdo->prepare("
            INSERT INTO admision_envios (ip, device_id, fp_id, fecha)
            VALUES (:ip, :d, :f, CURDATE())
        ")->execute([
            ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            ':d'  => $deviceId ?: null,
            ':f'  => $fpId     ?: null
        ]);

      $pdo->commit();
      reply(['ok'=>true]);
      break;
  }
  /* ══════ (cualquier otra combinación) ══════ */
  default:  throw new Exception('accion');
  }

} catch(Throwable $e){
    /* ► clave duplicada: rut_dni */
    if ($e instanceof PDOException &&
        $e->getCode() == 23000 &&
        str_contains($e->getMessage(), 'rut_dni')
    ){
        http_response_code(200);                      // deja que JS lo procese sin error 500
        reply(['ok'=>false,'error'=>'El usuario ya está registrado']);
    }

    http_response_code(400);
    reply(['ok'=>false,'error'=>$e->getMessage()]);
}
