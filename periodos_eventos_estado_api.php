<?php
/* periodos_eventos_estado_api.php */
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "
      SELECT
          p.id_periodo,
          CASE
             WHEN p.nombre_periodo LIKE '%-T1' THEN CONCAT('Enero-Abril ',  LEFT(p.nombre_periodo,4))
             WHEN p.nombre_periodo LIKE '%-T2' THEN CONCAT('Mayo-Agosto ',  LEFT(p.nombre_periodo,4))
             WHEN p.nombre_periodo LIKE '%-T3' THEN CONCAT('Septiembre-Diciembre ', LEFT(p.nombre_periodo,4))
          END AS nombre_periodo
      FROM periodos             p
      JOIN eventos              e ON get_period_id(DATE(e.fecha_hora_inicio)) = p.id_periodo
      WHERE p.nombre_periodo RLIKE '-T[123]$'          -- sólo cuatrimestres
        AND e.id_estado_final IS NOT NULL              -- eventos con estado definido
      GROUP BY p.id_periodo, p.nombre_periodo
      ORDER BY LEFT(p.nombre_periodo,4) DESC,
               CASE                                     -- T3,T2,T1 (igual que en periodos_equipos_api)
                  WHEN p.nombre_periodo LIKE '%-T3' THEN 3
                  WHEN p.nombre_periodo LIKE '%-T2' THEN 2
                  ELSE 1
               END DESC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'data'=>$rows]);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
