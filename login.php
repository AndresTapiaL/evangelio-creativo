<?php
require 'conexion.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = $_POST['correo'];
    $clave = $_POST['clave'];

    $stmt = $pdo->prepare("
        SELECT u.id_usuario, u.password_hash 
        FROM usuarios u
        JOIN correos_electronicos c ON u.id_usuario = c.id_usuario 
        WHERE c.correo_electronico = :correo
    ");
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($clave, $usuario['password_hash'])) {
        $id_usuario = $usuario['id_usuario'];

        // Obtener todos los roles y equipos del usuario
        $info = $pdo->prepare("
            SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
            FROM integrantes_equipos_proyectos iep
            JOIN roles r ON iep.id_rol = r.id_rol
            JOIN equipos_proyectos ep ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
            WHERE iep.id_usuario = :id_usuario
        ");
        $info->execute(['id_usuario' => $id_usuario]);
        $roles_equipos = $info->fetchAll(PDO::FETCH_ASSOC);

        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $insert = $pdo->prepare("
            INSERT INTO tokens_usuarios (token, id_usuario, expira_en)
            VALUES (:token, :id_usuario, :expira_en)
        ");
        $insert->execute([
            'token' => $token,
            'id_usuario' => $id_usuario,
            'expira_en' => $expira
        ]);

        echo json_encode([
            'mensaje' => '¡Login exitoso!',
            'token' => $token,
            'roles_equipos' => $roles_equipos
        ]);
    } else {
        echo json_encode(['error' => 'Correo o contraseña incorrectos.']);
    }
}
?>