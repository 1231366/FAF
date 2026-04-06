<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../core/config.php';

// Ativar reporte de erros para debugging total
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

        // Cálculo automático do ciclo (Semanas até à prova)
        $today = new DateTime();
        $target = new DateTime($race_date);
        $interval = $today->diff($target);
        $weeks = ceil($interval->days / 7);
        if($weeks < 4) $weeks = 4; // Mínimo de 4 semanas de preparação

        // Query para inserir ou atualizar o perfil do atleta
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
        
        // Tipos de dados: i = integer, s = string, d = double
        $types = "iiiisissidss"; 
        
        $stmt->bind_param($types, 
            $uid, $weight, $height, $age, $level, $dist, 
            $race_date, $t_pace, $r_dist, $r_pace, $days, $weeks
        );
        
        if ($stmt->execute()) {
            // Atualiza o status do utilizador para indicar que o diagnóstico foi concluído
            $conn->query("UPDATE users SET diagnostic_completed = 1 WHERE id = $uid");
            
            $_SESSION['plan_generated_now'] = true;
            
            // AJUSTADO: Redireciona para o plano na pasta public
            header("Location: ../../public/plan.php");
            exit();
        }

    } catch (Exception $e) {
        die("Erro Crítico no Diagnóstico: " . $e->getMessage());
    }
} else {
    // AJUSTADO: Se a sessão expirou, volta para o login
    header("Location: ../../public/login.php");
    exit();
}