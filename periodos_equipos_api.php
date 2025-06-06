<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // 1) Obtenemos todos los cuatrimestres (nombre_periodo termina en -T1/-T2/-T3)
    //    que efectivamente tengan al menos un integrante con 'historial' en ese periodo.
    //    Para eso unimos periodos con la vista v_last_estado_periodo.
    $sql = "
      SELECT
        p.id_periodo,
        CASE
          WHEN p.nombre_periodo LIKE '%-T1' THEN CONCAT('Enero-Abril ', LEFT(p.nombre_periodo,4))
          WHEN p.nombre_periodo LIKE '%-T2' THEN CONCAT('Mayo-Agosto ', LEFT(p.nombre_periodo,4))
          WHEN p.nombre_periodo LIKE '%-T3' THEN CONCAT('Septiembre-Diciembre ', LEFT(p.nombre_periodo,4))
        END AS nombre_periodo
      FROM periodos p
      JOIN v_last_estado_periodo lep
        ON lep.id_periodo = p.id_periodo
      WHERE p.nombre_periodo RLIKE '-T[123]$'
      GROUP BY p.id_periodo, p.nombre_periodo
      ORDER BY LEFT(p.nombre_periodo,4) DESC, 
               CASE 
                 WHEN p.nombre_periodo LIKE '%-T1' THEN 1
                 WHEN p.nombre_periodo LIKE '%-T2' THEN 2
                 WHEN p.nombre_periodo LIKE '%-T3' THEN 3
               END DESC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
