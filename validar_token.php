<?php
/**
 * validar_token.php  ·  centraliza…  (misma lógica de siempre + heartbeat)
 */
date_default_timezone_set('UTC');
require 'conexion.php';
header('Content-Type: application/json');

// ───── helpers ───────────────────────────────────────────────────────────
function getBearerToken(): ?string {
    $hdr = getallheaders();
    if (isset($hdr['Authorization']) && preg_match('/Bearer\s(\S+)/', $hdr['Authorization'], $m)) {
        return $m[1];
    }
    return $_GET['token'] ?? $_POST['token'] ?? null;
}
function jsonError(string $msg, int $code = 401): never {
    http_response_code($code);
    exit(json_encode(['ok'=>false,'error'=>$msg]));
}

// ───── 0. ¿se trata sólo de un heartbeat? ───────────────────────────────
//    • ?hb=1   ó   cabecera X-Heartbeat: 1   ⇒  respuesta mínima y rápida
$isHeartbeat = isset($_GET['hb']) || ($_SERVER['HTTP_X_HEARTBEAT'] ?? null) === '1';

// ───── 1. token requerido ───────────────────────────────────────────────
$token = getBearerToken();
if (!$token) jsonError('Token requerido');

// ───── 2. purga de tokens vencidos ──────────────────────────────────────
$pdo->prepare("DELETE FROM tokens_usuarios WHERE expira_en < UTC_TIMESTAMP()")
    ->execute();

// ───── 3. lookup ────────────────────────────────────────────────────────
$q = $pdo->prepare("SELECT id_usuario, expira_en FROM tokens_usuarios WHERE token = :t");
$q->execute(['t' => $token]);
$tk = $q->fetch(PDO::FETCH_ASSOC);
if (!$tk) jsonError('Token inválido');

if (strtotime($tk['expira_en']) < time()) {
    $pdo->prepare("DELETE FROM tokens_usuarios WHERE token = :t")->execute(['t'=>$token]);
    jsonError('Token expirado');
}

// ───── 4. sliding-expiration (+1 h) ─────────────────────────────────────
$nuevoVenc = date('Y-m-d H:i:s', time() + 3600);
$pdo->prepare("UPDATE tokens_usuarios SET expira_en = :e WHERE token = :t")
    ->execute(['e' => $nuevoVenc, 't' => $token]);

// ───── 5. respuesta ─────────────────────────────────────────────────────
if ($isHeartbeat) {                              // ★ HEARTBEAT
    echo json_encode(['ok'=>true,'heartbeat'=>true,'expira_en'=>$nuevoVenc]);
    exit;
}

/*  respuesta normal (roles + equipos) — igual que antes  */
$info = $pdo->prepare("
    SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
    FROM integrantes_equipos_proyectos iep
    JOIN roles            r  ON iep.id_rol             = r.id_rol
    JOIN equipos_proyectos ep ON iep.id_equipo_proyecto = ep.id_equipo_proyecto
    WHERE iep.id_usuario = :u
");
$info->execute(['u'=>$tk['id_usuario']]);
$rolesEquipos = $info->fetchAll(PDO::FETCH_ASSOC);

session_start();
$_SESSION['id_usuario'] = $tk['id_usuario'];

echo json_encode([
    'ok'           => true,
    'id_usuario'   => $tk['id_usuario'],
    'expira_en'    => $nuevoVenc,
    'rolesEquipos' => $rolesEquipos
]);
