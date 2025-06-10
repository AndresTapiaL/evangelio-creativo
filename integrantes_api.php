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

  /* ════════════════ 1) LISTA INTEGRANTES ════════════════ */
  case 'GET:lista': {
      $team = $_GET['team'] ?? 0;                          // 0=general ‖ 'ret'
      /* util para RUT chileno                                                */
      $fmt_rut = function($rut){
         if(!preg_match('/^\d{7,9}$/',$rut)) return $rut;
         $dv=substr($rut,-1); $num=substr($rut,0,-1);
         return number_format($num,0,'','.').'-'.$dv;
      };
      /* ——— query base ——— */
      $sql = "SELECT
                u.id_usuario,
                CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) AS nombre,
                DATE_FORMAT(u.fecha_nacimiento,'%d-%m-%Y')  AS nacimiento,
                DATE_FORMAT(u.fecha_nacimiento,'%d-%m')     AS dia_mes,
                TIMESTAMPDIFF(YEAR,u.fecha_nacimiento,:hoy) AS edad,
                u.rut_dni AS rut_fmt,
                GROUP_CONCAT(CONCAT(t.telefono,' (',dt.nombre_descripcion_telefono,')')
                             ORDER BY t.es_principal DESC SEPARATOR ' / ')               AS telefonos,
                ce.correo_electronico                                                    AS correo,
                CONCAT_WS(' / ',cc.nombre_ciudad_comuna,re.nombre_region_estado,
                                    p.nombre_pais)                                        AS ubicacion,
                u.direccion,
                u.iglesia_ministerio,
                u.profesion_oficio_estudio,
                DATE_FORMAT(u.fecha_registro,'%d-%m-%Y')                                  AS ingreso,
                TIMESTAMPDIFF(MONTH ,u.ultima_actualizacion,:hoy)                         AS meses_desde_update,
                DATE_FORMAT(u.ultima_actualizacion,'%d-%m-%Y')                            AS ultima_act,
                lep1.id_tipo_estado_actividad est1,
                lep2.id_tipo_estado_actividad est2,
                lep3.id_tipo_estado_actividad est3,
                /* si el LEFT JOIN no encontró nada,
                    usamos el id del período directamente */
                COALESCE( lep1.id_periodo,
                        (SELECT id_periodo
                            FROM periodos
                            WHERE fecha_termino >= :hoy
                            ORDER BY fecha_termino
                            LIMIT 1)
                        )                        AS per1_id,
                COALESCE( lep2.id_periodo,
                        (SELECT id_periodo
                            FROM periodos
                            WHERE fecha_termino < :hoy
                            ORDER BY fecha_termino DESC
                            LIMIT 1 OFFSET 0)
                        )                        AS per2_id,
                COALESCE( lep3.id_periodo,
                        (SELECT id_periodo
                            FROM periodos
                            WHERE fecha_termino < :hoy
                            ORDER BY fecha_termino DESC
                            LIMIT 1 OFFSET 1)
                        )                        AS per3_id,
                iep.id_integrante_equipo_proyecto
              FROM usuarios u
              LEFT JOIN paises p   ON p.id_pais=u.id_pais
              LEFT JOIN ciudad_comuna cc ON cc.id_ciudad_comuna=u.id_ciudad_comuna
              LEFT JOIN region_estado re ON re.id_region_estado=u.id_region_estado
              LEFT JOIN correos_electronicos ce ON ce.id_usuario=u.id_usuario
              LEFT JOIN telefonos t ON t.id_usuario=u.id_usuario
              LEFT JOIN descripcion_telefonos dt ON dt.id_descripcion_telefono=t.id_descripcion_telefono
              LEFT JOIN integrantes_equipos_proyectos iep ON iep.id_usuario=u.id_usuario
              /* tres últimos periodos */
              LEFT JOIN v_last_estado_periodo lep1 ON lep1.id_integrante_equipo_proyecto=iep.id_integrante_equipo_proyecto
                AND lep1.id_periodo=(SELECT id_periodo FROM periodos WHERE fecha_termino>=:hoy ORDER BY fecha_termino LIMIT 1)
              LEFT JOIN v_last_estado_periodo lep2 ON lep2.id_integrante_equipo_proyecto=iep.id_integrante_equipo_proyecto
                AND lep2.id_periodo=(SELECT id_periodo FROM periodos WHERE fecha_termino<:hoy ORDER BY fecha_termino DESC LIMIT 1 OFFSET 0)
              LEFT JOIN v_last_estado_periodo lep3 ON lep3.id_integrante_equipo_proyecto=iep.id_integrante_equipo_proyecto
                AND lep3.id_periodo=(SELECT id_periodo FROM periodos WHERE fecha_termino<:hoy ORDER BY fecha_termino DESC LIMIT 1 OFFSET 1)
              WHERE 1 ";

      if($team==='ret'){
          $sql.=" AND u.id_usuario IN (SELECT id_usuario FROM retirados) ";
      }elseif($team!=0){
          $sql.=" AND iep.id_equipo_proyecto=:team ";
      }else{
          $sql.=" AND u.id_usuario NOT IN (SELECT id_usuario FROM retirados) ";
      }
      $sql.=" GROUP BY u.id_usuario ORDER BY nombre";

      $st=$pdo->prepare($sql);
      $st->bindValue(':hoy',$hoy);
      if($team!=0 && $team!=='ret') $st->bindValue(':team',$team,PDO::PARAM_INT);
      $st->execute();
      $rows=$st->fetchAll(PDO::FETCH_ASSOC);
      foreach($rows as &$r){           // RUT visual
        $r['rut_dni_fmt']=$fmt_rut($r['rut_fmt']);
        unset($r['rut_fmt']);
      }
      echo json_encode(['ok'=>true,'integrantes'=>$rows]);
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
      $eq=$_GET['eq']??'null';
      $st=$pdo->prepare("SELECT id_rol AS id,nombre_rol AS nom
                           FROM roles
                          WHERE ".($eq==='null'?'id_equipo_proyecto IS NULL':'id_equipo_proyecto=:eq')."
                          ORDER BY nom");
      if($eq!=='null') $st->bindValue(':eq',$eq,PDO::PARAM_INT);
      $st->execute();
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
              CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) AS nombre_completo,
              DATE_FORMAT(u.fecha_nacimiento,'%d-%m-%Y')                     AS nacimiento_fmt,
              DATE_FORMAT(u.fecha_nacimiento,'%d-%m')                        AS dia_mes,
              TIMESTAMPDIFF(YEAR,u.fecha_nacimiento,CURDATE())               AS edad,
              DATE_FORMAT(u.fecha_registro ,'%d-%m-%Y')                      AS fecha_registro_fmt,
              TIMESTAMPDIFF(MONTH,u.ultima_actualizacion,CURDATE())          AS meses_upd,
              p.nombre_pais,
              re.nombre_region_estado,
              cc.nombre_ciudad_comuna,
              ce.correo_electronico,
              /* teléfonos – marca el principal y separa con saltos de línea */
              GROUP_CONCAT(
                  CONCAT(t.telefono,' (',dt.nombre_descripcion_telefono,
                          IF(t.es_principal=1,' – Principal',''),')')
                  ORDER BY t.es_principal DESC SEPARATOR '\n'
              ) AS telefonos,
              /* ocupaciones en texto plano */
              ( SELECT GROUP_CONCAT(o.nombre ORDER BY o.nombre SEPARATOR ', ')
                  FROM usuarios_ocupaciones uo
                  JOIN ocupaciones o ON o.id_ocupacion = uo.id_ocupacion
                  WHERE uo.id_usuario = u.id_usuario
              ) AS ocupaciones
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
         WHERE iep.id_usuario = :id";

    $stmt = $pdo->prepare($sqlEquip);
    $stmt->execute([':id' => $id]);
    $equip = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'user' => $u, 'equipos' => $equip]);
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

        /* 1-bis) ¿quitar foto de perfil? -------------------------------------- */
        if (!empty($_POST['del_foto']) && $_POST['del_foto']==='1') {
            $pdo->prepare("UPDATE usuarios
                            SET foto_perfil = NULL
                            WHERE id_usuario = :id")->execute([':id'=>$id]);
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
            $pdo->prepare("INSERT INTO telefonos
                (id_usuario, telefono, es_principal, id_descripcion_telefono)
                VALUES (:id, :tel, :pri, :des)")
                ->execute([
                    ':id'  => $id,
                    ':tel' => $_POST["tel$i"],
                    ':pri' => $i === 0 ? 1 : 0,
                    ':des' => $_POST["tel_desc$i"] ?: null
                ]);
        }

        /* 4) Equipos / proyectos nuevos --------------------------------------- */
        if (!empty($_POST['equip'])) {
            $arr = json_decode($_POST['equip'], true);
            foreach ($arr as $row) {
                if (empty($row['eq']) || empty($row['rol'])) continue;
                $pdo->prepare("INSERT IGNORE INTO integrantes_equipos_proyectos
                    (id_usuario, id_equipo_proyecto, id_rol)
                    VALUES (:u, :e, :r)")
                    ->execute([
                        ':u' => $id,
                        ':e' => $row['eq'],
                        ':r' => $row['rol']
                    ]);
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
          AND p.nombre_periodo RLIKE '-T[123]$'";
        
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['anio_min'=>null,'anio_max'=>null];

    echo json_encode(['ok'=>true]+$row);
    break;
  }

  /* ══════ (cualquier otra combinación) ══════ */
  default:  throw new Exception('accion');
  }

} catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
