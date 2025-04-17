<?php
require 'conexion.php';
header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!$token) {
  echo json_encode(['error' => 'Token no proporcionado']);
  exit;
}

$stmt = $pdo->prepare("SELECT u.id_usuario, u.nombres, u.apellido_paterno, u.apellido_materno,
  u.foto_perfil, u.fecha_nacimiento, u.rut_dni,
  u.id_pais, u.id_region_estado, u.id_ciudad_comuna,
  u.direccion, u.iglesia_ministerio, u.profesion_oficio_estudio, u.id_ocupacion,
  ce.correo_electronico AS correo, ce.boletin
  FROM tokens_usuarios t
  JOIN usuarios u ON t.id_usuario = u.id_usuario
  JOIN correos_electronicos ce ON ce.id_usuario = u.id_usuario
  WHERE t.token = :token AND t.expira_en > NOW()");
$stmt->execute(['token' => $token]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
  echo json_encode(['error' => 'Token inválido o expirado']);
  exit;
}

$roles = $pdo->prepare("SELECT r.nombre_rol AS rol, ep.nombre_equipo_proyecto AS equipo
  FROM integrantes_equipos_proyectos iep
  JOIN roles r ON r.id_rol = iep.id_rol
  JOIN equipos_proyectos ep ON ep.id_equipo_proyecto = iep.id_equipo_proyecto
  WHERE iep.id_usuario = :id");
$roles->execute(['id' => $usuario['id_usuario']]);
$usuario['roles_equipos'] = $roles->fetchAll(PDO::FETCH_ASSOC);

$telefonos = $pdo->prepare("SELECT telefono AS numero, es_principal, id_descripcion_telefono AS descripcion_id
  FROM telefonos WHERE id_usuario = :id");
$telefonos->execute(['id' => $usuario['id_usuario']]);
$usuario['telefonos'] = $telefonos->fetchAll(PDO::FETCH_ASSOC);

$usuario['actividades'] = [
  ["fecha" => "2024-04-01", "descripcion" => "Asistió a reunión de planificación"],
  ["fecha" => "2024-03-28", "descripcion" => "Marcó asistencia en actividad de voluntariado"],
  ["fecha" => "2024-03-22", "descripcion" => "Actualizó sus datos personales"]
];

echo json_encode($usuario);
?>