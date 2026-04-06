<?php
// Impede que avisos do PHP estraguem o JSON
error_reporting(0); 
ini_set('display_errors', 0);

session_start();
require_once 'db.php';

// Define o cabeçalho como JSON logo no início
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Sessão expirada');
    }

    $user_id = $_SESSION['user_id'];
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        throw new Exception('Dados inválidos recebidos');
    }

    $workout_id = (int)$data['id'];
    $status     = $data['status']; // 'completed', 'skipped', 'rescheduled'
    $dist       = !empty($data['dist']) ? (float)$data['dist'] : null;
    $pace       = !empty($data['pace']) ? $data['pace'] : null;
    $effort     = !empty($data['effort']) ? $data['effort'] : null;

    // 1. Atualizar o treino
    $stmt = $conn->prepare("UPDATE training_plans SET status = ?, real_distance = ?, real_pace = ?, effort_level = ?, completed_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sdssii", $status, $dist, $pace, $effort, $workout_id, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Erro ao atualizar base de dados');
    }

    // 2. Lógica de Tendência Neural (Apenas se completou)
    if ($status == 'completed' && $effort) {
        $res = $conn->query("SELECT neural_trend FROM user_profiles WHERE user_id = $user_id");
        $profile = $res->fetch_assoc();
        $trend = (int)($profile['neural_trend'] ?? 0);

        if ($effort == 'easy') $trend++;
        elseif ($effort == 'hard') $trend--;
        else $trend = 0;

        if (abs($trend) >= 2) {
            require_once 'kernel_engine.php';
            $factor = ($trend >= 2) ? 0.95 : 1.05;
            if (function_exists('recalculateFutureWeeks')) {
                recalculateFutureWeeks($user_id, $factor);
            }
            $trend = 0; 
        }
        $conn->query("UPDATE user_profiles SET neural_trend = $trend WHERE user_id = $user_id");
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();