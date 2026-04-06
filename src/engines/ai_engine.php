<?php
// src/engines/ai_engine.php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/AiEngine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['reply' => 'Sessão expirada. Protocolo de segurança ativo. Re-autentica.']); 
    exit();
}

$user_id = $_SESSION['user_id'];

// 1. DNA DO ATLETA (Biometria)
$query = "SELECT u.name, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?";
$stmt = $conn->prepare($query); 
$stmt->bind_param("i", $user_id); 
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

// 2. HISTÓRICO DE PERFORMANCE (Análise de falhas/sucessos)
// Vamos buscar os últimos 3 treinos para ver se o ritmo está a ser cumprido
$stmt_h = $conn->prepare("SELECT workout_type, distance, real_distance, pace, real_pace, effort_level FROM training_plans WHERE user_id = ? AND status = 'completed' ORDER BY workout_date DESC LIMIT 3");
$stmt_h->bind_param("i", $user_id); 
$stmt_h->execute();
$historico_rows = $stmt_h->get_result()->fetch_all(MYSQLI_ASSOC);

$contexto_historico = "";
foreach($historico_rows as $h) {
    $contexto_historico .= "FEITO: {$h['workout_type']} (Alvo {$h['distance']}k@{$h['pace']} -> REAL: {$h['real_distance']}k@{$h['real_pace']} | Esforço: {$h['effort_level']}). ";
}

// 3. O PLANO FUTURO (Próximos 3 treinos pendentes)
$stmt_f = $conn->prepare("SELECT day_name, workout_type, distance, pace FROM training_plans WHERE user_id = ? AND status = 'pending' ORDER BY workout_date ASC LIMIT 3");
$stmt_f->bind_param("i", $user_id); 
$stmt_f->execute();
$futuro_rows = $stmt_f->get_result()->fetch_all(MYSQLI_ASSOC);

$contexto_futuro = "";
foreach($futuro_rows as $f) {
    $contexto_futuro .= "PRÓXIMO ({$f['day_name']}): {$f['workout_type']} ({$f['distance']}k @ {$f['pace']}). ";
}

// 4. CAPTURA DE INPUT
$input = json_decode(file_get_contents('php://input'), true);
$userMsg = $input['message'] ?? '';

// CHAMADA AO SUPRASUMO (AiEngine)
$reply = AiEngine::ask($userMsg, $userData, $contexto_historico, $contexto_futuro);

echo json_encode(['reply' => $reply]);