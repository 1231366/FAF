<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../core/config.php';

// Define o cabeçalho como JSON para o AJAX
header('Content-Type: application/json');

// Impede que avisos do PHP estraguem o JSON
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Verificação de sessão via config.php
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Sessão expirada');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['days_order'])) {
        throw new Exception('Dados inválidos');
    }

    $user_id = $_SESSION['user_id'];
    $week = (int)($data['week'] ?? 1);
    $new_days = $data['days_order']; // Ex: ['Qua', 'Seg', 'Ter'...]

    // 1. Buscar todos os treinos desta semana do utilizador
    $stmt = $conn->prepare("SELECT id, day_name FROM training_plans WHERE user_id = ? AND week_number = ?");
    $stmt->bind_param("ii", $user_id, $week);
    $stmt->execute();
    $res = $stmt->get_result();

    $workouts = [];
    while($row = $res->fetch_assoc()) {
        $workouts[$row['day_name']] = $row['id'];
    }

    // 2. Mapeamento de nomes de dias para datas reais (baseado na segunda-feira dessa semana)
    // Nota: Esta lógica assume que o plano começa na próxima segunda-feira após o diagnóstico
    $monday = date('Y-m-d', strtotime("next monday +".($week-1)." weeks"));
    $day_to_date = [
        'Seg' => date('Y-m-d', strtotime($monday)),
        'Ter' => date('Y-m-d', strtotime($monday . " +1 day")),
        'Qua' => date('Y-m-d', strtotime($monday . " +2 days")),
        'Qui' => date('Y-m-d', strtotime($monday . " +3 days")),
        'Sex' => date('Y-m-d', strtotime($monday . " +4 days")),
        'Sab' => date('Y-m-d', strtotime($monday . " +5 days")),
        'Dom' => date('Y-m-d', strtotime($monday . " +6 days")),
    ];

    // 3. Atualizar cada treino com o seu "novo dia" mantendo a lógica de datas
    // Se arrastaste o treino da Terça para a Quinta, ele assume a data da Quinta no calendário.
    $ordem_fixa = ['Seg','Ter','Qua','Qui','Sex','Sab','Dom'];
    
    foreach($new_days as $index => $day_label) {
        // Mapeamento: A posição X (index) no ecrã corresponde ao dia real da semana
        if (isset($ordem_fixa[$index])) {
            $original_day_at_position = $ordem_fixa[$index];
            
            // Se havia um treino associado ao rótulo que foi movido
            if(isset($workouts[$day_label])) {
                $workout_id = $workouts[$day_label];
                $new_date = $day_to_date[$original_day_at_position];
                $new_name = $original_day_at_position;

                $upd = $conn->prepare("UPDATE training_plans SET workout_date = ?, day_name = ? WHERE id = ? AND user_id = ?");
                $upd->bind_param("ssii", $new_date, $new_name, $workout_id, $user_id);
                $upd->execute();
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();