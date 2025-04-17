<?php
require 'conexion.php';
header('Content-Type: application/json');

$tipo = $_GET['tipo'] ?? null;
$id = $_GET['id'] ?? null;

switch ($tipo) {
    case 'pais':
        $stmt = $pdo->query("SELECT id_pais AS id, nombre_pais AS nombre FROM paises ORDER BY nombre_pais ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'region':
        if (!$id) {
            echo json_encode([]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id_region_estado AS id, nombre_region_estado AS nombre FROM regiones_estados WHERE id_pais = :id ORDER BY nombre_region_estado ASC");
        $stmt->execute(['id' => $id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'ciudad':
        if (!$id) {
            echo json_encode([]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id_ciudad_comuna AS id, nombre_ciudad_comuna AS nombre FROM ciudades_comunas WHERE id_region_estado = :id ORDER BY nombre_ciudad_comuna ASC");
        $stmt->execute(['id' => $id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    default:
        echo json_encode([]);
}
?>