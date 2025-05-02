<?php
require 'conexion.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT id_descripcion_telefono, nombre_descripcion_telefono FROM descripcion_telefonos ORDER BY nombre_descripcion_telefono ASC");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>