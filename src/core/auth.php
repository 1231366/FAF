<?php
session_start();
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, name, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
    if (password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        
        // Verifica se já fez o diagnóstico
        if ($user['diagnostic_completed'] == 1) {
            header("Location: plan.php");
        } else {
            header("Location: methricsdiagonostic.php");
        }
        exit();
    }
}
    header("Location: login.php?error=invalid");
}
?>