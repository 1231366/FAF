<?php
// Impede que avisos do PHP estraguem o JSON em caso de erro inesperado
error_reporting(0);
ini_set('display_errors', 0);

// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../core/config.php';

// Mantém o streak_count do Circle e posta o evento correspondente no feed.
// Streak sobe uma vez por dia quando TODOS os treinos agendados nesse dia,
// entre membros do Circle, ficam 'completed'; qualquer 'skipped' apaga o fogo.
if (!function_exists('updateCircleAfterCheckin')) {
    function updateCircleAfterCheckin($conn, $user_id, $workout_date, $status, $workout_type) {
        $stmt_u = $conn->prepare("SELECT circle_id, name FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $user = $stmt_u->get_result()->fetch_assoc();
        $circle_id = $user['circle_id'] ?? null;
        if (!$circle_id) return;

        $stmt_c = $conn->prepare("SELECT streak_count, last_streak_update FROM circles WHERE id = ?");
        $stmt_c->bind_param("i", $circle_id);
        $stmt_c->execute();
        $circle = $stmt_c->get_result()->fetch_assoc();
        if (!$circle) return;

        if ($status === 'skipped') {
            $msg = "{$user['name']} falhou {$workout_type}. O fogo do Circle esfria 🔥→🕯️";
            if ((int)$circle['streak_count'] !== 0) {
                $upd = $conn->prepare("UPDATE circles SET streak_count = 0 WHERE id = ?");
                $upd->bind_param("i", $circle_id);
                $upd->execute();
            }
            $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'alert')");
            $ins->bind_param("iis", $circle_id, $user_id, $msg);
            $ins->execute();
            return;
        }

        // status === 'completed': só conta o dia se TODOS os treinos agendados
        // do Circle nesse dia já estiverem resolvidos (nenhum 'pending') e nenhum skip.
        $stmt_s = $conn->prepare("SELECT tp.status FROM training_plans tp JOIN users u ON tp.user_id = u.id
                                   WHERE u.circle_id = ? AND tp.workout_date = ?");
        $stmt_s->bind_param("is", $circle_id, $workout_date);
        $stmt_s->execute();
        $statuses = array_column($stmt_s->get_result()->fetch_all(MYSQLI_ASSOC), 'status');

        if (empty($statuses) || in_array('pending', $statuses) || in_array('skipped', $statuses)) return;
        if ($circle['last_streak_update'] === $workout_date) return; // já contado hoje

        $upd = $conn->prepare("UPDATE circles SET streak_count = streak_count + 1, last_streak_update = ? WHERE id = ?");
        $upd->bind_param("si", $workout_date, $circle_id);
        $upd->execute();

        $new_streak = (int)$circle['streak_count'] + 1;
        $msg = "Dia limpo! Todo o Circle cumpriu os treinos. Streak: {$new_streak} 🔥";
        $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, NULL, ?, 'system')");
        $ins->bind_param("is", $circle_id, $msg);
        $ins->execute();
    }
}

// Núcleo do check-in, reutilizável tanto pelo AJAX abaixo como pelo
// strava_sync.php (auto-checkin a partir de atividades sincronizadas).
if (!function_exists('resolveWorkoutCheckin')) {
    function resolveWorkoutCheckin($conn, $user_id, $workout_id, $status, $dist = null, $pace = null, $effort = null) {
        $stmt_w = $conn->prepare("SELECT workout_date, workout_type FROM training_plans WHERE id = ? AND user_id = ?");
        $stmt_w->bind_param("ii", $workout_id, $user_id);
        $stmt_w->execute();
        $workout_row = $stmt_w->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE training_plans SET status = ?, real_distance = ?, real_pace = ?, effort_level = ?, completed_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sdssii", $status, $dist, $pace, $effort, $workout_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception('Erro ao atualizar base de dados');
        }

        if ($workout_row && in_array($status, ['completed', 'skipped'])) {
            updateCircleAfterCheckin($conn, $user_id, $workout_row['workout_date'], $status, $workout_row['workout_type']);
        }

        // Lógica de Tendência Neural (Ajuste adaptativo do FAF)
        $adapted = false; $adapt_direction = null;
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
                require_once __DIR__ . '/../engines/kernel_engine.php';
                $factor = ($trend >= 2) ? 0.95 : 1.05;
                if (function_exists('recalculateFutureWeeks')) {
                    recalculateFutureWeeks($user_id, $factor);
                    $adapted = true;
                    $adapt_direction = ($trend >= 2) ? 'faster' : 'easier';
                }
                $trend = 0; // Reset após adaptação
            }
            $stmt_u = $conn->prepare("UPDATE user_profiles SET neural_trend = ? WHERE user_id = ?");
            $stmt_u->bind_param("ii", $trend, $user_id);
            $stmt_u->execute();
        }

        return ['adapted' => $adapted, 'direction' => $adapt_direction];
    }
}

// Só corre o handler AJAX quando este ficheiro é o endpoint pedido
// diretamente — não quando é apenas incluído por outro script (ex:
// strava_sync.php) só para reutilizar as funções acima.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
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

        $result = resolveWorkoutCheckin($conn, $user_id, $workout_id, $status, $dist, $pace, $effort);

        // Badges: só faz sentido verificar depois de um treino concluído
        $new_badges = [];
        if ($status === 'completed') {
            require_once __DIR__ . '/../engines/badge_engine.php';
            $new_badges = checkAndAwardBadges($conn, $user_id);
        }

        echo json_encode(['success' => true, 'adapted' => $result['adapted'], 'direction' => $result['direction'], 'badges' => $new_badges]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}
