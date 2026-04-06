<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $_SESSION['user_id'];
$week = $data['week'];
$new_days = $data['days_order']; // Ex: ['Qua', 'Seg', 'Ter'...]

// 1. Buscar todos os treinos desta semana do user
$stmt = $conn->prepare("SELECT id, day_name FROM training_plans WHERE user_id = ? AND week_number = ?");
$stmt->bind_param("ii", $user_id, $week);
$stmt->execute();
$res = $stmt->get_result();

$workouts = [];
while($row = $res->fetch_assoc()) {
    $workouts[$row['day_name']] = $row['id'];
}

// 2. Mapeamento de nomes de dias para datas reais (baseado na segunda-feira dessa semana)
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
// Se tu arrastaste o treino da Terça para a Quinta, ele assume a data da Quinta.
foreach($new_days as $index => $day_label) {
    // Pegar o mapeamento padrão: O slot X (index) corresponde ao dia Y (Seg, Ter...)
    $original_day_at_position = ['Seg','Ter','Qua','Qui','Sex','Sab','Dom'][$index];
    
    // Se havia um treino no dia que tu moveste
    if(isset($workouts[$day_label])) {
        $workout_id = $workouts[$day_label];
        $new_date = $day_to_date[$original_day_at_position];
        $new_name = $original_day_at_position;

        $upd = $conn->prepare("UPDATE training_plans SET workout_date = ?, day_name = ? WHERE id = ?");
        $upd->bind_param("ssi", $new_date, $new_name, $workout_id);
        $upd->execute();
    }
}

echo json_encode(['success' => true]);