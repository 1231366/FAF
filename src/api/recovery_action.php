<?php
// src/api/recovery_action.php — pedido de reset de password.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../public/login.php");
    exit();
}

$email = trim($_POST['email'] ?? '');

$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Nunca revelar se o email existe ou não (evita enumeração de contas).
if (!$user) {
    header("Location: ../../public/login.php?recovery=sent");
    exit();
}

$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

$stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $user['id'], $token, $expires);
$stmt->execute();

$reset_link = APP_URL . "/public/reset-password.php?token=" . $token;

$sent = false;
if (!empty(MAIL_HOST)) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->Port = MAIL_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USER;
        $mail->Password = MAIL_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->setFrom(MAIL_FROM, 'FAF');
        $mail->addAddress($email, $user['name']);
        $mail->Subject = 'FAF — Recuperar acesso';
        $mail->Body = "Olá {$user['name']},\n\nPediste para recuperar o acesso à tua conta FAF.\n\nClica no link abaixo para definir uma nova password (válido 1 hora):\n{$reset_link}\n\nSe não foste tu, ignora este email.";
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        $sent = false;
    }
}

// Sem SMTP configurado (ou envio falhou) em ambiente de debug: mostra o link
// diretamente, para o fluxo continuar testável sem depender de email real.
if (!$sent && APP_DEBUG) {
    header("Location: ../../public/login.php?recovery=debug&link=" . urlencode($reset_link));
    exit();
}

header("Location: ../../public/login.php?recovery=sent");
