<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/AiEngine.php';

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

// Zonas fisiológicas Z1-Z5 e categoria por tipo de treino gerado.
if (!function_exists('workoutZone')) {
    function workoutZone($type) {
        $zones = [
            'RODAGEM EASY' => 1, 'GALLOWAY' => 1,
            'LONGÃO' => 2,
            'TEMPO RUN' => 3, 'AFINAÇÃO' => 3, 'FARTLEK' => 3,
            'INTERVALADO' => 4,
        ];
        return $zones[$type] ?? null;
    }
}

if (!function_exists('workoutCategory')) {
    function workoutCategory($type) {
        $cats = [
            'RODAGEM EASY' => 'EASY', 'GALLOWAY' => 'EASY',
            'LONGÃO' => 'LONG',
            'TEMPO RUN' => 'TEMPO', 'AFINAÇÃO' => 'TEMPO',
            'FARTLEK' => 'INTERVAL', 'INTERVALADO' => 'INTERVAL',
        ];
        return $cats[$type] ?? null;
    }
}

// --- ENGINE DE ADAPTAÇÃO: recalcula os paces dos treinos pendentes ---
// Chamado pelo checkin_engine.php quando a tendência de esforço do atleta
// se desvia do esperado 2x seguidas. O fator é limitado a +/-10% por
// correção (regra dos 10%) para nunca chocar o atleta com uma mudança brusca.
if (!function_exists('recalculateFutureWeeks')) {
    function recalculateFutureWeeks($user_id, $adjustment_factor) {
        global $conn;

        $bounded_factor = max(0.90, min(1.10, $adjustment_factor));

        $stmt = $conn->prepare("SELECT id, pace FROM training_plans WHERE user_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $upd = $conn->prepare("UPDATE training_plans SET pace = ? WHERE id = ?");
        foreach ($rows as $row) {
            if (empty($row['pace']) || $row['pace'] === 'Variável') continue;
            $new_pace = secToPace(round(paceToSec($row['pace']) * $bounded_factor));
            $upd->bind_param("si", $new_pace, $row['id']);
            $upd->execute();
        }
    }
}

// --- 3. CONFIGURAÇÃO DE SESSÃO ---
$user_id = $_SESSION['user_id'];
$current_week = isset($_GET['week']) ? (int)$_GET['week'] : 1;

$stmt = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profileData = $stmt->get_result()->fetch_assoc();

if (!$profileData) die("Perfil neural não configurado.");

// Verificar se o plano já existe para evitar re-geração infinita
$stmt = $conn->prepare("SELECT id FROM training_plans WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$plan_exists = ($stmt->get_result()->num_rows > 0);

if (!$plan_exists) {
    // --- 4. PARÂMETROS ---
    $ref_dist = (float)($profileData['ref_dist'] ?? 5);
    $ref_pace = paceToSec($profileData['ref_pace'] ?? '25:00');
    $vdot = calculateVdot($ref_dist, $ref_pace);

    $target_dist = (int)($profileData['target_distance'] ?? 10);
    $total_weeks = (int)($profileData['prep_cycle'] ?? 12);
    $available_days = explode(',', $profileData['available_days']);
    $long_day = end($available_days);
    $fitness_level = $profileData['fitness_level'] ?? 'Regular';

    $p_easy = getPaceByIntensity($vdot, 'EASY');
    $p_threshold = getPaceByIntensity($vdot, 'THRESHOLD');
    $p_interval = getPaceByIntensity($vdot, 'INTERVAL');

    // Pro treina qualidade duas vezes por semana (se tiver dias disponíveis para isso);
    // Zero nunca faz séries clássicas — a "qualidade" dele é o run/walk progressivo.
    $quality_slots = ($fitness_level === 'Pro' && count($available_days) >= 4) ? [0, 2] : [0];

    // --- 5. CALENDÁRIO ABSOLUTO ---
    $hoje = new DateTime();
    $inicio_semana_1 = clone $hoje;
    if ($hoje->format('N') != 1) { $inicio_semana_1->modify('last Monday'); }
    $inicio_semana_1->setTime(0,0,0);

    $dias_offset = ['Seg'=>0,'Ter'=>1,'Qua'=>2,'Qui'=>3,'Sex'=>4,'Sab'=>5,'Dom'=>6];

    // --- 6. GERAÇÃO DO CICLO (periodização ondulatória 3:1 + taper) ---
    $peak_week_total = null; // maior volume semanal real já atingido, para a regra dos 10% e o taper
    $all_workouts = []; // achatado, para o passe opcional de variedade da IA

    for ($w = 1; $w <= $total_weeks; $w++) {
        $is_taper = ($w > $total_weeks - 2);
        $is_peak = (!$is_taper && $w > $total_weeks - 4);

        // Onda 3:1 — 3 semanas de carga, 1 de deload, com overload progressivo por bloco
        $block_index = intdiv($w - 1, 4);
        $pos_in_block = ($w - 1) % 4;
        $is_deload = (!$is_taper && $pos_in_block == 3);

        $block_base = min(1.0, 0.55 + $block_index * 0.12);
        if ($is_taper) {
            $weeks_to_race = $total_weeks - $w; // 1 na penúltima, 0 na semana da prova
            $vol_factor = ($weeks_to_race <= 0) ? 0.35 : 0.55;
        } elseif ($is_deload) {
            $vol_factor = $block_base * 0.70;
        } else {
            $vol_factor = $block_base + ($pos_in_block * 0.07);
        }

        // Zero: teto de volume mais conservador para reduzir risco de lesão
        if ($fitness_level === 'Zero') $vol_factor = min($vol_factor, 0.75);

        $is_base = (!$is_taper && !$is_peak && $w <= ceil($total_weeks * 0.4));
        $phase = $is_taper ? 'TAPER' : ($is_peak ? 'PEAK' : ($is_base ? 'BASE' : 'BUILD'));

        $last_intensity = 'none';
        $week_workouts = [];

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
            // B) QUALIDADE (ROTAÇÃO 3 SEMANAS, ou GALLOWAY progressivo para Zero)
            elseif (in_array($idx, $quality_slots) && $last_intensity != 'hard') {
                if ($fitness_level === 'Zero' && !$is_taper) {
                    // Run/walk real: a proporção de corrida cresce a cada 2 semanas
                    $run_min = min(8, 1 + intdiv($w, 2));
                    $walk_min = max(1, 3 - intdiv($w, 3));
                    $reps = max(4, round(28 / ($run_min + $walk_min)));
                    $workout = [
                        'type' => 'GALLOWAY',
                        'dist' => (($run_min * $reps) / $p_easy) * 60 * ($vol_factor / 0.75),
                        'pace' => secToPace($p_easy + 30),
                        'desc' => "Run/Walk progressivo: {$reps}x ({$run_min}min correr / {$walk_min}min caminhar)."
                    ];
                } elseif ($is_taper) {
                    $workout = [
                        'type' => 'AFINAÇÃO',
                        'dist' => 4 * $vol_factor,
                        'pace' => secToPace($p_threshold),
                        'desc' => "Polimento: 2km Easy + 2x 1km ao ritmo de prova (Recup: 2')."
                    ];
                } else {
                    $rotation = $w % 3;
                    if ($rotation == 1) { // INTERVALADOS
                        $rep_dist = ($fitness_level === 'Pro') ? 1.0 : 0.8;
                        $reps = max(4, floor(($target_dist * 0.4 * $vol_factor) / $rep_dist));
                        $recup = ($fitness_level === 'Pro') ? 75 : 90;
                        $workout = [
                            'type' => 'INTERVALADO',
                            'dist' => ($reps * $rep_dist) + 2,
                            'pace' => secToPace($p_interval),
                            'desc' => "VO2 MAX: Aquecimento 1km + {$reps}x ".($rep_dist*1000)."m a ".secToPace($p_interval)."/km (Recup: {$recup}s)."
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

            if ($workout) {
                $workout['day'] = $pt_day;
                $workout['date'] = $date_str;
                $workout['week'] = $w;
                $workout['phase'] = $phase;
                $workout['is_deload'] = $is_deload;
                $week_workouts[] = $workout;
            }
        }

        // Regra dos 10%: nunca deixar o volume semanal subir mais de 10% face ao
        // pico real já atingido (não a semana imediatamente anterior — assim uma
        // semana de deload não distorce o ressalto do bloco seguinte). A semana 1
        // é ignorada como pico se ficou parcial (menos dias que o normal, por
        // causa de dias já passados).
        // O taper é sempre uma fração do pico real, nunca da fórmula em bruto —
        // caso contrário podia acabar mais alto que a própria semana de pico.
        $week_total = array_sum(array_column($week_workouts, 'dist'));
        $is_partial_week = ($w == 1 && count($week_workouts) < count($available_days));

        if ($is_taper) {
            if ($peak_week_total > 0 && $week_total > 0) {
                $taper_fraction = ($total_weeks - $w <= 0) ? 0.45 : 0.70;
                $scale = ($peak_week_total * $taper_fraction) / $week_total;
                foreach ($week_workouts as &$ww) { $ww['dist'] *= $scale; }
                unset($ww);
                $week_total = $peak_week_total * $taper_fraction;
            }
        } elseif (!$is_deload && !$is_partial_week && $peak_week_total > 0) {
            $max_allowed = $peak_week_total * 1.10;
            if ($week_total > $max_allowed) {
                $scale = $max_allowed / $week_total;
                foreach ($week_workouts as &$ww) { $ww['dist'] *= $scale; }
                unset($ww);
                $week_total = $max_allowed;
            }
        }

        if (!$is_partial_week && !$is_taper && $week_total > ($peak_week_total ?? 0)) {
            $peak_week_total = $week_total;
        }

        foreach ($week_workouts as $ww) { $all_workouts[] = $ww; }
    }

    // --- 8. PASSE OPCIONAL DA IA: reescreve descrições para variedade/motivação.
    // As distâncias e paces já estão fixos pelas regras acima — a IA só troca o
    // texto. Falha silenciosamente para a descrição gerada pelas regras.
    $descriptions = AiEngine::varietyPass($all_workouts);

    // --- 9. PERSISTÊNCIA ---
    $ins = $conn->prepare("INSERT INTO training_plans
        (user_id, day_name, workout_date, week_number, workout_type, distance, pace, description, phase, intensity_zone, is_deload, workout_category)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($all_workouts as $i => $w) {
        $desc = $descriptions[$i] ?? $w['desc'];
        $zone = workoutZone($w['type']);
        $category = workoutCategory($w['type']);
        $is_deload_int = $w['is_deload'] ? 1 : 0;
        $ins->bind_param(
            "issisdsssiis",
            $user_id, $w['day'], $w['date'], $w['week'], $w['type'], $w['dist'], $w['pace'], $desc,
            $w['phase'], $zone, $is_deload_int, $category
        );
        $ins->execute();
    }

    // Redirecionar para evitar POST repetido e carregar a semana 1
    header("Location: " . $_SERVER['PHP_SELF'] . "?week=1");
    exit();
}

// 10. BUSCAR TREINOS DA SEMANA PARA A VIEW
$stmt = $conn->prepare("SELECT * FROM training_plans WHERE user_id = ? AND week_number = ? ORDER BY workout_date ASC");
$stmt->bind_param("ii", $user_id, $current_week);
$stmt->execute();
$res = $stmt->get_result();
$weekly_workouts = [];
while($row = $res->fetch_assoc()) {
    $weekly_workouts[$row['day_name']] = $row;
}
?>
