<?php
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Encriptar a password para segurança (Obrigatório para o password_verify funcionar depois)
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Preparar a query para evitar SQL Injection
    $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $email, $password_hash);

    if ($stmt->execute()) {
        // Sucesso: Volta para o login com mensagem de sucesso
        header("Location: login.php?success=1");
    } else {
        // Erro (ex: email já existe)
        header("Location: login.php?error=exists");
    }
    $stmt->close();
    $conn->close();
}
?>