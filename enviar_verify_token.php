<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

// 1) Capturar Authorization via apache_request_headers
$headers = function_exists('apache_request_headers')
         ? apache_request_headers()
         : $_SERVER;
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $auth, $m)) {
  http_response_code(401);
  echo json_encode(['error'=>'No autorizado']);
  exit;
}
$tok = $m[1];

// 2) Ahora valida $tok contra tokens_usuarios como antes

$stmt = $pdo->prepare("
  SELECT u.id_usuario 
    FROM tokens_usuarios t
    JOIN usuarios u USING(id_usuario)
   WHERE t.token = :tok AND t.expira_en > NOW()
");
$stmt->execute(['tok'=>$tok]);
$id = $stmt->fetchColumn();
if (!$id) {
  http_response_code(401);
  echo json_encode(['error'=>'Token inválido']);
  exit;
}

// 2) Leer registro de correo
$stmt = $pdo->prepare("
  SELECT correo_electronico AS correo,
         verify_token,
         token_expires
    FROM correos_electronicos
   WHERE id_usuario = :id
   LIMIT 1
");
$stmt->execute(['id'=>$id]);
$emailRec = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emailRec) {
  http_response_code(400);
  echo json_encode(['error'=>'No hay correo registrado']);
  exit;
}

// 3) Generar o reutilizar token
$now = new DateTime();
if (empty($emailRec['verify_token']) || $now > new DateTime($emailRec['token_expires'])) {
  $verifyToken = bin2hex(random_bytes(32));
  $expiresAt   = $now->modify('+1 day')->format('Y-m-d H:i:s');
  $upd = $pdo->prepare("
    UPDATE correos_electronicos
       SET verify_token  = :tok,
           token_expires = :exp
     WHERE id_usuario   = :id
  ");
  $upd->execute([
    'tok' => $verifyToken,
    'exp' => $expiresAt,
    'id'  => $id
  ]);
} else {
  $verifyToken = $emailRec['verify_token'];
}

// 4) Enviar email con PHPMailer
require_once __DIR__.'/phpmailer/Exception.php';
require_once __DIR__.'/phpmailer/PHPMailer.php';
require_once __DIR__.'/phpmailer/SMTP.php';

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
try {
  // SMTP config
  $mail->isSMTP();
  $mail->Host       = 'smtp.gmail.com';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'actividades.evangeliocreativo@gmail.com';
  $mail->Password   = 'vwsgpbigmyqaknpc';
  $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = 587;

  $mail->setFrom('actividades.evangeliocreativo@gmail.com', 'Evangelio Creativo');
  $mail->addAddress($emailRec['correo']);

  $mail->isHTML(false);
  $mail->Subject = 'Verifica tu correo';
  $link = "http://localhost/PW%20EC_Antes/verify.php?token=$verifyToken";
  $mail->Body    = "Hola,\n\nPor favor confirma tu correo pulsando este enlace:\n$link\n\nExpira en 24h.";

  $mail->send();
  echo json_encode(['mensaje'=>'Se envió el correo de verificación.']);
} catch (\PHPMailer\PHPMailer\Exception $e) {
  http_response_code(500);
  error_log("PHPMailer Error: ".$mail->ErrorInfo);
  echo json_encode(['error'=>'No se pudo enviar el correo.']);
}
