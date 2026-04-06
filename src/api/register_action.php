<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e gestão de erros
require_once __DIR__ . '/../core/config.php';

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
        // AJUSTADO: Sucesso - Volta para o login dentro da pasta public
        header("Location: ../../public/login.php?success=1");
    } else {
        // AJUSTADO: Erro (ex: email já existe) - Volta para o login dentro da pasta public
        header("Location: ../../public/login.php?error=exists");
    }
    
    $stmt->close();
    $conn->close();
    exit();
}
?>