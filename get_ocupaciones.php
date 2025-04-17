<?php
require 'conexion.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT id_ocupacion, nombre_ocupacion FROM ocupaciones ORDER BY nombre_ocupacion ASC");
$ocupaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($ocupaciones);
?>
