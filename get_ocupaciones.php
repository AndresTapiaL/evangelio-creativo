<?php
require 'conexion.php';
header('Content-Type: application/json');

/*  Devolvemos id + nombre (sin alias)  ### NUEVO */
$rows = $pdo->query("SELECT id_ocupacion, nombre FROM ocupaciones ORDER BY nombre")
            ->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
