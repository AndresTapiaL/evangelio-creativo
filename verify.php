<?php
require 'conexion.php';

// 1) Captura y valida token
$token = $_GET['token'] ?? '';
if (!$token) {
    $message = 'No se recibió un token de verificación.';
} else {
    // 2) Busca registro y expiración
    $stmt = $pdo->prepare("
      SELECT id_usuario, token_expires
        FROM correos_electronicos
       WHERE verify_token = :tok
    ");
    $stmt->execute(['tok' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $message = 'El enlace de verificación no es válido o ya fue usado.';
    } elseif (new DateTime() > new DateTime($row['token_expires'])) {
        $message = 'Este enlace de verificación ha expirado.';
    } else {
        // 3) Marca como verificado
        $pdo->prepare("
          UPDATE correos_electronicos
             SET verified      = 1,
                 verify_token  = NULL,
                 token_expires = NULL
           WHERE id_usuario = :id
        ")->execute(['id' => $row['id_usuario']]);
        $message = '¡Correo verificado con éxito! Esta pestaña se cerrará en breve.';
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Verificación de Correo</title>
  <style>
    body { 
      display: flex; 
      height: 100vh; 
      align-items: center; 
      justify-content: center; 
      font-family: sans-serif; 
      background: #f9f9f9;
    }
    .box {
      text-align: center;
      background: #fff;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .box h1 { margin-bottom: 1rem; font-size: 1.2rem; }
    .box p { color: #555; }
  </style>
</head>
<body>
  <div class="box">
    <h1><?= htmlspecialchars($message) ?></h1>
    <p>Si no se cierra automáticamente, puedes cerrar esta pestaña.</p>
  </div>
  <script>
    // Cierra la ventana tras 7 segundos
    setTimeout(() => {
      window.open('', '_self').close();
    }, 7000);
  </script>
</body>
</html>
