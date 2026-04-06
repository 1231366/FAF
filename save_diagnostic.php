<?php
session_start();
require_once 'db.php';

// Ativar reporte de erros para saberes exatamente o que falha se der erro de novo
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    try {
        $uid = $_SESSION['user_id'];
        
        // Captura e sanitização básica
        $weight    = (int)$_POST['weight'];
        $height    = (int)$_POST['height'];
        $age       = (int)$_POST['age'];
        $level     = $_POST['volume_atual'] ?? 'Zero'; 
        $dist      = (int)$_POST['target_dist'];
        $race_date = $_POST['race_date'];
        $t_pace    = $_POST['target_pace'];
        $r_dist    = (float)$_POST['current_pb_dist'];
        $r_pace    = $_POST['current_pb_pace'];
        $days      = $_POST['weekly_days'];

        // Cálculo automático do ciclo
        $today = new DateTime();
        $target = new DateTime($race_date);
        $interval = $today->diff($target);
        $weeks = ceil($interval->days / 7);
        if($weeks < 4) $weeks = 4; 

        // Query corrigida: Removi o user_id do UPDATE (não faz sentido atualizar a FK)
        $query = "INSERT INTO user_profiles (user_id, weight, height, age, fitness_level, target_distance, race_date, target_pace, ref_dist, ref_pace, available_days, prep_cycle) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                  ON DUPLICATE KEY UPDATE 
                    weight = VALUES(weight), 
                    height = VALUES(height), 
                    age = VALUES(age), 
                    fitness_level = VALUES(fitness_level), 
                    target_distance = VALUES(target_distance), 
                    race_date = VALUES(race_date), 
                    target_pace = VALUES(target_pace), 
                    ref_dist = VALUES(ref_dist), 
                    ref_pace = VALUES(ref_pace), 
                    available_days = VALUES(available_days), 
                    prep_cycle = VALUES(prep_cycle)";
        
        $stmt = $conn->prepare($query);
        
        // Agora só precisamos de fazer bind das 12 variáveis uma única vez!
        // s = string, i = integer, d = double (float)
        // Ordem: uid(i), weight(i), height(i), age(i), level(s), dist(i), race_date(s), t_pace(s), r_dist(d), r_pace(s), days(s), weeks(i)
        $types = "iiiisissidss"; 
        
        $stmt->bind_param($types, 
            $uid, 
            $weight, 
            $height, 
            $age, 
            $level, 
            $dist, 
            $race_date, 
            $t_pace, 
            $r_dist, 
            $r_pace, 
            $days, 
            $weeks
        );
        
        if ($stmt->execute()) {
            // Atualiza o status do usuário
            $conn->query("UPDATE users SET diagnostic_completed = 1 WHERE id = $uid");
            
            $_SESSION['plan_generated_now'] = true;
            header("Location: plan.php");
            exit();
        }

    } catch (Exception $e) {
        // Se houver erro, ele mostra-te aqui em vez de dar Erro 500
        die("Erro Crítico no Diagnóstico: " . $e->getMessage());
    }
} else {
    die("Acesso inválido ou sessão expirada.");
}