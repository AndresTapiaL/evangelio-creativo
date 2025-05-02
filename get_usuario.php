<?php
/* --------------------------------------------------------------
   Devuelve los datos del usuario + TODAS sus ocupaciones
-------------------------------------------------------------- */
require 'conexion.php';
header('Content-Type: application/json');

$tok = $_GET['token'] ?? '';
if (!$tok) { echo json_encode(['error'=>'Token no recibido']); exit; }

/* ——— datos básicos del usuario ——— */
$sql = "
 SELECT u.*
   FROM tokens_usuarios t
   JOIN usuarios       u ON u.id_usuario = t.id_usuario
  WHERE t.token = :tok
    AND t.expira_en > NOW()
  LIMIT 1";
$sth=$pdo->prepare($sql); $sth->execute(['tok'=>$tok]); $u=$sth->fetch(PDO::FETCH_ASSOC);
if(!$u){ echo json_encode(['error'=>'Token inválido']); exit; }

/* ——— ocupaciones múltiples ——— */
$oc = $pdo->prepare("
  SELECT o.id_ocupacion, o.nombre
    FROM usuarios_ocupaciones uo
    JOIN ocupaciones         o ON o.id_ocupacion = uo.id_ocupacion
   WHERE uo.id_usuario = ?
   ORDER BY o.nombre");
$oc->execute([$u['id_usuario']]);
$ocupaciones = $oc->fetchAll(PDO::FETCH_ASSOC);

/* ——— correo principal ——— */
$co = $pdo->prepare("SELECT correo_electronico, boletin
                       FROM correos_electronicos
                      WHERE id_usuario = ? LIMIT 1");
$co->execute([$u['id_usuario']]); $co=$co->fetch(PDO::FETCH_ASSOC);

/* ——— teléfonos ——— */
$te = $pdo->prepare("
  SELECT telefono    AS numero,
         id_descripcion_telefono AS descripcion_id,
         (SELECT nombre_descripcion_telefono
            FROM descripcion_telefonos d
           WHERE d.id_descripcion_telefono = t.id_descripcion_telefono)
           AS descripcion,
         es_principal
    FROM telefonos t
   WHERE id_usuario = ?
ORDER BY es_principal DESC");
$te->execute([$u['id_usuario']]);

/* ——— roles / equipos ——— */
$ro = $pdo->prepare("
  SELECT r.nombre_rol AS rol,
         e.nombre_equipo_proyecto AS equipo
    FROM integrantes_equipos_proyectos iep
    JOIN roles            r ON r.id_rol = iep.id_rol
    JOIN equipos_proyectos e ON e.id_equipo_proyecto = iep.id_equipo_proyecto
   WHERE iep.id_usuario = ?");
$ro->execute([$u['id_usuario']]);

echo json_encode([
  'id_usuario'               => $u['id_usuario'],
  'nombres'                  => $u['nombres'],
  'apellido_paterno'         => $u['apellido_paterno'],
  'apellido_materno'         => $u['apellido_materno'],
  'foto_perfil'              => $u['foto_perfil'],
  'fecha_nacimiento'         => $u['fecha_nacimiento'],
  'rut_dni'                  => $u['rut_dni'],
  'id_pais'                  => $u['id_pais'],
  'id_region_estado'         => $u['id_region_estado'],
  'id_ciudad_comuna'         => $u['id_ciudad_comuna'],
  'direccion'                => $u['direccion'],
  'iglesia_ministerio'       => $u['iglesia_ministerio'],
  'profesion_oficio_estudio' => $u['profesion_oficio_estudio'],
  'fecha_registro'           => $u['fecha_registro'],
  'correo'                   => $co['correo_electronico'] ?? null,
  'boletin'                  => $co['boletin']            ?? 0,
  'ocupaciones'              => $ocupaciones,          /* ← NUEVO */
  'telefonos'                => $te->fetchAll(PDO::FETCH_ASSOC),
  'roles_equipos'            => $ro->fetchAll(PDO::FETCH_ASSOC)
]);
