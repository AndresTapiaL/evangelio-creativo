<?php
require 'conexion.php';
header('Content-Type: application/json');

if (!isset($_GET['token'])) {
    echo json_encode(['error' => 'Token no proporcionado']);
    exit;
}

$token = $_GET['token'];

$stmt = $pdo->prepare("SELECT u.id_usuario, u.nombres, u.foto_perfil
                       FROM tokens_usuarios t
                       JOIN usuarios u ON t.id_usuario = u.id_usuario
                       WHERE t.token = :token AND t.expira_en > NOW()");
$stmt->execute(['token' => $token]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if ($usuario) {
    // Traer roles y equipos asociados
    $roles_stmt = $pdo->prepare("
        SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
        FROM integrantes_equipos_proyectos iep
        JOIN roles r ON iep.id_rol = r.id_rol
        JOIN equipos_proyectos ep ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
        WHERE iep.id_usuario = :id_usuario
    ");
    $roles_stmt->execute(['id_usuario' => $usuario['id_usuario']]);
    $roles_equipos = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'id_usuario' => $usuario['id_usuario'],
        'nombres' => $usuario['nombres'],
        'foto_perfil' => $usuario['foto_perfil'],
        'roles_equipos' => $roles_equipos
    ]);
} else {
    echo json_encode(['error' => 'Token inválido o expirado']);
}
?>