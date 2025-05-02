<?php
/* -------------------------------------------------------------
   Actualiza datos + lista de ocupaciones (muchos-a-muchos)
------------------------------------------------------------- */
require 'conexion.php';
header('Content-Type: application/json');

try{
  $tok = $_POST['token'] ?? '';
  if(!$tok) throw new Exception('Token faltante');

  $id = $pdo->prepare("
      SELECT id_usuario
        FROM tokens_usuarios
       WHERE token = ? AND expira_en > NOW()");
  $id->execute([$tok]); $id=$id->fetchColumn();
  if(!$id) throw new Exception('Token inválido');

  /* —— actualizar tabla usuarios —— */
  $f = fn($k)=>($_POST[$k]??'')!=''?$_POST[$k]:null;
  $pdo->prepare("
    UPDATE usuarios SET
      fecha_nacimiento           = :fn,
      rut_dni                    = :rut,
      id_pais                    = :pais,
      id_region_estado           = :reg,
      id_ciudad_comuna           = :ciu,
      direccion                  = :dir,
      iglesia_ministerio         = :igl,
      profesion_oficio_estudio   = :prof,
      ultima_actualizacion       = NOW()
    WHERE id_usuario = :id
  ")->execute([
      ':fn'=>$f('fecha_nacimiento'),
      ':rut'=>$f('rut_dni'),
      ':pais'=>$f('id_pais'),
      ':reg'=>$f('id_region_estado'),
      ':ciu'=>$f('id_ciudad_comuna'),
      ':dir'=>$f('direccion'),
      ':igl'=>$f('iglesia_ministerio'),
      ':prof'=>$f('profesion_oficio_estudio'),
      ':id'=>$id
  ]);

  /* —— ocupaciones múltiples —— */
  $pdo->prepare("DELETE FROM usuarios_ocupaciones WHERE id_usuario=?")->execute([$id]);
  if(isset($_POST['id_ocupacion']) && is_array($_POST['id_ocupacion'])){
    $ins = $pdo->prepare("INSERT INTO usuarios_ocupaciones(id_usuario,id_ocupacion) VALUES(?,?)");
    foreach($_POST['id_ocupacion'] as $oc)
      if($oc!=='') $ins->execute([$id,$oc]);
  }

  /* —— correo principal —— */
  if(($correo=$f('correo'))!==null){
    $bol = isset($_POST['boletin'])?1:0;
    $pdo->prepare("
      INSERT INTO correos_electronicos(correo_electronico,id_usuario,boletin)
      VALUES(?,?,?)
      ON DUPLICATE KEY UPDATE
        correo_electronico = VALUES(correo_electronico),
        boletin            = VALUES(boletin)
    ")->execute([$correo,$id,$bol]);
  }

  echo json_encode(['mensaje'=>'Actualizado']);
}catch(Exception $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
