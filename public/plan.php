<?php
require_once __DIR__ . '/../src/core/config.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];

// Escapa texto vindo de campos preenchidos pelo utilizador (nome, nome do circle)
// antes de o imprimir em HTML.
function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// URL de avatar com fallback: se o utilizador não tem foto (registo por email,
// não Google), gera um avatar determinístico a partir do nome.
function avatar($pic, $name) {
    if (!empty($pic)) return e($pic);
    return 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($name ?? 'FAF');
}

// Briefing pós-onboarding: mostrado uma única vez, logo após o plano ser gerado
$show_briefing = !empty($_SESSION['plan_generated_now']);
unset($_SESSION['plan_generated_now']);

/**
 * 1. DATA LAYER
 */
$query = "SELECT u.name, u.profile_pic, u.circle_id, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

// Sincroniza a sessão com a DB para evitar que o estado antigo bloqueie a interface
if (isset($userData['circle_id'])) {
    $_SESSION['circle_id'] = $userData['circle_id'];
}

/**
 * 2. IDENTITY LAYER
 */
$userName = $userData['name'] ?? $_SESSION['user_name'] ?? 'Atleta';
$userPic  = $userData['profile_pic'] ?? $_SESSION['user_pic'] ?? 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($userName);
$first_name = explode(' ', $userName)[0];

/**
 * 2b. SOCIAL ENGINE LOGIC (Circle, fogo, amigos, notificações)
 */
$circle_energy = 0; $circle_name = "Solo Protocol"; $streak = 0;
$clan_members = [];
if (!empty($userData['circle_id'])) {
    $c_id = $userData['circle_id'];
    $stmt_c = $conn->prepare("SELECT name, streak_count FROM circles WHERE id = ?");
    $stmt_c->bind_param("i", $c_id);
    $stmt_c->execute();
    $c_info = $stmt_c->get_result()->fetch_assoc();
    $circle_name = $c_info['name'] ?? "Alpha Circle";
    $streak = $c_info['streak_count'] ?? 0;

    $stmt_m = $conn->prepare("SELECT name, profile_pic FROM users WHERE circle_id = ?");
    $stmt_m->bind_param("i", $c_id);
    $stmt_m->execute();
    $clan_members = $stmt_m->get_result()->fetch_all(MYSQLI_ASSOC);

    $cw = isset($_GET['week']) ? (int)$_GET['week'] : 1;
    $stmt_stats = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as done
                                  FROM training_plans tp JOIN users u ON tp.user_id = u.id
                                  WHERE u.circle_id = ? AND tp.week_number = ?");
    $stmt_stats->bind_param("ii", $c_id, $cw);
    $stmt_stats->execute();
    $s = $stmt_stats->get_result()->fetch_assoc();
    $circle_energy = ($s['total'] > 0) ? round(($s['done'] / $s['total']) * 100) : 100;
}

// Tiers de intensidade do fogo do Circle, derivados do streak
function fireTier($streak) {
    if ($streak >= 7) return ['label' => 'INFERNO', 'emoji' => '🔥🔥🔥'];
    if ($streak >= 3) return ['label' => 'BLAZE', 'emoji' => '🔥🔥'];
    return ['label' => 'EMBER', 'emoji' => '🔥'];
}
$fire = fireTier($streak);

$stmt_f = $conn->prepare("SELECT u.name, u.profile_pic FROM friendships f JOIN users u ON (f.user_id = u.id OR f.friend_id = u.id) WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = 'accepted' AND u.id != ?");
$stmt_f->bind_param("iii", $user_id, $user_id, $user_id);
$stmt_f->execute();
$my_friends = $stmt_f->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt_inbox = $conn->prepare("SELECT f.user_id as athlete_id, u.name, u.profile_pic FROM friendships f JOIN users u ON f.user_id = u.id WHERE f.friend_id = ? AND f.status = 'pending'");
$stmt_inbox->bind_param("i", $user_id);
$stmt_inbox->execute();
$notifications = $stmt_inbox->get_result();
$notif_count = $notifications->num_rows;

// Rankings globais: circles pelo fogo, atletas por km reais nos últimos 30 dias
$top_circles = $conn->query("SELECT c.id, c.name, c.streak_count, COUNT(u.id) members
                              FROM circles c LEFT JOIN users u ON u.circle_id = c.id
                              GROUP BY c.id ORDER BY c.streak_count DESC, members DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$top_athletes = $conn->query("SELECT u.id, u.name, u.profile_pic, ROUND(SUM(COALESCE(tp.real_distance, tp.distance)), 1) km
                               FROM training_plans tp JOIN users u ON tp.user_id = u.id
                               WHERE tp.status = 'completed' AND tp.workout_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               GROUP BY u.id ORDER BY km DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

/**
 * 3. ENGINE LAYER
 */
$current_week = isset($_GET['week']) ? (int)$_GET['week'] : 1;
$total_cycle_weeks = (int)($userData['prep_cycle'] ?? 12);

// SEGURANÇA: Impede navegação para semanas inexistentes
if ($current_week < 1) { header("Location: ?week=1"); exit(); }
if ($current_week > $total_cycle_weeks) { header("Location: ?week=" . $total_cycle_weeks); exit(); }

require_once __DIR__ . '/../src/engines/kernel_engine.php'; 

$volume_total = 0;
$ordem_dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
if (isset($weekly_workouts)) {
    foreach ($weekly_workouts as $w) { $volume_total += (float)($w['distance'] ?? 0); }
}

$hoje_nome = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sab'][date('D')];
$target_dist = (int)($userData['target_distance'] ?? 42);
$target_label = ($target_dist <= 5) ? "5KM" : (($target_dist <= 10) ? "10KM" : (($target_dist <= 21) ? "HALF MARATHON" : "MARATHON"));

$proximo_alvo = null;
foreach($ordem_dias as $d) {
    if (isset($weekly_workouts[$d]) && ($weekly_workouts[$d]['status'] ?? 'pending') !== 'completed') {
        $proximo_alvo = $d; break;
    }
}

/**
 * 3b. ANALYTICS LAYER — métricas reais calculadas a partir do plano e dos check-ins
 */
$vdot = round(calculateVdot((float)($userData['ref_dist'] ?? 5), paceToSec($userData['ref_pace'] ?? '25:00')), 1);

// Volume e execução por semana (alimenta a onda do header e o gráfico do Neural Data)
$stmt_wk = $conn->prepare("SELECT week_number,
        SUM(distance) planned_km,
        SUM(CASE WHEN status='completed' THEN COALESCE(real_distance, distance) ELSE 0 END) done_km,
        SUM(status='completed') done_cnt,
        SUM(status='skipped') skip_cnt,
        SUM(status='pending' AND workout_date < CURDATE()) overdue_cnt,
        MAX(phase) phase, MAX(is_deload) is_deload
    FROM training_plans WHERE user_id = ? GROUP BY week_number ORDER BY week_number");
$stmt_wk->bind_param("i", $user_id);
$stmt_wk->execute();
$cycle_weeks = $stmt_wk->get_result()->fetch_all(MYSQLI_ASSOC);

$total_done_km = 0; $done_cnt = 0; $skip_cnt = 0; $overdue_cnt = 0;
foreach ($cycle_weeks as $cw_row) {
    $total_done_km += (float)$cw_row['done_km'];
    $done_cnt += (int)$cw_row['done_cnt'];
    $skip_cnt += (int)$cw_row['skip_cnt'];
    $overdue_cnt += (int)$cw_row['overdue_cnt'];
}
$closed_cnt = $done_cnt + $skip_cnt + $overdue_cnt;
$consistency = ($closed_cnt > 0) ? round(($done_cnt / $closed_cnt) * 100) : 100;
$max_week_km = max(array_merge([1], array_map(fn($r) => (float)$r['planned_km'], $cycle_weeks)));

// Readiness: heurística simples nos últimos 14 dias — skips pesam muito,
// esforço 'hard' repetido pesa um pouco, 'easy' recupera.
$stmt_rec = $conn->prepare("SELECT
        SUM(status='skipped') skips,
        SUM(status='completed' AND effort_level='hard') hards,
        SUM(status='completed' AND effort_level='easy') easies
    FROM training_plans WHERE user_id = ? AND workout_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND workout_date <= CURDATE()");
$stmt_rec->bind_param("i", $user_id);
$stmt_rec->execute();
$rec = $stmt_rec->get_result()->fetch_assoc();
$readiness = max(40, min(100, 100 - 12 * (int)$rec['skips'] - 6 * (int)$rec['hards'] + 2 * (int)$rec['easies']));

// Streak pessoal: treinos consecutivos concluídos, do mais recente para trás
$stmt_stk = $conn->prepare("SELECT status FROM training_plans WHERE user_id = ? AND workout_date <= CURDATE() ORDER BY workout_date DESC LIMIT 40");
$stmt_stk->bind_param("i", $user_id);
$stmt_stk->execute();
$personal_streak = 0;
foreach ($stmt_stk->get_result()->fetch_all(MYSQLI_ASSOC) as $row_s) {
    if ($row_s['status'] === 'completed') $personal_streak++;
    else break;
}

// Badges do atleta
require_once __DIR__ . '/../src/engines/badge_engine.php';
$stmt_bg = $conn->prepare("SELECT badge_code, earned_at FROM user_badges WHERE user_id = ?");
$stmt_bg->bind_param("i", $user_id);
$stmt_bg->execute();
$my_badges = array_column($stmt_bg->get_result()->fetch_all(MYSQLI_ASSOC), 'earned_at', 'badge_code');

// Recap semanal: a última semana do plano já terminada (para o modal de resumo)
$last_week_recap = null;
$stmt_lw = $conn->prepare("SELECT week_number FROM training_plans WHERE user_id = ?
                            GROUP BY week_number HAVING MAX(workout_date) < CURDATE()
                            ORDER BY MAX(workout_date) DESC LIMIT 1");
$stmt_lw->bind_param("i", $user_id);
$stmt_lw->execute();
$last_finished_week = $stmt_lw->get_result()->fetch_assoc()['week_number'] ?? null;
if ($last_finished_week !== null) {
    foreach ($cycle_weeks as $cw_row) {
        if ((int)$cw_row['week_number'] !== (int)$last_finished_week) continue;
        $wk_closed = (int)$cw_row['done_cnt'] + (int)$cw_row['skip_cnt'] + (int)$cw_row['overdue_cnt'];
        $wk_consistency = $wk_closed > 0 ? round(((int)$cw_row['done_cnt'] / $wk_closed) * 100) : 0;
        $recap_coach = $wk_consistency >= 100 ? "Semana perfeita. É disto que se fazem os grandes ciclos." :
                       ($wk_consistency >= 70 ? "Semana sólida. Consolida e ataca a próxima." :
                       "Semana difícil. Esquece — o plano já se adaptou. Recomeça hoje.");
        $last_week_recap = [
            'week' => (int)$cw_row['week_number'],
            'done_km' => (float)$cw_row['done_km'],
            'planned_km' => (float)$cw_row['planned_km'],
            'consistency' => $wk_consistency,
            'coach' => $recap_coach,
        ];
        break;
    }
}

// Countdown para a prova
$race_days_left = null;
$race_label_hdr = $target_label;
if (!empty($userData['race_date'])) {
    $diff = (new DateTime('today'))->diff(new DateTime($userData['race_date']));
    if (!$diff->invert) $race_days_left = $diff->days;
}
if (!empty($userData['race_name'])) $race_label_hdr = mb_strtoupper($userData['race_name']);

// Cores por fase do ciclo (usadas nos chips dos cards e na timeline do Neural Data)
function phaseColor($phase) {
    return ['BASE' => '#38bdf8', 'BUILD' => '#c3f400', 'PEAK' => '#f97316', 'TAPER' => '#a78bfa'][$phase] ?? '#ffffff';
}

// Contexto da semana atual para a mensagem do coach
$week_is_deload = false; $week_phase = null;
foreach ($cycle_weeks as $cw_row) {
    if ((int)$cw_row['week_number'] === $current_week) {
        $week_is_deload = (bool)$cw_row['is_deload'];
        $week_phase = $cw_row['phase'];
        break;
    }
}

$workout_hoje = $weekly_workouts[$hoje_nome] ?? null;
if ($workout_hoje) {
    $coach_msg = "Hey $first_name! Hoje: {$workout_hoje['workout_type']}. Foca no ritmo.";
    if ($week_is_deload) $coach_msg = "Hey $first_name! Semana de descarga — hoje é {$workout_hoje['workout_type']}, mas o objetivo é recuperar. Não acelerar.";
    elseif ($week_phase === 'TAPER') $coach_msg = "Hey $first_name! Taper ativo. {$workout_hoje['workout_type']} curto e afiado — as pernas estão a carregar.";
} else {
    $coach_msg = "Hey $first_name! Hoje o asfalto descansa. A adaptação acontece no repouso.";
}
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"/>
    <title>FAF Neural - Performance Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-25..0" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@800&family=Inter:wght@400;600;900&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = { theme: { extend: { colors: { "faf-neon": "#c3f400" }, fontFamily: { "headline": ["Plus Jakarta Sans"], "body": ["Inter"] } } } }
    </script>
    <style>
        :root { --safe-top: env(safe-area-inset-top); --safe-bottom: env(safe-area-inset-bottom); }
        body { background-color: #000; color: #fff; font-family: 'Inter', sans-serif; overflow: hidden; height: 100vh; display: flex; flex-direction: column; }
        
        /* ANIMAÇÃO DE ROTAÇÃO RESTRITA À BARRA DO CALENDÁRIO */
        .week-rotate-anim { animation: barRotate 0.6s cubic-bezier(0.34, 1.56, 0.64, 1); }
        @keyframes barRotate { 
            0% { opacity: 0; transform: scaleX(0.9) translateY(5px); }
            100% { opacity: 1; transform: scaleX(1) translateY(0); }
        }

        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.05); }
        header { flex-shrink: 0; z-index: 500; }
        main { flex-grow: 1; overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch; scroll-behavior: smooth; position: relative; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .workout-stack { display: flex; flex-direction: column; gap: -45px; padding-bottom: 180px; padding-top: 20px; }
        .workout-card { transition: all 0.5s cubic-bezier(0.2, 1, 0.3, 1); position: relative; }
        .workout-card.focused { transform: scale(1.05) translateY(-10px); z-index: 100 !important; opacity: 1 !important; }
        .workout-card.focused .glass-card { border-color: rgba(195, 244, 0, 0.5); box-shadow: 0 30px 60px rgba(0,0,0,0.9); }
        .workout-card.minimized { opacity: 0.4; transform: scale(0.95); }
        
        .day-item { touch-action: pan-y; -webkit-user-select: none; user-select: none; }
        .day-item.selected { color: #c3f400; transform: scale(1.15); }
        .day-item.selected .dot { background: #c3f400; box-shadow: 0 0 10px #c3f400; }
        ::-webkit-scrollbar { display: none; }
        .nav-active { color: #c3f400 !important; background: rgba(195, 244, 0, 0.1); border-radius: 20px; }
        .drag-handle { cursor: grab; }
        #abort-modal, #feedback-modal, #neural-inbox, #search-overlay, #generic-modal, #briefing-modal, #success-modal, #recap-modal { display: none; position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.92); backdrop-filter: blur(15px); align-items: center; justify-content: center; padding: 24px; }
        #briefing-modal .briefing-card { max-height: 85vh; overflow-y: auto; }
        input:focus, textarea:focus, button:focus-visible { outline: none; box-shadow: 0 0 0 2px rgba(195, 244, 0, 0.5); }
        .skeleton { background: linear-gradient(90deg, rgba(255,255,255,0.04) 25%, rgba(255,255,255,0.09) 37%, rgba(255,255,255,0.04) 63%); background-size: 400% 100%; animation: skeleton-pulse 1.4s ease infinite; border-radius: 12px; }
        @keyframes skeleton-pulse { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; } }

        /* Coach Overlay */
        #coach-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(12px); z-index: 4000; align-items: center; justify-content: center; padding: 20px; }
        #coach-modal-chat { width: 100%; max-width: 420px; height: 70vh; background: #0d0d0d; border: 1px solid rgba(195,244,0,0.15); border-radius: 35px; display: flex; flex-direction: column; overflow: hidden; }
        #coach-overlay.active { display: flex; animation: sheetUp 0.4s cubic-bezier(0.2, 1, 0.3, 1) forwards; }
        @keyframes sheetUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }
        .coach-bubble { align-self: flex-start; background: rgba(255,255,255,0.05); padding: 12px 16px; border-radius: 4px 20px 20px 20px; font-size: 13px; max-width: 85%; border-left: 2px solid #c3f400; margin-bottom: 12px; }
        .user-bubble { align-self: flex-end; background: #c3f400; color: #000; padding: 12px 16px; border-radius: 20px 20px 4px 20px; font-weight: 800; font-style: italic; font-size: 13px; max-width: 85%; margin-bottom: 12px; }
    </style>
</head>
<body>

    <header class="pt-[var(--safe-top)] px-6 bg-black border-b border-white/5">
        <div class="py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="<?= $userPic ?>" class="w-9 h-9 rounded-full border border-faf-neon/30 object-cover" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                <h1 class="text-xl font-headline font-black italic uppercase tracking-tighter">FAF<span class="text-faf-neon">.</span></h1>
            </div>
            <div class="flex items-center gap-4">
                <span onclick="openBriefing()" class="material-symbols-outlined text-white/40 text-2xl cursor-pointer" aria-label="Como funciona o plano">help</span>
                <div onclick="toggleInbox()" class="relative cursor-pointer">
                    <span class="material-symbols-outlined text-white/40 text-2xl">notifications</span>
                    <?php if($notif_count > 0): ?><div class="absolute -top-1 -right-1 w-4 h-4 bg-faf-neon rounded-full flex items-center justify-center text-[10px] text-black font-black"><?= $notif_count ?></div><?php endif; ?>
                </div>
                <span onclick="switchTab('profile')" class="material-symbols-outlined text-faf-neon text-2xl cursor-pointer">settings</span>
            </div>
        </div>

        <div id="run-header-extras" class="pb-4 space-y-4">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-black uppercase text-faf-neon tracking-[0.2em] italic mb-0.5"><?= e($race_label_hdr) ?> MISSION</p>
                    <h2 class="text-2xl font-headline font-black italic uppercase tracking-tighter leading-none italic">Neural Protocol</h2>
                </div>
                <?php if($race_days_left !== null): ?>
                <div class="text-right">
                    <p class="text-3xl font-headline font-black italic text-faf-neon leading-none"><?= $race_days_left ?></p>
                    <p class="text-[8px] font-black uppercase tracking-[0.2em] text-white/40 italic">dias p/ prova</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Onda real de volume do ciclo: cada barra é uma semana (vê-se a periodização) -->
            <div class="flex items-end gap-1 h-6">
                <?php foreach($cycle_weeks as $cw_row):
                    $wk = (int)$cw_row['week_number'];
                    $hpct = 25 + round(((float)$cw_row['planned_km'] / $max_week_km) * 75);
                    if ($wk < $current_week)      $bar = 'bg-faf-neon/50';
                    elseif ($wk == $current_week) $bar = 'bg-faf-neon shadow-[0_0_8px_#c3f400]';
                    else                          $bar = ((int)$cw_row['is_deload']) ? 'bg-white/5' : 'bg-white/10';
                ?>
                    <div onclick="window.location.href='?week=<?= $wk ?>'" class="flex-1 rounded-sm cursor-pointer transition-all hover:opacity-80 <?= $bar ?>" style="height: <?= $hpct ?>%"></div>
                <?php endforeach; ?>
            </div>

            <div onclick="openCoachChat()" class="flex gap-3 items-center bg-white/5 p-3 rounded-2xl border border-white/5 cursor-pointer active:scale-95 transition-all">
                <div class="relative">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=CoachK" class="w-8 h-8 rounded-full border border-faf-neon/30">
                    <div class="absolute -top-0.5 -right-0.5 w-2 h-2 bg-faf-neon rounded-full border border-black animate-pulse"></div>
                </div>
                <p class="text-[10px] text-white/70 italic leading-tight">"<?= $coach_msg ?>"</p>
            </div>

            <section id="swipe-area" class="bg-zinc-900/60 p-4 rounded-[24px] border border-white/5 shadow-2xl flex items-center gap-2 week-rotate-anim">
                <button onclick="changeWeek(-1)" class="text-faf-neon/40 font-black text-xl select-none px-2 active:scale-150 transition-all <?= $current_week <= 1 ? 'invisible' : '' ?>">&lt;</button>
                
                <div id="days-nav" class="flex-1 flex justify-between items-center">
                    <?php foreach($ordem_dias as $d): 
                        $hasW = isset($weekly_workouts[$d]);
                        $isH = ($d == $hoje_nome);
                    ?>
                    <div onclick="focusDay('<?= $d ?>')" data-day="<?= $d ?>" class="day-item flex flex-col items-center gap-1 cursor-pointer transition-all duration-300 <?= $isH ? 'selected' : 'opacity-30' ?>">
                        <span class="text-[9px] font-black uppercase"><?= $d ?></span>
                        <div class="dot w-1.5 h-1.5 rounded-full <?= $hasW ? 'bg-white shadow-[0_0_5px_white]' : 'bg-transparent border border-white/20' ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button onclick="changeWeek(1)" class="text-faf-neon/40 font-black text-xl select-none px-2 active:scale-150 transition-all <?= $current_week >= $total_cycle_weeks ? 'invisible' : '' ?>">&gt;</button>
            </section>
        </div>
    </header>

    <main id="app-main" class="px-6 pt-4">

        <div id="home" class="tab-content active">
            <div class="flex justify-between items-end px-2 pb-4">
                <p class="text-[10px] font-black text-white/30 uppercase tracking-widest italic">Week <?= $current_week ?> • <?= number_format($volume_total, 1) ?> KM</p>
                <div class="flex items-center gap-1 opacity-20"><span class="material-symbols-outlined text-[10px]">swipe_left</span><p class="text-[8px] font-black uppercase italic">Swipe Calendar</p></div>
            </div>

            <div id="drag-container" class="workout-stack">
                <?php 
                $idx = 0;
                foreach($ordem_dias as $dia): 
                    $w = $weekly_workouts[$dia] ?? null;
                    if(!$w) continue;
                    $idx++;
                    $isTarget = ($dia === $proximo_alvo);
                    $concluido = ($w['status'] == 'completed');
                    $tipo_w = strtolower($w['workout_type'] ?? '');
                    $icon = (strpos($tipo_w, 'long') !== false) ? 'terrain' : ((strpos($tipo_w, 'easy') !== false) ? 'favorite' : 'bolt');
                    if($concluido) $icon = 'check_circle';
                ?>
                <div data-day="<?= $dia ?>" id="card-<?= $dia ?>" class="workout-card cursor-pointer <?= $isTarget ? 'focused' : 'minimized' ?>" style="z-index: <?= 50 - $idx ?>;" onclick="focusDay('<?= $dia ?>')">
                    <div class="glass-card rounded-[40px] p-7 shadow-2xl relative overflow-hidden">
                        <div class="flex justify-between items-start mb-3">
                            <div><p class="text-[11px] font-black uppercase text-faf-neon italic tracking-widest mb-1"><?= $dia ?></p><h4 class="text-3xl font-headline font-black italic uppercase leading-none"><?= $w['workout_type'] ?></h4></div>
                            <div class="drag-handle w-12 h-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center"><span class="material-symbols-outlined text-faf-neon text-2xl"><?= $icon ?></span></div>
                        </div>

                        <?php if(!empty($w['phase']) || !empty($w['intensity_zone']) || !empty($w['is_deload'])): ?>
                        <div class="flex gap-2 mb-4">
                            <?php if(!empty($w['phase'])): $pc = phaseColor($w['phase']); ?>
                                <span class="text-[8px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full border italic" style="color: <?= $pc ?>; border-color: <?= $pc ?>40; background: <?= $pc ?>12;"><?= $w['phase'] ?></span>
                            <?php endif; ?>
                            <?php if(!empty($w['intensity_zone'])): ?>
                                <span class="text-[8px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full border border-white/15 bg-white/5 text-white/60 italic">Z<?= (int)$w['intensity_zone'] ?></span>
                            <?php endif; ?>
                            <?php if(!empty($w['is_deload'])): ?>
                                <span class="text-[8px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full border border-sky-400/40 bg-sky-400/10 text-sky-300 italic">Deload</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="mb-6"><p class="text-[10px] text-white/50 font-medium leading-relaxed italic"><?= !empty($w['description']) ? $w['description'] : 'Foco na manutenção aeróbica.' ?></p></div>

                        <?php if($concluido): ?>
                        <div class="bg-faf-neon/5 border border-faf-neon/20 rounded-3xl p-4 mb-6 grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[8px] font-black text-white/20 uppercase italic mb-1">Target</p>
                                <p class="text-xs font-black text-white/60 italic"><?= number_format($w['distance'], 1) ?>k @ <?= $w['pace'] ?></p>
                            </div>
                            <div class="border-l border-white/10 pl-4">
                                <p class="text-[8px] font-black text-faf-neon uppercase italic mb-1">Real</p>
                                <p class="text-xs font-black text-faf-neon italic"><?= number_format((float)($w['real_distance'] ?? $w['distance']), 1) ?>k @ <?= $w['real_pace'] ?? $w['pace'] ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center">
                            <div class="flex gap-6">
                                <div><p class="text-[9px] text-white/30 uppercase font-black italic">Distância</p><p class="text-xl font-black italic"><?= number_format($w['distance'] ?? 0, 1) ?>k</p></div>
                                <div><p class="text-[9px] text-white/30 uppercase font-black italic">Target</p><p class="text-xl font-black italic text-faf-neon"><?= $w['pace'] ?? '0:00' ?></p></div>
                            </div>
                            <?php if(!$concluido): ?>
                                <button onclick="openCheckIn(<?= $w['id'] ?>, '<?= $w['workout_type'] ?>', '<?= $w['distance'] ?>')" class="bg-faf-neon text-black font-black uppercase px-6 py-3 rounded-2xl text-[10px] italic shadow-lg active:scale-90 transition-all">Feedback</button>
                            <?php else: ?>
                                <button data-type="<?= e($w['workout_type']) ?>" data-dist="<?= number_format((float)($w['real_distance'] ?? $w['distance']), 1) ?>" data-pace="<?= e($w['real_pace'] ?? $w['pace']) ?>" onclick="shareWorkout(this); event.stopPropagation();" class="w-12 h-12 rounded-2xl border border-faf-neon/40 text-faf-neon flex items-center justify-center active:scale-90 transition-all" aria-label="Partilhar treino">
                                    <span class="material-symbols-outlined text-xl">ios_share</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if(empty($weekly_workouts)): ?>
                <div class="glass-card rounded-[40px] p-12 text-center border-dashed border-2 border-white/10 mt-6">
                    <span class="material-symbols-outlined text-faf-neon/40 text-5xl mb-4">self_improvement</span>
                    <h4 class="text-xl font-headline font-black italic uppercase tracking-tighter mb-2">Semana em branco</h4>
                    <p class="text-[10px] text-white/40 italic leading-relaxed">Sem treinos agendados nesta semana.<br>Usa as setas ou faz swipe no calendário para navegar.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="insights" class="tab-content space-y-6 pb-32">
            <h2 class="text-4xl font-headline font-black italic uppercase tracking-tighter pt-4">Neural Data</h2>

            <div class="grid grid-cols-2 gap-3">
                <div class="glass-card p-6 rounded-[35px]">
                    <p class="text-[9px] font-black text-white/30 uppercase italic tracking-widest mb-2">VDOT</p>
                    <p class="text-4xl font-headline font-black italic text-faf-neon leading-none"><?= $vdot ?></p>
                    <p class="text-[8px] text-white/20 uppercase font-black italic mt-2">Motor aeróbico (J. Daniels)</p>
                </div>
                <div class="glass-card p-6 rounded-[35px]">
                    <p class="text-[9px] font-black text-white/30 uppercase italic tracking-widest mb-2">Readiness</p>
                    <p class="text-4xl font-headline font-black italic leading-none <?= $readiness >= 80 ? 'text-faf-neon' : ($readiness >= 60 ? 'text-orange-400' : 'text-red-500') ?>"><?= $readiness ?>%</p>
                    <p class="text-[8px] text-white/20 uppercase font-black italic mt-2">Últimos 14 dias</p>
                </div>
                <div class="glass-card p-6 rounded-[35px]">
                    <p class="text-[9px] font-black text-white/30 uppercase italic tracking-widest mb-2">Consistência</p>
                    <p class="text-4xl font-headline font-black italic leading-none"><?= $consistency ?>%</p>
                    <p class="text-[8px] text-white/20 uppercase font-black italic mt-2"><?= $done_cnt ?> feitos · <?= $skip_cnt + $overdue_cnt ?> falhados</p>
                </div>
                <div class="glass-card p-6 rounded-[35px]">
                    <p class="text-[9px] font-black text-white/30 uppercase italic tracking-widest mb-2">KM Reais</p>
                    <p class="text-4xl font-headline font-black italic leading-none"><?= number_format($total_done_km, 1) ?></p>
                    <p class="text-[8px] text-white/20 uppercase font-black italic mt-2">Streak pessoal: <?= $personal_streak ?> 🔥</p>
                </div>
            </div>

            <div class="glass-card p-6 rounded-[35px] space-y-4">
                <div class="flex justify-between items-center">
                    <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Volume Semanal</p>
                    <div class="flex gap-4 text-[8px] font-black uppercase italic">
                        <span class="text-white/30">▪ Plano</span>
                        <span class="text-faf-neon">▪ Real</span>
                    </div>
                </div>
                <div class="flex items-end gap-1.5 h-28">
                    <?php foreach($cycle_weeks as $cw_row):
                        $wk = (int)$cw_row['week_number'];
                        $plan_h = max(4, round(((float)$cw_row['planned_km'] / $max_week_km) * 100));
                        $done_h = max(0, round(((float)$cw_row['done_km'] / $max_week_km) * 100));
                    ?>
                    <div onclick="window.location.href='?week=<?= $wk ?>'" class="flex-1 h-full flex items-end justify-center relative cursor-pointer group">
                        <div class="absolute bottom-0 w-full rounded-t-md bg-white/10 group-hover:bg-white/15 transition-colors" style="height: <?= $plan_h ?>%"></div>
                        <?php if($done_h > 0): ?><div class="absolute bottom-0 w-full rounded-t-md bg-faf-neon/80" style="height: <?= $done_h ?>%"></div><?php endif; ?>
                        <?php if($wk == $current_week): ?><div class="absolute -bottom-1 w-1.5 h-1.5 rounded-full bg-faf-neon shadow-[0_0_6px_#c3f400]"></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if(!empty(array_filter($cycle_weeks, fn($r) => !empty($r['phase'])))): ?>
            <div class="glass-card p-6 rounded-[35px] space-y-4">
                <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Ciclo de Periodização</p>
                <div class="flex gap-1 h-3 rounded-full overflow-hidden">
                    <?php foreach($cycle_weeks as $cw_row):
                        $pc = phaseColor($cw_row['phase']);
                        $op = ((int)$cw_row['week_number'] <= $current_week) ? '' : '55';
                    ?>
                    <div class="flex-1 <?= (int)$cw_row['week_number'] == $current_week ? 'ring-1 ring-white' : '' ?>" style="background: <?= $pc . ($op ? '' : '') ?>; opacity: <?= (int)$cw_row['week_number'] <= $current_week ? '1' : '0.3' ?>;"></div>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-between text-[8px] font-black uppercase italic tracking-widest">
                    <span style="color: #38bdf8">Base</span>
                    <span style="color: #c3f400">Build</span>
                    <span style="color: #f97316">Peak</span>
                    <span style="color: #a78bfa">Taper</span>
                </div>
                <?php if($week_is_deload): ?>
                <p class="text-[9px] text-sky-300 italic font-bold">⚡ Semana atual: DELOAD — o volume desce de propósito para o corpo absorver o treino.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div id="club" class="tab-content space-y-6">
            <div class="flex justify-between items-center pt-4"><h2 class="text-4xl font-headline font-black italic uppercase tracking-tighter leading-none">Syndicate</h2><button onclick="toggleSearch()" class="w-12 h-12 rounded-full bg-faf-neon text-black flex items-center justify-center active:scale-90 shadow-lg"><span class="material-symbols-outlined font-black">person_add</span></button></div>
            <div class="flex gap-8 border-b border-white/5"><button onclick="toggleClubSubTab('syndicate')" id="btn-club-syn" class="pb-3 text-xs font-black uppercase italic tracking-tighter text-faf-neon border-b-2 border-faf-neon">Friends</button><button onclick="toggleClubSubTab('circle')" id="btn-club-cir" class="pb-3 text-xs font-black uppercase italic tracking-tighter text-white/30">The Circle</button><button onclick="toggleClubSubTab('ranks')" id="btn-club-rnk" class="pb-3 text-xs font-black uppercase italic tracking-tighter text-white/30">Ranks</button></div>

            <div id="club-syndicate-hub" class="space-y-4">
                <?php foreach($my_friends as $f): ?>
                <div class="glass-card p-5 rounded-[35px] flex items-center gap-5 border-l-4 border-faf-neon">
                    <img src="<?= avatar($f['profile_pic'], $f['name']) ?>" class="w-14 h-14 rounded-full border border-faf-neon/30 p-1 object-cover" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                    <div class="flex-1"><p class="text-lg font-black italic uppercase leading-none"><?= e($f['name']) ?></p><p class="text-[10px] text-faf-neon mt-1 font-bold italic uppercase tracking-widest">ATHLETE SYNCED</p></div>
                </div>
                <?php endforeach; if(empty($my_friends)) echo "<p class='text-[10px] text-white/20 text-center py-10 italic'>No allies found.</p>"; ?>
            </div>

            <div id="club-circle-hub" class="hidden space-y-6">
                <?php if(!empty($userData['circle_id'])): ?>
                    <div class="bg-faf-neon p-7 rounded-[45px] text-black shadow-2xl flex justify-between items-center">
                        <div>
                            <h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter"><?= e($circle_name) ?></h3>
                            <p class="text-[9px] font-black uppercase tracking-widest opacity-60">Clan Sync Active</p>
                            <p class="text-[10px] font-black uppercase tracking-widest mt-1">ID de convite: #<?= $userData['circle_id'] ?></p>
                        </div>
                        <div class="text-center text-3xl"><?= $fire['emoji'] ?> <span class="block text-xl font-black"><?= $streak ?></span><span class="block text-[8px] font-black uppercase tracking-widest"><?= $fire['label'] ?></span></div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <button onclick="shareRecruit()" class="py-4 border border-faf-neon/40 text-faf-neon rounded-2xl text-[10px] font-black uppercase italic flex items-center justify-center gap-2 active:scale-95 transition-all">
                            <span class="material-symbols-outlined text-sm">person_add</span> Recrutar
                        </button>
                        <button onclick="shareCircleCard()" class="py-4 bg-faf-neon text-black rounded-2xl text-[10px] font-black uppercase italic flex items-center justify-center gap-2 active:scale-95 transition-all">
                            <span class="material-symbols-outlined text-sm">ios_share</span> Mostrar Fogo
                        </button>
                    </div>

                    <div class="glass-card rounded-[35px] p-6 space-y-4">
                        <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Clan Leaderboard</p>
                        <?php foreach($clan_members as $idx => $m): ?>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-black text-faf-neon"><?= str_pad($idx+1, 2, '0', STR_PAD_LEFT) ?></span>
                                <img src="<?= avatar($m['profile_pic'], $m['name']) ?>" class="w-6 h-6 rounded-full object-cover" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                                <p class="text-xs font-black italic uppercase"><?= e($m['name']) ?></p>
                            </div>
                            <span class="text-xs font-black italic">ACTIVE</span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="glass-card rounded-[35px] p-6 space-y-4">
                        <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Circle Feed</p>
                        <div id="circle-feed-list" class="space-y-3 max-h-[240px] overflow-y-auto"></div>
                        <div class="flex gap-2 pt-2 border-t border-white/5">
                            <input id="circle-feed-input" type="text" placeholder="Fala com o Circle..." class="flex-1 bg-white/5 border border-white/10 rounded-2xl p-3 text-xs text-white outline-none">
                            <button onclick="sendCircleMessage()" class="w-10 h-10 rounded-2xl bg-faf-neon text-black flex items-center justify-center"><span class="material-symbols-outlined text-sm font-black">send</span></button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center pt-4 pb-2">
                        <div class="text-5xl mb-3">🔥</div>
                        <h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter leading-none mb-2">O Fogo Espera</h3>
                        <p class="text-[10px] text-white/40 italic leading-relaxed px-6">Um Circle é o teu clã de treino: se todos cumprirem os treinos do dia, o fogo cresce. Se alguém falhar... apaga-se. E toda a gente vê.</p>
                    </div>
                    <div onclick="openCircleEstablishModal()" class="glass-card p-6 rounded-[35px] flex items-center gap-5 cursor-pointer border-l-4 border-faf-neon active:scale-95 transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-faf-neon flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-black font-black text-2xl">add_circle</span></div>
                        <div class="flex-1 text-left">
                            <p class="text-base font-black italic uppercase leading-none">Fundar um Circle</p>
                            <p class="text-[9px] text-white/40 mt-1.5 italic">Cria a tua unidade e recruta os teus aliados</p>
                        </div>
                        <span class="material-symbols-outlined text-white/20">chevron_right</span>
                    </div>
                    <div onclick="openCircleJoinModal()" class="glass-card p-6 rounded-[35px] flex items-center gap-5 cursor-pointer border-l-4 border-white/10 active:scale-95 transition-all">
                        <div class="w-14 h-14 rounded-2xl bg-white/5 border border-faf-neon/30 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-faf-neon text-2xl">key</span></div>
                        <div class="flex-1 text-left">
                            <p class="text-base font-black italic uppercase leading-none">Juntar-me a um Circle</p>
                            <p class="text-[9px] text-white/40 mt-1.5 italic">Tens um ID de convite? Entra na unidade</p>
                        </div>
                        <span class="material-symbols-outlined text-white/20">chevron_right</span>
                    </div>
                <?php endif; ?>
            </div>

            <div id="club-ranks-hub" class="hidden space-y-6">
                <div class="glass-card rounded-[35px] p-6 space-y-4">
                    <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">🔥 Circles em Chamas</p>
                    <?php foreach($top_circles as $idx => $tc): $tf = fireTier((int)$tc['streak_count']); ?>
                    <div class="flex justify-between items-center <?= !empty($userData['circle_id']) && (int)$tc['id'] === (int)$userData['circle_id'] ? 'bg-faf-neon/10 -mx-3 px-3 py-1.5 rounded-xl' : '' ?>">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-black <?= $idx < 3 ? 'text-faf-neon' : 'text-white/30' ?>"><?= str_pad($idx+1, 2, '0', STR_PAD_LEFT) ?></span>
                            <div>
                                <p class="text-xs font-black italic uppercase leading-none"><?= e($tc['name']) ?></p>
                                <p class="text-[8px] text-white/30 font-black uppercase mt-0.5"><?= $tc['members'] ?> atletas</p>
                            </div>
                        </div>
                        <span class="text-xs font-black italic"><?= $tf['emoji'] ?> <?= $tc['streak_count'] ?></span>
                    </div>
                    <?php endforeach; if(empty($top_circles)) echo "<p class='text-[10px] text-white/20 text-center py-6 italic'>Ainda não há circles. Funda o primeiro.</p>"; ?>
                </div>

                <div class="glass-card rounded-[35px] p-6 space-y-4">
                    <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">🏃 Atletas · KM (30 dias)</p>
                    <?php foreach($top_athletes as $idx => $ta): ?>
                    <div class="flex justify-between items-center <?= (int)$ta['id'] === $user_id ? 'bg-faf-neon/10 -mx-3 px-3 py-1.5 rounded-xl' : '' ?>">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-black <?= $idx < 3 ? 'text-faf-neon' : 'text-white/30' ?>"><?= str_pad($idx+1, 2, '0', STR_PAD_LEFT) ?></span>
                            <img src="<?= avatar($ta['profile_pic'], $ta['name']) ?>" class="w-7 h-7 rounded-full object-cover" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                            <p class="text-xs font-black italic uppercase"><?= e($ta['name']) ?></p>
                        </div>
                        <span class="text-xs font-black italic text-faf-neon"><?= $ta['km'] ?> km</span>
                    </div>
                    <?php endforeach; if(empty($top_athletes)) echo "<p class='text-[10px] text-white/20 text-center py-6 italic'>Sem atividade nos últimos 30 dias.</p>"; ?>
                </div>
            </div>
        </div>

        <div id="profile" class="tab-content space-y-8 text-center pt-8">
            <div class="relative w-32 h-32 mx-auto"><img src="<?= $userPic ?>" class="w-full h-full rounded-full border-4 border-faf-neon p-1 bg-zinc-900 shadow-2xl object-cover" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'"></div>
            <div>
                <h3 class="text-3xl font-headline font-black italic uppercase italic tracking-tighter"><?= e($userName) ?></h3>
                <p class="text-[10px] font-black text-faf-neon uppercase tracking-[0.3em] italic mt-1">Athlete ID: #<?= $user_id ?></p>
            </div>
            <div class="grid grid-cols-3 gap-3 px-4">
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">Peso</p><p class="text-xl font-black italic"><?= $userData['weight'] ?? '--' ?>kg</p></div>
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">Idade</p><p class="text-xl font-black italic"><?= $userData['age'] ?? '--' ?></p></div>
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">VDOT</p><p class="text-xl font-black italic text-faf-neon"><?= $vdot ?></p></div>
            </div>

            <?php if($race_days_left !== null): ?>
            <div class="mx-4 bg-faf-neon rounded-[35px] p-6 text-black text-left flex justify-between items-center shadow-[0_0_30px_rgba(195,244,0,0.15)]">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest opacity-60 mb-1">A Missão</p>
                    <h4 class="text-lg font-headline font-black italic uppercase tracking-tighter leading-none"><?= e($userData['race_name'] ?? $target_label) ?></h4>
                    <p class="text-[9px] font-black uppercase mt-1 opacity-60"><?= date('d M Y', strtotime($userData['race_date'])) ?> · <?= $userData['target_distance'] ?>km</p>
                </div>
                <div class="text-center">
                    <p class="text-4xl font-headline font-black italic leading-none"><?= $race_days_left ?></p>
                    <p class="text-[8px] font-black uppercase tracking-widest">dias</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Badges: conquistas ganhas brilham, as restantes ficam a desbloquear -->
            <div class="mx-4 glass-card rounded-[35px] p-6 text-left space-y-4">
                <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Conquistas · <?= count($my_badges) ?>/<?= count(BADGE_DEFS) ?></p>
                <div class="grid grid-cols-4 gap-3">
                    <?php foreach(BADGE_DEFS as $code => $b): $has = isset($my_badges[$code]); ?>
                    <div class="text-center <?= $has ? '' : 'opacity-25 grayscale' ?>" title="<?= e($b['name'] . ' — ' . $b['desc']) ?>">
                        <div class="text-3xl mb-1 <?= $has ? 'drop-shadow-[0_0_8px_rgba(195,244,0,0.4)]' : '' ?>"><?= $b['emoji'] ?></div>
                        <p class="text-[7px] font-black uppercase tracking-tight leading-tight <?= $has ? 'text-faf-neon' : 'text-white/40' ?>"><?= $b['name'] ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Notificações push -->
            <div class="mx-4 glass-card rounded-[35px] p-6 text-left flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic mb-1">Lembretes de Treino</p>
                    <p class="text-[9px] text-white/40 italic leading-relaxed">Recebe uma notificação no dia de cada treino.</p>
                </div>
                <button id="btn-push" onclick="togglePush()" class="px-5 py-3 rounded-2xl text-[9px] font-black uppercase italic border border-faf-neon/40 text-faf-neon active:scale-95 transition-all whitespace-nowrap disabled:opacity-50">Ativar</button>
            </div>

            <div class="mt-8 px-4 space-y-4 text-left">
                <div class="glass-card p-6 rounded-[40px] border-l-4 border-red-600/50">
                    <p class="text-[10px] font-black uppercase text-red-500 mb-1 tracking-widest">Danger Zone</p>
                    <h4 class="text-xl font-headline font-black italic uppercase tracking-tighter mb-4">Protocol Actions</h4>
                    
                    <button onclick="openAbortModal()" class="w-full py-4 bg-red-600/10 border border-red-600/20 rounded-2xl text-[10px] font-black uppercase italic tracking-widest text-red-500 mb-4 active:bg-red-600 active:text-white transition-all">Incinerar Plano Atual</button>
                    
                    <a href="logout.php" class="flex items-center justify-center w-full py-4 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase italic tracking-widest text-white/50 active:bg-white active:text-black transition-all">
                        <span class="material-symbols-outlined mr-2 text-sm">logout</span> Terminar Sessão
                    </a>
                </div>
            </div>
        </div>
    </main>

    <div id="coach-overlay">
        <div id="coach-modal-chat">
            <header class="p-6 border-b border-white/5 flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-3">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=CoachK" class="w-10 h-10 rounded-full border border-faf-neon/30">
                    <div><h3 class="font-headline font-black italic uppercase text-faf-neon text-sm">Coach Neural</h3><span class="text-[8px] text-white/30 uppercase font-black">Bio-Sincronização Ativa</span></div>
                </div>
                <button onclick="closeCoachChat()" class="w-8 h-8 rounded-full bg-white/5 flex items-center justify-center text-white/40"><span class="material-symbols-outlined text-sm">close</span></button>
            </header>
            <div id="chat-messages" class="flex-1 overflow-y-auto p-6 space-y-4 flex flex-col"><div class="coach-bubble italic">Boas, <?= $first_name ?>. Como te posso ajudar?</div></div>
            <div class="p-6 bg-black">
                <div class="glass-card p-1.5 rounded-[28px] flex items-center gap-2 border-faf-neon/20 focus-within:border-faf-neon transition-all">
                    <input id="chat-input" type="text" placeholder="Reportar treino..." class="flex-1 bg-transparent border-none focus:ring-0 text-sm p-3 text-white">
                    <button onclick="sendMessage()" class="w-11 h-11 rounded-2xl bg-faf-neon text-black flex items-center justify-center"><span class="material-symbols-outlined font-black">send</span></button>
                </div>
            </div>
        </div>
    </div>

    <div id="neural-inbox">
        <div class="glass-card p-8 rounded-[40px] max-w-sm w-full space-y-6">
            <div class="flex justify-between items-center"><p class="text-[10px] font-black uppercase text-faf-neon italic tracking-widest">Neural Inbox</p><span onclick="toggleInbox()" class="material-symbols-outlined text-white/20 cursor-pointer">close</span></div>
            <div class="space-y-4 max-h-[300px] overflow-y-auto">
                <?php while($req = $notifications->fetch_assoc()): ?>
                <div class="flex items-center justify-between bg-white/5 p-4 rounded-2xl border-l-2 border-faf-neon">
                    <p class="text-xs font-black italic uppercase"><?= e($req['name']) ?></p>
                    <div class="flex gap-2">
                        <button onclick="handleFriend('accept', <?= $req['athlete_id'] ?>)" class="bg-faf-neon text-black p-2 rounded-xl"><span class="material-symbols-outlined text-sm font-black">done</span></button>
                    </div>
                </div>
                <?php endwhile; if($notif_count == 0) echo "<p class='text-[10px] text-white/20 text-center py-10 italic'>No requests.</p>"; ?>
            </div>
        </div>
    </div>

    <div id="generic-modal">
        <div class="glass-card p-8 rounded-[40px] max-w-sm w-full space-y-6 text-center">
            <h3 id="gmodal-title" class="text-xl font-headline font-black italic uppercase text-faf-neon">Neural Setup</h3>
            <div id="gmodal-input-container" class="hidden">
                <input type="text" id="gmodal-field" placeholder="..." class="w-full bg-white/5 p-4 text-white font-black italic outline-none rounded-2xl border border-white/10">
            </div>
            <p id="gmodal-body" class="text-xs text-white/60 italic"></p>
            <div class="flex gap-3">
                <button id="gmodal-cancel" class="flex-1 py-4 bg-white/5 rounded-2xl text-[10px] font-black uppercase italic">Abort</button>
                <button id="gmodal-confirm" class="flex-1 py-4 bg-faf-neon text-black rounded-2xl text-[10px] font-black uppercase italic">Execute</button>
            </div>
        </div>
    </div>

    <div id="search-overlay">
        <div class="glass-card p-8 rounded-[45px] max-w-sm w-full space-y-6">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-headline font-black italic uppercase text-faf-neon tracking-tighter leading-none">Sync Unit</h2>
                    <p class="text-[9px] text-white/30 uppercase font-black tracking-widest mt-1 italic">Adiciona um atleta pelo ID</p>
                </div>
                <button onclick="toggleSearch()" class="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center text-white/40 cursor-pointer" aria-label="Fechar"><span class="material-symbols-outlined text-sm">close</span></button>
            </div>

            <div class="bg-white/5 border border-white/10 rounded-2xl p-4 flex items-center gap-3">
                <span class="material-symbols-outlined text-faf-neon/50">fingerprint</span>
                <input type="number" inputmode="numeric" id="sid" placeholder="Athlete ID (ex: 12)" class="flex-1 bg-transparent text-white font-black italic outline-none text-lg" onkeypress="if(event.key==='Enter') identifyAthlete()">
            </div>
            <p class="text-[9px] text-white/25 italic leading-relaxed">O teu ID é <span class="text-faf-neon font-black">#<?= $user_id ?></span> — partilha-o com os teus amigos para eles te encontrarem.</p>

            <div id="search-result"></div>

            <button id="btn-identify" onclick="identifyAthlete()" class="w-full py-5 bg-faf-neon text-black rounded-3xl font-black uppercase italic shadow-lg active:scale-95 transition-all disabled:opacity-50">Identificar</button>
        </div>
    </div>

    <div id="abort-modal">
        <div class="glass-card p-10 rounded-[50px] border border-red-600/30 max-w-sm w-full text-center space-y-8">
            <span class="material-symbols-outlined text-red-600 text-6xl">warning</span>
            <div><h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter text-white mb-2">Neural Reset</h3><p class="text-xs text-white/40 italic">Confirmas a destruição total do protocolo atual? Esta ação é irreversível.</p></div>
            <div class="space-y-3">
                <button onclick="window.location.href='../src/api/abort_engine.php'" class="w-full py-5 bg-red-600 text-white rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg">Confirmar Incineração</button>
                <button onclick="closeAbortModal()" class="w-full py-5 bg-white/5 text-white/40 rounded-2xl font-black uppercase italic text-xs tracking-widest">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="feedback-modal">
        <div class="glass-card p-8 rounded-[50px] border border-faf-neon/20 max-w-sm w-full space-y-6">
            <input type="hidden" id="modal_workout_id">
            <div class="flex justify-between items-center"><h3 class="text-xl font-headline font-black italic uppercase italic text-faf-neon">Workout Feedback</h3><span onclick="closeCheckIn()" class="material-symbols-outlined text-white/20 cursor-pointer">close</span></div>
            <select id="workout_status" onchange="toggleFeedbackFields()" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black uppercase italic outline-none text-white"><option value="completed">TREINO CONCLUÍDO</option><option value="skipped">NÃO CONSEGUI FAZER</option></select>
            <div id="feedback_fields" class="space-y-4">
                <div class="grid grid-cols-2 gap-3"><input type="number" step="0.01" id="modal_real_dist" class="bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none"><input type="text" id="modal_real_pace" placeholder="5:30" class="bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none"></div>
                <div class="grid grid-cols-3 gap-2">
                    <label class="cursor-pointer"><input type="radio" name="effort_level" value="easy" class="hidden peer"><div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-green-500/20 peer-checked:border-green-500 transition-all uppercase">Easy</div></label>
                    <label class="cursor-pointer"><input type="radio" name="effort_level" value="perfect" checked class="hidden peer"><div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-faf-neon/20 peer-checked:border-faf-neon transition-all uppercase text-faf-neon">Perfect</div></label>
                    <label class="cursor-pointer"><input type="radio" name="effort_level" value="hard" class="hidden peer"><div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-red-500/20 peer-checked:border-red-500 transition-all uppercase text-red-500">Hard</div></label>
                </div>
            </div>
            <button id="btn-submit-feedback" onclick="submitWorkoutFeedback()" class="w-full py-4 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg transition-opacity disabled:opacity-50">Sincronizar</button>
        </div>
    </div>

    <!-- Modal de sucesso pós-feedback: fecha o loop com o atleta -->
    <div id="success-modal">
        <div class="glass-card p-10 rounded-[50px] border border-faf-neon/20 max-w-sm w-full text-center space-y-6">
            <div id="success-icon" class="text-6xl">✅</div>
            <div>
                <h3 id="success-title" class="text-2xl font-headline font-black italic uppercase tracking-tighter mb-2">Treino Sincronizado</h3>
                <p id="success-body" class="text-xs text-white/50 italic leading-relaxed"></p>
            </div>
            <div id="success-badges" class="hidden space-y-2"></div>
            <button onclick="location.reload()" class="w-full py-5 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg active:scale-95 transition-all">Continuar</button>
        </div>
    </div>

    <?php if($last_week_recap): ?>
    <!-- Recap da semana terminada (mostrado 1x por semana, controlado por localStorage) -->
    <div id="recap-modal">
        <div class="glass-card p-8 rounded-[50px] border border-faf-neon/20 max-w-sm w-full text-center space-y-6">
            <p class="text-[9px] font-black uppercase tracking-[0.3em] text-faf-neon italic">Weekly Recap</p>
            <h3 class="text-3xl font-headline font-black italic uppercase tracking-tighter leading-none">Semana <?= $last_week_recap['week'] ?><br>Fechada</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-white/5 rounded-3xl p-5">
                    <p class="text-3xl font-headline font-black italic text-faf-neon leading-none"><?= number_format($last_week_recap['done_km'], 1) ?></p>
                    <p class="text-[8px] font-black uppercase tracking-widest text-white/40 mt-1">km reais / <?= number_format($last_week_recap['planned_km'], 1) ?> plano</p>
                </div>
                <div class="bg-white/5 rounded-3xl p-5">
                    <p class="text-3xl font-headline font-black italic leading-none <?= $last_week_recap['consistency'] >= 80 ? 'text-faf-neon' : 'text-orange-400' ?>"><?= $last_week_recap['consistency'] ?>%</p>
                    <p class="text-[8px] font-black uppercase tracking-widest text-white/40 mt-1">consistência</p>
                </div>
            </div>
            <p class="text-xs text-white/50 italic leading-relaxed">"<?= $last_week_recap['coach'] ?>"</p>
            <button onclick="closeRecap()" class="w-full py-5 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg active:scale-95 transition-all">Próxima semana 💪</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Briefing do protocolo: mostrado após o onboarding, reabrível pelo ícone ? -->
    <div id="briefing-modal">
        <div class="briefing-card glass-card p-8 rounded-[45px] max-w-sm w-full space-y-6">
            <div class="text-center">
                <p class="text-[9px] font-black uppercase tracking-[0.3em] text-faf-neon italic mb-2">Protocol Briefing</p>
                <h3 class="text-3xl font-headline font-black italic uppercase tracking-tighter leading-none">Como funciona<br>o teu plano</h3>
            </div>

            <div class="space-y-3">
                <p class="text-[9px] font-black uppercase text-white/30 tracking-widest italic">1 · Periodização</p>
                <div class="flex gap-1 h-2.5 rounded-full overflow-hidden">
                    <div class="flex-[4]" style="background:#38bdf8"></div>
                    <div class="flex-[4]" style="background:#c3f400"></div>
                    <div class="flex-[2]" style="background:#f97316"></div>
                    <div class="flex-[2]" style="background:#a78bfa"></div>
                </div>
                <p class="text-[10px] text-white/50 italic leading-relaxed"><span style="color:#38bdf8" class="font-black">BASE</span> constrói resistência, <span style="color:#c3f400" class="font-black">BUILD</span> adiciona intensidade, <span style="color:#f97316" class="font-black">PEAK</span> é o máximo de carga e <span style="color:#a78bfa" class="font-black">TAPER</span> reduz volume para chegares fresco à prova. A cada 4 semanas há uma <span class="text-sky-300 font-black">DELOAD</span>: o volume desce de propósito — é aí que o corpo absorve o treino.</p>
            </div>

            <div class="space-y-3">
                <p class="text-[9px] font-black uppercase text-white/30 tracking-widest italic">2 · Tipos de treino</p>
                <div class="space-y-2 text-[10px] italic leading-relaxed">
                    <p><span class="text-faf-neon font-black uppercase">Longão</span> <span class="text-white/50">— o treino mais longo da semana, ritmo confortável. Constrói o motor.</span></p>
                    <p><span class="text-faf-neon font-black uppercase">Rodagem Easy</span> <span class="text-white/50">— corrida leve de recuperação. Devagar é o objetivo, não um defeito.</span></p>
                    <p><span class="text-faf-neon font-black uppercase">Tempo Run</span> <span class="text-white/50">— ritmo "desconfortavelmente sustentável". Treina o limiar.</span></p>
                    <p><span class="text-faf-neon font-black uppercase">Intervalado</span> <span class="text-white/50">— séries rápidas com recuperação. Sobe o VO2max.</span></p>
                    <p><span class="text-faf-neon font-black uppercase">Fartlek</span> <span class="text-white/50">— jogo de velocidade livre: acelera e recupera sem parar.</span></p>
                    <p><span class="text-faf-neon font-black uppercase">Galloway</span> <span class="text-white/50">— alternar corrida/caminhada. A forma inteligente de começar do zero.</span></p>
                </div>
            </div>

            <div class="space-y-3">
                <p class="text-[9px] font-black uppercase text-white/30 tracking-widest italic">3 · O plano adapta-se a ti</p>
                <p class="text-[10px] text-white/50 italic leading-relaxed">Depois de cada treino diz-nos como te sentiste: <span class="text-green-400 font-black">EASY</span>, <span class="text-faf-neon font-black">PERFECT</span> ou <span class="text-red-400 font-black">HARD</span>. Se 2 treinos seguidos saírem do esperado, o Neural Engine <span class="text-white font-black">recalcula automaticamente os paces de todos os treinos futuros</span> — mais rápidos se estás a voar, mais suaves se estás a sofrer. O plano nunca fica parado.</p>
            </div>

            <button onclick="closeBriefing()" class="w-full py-5 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg active:scale-95 transition-all">Entendido. Bora treinar 🔥</button>
        </div>
    </div>

    <nav class="fixed bottom-0 left-0 w-full p-6 pb-[var(--safe-bottom)] z-[400] bg-gradient-to-t from-black via-black/80 to-transparent">
        <div class="bg-black/95 backdrop-blur-3xl rounded-[35px] border border-white/10 p-2 flex justify-around items-center shadow-2xl max-w-sm mx-auto">
            <button onclick="switchTab('home')" id="btn-home" class="nav-active flex flex-col items-center justify-center w-14 h-14 text-white/30"><span class="material-symbols-outlined text-2xl">directions_run</span></button>
            <button onclick="switchTab('insights')" id="btn-insights" class="flex flex-col items-center justify-center w-14 h-14 text-white/30"><span class="material-symbols-outlined text-2xl">analytics</span></button>
            <button onclick="switchTab('club')" id="btn-club" class="flex flex-col items-center justify-center w-14 h-14 text-white/30"><span class="material-symbols-outlined text-2xl">groups</span></button>
            <button onclick="switchTab('profile')" id="btn-profile" class="flex flex-col items-center justify-center w-14 h-14 text-white/30"><span class="material-symbols-outlined text-2xl">person</span></button>
        </div>
    </nav>

    <script>
        // COACH LOGIC
        function openCoachChat() { document.getElementById('coach-overlay').classList.add('active'); setTimeout(() => document.getElementById('chat-input').focus(), 400); }
        function closeCoachChat() { document.getElementById('coach-overlay').classList.remove('active'); }
        async function sendMessage() {
            const input = document.getElementById('chat-input');
            const container = document.getElementById('chat-messages');
            if (!input.value.trim()) return;
            const msg = input.value; input.value = '';
            const userDiv = document.createElement('div'); userDiv.className = 'user-bubble'; userDiv.innerText = msg; container.appendChild(userDiv);
            const loading = document.createElement('div'); loading.className = 'coach-bubble opacity-50'; loading.innerText = 'Neural Process...'; container.appendChild(loading);
            try {
                const response = await fetch('../src/engines/ai_engine.php', { method: 'POST', body: JSON.stringify({ message: msg }) });
                const data = await response.json(); container.removeChild(loading);
                const coachDiv = document.createElement('div'); coachDiv.className = 'coach-bubble italic'; coachDiv.innerText = data.reply;
                container.appendChild(coachDiv); container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
            } catch (e) { loading.innerText = "Error."; }
        }

        // NAVIGATION LOGIC
        function changeWeek(delta) {
            const current = <?= $current_week ?>;
            const total = <?= $total_cycle_weeks ?>;
            const target = current + delta;
            if(target >= 1 && target <= total) { window.location.href = `?week=${target}`; }
        }

        function focusDay(day) {
            document.querySelectorAll('.day-item').forEach(el => el.classList.remove('selected', 'opacity-100'));
            const target = document.querySelector(`.day-item[data-day="${day}"]`);
            if(target) target.classList.add('selected');
            document.querySelectorAll('.workout-card').forEach(card => { card.classList.remove('focused'); card.classList.add('minimized'); });
            const selectedCard = document.getElementById(`card-${day}`);
            if(selectedCard) { selectedCard.classList.add('focused'); selectedCard.classList.remove('minimized'); document.getElementById('app-main').scrollTo({ top: selectedCard.offsetTop - 15, behavior: 'smooth' }); }
        }

        function switchTab(id) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('nav button').forEach(b => b.classList.remove('nav-active'));
            document.getElementById(id).classList.add('active');
            document.getElementById('btn-' + id).classList.add('nav-active');
            document.getElementById('run-header-extras').style.display = (id === 'home') ? 'block' : 'none';
        }

        // SWIPE LOGIC
        let touchstartX = 0;
        const swipeArea = document.getElementById('swipe-area');
        swipeArea.addEventListener('touchstart', e => { touchstartX = e.changedTouches[0].screenX; }, {passive: true});
        swipeArea.addEventListener('touchend', e => { 
            const dist = e.changedTouches[0].screenX - touchstartX;
            if (dist < -80) changeWeek(1);
            if (dist > 80) changeWeek(-1);
        }, {passive: true});

        // AJAX FEEDBACK — mostra o resultado (incluindo recalibração do plano) antes do reload
        async function submitWorkoutFeedback() {
            const btn = document.getElementById('btn-submit-feedback');
            btn.disabled = true; btn.innerText = 'A sincronizar...';
            const status = document.getElementById('workout_status').value;
            const data = {
                id: document.getElementById('modal_workout_id').value,
                status: status,
                dist: document.getElementById('modal_real_dist').value,
                pace: document.getElementById('modal_real_pace').value,
                effort: document.querySelector('input[name="effort_level"]:checked').value
            };
            let result = { adapted: false };
            try {
                const res = await fetch('../src/api/checkin_engine.php', { method: 'POST', body: JSON.stringify(data) });
                result = await res.json();
            } catch (e) { location.reload(); return; }

            document.getElementById('feedback-modal').style.display = 'none';
            const icon = document.getElementById('success-icon');
            const title = document.getElementById('success-title');
            const body = document.getElementById('success-body');

            if (result.adapted) {
                icon.innerText = '🧠';
                title.innerText = 'Plano Recalibrado';
                body.innerText = result.direction === 'faster'
                    ? 'Estás consistentemente acima do esperado. O Neural Engine acelerou os paces de todos os treinos futuros. Novo nível desbloqueado.'
                    : 'Detetámos esforço elevado em treinos seguidos. O Neural Engine suavizou os paces futuros para protegeres o corpo. Recuperar também é treinar.';
            } else if (status === 'completed') {
                icon.innerText = '✅';
                title.innerText = 'Treino Sincronizado';
                body.innerText = 'Registado. O motor está a monitorizar a tua tendência de esforço — se 2 treinos seguidos saírem do esperado, os paces futuros são recalculados automaticamente.';
            } else {
                icon.innerText = '🕯️';
                title.innerText = 'Treino Falhado';
                body.innerText = 'Acontece. O importante é voltar no próximo. Se estiveres num Circle, o fogo do clã ressente-se — os teus aliados vão saber.';
            }

            // Badges desbloqueados neste check-in
            const badgeBox = document.getElementById('success-badges');
            if (result.badges && result.badges.length > 0) {
                badgeBox.innerHTML = result.badges.map(b =>
                    `<div class="bg-faf-neon/10 border border-faf-neon/40 rounded-2xl p-4 flex items-center gap-4 text-left">
                        <span class="text-4xl">${escapeHtml(b.emoji)}</span>
                        <div>
                            <p class="text-xs font-black italic uppercase text-faf-neon leading-none">Badge desbloqueado: ${escapeHtml(b.name)}</p>
                            <p class="text-[9px] text-white/40 italic mt-1">${escapeHtml(b.desc)}</p>
                        </div>
                    </div>`).join('');
                badgeBox.classList.remove('hidden');
            } else {
                badgeBox.classList.add('hidden');
            }
            document.getElementById('success-modal').style.display = 'flex';
        }

        const syncOrder = async () => {
            const days = Array.from(document.querySelectorAll('.day-item')).map(i => i.getAttribute('data-day'));
            await fetch('../src/api/reorder_engine.php', { method: 'POST', body: JSON.stringify({week: <?= $current_week ?>, days_order: days}) });
            location.reload();
        };

        function openAbortModal() { document.getElementById('abort-modal').style.display = 'flex'; }
        function closeAbortModal() { document.getElementById('abort-modal').style.display = 'none'; }
        function openCheckIn(id, type, dist) { document.getElementById('modal_workout_id').value = id; document.getElementById('modal_real_dist').value = dist; document.getElementById('feedback-modal').style.display = 'flex'; }
        function closeCheckIn() { document.getElementById('feedback-modal').style.display = 'none'; }
        function toggleFeedbackFields() { document.getElementById('feedback_fields').style.display = (document.getElementById('workout_status').value === 'completed') ? 'block' : 'none'; }

        // --- SOCIAL: Syndicate / Circle ---
        function toggleInbox() { const el = document.getElementById('neural-inbox'); el.style.display = (el.style.display === 'flex') ? 'none' : 'flex'; }
        function toggleSearch() { const el = document.getElementById('search-overlay'); el.style.display = (el.style.display === 'flex') ? 'none' : 'flex'; }

        function toggleClubSubTab(sub) {
            const tabs = { syndicate: 'club-syndicate-hub', circle: 'club-circle-hub', ranks: 'club-ranks-hub' };
            const btns = { syndicate: 'btn-club-syn', circle: 'btn-club-cir', ranks: 'btn-club-rnk' };
            for (const key in tabs) {
                document.getElementById(tabs[key]).classList.toggle('hidden', key !== sub);
                document.getElementById(btns[key]).className = key === sub
                    ? "pb-3 text-xs font-black uppercase italic text-faf-neon border-b-2 border-faf-neon"
                    : "pb-3 text-xs font-black uppercase italic text-white/30";
            }
            if (sub === 'circle') loadCircleFeed();
        }

        function showNeuralModal(title, body, isInput, onConfirm) {
            const m = document.getElementById('generic-modal');
            const inputContainer = document.getElementById('gmodal-input-container');
            document.getElementById('gmodal-title').innerText = title;
            document.getElementById('gmodal-body').innerText = body;
            inputContainer.classList.toggle('hidden', !isInput);
            m.style.display = 'flex';
            const confirmBtn = document.getElementById('gmodal-confirm');
            confirmBtn.disabled = false; confirmBtn.innerText = 'Execute'; confirmBtn.classList.remove('disabled:opacity-50');
            confirmBtn.onclick = async () => {
                const val = document.getElementById('gmodal-field').value;
                confirmBtn.disabled = true; confirmBtn.innerText = '...'; confirmBtn.classList.add('disabled:opacity-50');
                await onConfirm(val);
                m.style.display = 'none';
            };
            document.getElementById('gmodal-cancel').onclick = () => m.style.display = 'none';
        }

        // Pesquisa de atleta com resultado inline no próprio overlay (sem alerts)
        async function identifyAthlete() {
            const athleteId = document.getElementById('sid').value;
            const resultBox = document.getElementById('search-result');
            const btn = document.getElementById('btn-identify');
            if(!athleteId) return;
            btn.disabled = true;
            resultBox.innerHTML = `<div class="skeleton h-16 w-full"></div>`;
            try {
                const res = await fetch('../src/api/search_action.php', { method: 'POST', body: JSON.stringify({ action: 'search', athlete_id: athleteId }) });
                const data = await res.json();
                btn.disabled = false;
                if(data.success) {
                    const pic = data.user.profile_pic || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(data.user.name)}`;
                    resultBox.innerHTML = `
                        <div class="bg-faf-neon/5 border border-faf-neon/30 rounded-3xl p-4 flex items-center gap-4">
                            <img src="${escapeHtml(pic)}" class="w-12 h-12 rounded-full border border-faf-neon/40 object-cover" onerror="this.onerror=null;this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                            <div class="flex-1 text-left">
                                <p class="text-sm font-black italic uppercase leading-none">${escapeHtml(data.user.name)}</p>
                                <p class="text-[9px] text-white/30 font-black uppercase mt-1">Atleta #${escapeHtml(String(data.user.id))}</p>
                            </div>
                            <button onclick="sendFriendRequest(${parseInt(data.user.id)}, this)" class="bg-faf-neon text-black px-4 py-2.5 rounded-xl text-[9px] font-black uppercase italic active:scale-90 transition-all disabled:opacity-50">Sincronizar</button>
                        </div>`;
                } else {
                    resultBox.innerHTML = `<div class="bg-red-500/5 border border-red-500/20 rounded-2xl p-4 text-center"><p class="text-[10px] text-red-400 font-black uppercase italic">Atleta não encontrado</p><p class="text-[9px] text-white/25 italic mt-1">Confirma o ID e tenta outra vez.</p></div>`;
                }
            } catch (e) {
                btn.disabled = false;
                resultBox.innerHTML = `<p class="text-[10px] text-red-400 italic text-center">Erro de ligação. Tenta novamente.</p>`;
            }
        }

        async function sendFriendRequest(friendId, btn) {
            btn.disabled = true; btn.innerText = '...';
            const res = await fetch('../src/api/social_engine.php', { method: 'POST', body: JSON.stringify({ action: 'request', friend_id: friendId }) });
            const data = await res.json();
            btn.innerText = data.success ? 'Enviado ✓' : 'Já pedido';
        }

        async function handleFriend(action, friendId) {
            const res = await fetch('../src/api/social_engine.php', { method: 'POST', body: JSON.stringify({ action: action, friend_id: friendId }) });
            const data = await res.json();
            if(data.success) location.reload();
        }

        async function openCircleEstablishModal() {
            showNeuralModal("Establish Circle", "Define o nome da tua unidade de elite.", true, async (name) => {
                if(!name) return;
                const res = await fetch('../src/api/circle_engine.php', { method: 'POST', body: JSON.stringify({ action: 'create', name: name }) });
                const data = await res.json();
                if(data.success) location.reload();
            });
        }

        async function openCircleJoinModal() {
            showNeuralModal("Join Circle", "Introduz o ID de convite do Circle.", true, async (code) => {
                if(!code) return;
                const res = await fetch('../src/api/circle_engine.php', { method: 'POST', body: JSON.stringify({ action: 'join', invite_code: code }) });
                const data = await res.json();
                if(data.success) location.reload();
                else alert(data.error || 'Circle não encontrado.');
            });
        }

        // Link real de convite: abre recruit.php que faz join automático após login
        function shareRecruit() {
            const link = new URL('recruit.php?circle=<?= $userData['circle_id'] ?? '' ?>', location.href).href;
            const text = 'Junta-te ao meu Circle no FAF — se falhares um treino, o fogo apaga-se para todos. 🔥';
            if (navigator.share) {
                navigator.share({ title: 'FAF Circle', text, url: link }).catch(() => {});
            } else {
                navigator.clipboard.writeText(link).then(() => alert('Link de convite copiado!'));
            }
        }

        // Share card do Circle: fogo do clã em formato story
        async function shareCircleCard() {
            try { await document.fonts.ready; } catch(e) {}
            const c = document.createElement('canvas'); c.width = 1080; c.height = 1350;
            const x = c.getContext('2d');
            x.fillStyle = '#080808'; x.fillRect(0, 0, 1080, 1350);
            x.save(); x.rotate(-0.10);
            x.fillStyle = 'rgba(195,244,0,0.07)'; x.fillRect(-200, 900, 1700, 260);
            x.restore();

            x.font = 'italic 900 90px "Plus Jakarta Sans", sans-serif';
            x.fillStyle = '#ffffff'; x.fillText('FAF', 80, 160);
            x.fillStyle = '#c3f400'; x.fillText('.', 80 + x.measureText('FAF').width, 160);
            x.font = '900 26px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.3)';
            x.fillText('CIRCLE REPORT', 82, 210);

            x.font = '200px serif';
            x.fillText('<?= $fire['emoji'] ?>'.slice(0, 2), 80, 560);

            x.font = 'italic 900 96px "Plus Jakarta Sans", sans-serif'; x.fillStyle = '#c3f400';
            x.fillText(<?= json_encode(mb_strtoupper($circle_name)) ?>, 80, 720);

            x.font = 'italic 900 170px "Plus Jakarta Sans", sans-serif'; x.fillStyle = '#ffffff';
            x.fillText('<?= (int)$streak ?>', 80, 930);
            x.font = '900 40px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.4)';
            x.fillText('DIAS DE FOGO · <?= $fire['label'] ?> · <?= count($clan_members) ?> ATLETAS', 84, 990);

            x.fillStyle = '#c3f400'; x.font = 'italic 900 40px "Plus Jakarta Sans", sans-serif';
            x.fillText('NINGUÉM FALHA. O FOGO NÃO APAGA.', 80, 1250);

            c.toBlob(async (blob) => {
                const file = new File([blob], 'faf-circle.png', { type: 'image/png' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    try { await navigator.share({ files: [file], title: 'FAF Circle' }); } catch(e) {}
                } else {
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob); a.download = 'faf-circle.png'; a.click();
                    URL.revokeObjectURL(a.href);
                }
            }, 'image/png');
        }

        function escapeHtml(str) {
            const d = document.createElement('div');
            d.innerText = str ?? '';
            return d.innerHTML;
        }

        async function loadCircleFeed() {
            const list = document.getElementById('circle-feed-list');
            if (!list) return;
            list.innerHTML = `<div class="skeleton h-4 w-3/4"></div><div class="skeleton h-4 w-1/2"></div><div class="skeleton h-4 w-2/3"></div>`;
            const res = await fetch('../src/api/circle_engine.php', { method: 'POST', body: JSON.stringify({ action: 'get_messages' }) });
            const data = await res.json();
            if (!data.success) return;
            list.innerHTML = data.messages.map(m => {
                if (m.type === 'user_action') {
                    return `<div class="text-xs"><span class="font-black text-faf-neon uppercase italic">${escapeHtml(m.name || 'Atleta')}:</span> <span class="text-white/70">${escapeHtml(m.message)}</span></div>`;
                }
                const color = m.type === 'alert' ? 'text-red-500' : 'text-white/40';
                return `<div class="text-[10px] italic ${color}">${escapeHtml(m.message)}</div>`;
            }).join('') || `<p class="text-[10px] text-white/20 text-center py-6 italic">Sem atividade ainda.</p>`;
            list.scrollTop = list.scrollHeight;
        }

        async function sendCircleMessage() {
            const input = document.getElementById('circle-feed-input');
            const message = input.value.trim();
            if (!message) return;
            input.value = '';
            await fetch('../src/api/circle_engine.php', { method: 'POST', body: JSON.stringify({ action: 'send_message', message }) });
            loadCircleFeed();
        }

        // --- BRIEFING DO PROTOCOLO ---
        function openBriefing() { document.getElementById('briefing-modal').style.display = 'flex'; }
        function closeBriefing() { document.getElementById('briefing-modal').style.display = 'none'; }
        <?php if($show_briefing): ?>openBriefing();<?php endif; ?>

        // --- WEEKLY RECAP: uma vez por semana terminada, via localStorage ---
        <?php if($last_week_recap && !$show_briefing): ?>
        (function() {
            const key = 'faf_recap_seen_<?= $user_id ?>_<?= $last_week_recap['week'] ?>';
            if (!localStorage.getItem(key)) {
                document.getElementById('recap-modal').style.display = 'flex';
            }
            window.closeRecap = function() {
                localStorage.setItem(key, '1');
                document.getElementById('recap-modal').style.display = 'none';
            };
        })();
        <?php endif; ?>

        // --- WEB PUSH: lembretes de treino ---
        const VAPID_PUBLIC = '<?= $_ENV['VAPID_PUBLIC_KEY'] ?? '' ?>';

        function urlB64ToUint8(base64) {
            const padding = '='.repeat((4 - base64.length % 4) % 4);
            const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
            return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
        }

        async function refreshPushButton() {
            const btn = document.getElementById('btn-push');
            if (!btn) return;
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !VAPID_PUBLIC) {
                btn.innerText = 'Indisponível'; btn.disabled = true; return;
            }
            const reg = await navigator.serviceWorker.getRegistration();
            const sub = reg ? await reg.pushManager.getSubscription() : null;
            btn.innerText = sub ? 'Ativado ✓' : 'Ativar';
            btn.dataset.active = sub ? '1' : '0';
        }

        async function togglePush() {
            const btn = document.getElementById('btn-push');
            btn.disabled = true;
            try {
                const reg = await navigator.serviceWorker.register('sw.js');
                await navigator.serviceWorker.ready;
                const existing = await reg.pushManager.getSubscription();
                if (existing) {
                    await fetch('../src/api/push_engine.php', { method: 'POST', body: JSON.stringify({ action: 'unsubscribe', endpoint: existing.endpoint }) });
                    await existing.unsubscribe();
                } else {
                    const perm = await Notification.requestPermission();
                    if (perm !== 'granted') { btn.disabled = false; return; }
                    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(VAPID_PUBLIC) });
                    await fetch('../src/api/push_engine.php', { method: 'POST', body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() }) });
                }
            } catch (e) { console.error('push:', e); }
            btn.disabled = false;
            refreshPushButton();
        }
        refreshPushButton();

        // --- SHARE CARD: gera imagem 1080x1350 para stories/partilha ---
        async function shareWorkout(btn) {
            const type = btn.dataset.type, dist = btn.dataset.dist, pace = btn.dataset.pace;
            const origIcon = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined text-xl animate-spin">progress_activity</span>';
            try { await document.fonts.ready; } catch(e) {}

            const c = document.createElement('canvas'); c.width = 1080; c.height = 1350;
            const x = c.getContext('2d');

            // Fundo + faixa diagonal neon
            x.fillStyle = '#080808'; x.fillRect(0, 0, 1080, 1350);
            x.save(); x.rotate(-0.10);
            x.fillStyle = 'rgba(195,244,0,0.07)'; x.fillRect(-200, 900, 1700, 260);
            x.fillStyle = 'rgba(195,244,0,0.04)'; x.fillRect(-200, 1180, 1700, 120);
            x.restore();

            // Marca
            x.textBaseline = 'alphabetic';
            x.font = 'italic 900 90px "Plus Jakarta Sans", sans-serif';
            x.fillStyle = '#ffffff'; x.fillText('FAF', 80, 160);
            x.fillStyle = '#c3f400'; x.fillText('.', 80 + x.measureText('FAF').width, 160);
            x.font = '900 26px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.3)';
            x.fillText('NEURAL PROTOCOL — COMPLETE', 82, 210);

            // Tipo de treino
            x.font = 'italic 900 110px "Plus Jakarta Sans", sans-serif';
            x.fillStyle = '#c3f400';
            x.fillText(type.toUpperCase(), 80, 560);

            // Métricas grandes
            x.fillStyle = '#ffffff';
            x.font = 'italic 900 190px "Plus Jakarta Sans", sans-serif';
            x.fillText(dist, 80, 800);
            const distW = x.measureText(dist).width;
            x.font = '900 44px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.4)';
            x.fillText('KM', 105 + distW, 795);

            x.font = 'italic 900 90px "Plus Jakarta Sans", sans-serif'; x.fillStyle = '#ffffff';
            x.fillText('@ ' + pace, 80, 950);
            x.font = '900 30px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.35)';
            x.fillText('MIN / KM', 84, 1000);

            // Rodapé
            const hoje = new Date().toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
            x.font = '900 32px Inter, sans-serif'; x.fillStyle = 'rgba(255,255,255,0.5)';
            x.fillText(hoje.toUpperCase(), 80, 1230);
            x.fillStyle = '#c3f400'; x.font = 'italic 900 40px "Plus Jakarta Sans", sans-serif';
            x.fillText('EARN YOUR FIRE 🔥', 80, 1290);

            c.toBlob(async (blob) => {
                btn.innerHTML = origIcon;
                const file = new File([blob], 'faf-workout.png', { type: 'image/png' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    try { await navigator.share({ files: [file], title: 'FAF Workout' }); } catch(e) {}
                } else {
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob); a.download = 'faf-workout.png'; a.click();
                    URL.revokeObjectURL(a.href);
                }
            }, 'image/png');
        }

        Sortable.create(document.getElementById('days-nav'), { animation: 300, onEnd: syncOrder });
        Sortable.create(document.getElementById('drag-container'), { animation: 400, handle: ".drag-handle", onEnd: syncOrder });
        document.getElementById('chat-input').addEventListener('keypress', (e) => { if(e.key === 'Enter') sendMessage(); });
    </script>
</body>
</html>