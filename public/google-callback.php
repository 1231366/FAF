<?php
// 1. Diagnóstico de Erros (Ativo para detetar falhas no XAMPP)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../src/core/config.php';

// AJUSTADO: O vendor está na raiz, logo subimos um nível a partir de 'public/'
require_once __DIR__ . '/../vendor/autoload.php';

$client = new Google_Client();
// AJUSTADO: Utiliza as constantes definidas no src/core/config.php
$client->setClientId(GOOGLE_ID);
$client->setClientSecret(GOOGLE_SECRET);

// AJUSTADO: URL de redirecionamento agora inclui a pasta 'public/' para coincidir com a estrutura
$client->setRedirectUri('http://localhost/FAF/public/google-callback.php');

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

    // 4. Verificar se o utilizador já existe (Usa $conn vindo do config.php)
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
        
        // Redirecionamento inteligente baseado no diagnóstico
        if ($user['diagnostic_completed'] == 1) {
            header("Location: plan.php");
        } else {
            header("Location: methricsdiagonostic.php");
        }
    } else {
        /**
         * UTILIZADOR NOVO: Criar conta com dados do Google
         */
        $stmt = $conn->prepare("INSERT INTO users (name, email, profile_pic, google_id, diagnostic_completed) VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param("ssss", $name, $email, $picture, $google_id);
        
        if ($stmt->execute()) {
            $_SESSION['user_id']   = $conn->insert_id;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_pic']  = $picture; // Foto para a UI
            
            header("Location: methricsdiagonostic.php");
        } else {
            header("Location: login.php?error=database_error");
        }
    }
    exit();
} else {
    header("Location: login.php");
    exit();
}
?>