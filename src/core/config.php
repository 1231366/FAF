<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações da Base de Dados
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'faf_running');

// Chaves de API
define('GROQ_KEY', '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');
define('GOOGLE_ID', '35388883787-pco59ltnsthb73c1ho8o4iqafoir9cfu.apps.googleusercontent.com');
define('GOOGLE_SECRET', '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');

// Conexão Única
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(["error" => "Falha na ligação à base de dados: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Helper para caminhos (opcional, mas ajuda)
define('BASE_PATH', __DIR__ . '/../../');
?>