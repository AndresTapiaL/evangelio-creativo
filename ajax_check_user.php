<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
session_start();
if (empty($_SESSION['id_usuario'])) { http_response_code(401); exit; }
require 'conexion.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$idPais = (int)($data['id_pais'] ?? 0);
$rut    = trim($data['rut'] ?? '');

$u = $pdo->prepare("
      SELECT id_usuario, CONCAT(nombres,' ',apellido_paterno,' ',apellido_materno) AS nom
        FROM usuarios
       WHERE id_pais = ? AND rut_dni = ?
       LIMIT 1");
$u->execute([$idPais,$rut]);
$row = $u->fetch(PDO::FETCH_ASSOC);

echo json_encode($row
        ? ['ok'=>1,'id'=>$row['id_usuario'],'nombre'=>$row['nom']]
        : ['ok'=>0]);
