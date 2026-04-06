<?php
// 1. Diagnóstico de Erros (Ativo para detetar falhas no XAMPP)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'vendor/autoload.php';
require_once 'db.php';

$client = new Google_Client();
// Configurações Oficiais (FAF em maiúsculas conforme o teu diretório no Mac)
$client->setClientId('35388883787-pco59ltnsthb73c1ho8o4iqafoir9cfu.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('http://localhost/FAF/google-callback.php');

if (isset($_GET['code'])) {
    // 2. Trocar o código pelo Token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    // VALIDAÇÃO: Impede o Fatal Error se o token expirar ou for reutilizado
    if (isset($token['error'])) {
        header("Location: login.php?error=" . urlencode($token['error_description']));
        exit();
    }

    $client->setAccessToken($token);

    // 3. Obter informações reais do Google
    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    
    $email     = $google_account_info->email;
    $name      = $google_account_info->name;
    $picture   = $google_account_info->picture; // URL da Foto de Perfil
    $google_id = $google_account_info->id;

    // 4. Verificar se o utilizador já existe
    $stmt = $conn->prepare("SELECT id, diagnostic_completed FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        /**
         * UTILIZADOR EXISTE: Atualiza Foto e Nome sempre que faz login
         */
        $update = $conn->prepare("UPDATE users SET name = ?, profile_pic = ?, google_id = ? WHERE id = ?");
        $update->bind_param("sssi", $name, $picture, $google_id, $user['id']);
        $update->execute();

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $name;
        $_SESSION['user_pic']  = $picture; // Foto para a UI
        
        // Redirecionamento inteligente
        if ($user['diagnostic_completed'] == 1) {
            header("Location: plan.php");
        } else {
            header("Location: methricsdiagonostic.php");
        }
    } else {
        /**
         * UTILIZADOR NOVO: Criar conta com dados do Google
         */
        $stmt = $conn->prepare("INSERT INTO users (name, email, profile_pic, google_id, password, diagnostic_completed) VALUES (?, ?, ?, ?, NULL, 0)");
        $stmt->bind_param("ssss", $name, $email, $picture, $google_id);
        
        if ($stmt->execute()) {
            $_SESSION['user_id']   = $conn->insert_id;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_pic']  = $picture; // Foto para a UI
            
            header("Location: methricsdiagonostic.php");
        } else {
            // Se chegar aqui, a DB rejeitou o registo (provavelmente falta das colunas)
            header("Location: login.php?error=database_error_check_columns");
        }
    }
    exit();
} else {
    header("Location: login.php");
    exit();
}
?>