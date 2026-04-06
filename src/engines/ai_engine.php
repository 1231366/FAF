<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php'; 

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['reply' => 'Sessão expirada.']); exit();
}

$user_id = $_SESSION['user_id'];

// 1. CONTEXTO BIOMÉTRICO
$query = "SELECT u.name, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?";
$stmt = $conn->prepare($query); $stmt->bind_param("i", $user_id); $stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

// 2. CONTEXTO DE HOJE
$dias_pt = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sab'];
$hoje_nome = $dias_pt[date('D')];
$stmt_t = $conn->prepare("SELECT * FROM training_plans WHERE user_id = ? AND day_name = ? ORDER BY workout_date DESC LIMIT 1");
$stmt_t->bind_param("is", $user_id, $hoje_nome); $stmt_t->execute();
$treino_hoje = $stmt_t->get_result()->fetch_assoc();

$missao = $treino_hoje ? 
    "MISSÃO DE HOJE: " . $treino_hoje['workout_type'] . " (" . $treino_hoje['distance'] . "km @ " . $treino_hoje['pace'] . ")" : 
    "HOJE: Descanso.";

$input = json_decode(file_get_contents('php://input'), true);
$userMsg = $input['message'] ?? '';

$apiKey = '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';
$apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

// SYSTEM PROMPT: O COACH QUE SABE OUVIR
$systemPrompt = "És o FastAsFuckAiCoach. Tu ANALISAS e DIRECIONAS, mas és REATIVO ao utilizador.
ATLETA: {$userData['name']} | OBJETIVO: {$userData['target_distance']}K | PB: {$userData['latest_pb']} | PESO: {$userData['weight']}kg | FUMADOR: " . ($userData['smoker'] ? 'SIM' : 'NÃO') . "
MISSÃO ATUAL: {$missao}

REGRAS DE OURO:
1. NÃO PRESUMAS: Se o user apenas saudar (Boas, Olá, etc), responde de forma curta e pergunta o que ele tem para reportar. Não assumas que ele já treinou.
2. SÓ ANALISA SE HOUVER DADOS: Se ele falar de um treino, ativa o modo analítico. Pede Pace, HR ou Sensação se faltarem.
3. CURTO E HUMANO: Máximo 20-30 palavras. Sem introduções genéricas. 
4. SINCERIDADE BRUTAL: És elite. Se ele vier com desculpas, aperta com ele. Se vier com factos, analisa cientificamente.
5. Responde sempre em PT-PT.";

$data = [
    'model' => 'llama-3.3-70b-versatile', 
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMsg]
    ],
    'temperature' => 0.4, // Baixei ligeiramente para ele ser ainda menos 'inventivo'
    'max_tokens' => 100    
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$apiKey, 'Content-Type: application/json']);

$response = curl_exec($ch);
$resData = json_decode($response, true);
$reply = $resData['choices'][0]['message']['content'] ?? 'Erro neural. Repete.';

echo json_encode(['reply' => $reply]);