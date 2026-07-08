<?php
// src/engines/badge_engine.php — conquistas por marcos de treino.
// checkAndAwardBadges() é chamado após cada check-in concluído e devolve
// os badges ganhos NESSA chamada (para a UI celebrar na hora).

const BADGE_DEFS = [
    'first_blood' => ['emoji' => '🩸', 'name' => 'First Blood',   'desc' => 'Primeiro treino concluído'],
    'clean_week'  => ['emoji' => '🧼', 'name' => 'Clean Week',    'desc' => 'Uma semana 100% cumprida'],
    'streak_7'    => ['emoji' => '🔥', 'name' => 'On Fire',       'desc' => '7 treinos seguidos sem falhar'],
    'km_50'       => ['emoji' => '🛣️', 'name' => 'Road Warrior',  'desc' => '50 km reais acumulados'],
    'km_100'      => ['emoji' => '💯', 'name' => 'Century Club',  'desc' => '100 km reais acumulados'],
    'km_250'      => ['emoji' => '🏔️', 'name' => 'Ultra Mindset', 'desc' => '250 km reais acumulados'],
    'deload_zen'  => ['emoji' => '🧘', 'name' => 'Deload Zen',    'desc' => 'Semana de descarga respeitada na íntegra'],
    'taper_mode'  => ['emoji' => '⚡', 'name' => 'Taper Mode',    'desc' => 'Chegar ao taper com consistência ≥ 80%'],
];

function checkAndAwardBadges($conn, $user_id) {
    // Badges já ganhos
    $stmt = $conn->prepare("SELECT badge_code FROM user_badges WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $owned = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'badge_code');

    // Métricas base num só passe
    $stmt = $conn->prepare("SELECT
            SUM(status='completed') done_cnt,
            SUM(status='skipped') skip_cnt,
            SUM(status='pending' AND workout_date < CURDATE()) overdue_cnt,
            SUM(CASE WHEN status='completed' THEN COALESCE(real_distance, distance) ELSE 0 END) done_km
        FROM training_plans WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    $done_cnt = (int)$m['done_cnt'];
    $done_km  = (float)$m['done_km'];
    $closed   = $done_cnt + (int)$m['skip_cnt'] + (int)$m['overdue_cnt'];
    $consistency = ($closed > 0) ? ($done_cnt / $closed) * 100 : 100;

    $earned = [];
    $award = function($code) use (&$earned, $owned) {
        if (!in_array($code, $owned) && !in_array($code, array_column($earned, 'code'))) {
            $earned[] = ['code' => $code] + BADGE_DEFS[$code];
        }
    };

    if ($done_cnt >= 1) $award('first_blood');
    if ($done_km >= 50)  $award('km_50');
    if ($done_km >= 100) $award('km_100');
    if ($done_km >= 250) $award('km_250');

    // Semana limpa / deload zen: semanas já terminadas com todos os treinos concluídos
    $stmt = $conn->prepare("SELECT MAX(is_deload) is_deload FROM training_plans WHERE user_id = ?
                             GROUP BY week_number
                             HAVING COUNT(*) > 0 AND SUM(status != 'completed') = 0 AND MAX(workout_date) <= CURDATE()");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $clean_weeks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($clean_weeks) > 0) $award('clean_week');
    foreach ($clean_weeks as $cwk) { if ((int)$cwk['is_deload'] === 1) { $award('deload_zen'); break; } }

    // Streak de 7: treinos consecutivos concluídos até hoje
    $stmt = $conn->prepare("SELECT status FROM training_plans WHERE user_id = ? AND workout_date <= CURDATE() ORDER BY workout_date DESC LIMIT 10");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $streak = 0;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['status'] === 'completed') $streak++; else break;
    }
    if ($streak >= 7) $award('streak_7');

    // Taper mode: já dentro da janela de taper com consistência alta
    $stmt = $conn->prepare("SELECT MIN(workout_date) d FROM training_plans WHERE user_id = ? AND phase = 'TAPER'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $taper_start = $stmt->get_result()->fetch_assoc()['d'] ?? null;
    if ($taper_start && $taper_start <= date('Y-m-d') && $consistency >= 80) $award('taper_mode');

    // Persistir os novos
    if (!empty($earned)) {
        $ins = $conn->prepare("INSERT IGNORE INTO user_badges (user_id, badge_code) VALUES (?, ?)");
        foreach ($earned as $b) {
            $ins->bind_param("is", $user_id, $b['code']);
            $ins->execute();
        }
    }
    return $earned;
}
