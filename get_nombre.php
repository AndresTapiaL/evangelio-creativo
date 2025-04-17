<?php
require 'conexion.php';
header('Content-Type: application/json');

$tabla = $_GET['tabla'] ?? '';
$columna = $_GET['columna'] ?? '';
$id = $_GET['id'] ?? '';

$permitidas = [
  'paises' => 'nombre_pais',
  'region_estado' => 'nombre_region_estado',
  'ciudad_comuna' => 'nombre_ciudad_comuna',
  'ocupaciones' => 'nombre'
];

if (!isset($permitidas[$tabla]) || !$columna || !$id) {
  echo json_encode(['nombre' => '']);
  exit;
}

$campo_nombre = $permitidas[$tabla];

$stmt = $pdo->prepare("SELECT $campo_nombre AS nombre FROM $tabla WHERE $columna = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$nombre = $stmt->fetchColumn();

echo json_encode(['nombre' => $nombre ?: '']);
?>
