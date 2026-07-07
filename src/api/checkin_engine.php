<?php
// Impede que avisos do PHP estraguem o JSON em caso de erro inesperado
error_reporting(0); 
ini_set('display_errors', 0);

// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../core/config.php';

// Define o cabeçalho como JSON logo no início para o AJAX
header('Content-Type: application/json');

try {
    // Verificação de sessão via config.php
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

    // 1. Atualizar o treino na tabela training_plans
    $stmt = $conn->prepare("UPDATE training_plans SET status = ?, real_distance = ?, real_pace = ?, effort_level = ?, completed_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sdssii", $status, $dist, $pace, $effort, $workout_id, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Erro ao atualizar base de dados');
    }

    // 2. Lógica de Tendência Neural (Ajuste adaptativo do FAF)
    if ($status == 'completed' && $effort) {
        $stmt_t = $conn->prepare("SELECT neural_trend FROM user_profiles WHERE user_id = ?");
        $stmt_t->bind_param("i", $user_id);
        $stmt_t->execute();
        $profile = $stmt_t->get_result()->fetch_assoc();
        $trend = (int)($profile['neural_trend'] ?? 0);

        // Se o esforço for 'easy' (fácil), a tendência sobe; se for 'hard' (difícil), desce
        if ($effort == 'easy') $trend++;
        elseif ($effort == 'hard') $trend--;
        else $trend = 0;

        // Se houver 2 treinos seguidos fora do esperado, o motor recalcula os paces
        if (abs($trend) >= 2) {
            // AJUSTADO: Caminho para o kernel_engine em /src/engines/
            require_once __DIR__ . '/../engines/kernel_engine.php';

            // Fator de correção: 0.95 (mais rápido) ou 1.05 (mais lento)
            $factor = ($trend >= 2) ? 0.95 : 1.05;
            if (function_exists('recalculateFutureWeeks')) {
                recalculateFutureWeeks($user_id, $factor);
            }
            $trend = 0; // Reset após adaptação
        }
        $stmt_u = $conn->prepare("UPDATE user_profiles SET neural_trend = ? WHERE user_id = ?");
        $stmt_u->bind_param("ii", $trend, $user_id);
        $stmt_u->execute();
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();