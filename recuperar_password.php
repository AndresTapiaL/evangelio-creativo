<?php
require 'conexion.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('UTC'); // ya usas UTC en la DB
header('X-Content-Type-Options: nosniff');

function mostrarMensaje($mensaje, $tipo = 'info') {
    $color = $tipo === 'error' ? '#d90429' : '#28a745';
    echo "<!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <meta http-equiv='refresh' content='5;url=login.html'>
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }
            .mensaje {
                background: white;
                padding: 2rem;
                border-radius: 8px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                text-align: center;
                color: $color;
                max-width: 400px;
            }
            .mensaje h2 {
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body>
        <div class='mensaje'>
            <h2>$mensaje</h2>
            <p>Serás redirigido al login en 5 segundos...</p>
        </div>
    </body>
    </html>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $correo = trim($_POST['correo'] ?? '');
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        mostrarMensaje('Formato de correo inválido.', 'error');
    }

    $pdo->beginTransaction();

    /* 1. Usuario por correo */
    $stmt = $pdo->prepare("
        SELECT u.id_usuario, u.nombres
        FROM usuarios u
        JOIN correos_electronicos c ON u.id_usuario = c.id_usuario
        WHERE c.correo_electronico = :correo
        LIMIT 1
    ");
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $pdo->rollBack();
        mostrarMensaje('Correo no encontrado.', 'error');
    }

    $id_usuario = (int)$usuario['id_usuario'];

    /* 2. ¿Habilitado al menos en un equipo? */
    $chkHab = $pdo->prepare("
        SELECT 1
        FROM integrantes_equipos_proyectos
        WHERE id_usuario = :id AND habilitado = 1
        LIMIT 1
    ");
    $chkHab->execute(['id' => $id_usuario]);

    if (!$chkHab->fetchColumn()) {
        $pdo->rollBack();
        mostrarMensaje('Usuario no habilitado, por favor contacta a nuestros líderes.', 'error');
    }

    /* 3. ¿Ya pidió hoy? (por si NO quieres usar el trigger o para doble seguridad) */
    $chkDay = $pdo->prepare("
        SELECT COUNT(*) 
        FROM password_reset_log
        WHERE id_usuario = :id
        AND DATE(reset_at) = CURDATE()
    ");
    $chkDay->execute(['id' => $id_usuario]);
    if ((int)$chkDay->fetchColumn() >= 1) {
        $pdo->rollBack();
        mostrarMensaje('Error: Ya se envió una contraseña temporal hoy, inténtelo mañana.', 'error');
    }

    /* 4. Generar clave temporal y guardar */
    $clave_temporal = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)); // 8 chars
    $hash = password_hash($clave_temporal, PASSWORD_DEFAULT);

    $upd = $pdo->prepare("UPDATE usuarios SET password = :hash WHERE id_usuario = :id");
    $upd->execute(['hash' => $hash, 'id' => $id_usuario]);

    /* 5. Registrar en log (si ya tienes trigger, igual insertamos: fallará solo si excede) */
    $insLog = $pdo->prepare("
        INSERT INTO password_reset_log (id_usuario, correo_electronico)
        VALUES (:id, :correo)
    ");
    $insLog->execute(['id' => $id_usuario, 'correo' => $correo]);

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'actividades.evangeliocreativo@gmail.com';
        $mail->Password = 'vwsgpbigmyqaknpc';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('actividades.evangeliocreativo@gmail.com', 'Evangelio Creativo');
        $mail->addAddress($correo, $usuario['nombres']);

        $mail->isHTML(true);
        $mail->Subject = 'Recuperación de contraseña - Evangelio Creativo';
        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1.0">
        <style>
        /* Estilos básicos inline-friendly, con media query mínima */
        body{margin:0;background:#f3f4f8;font-family:Poppins,Arial,sans-serif;color:#374151;}
        .wrapper{max-width:520px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;
                box-shadow:0 8px 24px rgba(0,0,0,.08);}
        .bar{height:6px;background:linear-gradient(90deg,#ff7a33 0%,#ff5614 100%);}
        .content{padding:28px 24px;}
        h1{font-size:22px;margin:0 0 12px 0;color:#ff5614;font-weight:600;}
        p{margin:0 0 14px 0;line-height:1.5;font-size:15px;}
        .code{display:inline-block;padding:12px 18px;border-radius:10px;background:#ffe2d5;color:#d90429;
            font-size:20px;font-weight:700;letter-spacing:2px;}
        .small{font-size:12px;color:#6b7280;}
        .logo{display:block;margin:0 auto 18px auto;width:84px;}
        @media (max-width:480px){
        .content{padding:22px 18px;}
        h1{font-size:20px;}
        .code{font-size:18px;}
        }
        </style>
        </head>
        <body>
        <div class="wrapper">
            <div class="bar"></div>
            <div class="content">
            <img class="logo" src="cid:logoec" alt="Logo EC">
            <h1>Recuperación de contraseña</h1>
            <p>Hola <strong>'.htmlspecialchars($usuario['nombres'],ENT_QUOTES,'UTF-8').'</strong>,</p>
            <p>Has solicitado restablecer tu contraseña. Tu clave temporal es:</p>
            <div class="code">'.$clave_temporal.'</div>
            <p>Ingresa con esta clave y cámbiala inmediatamente desde tu perfil.</p>
            <p class="small">Si tú no solicitaste este correo, ignóralo.</p>
            </div>
        </div>
        </body>
        </html>';
        $mail->AltBody = 'Hola '.$usuario['nombres'].",\n\nTu clave temporal es: ".$clave_temporal."\n\nIngresa y cámbiala inmediatamente.\n\nEvangelio Creativo";

        $mail->addEmbeddedImage(__DIR__.'/images/LogoEC.png', 'logoec', 'LogoEC.png');
        $mail->send();
        $pdo->commit();
        mostrarMensaje('Correo enviado con éxito. Revisa tu bandeja de entrada.');

    } catch (Throwable $e) {  // <<< AÑADE ESTE BLOQUE
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // No muestres el detalle del error al usuario final
        mostrarMensaje('Error interno. Inténtalo más tarde.', 'error');
    }
}
?>