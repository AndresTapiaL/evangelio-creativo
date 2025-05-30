<?php
/*  Devuelve los 3 períodos más recientes (sin histórico) */
require_once 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $rows = $pdo->query(
        "SELECT id_periodo, nombre_periodo
           FROM periodos
          WHERE es_historico = 0
          ORDER BY fecha_termino DESC
          LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true, 'data'=>$rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
