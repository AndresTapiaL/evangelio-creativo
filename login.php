<?php
require 'conexion.php';
date_default_timezone_set('UTC');          // ── NUEVO: siempre UTC
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $correo = $_POST['correo'];
    $clave  = $_POST['clave'];

    /* ── 1. localizar usuario ─────────────────────────────────────────── */
    $stmt = $pdo->prepare("
        SELECT u.id_usuario, u.password, u.nombres, u.foto_perfil
        FROM usuarios u
        JOIN correos_electronicos c ON u.id_usuario = c.id_usuario 
        WHERE c.correo_electronico = :correo
    ");
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !password_verify($clave, $usuario['password'])) {
        exit(json_encode(['error' => 'Correo o contraseña incorrectos.']));
    }

    $id_usuario = $usuario['id_usuario'];

    session_start();
    session_regenerate_id(true);
    $_SESSION['id_usuario'] = $id_usuario;

    /* ── 2. roles + equipos para el front ─────────────────────────────── */
    $info = $pdo->prepare("
        SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
        FROM integrantes_equipos_proyectos iep
        JOIN roles            r  ON iep.id_rol             = r.id_rol
        JOIN equipos_proyectos ep ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
        WHERE iep.id_usuario = :id_usuario
    ");
    $info->execute(['id_usuario' => $id_usuario]);
    $roles_equipos = $info->fetchAll(PDO::FETCH_ASSOC);

    /* ── 3. limpieza de tokens caducados (house-keeping) ──────────────── */
    $pdo->prepare("
        DELETE FROM tokens_usuarios
        WHERE expira_en < UTC_TIMESTAMP()
    ")->execute();

    /* ── 4. crear token válido por 1 h ────────────────────────────────── */
    $token  = bin2hex(random_bytes(32));
    $expira = gmdate('Y-m-d H:i:s', time() + 3600);   // +1 h, en UTC

    $insert = $pdo->prepare("
        INSERT INTO tokens_usuarios (token, id_usuario, expira_en)
        VALUES (:token, :id_usuario, :expira_en)
    ");
    $insert->execute([
        'token'       => $token,
        'id_usuario'  => $id_usuario,
        'expira_en'   => $expira
    ]);

    /* ── 5. respuesta JSON ────────────────────────────────────────────── */
    echo json_encode([
        'mensaje'       => '¡Login exitoso!',
        'token'         => $token,
        'roles_equipos' => $roles_equipos,
        'usuario'       => [
            'nombres'      => $usuario['nombres'],
            'foto_perfil'  => $usuario['foto_perfil']
        ]
    ]);
}
?>
