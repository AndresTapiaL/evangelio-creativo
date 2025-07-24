<?php
require 'conexion.php';
date_default_timezone_set('UTC');
header('Content-Type: application/json');

/* ──────────  LÍMITES (5 intentos / 6 h) ────────── */
const BLOCK_TRIES      = 5;
const BLOCK_WINDOW_SQL = 'INTERVAL 6 HOUR';
const WARN_THRESHOLD   = BLOCK_TRIES - 1;

/**
 * Cuenta intentos en la ventana para un campo concreto.
 */
function countAttempts(PDO $pdo, string $field, string $value): int
{
    if (!in_array($field, ['device_id', 'fp_id'], true)) {
        throw new InvalidArgumentException('Campo no permitido');
    }
    $sql = "
        SELECT COUNT(*) FROM intentos_login
        WHERE $field = :v
          AND intento_at >= DATE_SUB(UTC_TIMESTAMP(), " . BLOCK_WINDOW_SQL . ")
    ";
    $q = $pdo->prepare($sql);
    $q->execute(['v' => $value]);
    return (int)$q->fetchColumn();
}

/**
 * Registra un intento fallido y responde con JSON.
 *  - 4.º fallo del mismo device/fp -> advertencia
 *  - ≥5 fallos device/fp -> bloqueo
 */
function fail(PDO $pdo, string $deviceId, string $fpId, string $ip, string $msg): never
{
    $pdo->prepare("
        INSERT INTO intentos_login (device_id, fp_id, ip)
        VALUES (:d, :fp, :ip)
    ")->execute(['d' => $deviceId, 'fp' => $fpId, 'ip' => $ip]);

    $devTries = countAttempts($pdo, 'device_id', $deviceId);
    $fpTries  = countAttempts($pdo, 'fp_id',     $fpId);

    if ($devTries >= BLOCK_TRIES || $fpTries >= BLOCK_TRIES) {
        exit(json_encode(['error' => 'Dispositivo bloqueado, inténtalo más tarde.']));
    }

    if ($devTries === WARN_THRESHOLD || $fpTries === WARN_THRESHOLD) {
        $msg .= ' — Queda 1 intento antes del bloqueo de 6 h.';
    }

    exit(json_encode(['error' => $msg]));
}

/* === IDENTIFICACIÓN DEL INTENTO ================================ */
$deviceId = $_POST['device'] ?? '';
$fpId     = $_POST['fp']     ?? '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($fpId === '') {  // fallback si el front antiguo no lo envía
    $fpId = substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32);
}
if ($deviceId === '') {
    $deviceId = substr(hash('sha256', $fpId), 0, 36);
}
/* =============================================================== */

/* --- BLOQUEO PREVIO (device/fp) --- */
if (countAttempts($pdo, 'device_id', $deviceId) >= BLOCK_TRIES ||
    countAttempts($pdo, 'fp_id',     $fpId)     >= BLOCK_TRIES) {
    exit(json_encode(['error' => 'Dispositivo bloqueado, inténtalo más tarde.']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $correo = $_POST['correo'] ?? '';
    $clave  = $_POST['clave']  ?? '';

    /* 1. localizar usuario */
    $stmt = $pdo->prepare("
        SELECT  u.id_usuario,
                u.password,
                u.nombres,
                u.foto_perfil
        FROM  correos_electronicos c
        JOIN  usuarios           u ON u.id_usuario = c.id_usuario
        WHERE  c.correo_electronico = :correo
        LIMIT  1
    ");
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        fail($pdo, $deviceId, $fpId, $ip, 'Usuario no válido.');
    }

    $id_usuario = (int)$usuario['id_usuario'];

    /* 2. habilitado / exclusiones */
    $disabledMsg = 'Usuario deshabilitado, por favor contacta a nuestros líderes.';

    $enabled = $pdo->prepare("
        SELECT 1
        FROM integrantes_equipos_proyectos
        WHERE id_usuario = :id
          AND habilitado = 1
        LIMIT 1
    ");
    $enabled->execute(['id' => $id_usuario]);

    $enAdmision  = $pdo->prepare("SELECT 1 FROM admision  WHERE id_usuario = :id LIMIT 1");
    $enRetirados = $pdo->prepare("SELECT 1 FROM retirados WHERE id_usuario = :id LIMIT 1");
    $enAdmision ->execute(['id' => $id_usuario]);
    $enRetirados->execute(['id' => $id_usuario]);

    if (!$enabled->fetchColumn() || $enAdmision->fetchColumn() || $enRetirados->fetchColumn()) {
        fail($pdo, $deviceId, $fpId, $ip, $disabledMsg);
    }

    /* 3. contraseña */
    if (empty($usuario['password']) || !password_verify($clave, $usuario['password'])) {
        fail($pdo, $deviceId, $fpId, $ip, 'Correo o contraseña incorrectos.');
    }

    /* 4. sesión PHP */
    session_start();
    session_regenerate_id(true);
    $_SESSION['id_usuario'] = $id_usuario;

    /* 5. roles + equipos */
    $info = $pdo->prepare("
        SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
        FROM integrantes_equipos_proyectos iep
        JOIN roles            r  ON iep.id_rol             = r.id_rol
        JOIN equipos_proyectos ep ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
        WHERE iep.id_usuario = :id_usuario
    ");
    $info->execute(['id_usuario' => $id_usuario]);
    $roles_equipos = $info->fetchAll(PDO::FETCH_ASSOC);

    /* 6. limpieza tokens caducados */
    $pdo->prepare("
        DELETE FROM tokens_usuarios
        WHERE expira_en < UTC_TIMESTAMP()
    ")->execute();

    /* 7. crear token */
    $token  = bin2hex(random_bytes(32));
    $expira = gmdate('Y-m-d H:i:s', time() + 3600);

    $insert = $pdo->prepare("
        INSERT INTO tokens_usuarios (token, id_usuario, expira_en)
        VALUES (:token, :id_usuario, :expira_en)
    ");
    $insert->execute([
        'token'      => $token,
        'id_usuario' => $id_usuario,
        'expira_en'  => $expira
    ]);

    /* 8. respuesta */
    echo json_encode([
        'mensaje'       => '¡Login exitoso!',
        'token'         => $token,
        'roles_equipos' => $roles_equipos,
        'usuario'       => [
            'nombres'     => $usuario['nombres'],
            'foto_perfil' => $usuario['foto_perfil']
        ]
    ]);
}
?>
