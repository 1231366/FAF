<?php
$host = "localhost";
$user = "root"; // Padrão do XAMPP
$pass = "";     // Padrão do XAMPP
$db   = "faf_running";

$conn = new mysqli($host, $user, $pass, $db);

// Verifica se a conexão falhou
if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}
?>