<?php
require_once 'db.php';

// --- 1. UTILITÁRIOS DE CONVERSÃO (Blindagem Total) ---
if (!function_exists('paceToSec')) {
    function paceToSec($pace) {
        if (empty($pace) || $pace == '00:00' || $pace == '0') return 360; // Default 6:00
        $p = explode(':', $pace);
        // Se o user inseriu "6", convertemos para 6:00 (360s)
        if (count($p) == 1) {
            $val = (int)$p[0];
            return ($val < 30) ? $val * 60 : $val; // Se for < 30 assume minutos, senão assume segundos
        }
        return (count($p) == 3) ? ($p[0] * 3600) + ($p[1] * 60) + $p[2] : ($p[0] * 60) + $p[1];
    }
}

if (!function_exists('secToPace')) {
    function secToPace($sec) {
        $sec = max(185, $sec); // Proteção: Ninguém treina abaixo de 3:05/km (Bug do Flash)
        return floor($sec / 60) . ":" . str_pad(round($sec % 60), 2, '0', STR_PAD_LEFT);
    }
}

// --- 2. O CÉREBRO: LÓGICA VDOT (Fisiologia Jack Daniels) ---
function calculateVdot($dist_km, $pace_sec) {
    $dist_km = max(0.5, $dist_km);
    $pace_sec = max(185, $pace_sec);
    $t = ($dist_km * $pace_sec) / 60;
    $v = ($dist_km * 1000) / max(1, $t);
    $vo2 = -4.60 + 0.182258 * $v + 0.000104 * pow($v, 2);
    $c = 0.8 + 0.1894393 * exp(-0.01152 * $t) + 0.2989558 * exp(-0.19326 * $t);
    return max(30, $vo2 / $c); 
}

function getPaceByIntensity($vdot, $intensity) {
    $intensities = ['EASY' => 0.62, 'THRESHOLD' => 0.86, 'INTERVAL' => 0.97];
    $vo2 = $intensities[$intensity] * $vdot;
    $v = (sqrt(pow(0.182258, 2) - 4 * 0.000104 * (-4.60 - $vo2)) - 0.182258) / (2 * 0.000104);
    return 60 / (max(1, $v) / 1000);
}

// --- 3. CONFIGURAÇÃO DE SESSÃO ---
$user_id = $_SESSION['user_id'];
$current_week = isset($_GET['week']) ? (int)$_GET['week'] : 1;

$stmt = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

if (!$userData) die("Perfil neural não configurado.");

// Verificar se o plano já existe para evitar re-geração infinita
$stmt = $conn->prepare("SELECT id FROM training_plans WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$plan_exists = ($stmt->get_result()->num_rows > 0);

if (!$plan_exists) {
    // --- 4. PARÂMETROS ---
    $ref_dist = (float)($userData['ref_dist'] ?? 5); 
    $ref_pace = paceToSec($userData['ref_pace'] ?? '25:00'); 
    $vdot = calculateVdot($ref_dist, $ref_pace);
    
    $target_dist = (int)($userData['target_distance'] ?? 10);
    $total_weeks = (int)($userData['prep_cycle'] ?? 12);
    $available_days = explode(',', $userData['available_days']);
    $long_day = end($available_days);
    $fitness_level = $userData['fitness_level'] ?? 'Regular';

    $p_easy = getPaceByIntensity($vdot, 'EASY');
    $p_threshold = getPaceByIntensity($vdot, 'THRESHOLD');
    $p_interval = getPaceByIntensity($vdot, 'INTERVAL');

    // --- 5. CALENDÁRIO ABSOLUTO ---
    $hoje = new DateTime();
    $inicio_semana_1 = clone $hoje;
    if ($hoje->format('N') != 1) { $inicio_semana_1->modify('last Monday'); }
    $inicio_semana_1->setTime(0,0,0);

    $dias_offset = ['Seg'=>0,'Ter'=>1,'Qua'=>2,'Qui'=>3,'Sex'=>4,'Sab'=>5,'Dom'=>6];

    // --- 6. GERAÇÃO DO CICLO ---
    for ($w = 1; $w <= $total_weeks; $w++) {
        $is_taper = ($w > $total_weeks - 2); 
        $is_recovery = ($w % 4 == 0 && !$is_taper);
        
        $vol_factor = 0.5 + (min($w, 10) * 0.05); 
        if ($is_recovery) $vol_factor *= 0.75;
        if ($is_taper) $vol_factor *= 0.6;

        $last_intensity = 'none'; 

        foreach ($available_days as $idx => $pt_day) {
            $current_workout_date = clone $inicio_semana_1;
            $current_workout_date->modify("+".($w-1)." weeks +".$dias_offset[$pt_day]." days");

            if ($w == 1 && $current_workout_date < $hoje) continue;

            $date_str = $current_workout_date->format('Y-m-d');
            $workout = null;

            // --- 7. MOTOR DE DECISÃO TÁTICA ---
            
            // A) LONGÃO
            if ($pt_day == $long_day) {
                $dist = ($target_dist * 0.85) * $vol_factor;
                if ($fitness_level == 'Zero') $dist = min($dist, 11); 
                $workout = [
                    'type' => 'LONGÃO',
                    'dist' => $dist,
                    'pace' => secToPace($p_easy + 20),
                    'desc' => "Endurance: Foco em volume. Ritmo confortável (".secToPace($p_easy + 20)."/km)."
                ];
                $last_intensity = 'hard';
            }
            // B) QUALIDADE (ROTAÇÃO 3 SEMANAS)
            elseif ($idx == 0 && $last_intensity != 'hard') { 
                if ($is_taper) {
                    $workout = [ 
                        'type' => 'AFINAÇÃO', 
                        'dist' => 4 * $vol_factor, 
                        'pace' => secToPace($p_threshold), 
                        'desc' => "Polimento: 2km Easy + 2x 1km ao ritmo de prova (Recup: 2')." 
                    ];
                } else {
                    $rotation = $w % 3;
                    if ($rotation == 1) { // INTERVALADOS
                        $reps = max(4, floor(($target_dist * 0.4 * $vol_factor) / 0.8));
                        $workout = [
                            'type' => 'INTERVALADO',
                            'dist' => ($reps * 0.8) + 2,
                            'pace' => secToPace($p_interval),
                            'desc' => "VO2 MAX: Aquecimento 1km + {$reps}x 800m a ".secToPace($p_interval)."/km (Recup: 90s)."
                        ];
                    } elseif ($rotation == 2) { // TEMPO RUN
                        $tempo_km = round(($target_dist * 0.5) * $vol_factor);
                        $workout = [
                            'type' => 'TEMPO RUN',
                            'dist' => $tempo_km + 2,
                            'pace' => secToPace($p_threshold),
                            'desc' => "LIMIAR: 1km Easy + {$tempo_km}km constantes a ".secToPace($p_threshold)."/km + 1km Easy."
                        ];
                    } else { // FARTLEK
                        $f_min = round(15 * $vol_factor + ($w * 2));
                        $workout = [
                            'type' => 'FARTLEK',
                            'dist' => ($target_dist * 0.55) * $vol_factor,
                            'pace' => 'Variável',
                            'desc' => "JOGO VELOCIDADE: 10' Easy + {$f_min} min de [2' Forte / 2' Lento]. Sem paragens."
                        ];
                    }
                }
                $last_intensity = 'hard';
            }
            // C) REGENERAÇÃO
            else {
                $workout = [
                    'type' => 'RODAGEM EASY',
                    'dist' => ($target_dist * 0.3) * $vol_factor,
                    'pace' => secToPace($p_easy),
                    'desc' => "Recuperação: Corrida leve a ".secToPace($p_easy)."/km para limpar lactato."
                ];
                $last_intensity = 'easy';
            }

            if ($ref_pace > 480) { $workout['desc'] .= " [Galloway: 3' Correr / 1' Caminhar]"; }

            if ($workout) {
                $ins = $conn->prepare("INSERT INTO training_plans (user_id, day_name, workout_date, week_number, workout_type, distance, pace, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param("issisdss", $user_id, $pt_day, $date_str, $w, $workout['type'], $workout['dist'], $workout['pace'], $workout['desc']);
                $ins->execute();
            }
        }
    }
    // Redirecionar para evitar POST repetido e carregar a semana 1
    header("Location: " . $_SERVER['PHP_SELF'] . "?week=1"); 
    exit();
}

// 8. BUSCAR TREINOS DA SEMANA PARA A VIEW
$stmt = $conn->prepare("SELECT * FROM training_plans WHERE user_id = ? AND week_number = ? ORDER BY workout_date ASC");
$stmt->bind_param("ii", $user_id, $current_week);
$stmt->execute();
$res = $stmt->get_result();
$weekly_workouts = [];
while($row = $res->fetch_assoc()) { 
    $weekly_workouts[$row['day_name']] = $row; 
}
?>