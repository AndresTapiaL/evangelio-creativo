<?php
ini_set('display_errors',        0);
ini_set('display_startup_errors',0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
/* -------------------------------------------------------------
   Actualiza datos + lista de ocupaciones (muchos-a-muchos)
------------------------------------------------------------- */
// Requiere los archivos de PHPMailer desde tu carpeta phpmailer/
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'conexion.php';

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

  /* —— correo principal + verificación —— */
  if( ($newEmail = $f('correo')) !== null ){
    $bol = isset($_POST['boletin']) ? 1 : 0;

    // ——— Punto A: detectar cambio de correo ———
    $stmtOld = $pdo->prepare("
      SELECT correo_electronico
        FROM correos_electronicos
       WHERE id_usuario = ?
    ");
    $stmtOld->execute([$id]);
    $oldEmail = $stmtOld->fetchColumn();

    if( $newEmail !== $oldEmail ){
      // 1) Generar token y expiración
      $verifyToken = bin2hex(random_bytes(32));
      $expiresAt   = date('Y-m-d H:i:s', strtotime('+1 day'));

      // 2) Actualizar el registro, reseteando verified a 0
      $upd = $pdo->prepare("
        UPDATE correos_electronicos
           SET correo_electronico = :email,
               boletin          = :bol,
               verified         = 0,
               verify_token     = :tok,
               token_expires    = :exp
         WHERE id_usuario = :id
      ");
      $upd->execute([
        ':email' => $newEmail,
        ':bol'   => $bol,
        ':tok'   => $verifyToken,
        ':exp'   => $expiresAt,
        ':id'    => $id
      ]);

      // ——— Enviar correo de verificación con PHPMailer ———

      try {
          $mail = new PHPMailer(true);
          // 1) Configuración SMTP (ajusta host/credenciales)
          $mail->isSMTP();
          $mail->Host       = 'smtp.gmail.com';      // p.ej. smtp.gmail.com
          $mail->SMTPAuth   = true;
          $mail->Username   = 'actividades.evangeliocreativo@gmail.com';
          $mail->Password   = 'vwsgpbigmyqaknpc';
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
          $mail->Port       = 587;

          // 2) Remitente y destinatario
          $mail->setFrom('actividades.evangeliocreativo@gmail.com', 'Evangelio Creativo');
          $mail->addAddress($newEmail);

          // 3) Contenido del mensaje
          $mail->isHTML(false);
          $mail->Subject = 'Verifica tu correo';
          $mail->Body    = "Hola,\n\nPor favor confirma tu correo haciendo clic en el enlace:\n\n"
                        . "http://localhost/PW%20EC_Antes/verify.php?token=$verifyToken\n\n"
                        . "Este enlace expirará en 24 horas.";

          // 4) Enviar y registrar resultado
          $mail->send();
          error_log("PHPMailer: enviado correo de verificación a $newEmail");
      } catch (Exception $e) {
          error_log("PHPMailer Error: " . $mail->ErrorInfo);
      }

    }
    else {
      // ——— si no cambió, hace el mismo upsert que ya tenías ———
      $pdo->prepare("
        INSERT INTO correos_electronicos(correo_electronico,id_usuario,boletin)
        VALUES(?,?,?)
        ON DUPLICATE KEY UPDATE
          correo_electronico = VALUES(correo_electronico),
          boletin            = VALUES(boletin)
      ")->execute([$newEmail, $id, $bol]);
    }
  }

  echo json_encode(['mensaje'=>'Actualizado']);
}catch(Exception $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
