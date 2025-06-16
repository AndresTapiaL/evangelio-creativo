<?php
$host = 'localhost';
$db = 'proyectodetituloec';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 15");   // 15 s en vez de 50 s
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
