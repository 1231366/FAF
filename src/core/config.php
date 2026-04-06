<?php
// Define constantes para não andares a escrever "localhost" em todo o lado
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'faf_running');

// Chaves que estavam expostas (Usa variáveis de ambiente no futuro)
define('GROQ_KEY', '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');
define('GOOGLE_ID', '35388883787-pco59ltnsthb73c1ho8o4iqafoir9cfu.apps.googleusercontent.com');
define('GOOGLE_SECRET', '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');

// Conexão Única
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die(json_encode(["error" => "Falha na ligação à DB"]));
}
$conn->set_charset("utf8mb4");