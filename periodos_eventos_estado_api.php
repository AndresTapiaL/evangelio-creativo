<?php
/* periodos_eventos_estado_api.php */
require 'conexion.php';
require_once 'lib_auth.php';
session_start();
$uid = $_SESSION['id_usuario'] ?? 0;
if (!user_can_use_reports($pdo,$uid)){
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}
header('Content-Type: application/json; charset=utf-8');

/* periodos_eventos_estado_api.php */
try {

    /* ①  crea, si hace falta, el anual del año en curso (igual que periodos_api.php) */
    $yy = date('Y');
    $pdo->exec("
        INSERT IGNORE INTO periodos (nombre_periodo, fecha_inicio, fecha_termino)
        VALUES ('$yy-Anual', '$yy-01-01', '$yy-12-31')
    ");

    /* ②  consulta principal */
    $sql = "
      /* ───── Cuatrimestres que SÍ tienen eventos ───── */
      SELECT
          p.id_periodo,
          CASE
            WHEN p.nombre_periodo LIKE '%-T1'   THEN CONCAT('Enero-Abril ',       LEFT(p.nombre_periodo,4))
            WHEN p.nombre_periodo LIKE '%-T2'   THEN CONCAT('Mayo-Agosto ',       LEFT(p.nombre_periodo,4))
            WHEN p.nombre_periodo LIKE '%-T3'   THEN CONCAT('Septiembre-Diciembre ', LEFT(p.nombre_periodo,4))
          END AS nombre_periodo
      FROM  periodos p
      JOIN  eventos  e
        ON  get_period_id(DATE(e.fecha_hora_inicio)) = p.id_periodo
      WHERE p.nombre_periodo RLIKE '-T[123]$'
        AND e.id_estado_final IS NOT NULL
      GROUP BY p.id_periodo

      UNION ALL

      /* ─────  Períodos ANUALES — uno por cada año con eventos ───── */
      SELECT
          p.id_periodo,
          CONCAT('Anual ', LEFT(p.nombre_periodo,4)) AS nombre_periodo
      FROM  periodos p
      WHERE p.nombre_periodo LIKE '%-Anual'
        /* el año debe existir en la tabla eventos               */
        AND LEFT(p.nombre_periodo,4) IN (
              SELECT DISTINCT YEAR(fecha_hora_inicio)
              FROM   eventos
              WHERE  id_estado_final IS NOT NULL
          )

      ORDER BY LEFT(nombre_periodo,4) DESC,
               CASE
                 WHEN nombre_periodo LIKE 'Enero-Abril%'           THEN 1
                 WHEN nombre_periodo LIKE 'Mayo-Agosto%'           THEN 2
                 WHEN nombre_periodo LIKE 'Septiembre-Diciembre%'  THEN 3
                 WHEN nombre_periodo LIKE 'Anual%'                 THEN 4
               END
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'data'=>$rows]);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
