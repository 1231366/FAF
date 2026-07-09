<?php
// src/core/config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FUNÇÃO MANUAL PARA LER O .ENV
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || !strpos($line, '=')) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim(str_replace(['"', "'"], '', $value));
    }
}

// Carrega o .env (assume que o config.php está em src/core/)
loadEnv(__DIR__ . '/../../.env');

// DATABASE
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'faf_running');

// AI ENGINE (Nota: Usando o nome exato do teu .env)
define('GROQ_KEY', $_ENV['GROQ_API_KEY'] ?? '');

// GOOGLE AUTH (Nota: Usando os nomes exatos do teu .env)
define('GOOGLE_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT', $_ENV['GOOGLE_REDIRECT_URL'] ?? 'http://localhost/FAF/google-callback.php');

// STRAVA AUTH (cria a app em https://www.strava.com/settings/api e põe as chaves no .env)
define('STRAVA_CLIENT_ID', $_ENV['STRAVA_CLIENT_ID'] ?? '');
define('STRAVA_CLIENT_SECRET', $_ENV['STRAVA_CLIENT_SECRET'] ?? '');
define('STRAVA_REDIRECT', $_ENV['STRAVA_REDIRECT_URL'] ?? 'http://localhost/FAF/public/strava-callback.php');

// SMTP (recuperação de password). Se MAIL_HOST estiver vazio, o link de reset
// é devolvido diretamente na resposta em vez de enviado por email — só em DEBUG.
define('MAIL_HOST', $_ENV['MAIL_HOST'] ?? '');
define('MAIL_PORT', $_ENV['MAIL_PORT'] ?? 587);
define('MAIL_USER', $_ENV['MAIL_USER'] ?? '');
define('MAIL_PASS', $_ENV['MAIL_PASS'] ?? '');
define('MAIL_FROM', $_ENV['MAIL_FROM'] ?? 'no-reply@faf.app');
define('APP_URL', rtrim($_ENV['APP_URL'] ?? 'http://localhost/FAF', '/'));
define('APP_DEBUG', ($_ENV['DEBUG'] ?? 'false') === 'true');

// CONEXÃO
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die(json_encode(["error" => "Falha na ligação: " . $conn->connect_error]));
}
$conn->set_charset("utf8mb4");

define('BASE_PATH', __DIR__ . '/../../');
?>