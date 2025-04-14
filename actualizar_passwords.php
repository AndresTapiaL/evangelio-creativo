<?php
require 'conexion.php';

$usuarios = [
    ['correo' => 'juan.perez@example.com', 'clave' => 'hash123'],
    ['correo' => 'maria.lopez@example.com', 'clave' => 'hash234'],
    ['correo' => 'carlos.ramirez@example.com', 'clave' => 'hash345'],
    ['correo' => 'ana.hernandez@example.com', 'clave' => 'hash456'],
    ['correo' => 'pedro.martinez@example.com', 'clave' => 'hash567'],
];

foreach ($usuarios as $u) {
    $hash = password_hash($u['clave'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        UPDATE usuarios u 
        JOIN correos_electronicos c ON u.id_usuario = c.id_usuario
        SET u.password_hash = :hash
        WHERE c.correo_electronico = :correo
    ");
    $stmt->execute(['hash' => $hash, 'correo' => $u['correo']]);
}

echo "Contraseñas actualizadas correctamente.";
?>
