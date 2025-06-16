<?php
/* ───────────────────────────────────────────────────────────
   API única Integrantes   –   responde JSON               (UTF-8)
   Param  accion = lista | equipos | roles | detalles | estado | editar
   © Evangelio Creativo – 2025
----------------------------------------------------------------*/
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

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

  /* ════════════════ 1) LISTA INTEGRANTES ════════════════ */
  case 'GET:lista': {
      $team   = $_GET['team'] ?? '0';
      $page   = max(1,(int)($_GET['page']??1));
      $per    = max(1,min(200,(int)($_GET['per']??50)));   // 50 por defecto

      $sort = $_GET['sort'] ?? 'nombre';              // alias pedido desde el front
      $dir  = (strtoupper($_GET['dir']??'ASC')==='DESC') ? 'DESC' : 'ASC';

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
      'es_difunto'                 => 'r.es_difunto'
      ];

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

      /* util para RUT chileno                                                */
      $fmt_rut = function($rut){
         if(!preg_match('/^\d{7,9}$/',$rut)) return $rut;
         $dv=substr($rut,-1); $num=substr($rut,0,-1);
         return number_format($num,0,'','.').'-'.$dv;
      };
      /* ---------- columnas / joins de estados según el equipo ------------- */
      $selectEstados = '';
      $joinEstados   = '';

      if ($team !== '0' && $team !== 'ret') {       // Equipos o Proyectos “reales”
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
      } else {                                    // “General” o “Retirados”
          $selectEstados = "
              NULL AS est1,
              NULL AS est2,
              NULL AS est3,
              NULL AS per1_id,
              NULL AS per2_id,
              NULL AS per3_id,
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
                  $selectEstados
                  iep.id_integrante_equipo_proyecto
              FROM usuarios u
              {$joinRet}
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

      if($team==='ret'){
          $sql.=" AND u.id_usuario IN (SELECT id_usuario FROM retirados) ";
      }elseif($team!=='ret' && $team!='0'){
          $sql.=" AND iep.id_equipo_proyecto=:team
                    AND iep.habilitado = 1 ";
      }else{                 /* General */
          $sql.=" AND u.id_usuario NOT IN (SELECT id_usuario FROM retirados)
                  AND EXISTS (SELECT 1
                                FROM integrantes_equipos_proyectos ie2
                                WHERE ie2.id_usuario = u.id_usuario
                                  AND ie2.habilitado = 1)";
      }

      /* total antes del LIMIT → para paginación */
      $sqlCnt = 'SELECT COUNT(*) FROM ('.$sql.' GROUP BY u.id_usuario) x';
      $tot = $pdo->prepare($sqlCnt);
      if($team!=0 && $team!=='ret') $tot->bindValue(':team',$team,PDO::PARAM_INT);
      $tot->bindValue(':hoy',$hoy); $tot->execute();
      $totalRows = (int)$tot->fetchColumn();

      $sql .= " GROUP BY u.id_usuario
              ORDER BY $orderBy $dir
              LIMIT :off,:per";

      $st=$pdo->prepare($sql);
      $st->bindValue(':hoy',$hoy);
      $st->bindValue(':off',($page-1)*$per,PDO::PARAM_INT);
      $st->bindValue(':per',$per,PDO::PARAM_INT);
      if($team!=0 && $team!=='ret') $st->bindValue(':team',$team,PDO::PARAM_INT);
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
      $items=[ ['id'=>0,'nombre'=>'General','es_equipo'=>null] ];
      $items=array_merge($items,
        $pdo->query("SELECT id_equipo_proyecto AS id,nombre_equipo_proyecto AS nombre,es_equipo
                       FROM equipos_proyectos
                   ORDER BY es_equipo DESC,nombre_equipo_proyecto")->fetchAll(PDO::FETCH_ASSOC));
      $items[]=['id'=>'ret','nombre'=>'Retirados','es_equipo'=>null];
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
           AND iep.habilitado = 1";

    $stmt = $pdo->prepare($sqlEquip);
    $stmt->execute([':id' => $id]);
    $equip = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $esRetirado = $pdo->query("SELECT 1 FROM retirados WHERE id_usuario=$id")->fetchColumn();
    if ($esRetirado){
        $ret = $pdo->query("
            SELECT razon, es_difunto, ex_equipo, fecha_retiro
            FROM retirados
            WHERE id_usuario = $id
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'      => true,
            'user'    => $u,
            'ret'     => $ret,
            'equipos' => []        // ← evita TypeError en el front-end
        ]);
        return;
    }

    /* ---- equipos actuales (para edición) ---- */
    $equipNowStmt = $pdo->prepare("
        SELECT id_equipo_proyecto AS eq,
            id_rol             AS rol
        FROM integrantes_equipos_proyectos
        WHERE id_usuario = ?
        AND habilitado = 1");
    $equipNowStmt->execute([$id]);
    $equip_now = $equipNowStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
          'ok'        => true,
          'user'      => $u,
          'equipos'   => $equip,     // tabla resumen con estados
          'equip_now' => $equip_now  // equipos vigentes + rol
    ]);
    break;
  }

  /* ════════════════ 4-bis) ESTADOS POR AÑO ════════════════ */
  case 'GET:estados_anio': {
   $id  = (int)($_GET['id'] ?? 0);          // id_usuario
   $yr  = (int)($_GET['anio'] ?? date('Y'));
   if(!$id) throw new Exception('id');

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
        AND iep.habilitado = 1
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

  /* ════════════════ 6) EDITAR USUARIO ════════════════ */
  case 'POST:editar': {
      $id=(int)($_POST['id']??0);
      if(!$id) throw new Exception('id');
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
            ':nom' => $_POST['nombres']                 ?? '',
            ':ap'  => $_POST['apellido_paterno']        ?? '',
            ':am'  => $_POST['apellido_materno']        ?? '',
            ':fnac'=> $_POST['fecha_nacimiento']        ?? null,
            ':rut' => $_POST['rut_dni']                 ?? '',
            ':pais'=> $_POST['id_pais']                 ?: null,
            ':reg' => $_POST['id_region_estado']        ?: null,
            ':ciu' => $_POST['id_ciudad_comuna']        ?: null,
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
                ->execute([':c'=>$_POST['correo'], ':id'=>$id]);
        }

        /* 3) Teléfonos (máx 3) ------------------------------------------------- */
        $pdo->prepare("DELETE FROM telefonos WHERE id_usuario = :id")
            ->execute([':id'=>$id]);

        for ($i = 0; $i < 3; $i++) {
            if (empty($_POST["tel$i"])) continue;

            // el front end ya normaliza a +E.164
            $tel = preg_replace('/\s+/', '', $_POST["tel$i"]);

            // (opcional) validación ultra-básica: debe empezar con “+” y 8-15 dígitos
            if (!preg_match('/^\+\d{8,15}$/', $tel)) {
                throw new Exception("Teléfono $i con formato inválido");
            }

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

      $pdo->commit();
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
    $forceret = (int)($_POST['force'] ?? 0);          // 1 = retiro confirmado
    $motivo   = trim($_POST['motivo'] ?? '');
    $difunto  = (int)($_POST['difunto'] ?? 0);

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
                ':u'=>$uid,
                ':raz'=>$motivo ?: null,
                ':ex'=>$eqNom,
                ':dif'=>$difunto
            ]);

    $pdo->commit();
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
    echo json_encode(['ok'=>true]);
    break;
  }

  case 'POST:delete_user': {
    $id=(int)($_POST['id_usuario']??0);
    if(!$id) throw new Exception('id');
    $pdo->prepare("DELETE FROM usuarios WHERE id_usuario=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    break;
  }

  /* ══════ (cualquier otra combinación) ══════ */
  default:  throw new Exception('accion');
  }

} catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
