<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e gestão de sessão
require_once __DIR__ . '/config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // AJUSTADO: Adicionada a coluna diagnostic_completed na query para podermos verificar o status
    $stmt = $conn->prepare("SELECT id, name, password, diagnostic_completed FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            
            // Verifica se o atleta já concluiu o diagnóstico neural
            if ($user['diagnostic_completed'] == 1) {
                // AJUSTADO: Caminho para sair de src/core/ e entrar em public/
                header("Location: ../../public/plan.php");
            } else {
                // AJUSTADO: Caminho para o diagnóstico em public/
                header("Location: ../../public/methricsdiagonostic.php");
            }
            exit();
        }
    }
    
    // AJUSTADO: Redirecionamento de erro de volta para o login em public/
    header("Location: ../../public/login.php?error=invalid");
    exit();
}
?>