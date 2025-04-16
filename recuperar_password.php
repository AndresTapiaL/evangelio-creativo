<?php
require 'conexion.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    $correo = $_POST['correo'];
    $stmt = $pdo->prepare("
        SELECT u.id_usuario, u.nombres
        FROM usuarios u
        JOIN correos_electronicos c ON u.id_usuario = c.id_usuario
        WHERE c.correo_electronico = :correo
    ");
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario) {
        $clave_temporal = substr(bin2hex(random_bytes(3)), 0, 6);
        $hash = password_hash($clave_temporal, PASSWORD_DEFAULT);

        $update = $pdo->prepare("UPDATE usuarios SET password = :hash WHERE id_usuario = :id");
        $update->execute(['hash' => $hash, 'id' => $usuario['id_usuario']]);

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
                <h2>Hola ' . htmlspecialchars($usuario['nombres']) . ',</h2>
                <p>Has solicitado recuperar tu contraseña.</p>
                <p>Tu clave temporal es:</p>
                <h3 style="color: #d90429;">' . $clave_temporal . '</h3>
                <p>Por favor, inicia sesión y cámbiala lo antes posible.</p>
                <br><small>Este mensaje fue generado automáticamente.</small>
            ';

            $mail->send();
            mostrarMensaje('Correo enviado con éxito. Revisa tu bandeja de entrada.');
        } catch (Exception $e) {
            mostrarMensaje('Error al enviar correo: ' . $mail->ErrorInfo, 'error');
        }
    } else {
        mostrarMensaje('Correo no encontrado.', 'error');
    }
}
?>