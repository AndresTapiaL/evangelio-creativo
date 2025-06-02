<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try{
    /* Último año “no histórico” presente en la tabla */
    $y = (int)$pdo->query("SELECT MAX(YEAR(fecha_inicio)) FROM periodos
                            WHERE es_historico = 0")
                  ->fetchColumn();

    /* crea el periodo anual del año en curso si no existe */
    $yy = date('Y');
    $pdo->exec("
    INSERT IGNORE INTO periodos (nombre_periodo, fecha_inicio, fecha_termino)
    VALUES ('$yy-Anual', '$yy-01-01', '$yy-12-31')
    ");

    /* Botones: T1,T2,T3, Anual y el id del período Histórico */
    $sql = "
    SELECT id_periodo,
        CASE
            WHEN nombre_periodo LIKE '%-T1'   THEN CONCAT('Enero-Abril ', LEFT(nombre_periodo,4))
            WHEN nombre_periodo LIKE '%-T2'   THEN CONCAT('Mayo-Agosto ', LEFT(nombre_periodo,4))
            WHEN nombre_periodo LIKE '%-T3'   THEN CONCAT('Sept-Dic ',    LEFT(nombre_periodo,4))
            WHEN nombre_periodo LIKE '%-Anual' THEN CONCAT('Anual ',      LEFT(nombre_periodo,4))
            WHEN es_historico = 1             THEN 'Histórico'
            ELSE nombre_periodo
        END AS nombre_periodo
    FROM (
        /* ─── 3 últimos cuatrimestres ─── */
        ( SELECT id_periodo, nombre_periodo, fecha_termino, es_historico
            FROM periodos
            WHERE es_historico = 0
            AND nombre_periodo RLIKE '-T[123]$'
            ORDER BY fecha_termino DESC
            LIMIT 3 )

        UNION ALL

        /* ─── Anual del año actual ─── */
        ( SELECT id_periodo, nombre_periodo, fecha_termino, es_historico
            FROM periodos
            WHERE nombre_periodo = CONCAT(YEAR(CURDATE()),'-Anual') )

        UNION ALL

        /* ─── Histórico ─── */
        ( SELECT id_periodo, nombre_periodo, fecha_termino, es_historico
            FROM periodos
            WHERE es_historico = 1 )
    ) AS p
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'data'=>$rows]);

}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
