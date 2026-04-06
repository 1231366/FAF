<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];

// --- LÓGICA DE FEEDBACK (GUARDAR TREINO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_workout') {
    $workout_id = $_POST['workout_id'];
    $status = $_POST['status']; // 'completed' ou 'skipped'
    
    if ($status === 'completed') {
        $real_dist = $_POST['real_distance'];
        $real_pace = $_POST['real_pace'];
        $effort = $_POST['effort_level'];
        
        $stmt = $conn->prepare("UPDATE training_plans SET status = 'completed', is_completed = 1, real_distance = ?, real_pace = ?, effort_level = ?, completed_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param("dssii", $real_dist, $real_pace, $effort, $workout_id, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE training_plans SET status = 'skipped', is_completed = 0 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $workout_id, $user_id);
    }
    $stmt->execute();
    header("Location: plan.php?week=" . ($_GET['week'] ?? 1));
    exit();
}

// --- LÓGICA DE ABORT (INCINERAR) INTEGRADA ---
if (isset($_GET['action']) && $_GET['action'] === 'abort') {
    try {
        $stmt1 = $conn->prepare("DELETE FROM training_plans WHERE user_id = ?");
        $stmt1->bind_param("i", $user_id);
        $stmt1->execute();
        $stmt2 = $conn->prepare("UPDATE user_profiles SET target_distance = NULL, race_date = NULL, prep_cycle = NULL WHERE user_id = ?");
        $stmt2->bind_param("i", $user_id);
        if($stmt2->execute()) { header("Location: methricsdiagonotic.php"); exit(); }
        else { throw new Exception($conn->error); }
    } catch (Exception $e) { die("Erro Crítico no Engine: " . $e->getMessage()); }
}

// QUERY REFINADA: Busca dados de utilizador e perfil
$query = "SELECT u.name, u.profile_pic, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

$current_week = isset($_GET['week']) ? (int)$_GET['week'] : 1;
$total_cycle_weeks = (int)($userData['prep_cycle'] ?? 12);
require_once 'kernel_engine.php'; 

$volume_total = 0;
$ordem_dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
if (isset($weekly_workouts)) {
    foreach ($weekly_workouts as $w) { $volume_total += (float)($w['distance'] ?? 0); }
}

$userName = $userData['name'] ?? 'Atleta';
$userPic  = $userData['profile_pic'] ?? $_SESSION['user_pic'] ?? 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($userName);
$first_name = explode(' ', $userName)[0];
$hoje_nome = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sab'][date('D')];

$target_dist = (int)($userData['target_distance'] ?? 42);
$target_label = ($target_dist <= 5) ? "5KM" : (($target_dist <= 10) ? "10KM" : (($target_dist <= 21) ? "HALF MARATHON" : "MARATHON"));

$proximo_alvo = null;
foreach($ordem_dias as $d) {
    if (isset($weekly_workouts[$d]) && ($weekly_workouts[$d]['status'] ?? 'pending') !== 'completed') {
        $proximo_alvo = $d; break;
    }
}

$workout_hoje = $weekly_workouts[$hoje_nome] ?? null;
$coach_msg = $workout_hoje ? "Hey $first_name! Alvo identificado para hoje. Foca no ritmo e na consistência." : "Hey $first_name! Hoje o asfalto descansa. Recuperação neural é onde o músculo cresce.";
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"/>
    <title>FAF Neural - Final Master Build</title>
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
        .day-item { touch-action: pan-x; -webkit-user-select: none; user-select: none; }
        .day-item.selected { color: #c3f400; transform: scale(1.15); }
        .day-item.selected .dot { background: #c3f400; box-shadow: 0 0 10px #c3f400; }
        ::-webkit-scrollbar { display: none; }
        .nav-active { color: #c3f400 !important; background: rgba(195, 244, 0, 0.1); border-radius: 20px; }
        .drag-handle { cursor: grab; }
        #abort-modal, #feedback-modal { display: none; position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.92); backdrop-filter: blur(15px); align-items: center; justify-content: center; padding: 24px; }
    </style>
</head>
<body>

    <header class="pt-[var(--safe-top)] px-6 bg-black border-b border-white/5">
        <div class="py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="<?= $userPic ?>" class="w-9 h-9 rounded-full border border-faf-neon/30 object-cover" referrerpolicy="no-referrer" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                <h1 class="text-xl font-headline font-black italic uppercase tracking-tighter">FAF<span class="text-faf-neon">.</span></h1>
            </div>
            <span onclick="switchTab('profile')" class="material-symbols-outlined text-faf-neon text-2xl cursor-pointer">settings</span>
        </div>

        <div id="run-header-extras" class="pb-4 space-y-4">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-black uppercase text-faf-neon tracking-[0.2em] italic mb-0.5"><?= $target_label ?> MISSION</p>
                    <h2 class="text-2xl font-headline font-black italic uppercase tracking-tighter leading-none italic">Neural Protocol</h2>
                </div>
            </div>
            
            <div class="flex items-end gap-1.5 h-4">
                <?php $active_bars = (int)(($current_week / $total_cycle_weeks) * 20); if($active_bars < 1) $active_bars = 1;
                for($i=1; $i<=20; $i++): ?>
                    <div class="flex-1 rounded-sm <?= ($i <= $active_bars) ? 'bg-faf-neon shadow-[0_0_8px_#c3f400]' : 'bg-white/10' ?> h-<?= rand(2,4) ?>"></div>
                <?php endfor; ?>
            </div>

            <div class="flex gap-3 items-center bg-white/5 p-3 rounded-2xl border border-white/5">
                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=CoachK" class="w-8 h-8 rounded-full border border-faf-neon/30">
                <p class="text-[10px] text-white/70 italic leading-tight">"<?= $coach_msg ?>"</p>
            </div>

            <section id="days-nav" class="flex justify-between items-center bg-zinc-900/60 p-4 rounded-[24px] border border-white/5 shadow-2xl">
                <?php foreach($ordem_dias as $d): 
                    $hasW = isset($weekly_workouts[$d]);
                    $isH = ($d == $hoje_nome);
                ?>
                <div onclick="focusDay('<?= $d ?>')" data-day="<?= $d ?>" class="day-item flex flex-col items-center gap-1 cursor-pointer transition-all duration-300 <?= $isH ? 'selected' : 'opacity-30' ?>">
                    <span class="text-[9px] font-black uppercase"><?= $d ?></span>
                    <div class="dot w-1.5 h-1.5 rounded-full <?= $hasW ? 'bg-white shadow-[0_0_5px_white]' : 'bg-transparent border border-white/20' ?>"></div>
                </div>
                <?php endforeach; ?>
            </section>
        </div>
    </header>

    <main id="app-main" class="px-6 pt-4">

        <div id="home" class="tab-content active">
            <div class="flex justify-between items-end px-2 pb-4">
                <p class="text-[10px] font-black text-white/30 uppercase tracking-widest italic">Week <?= $current_week ?> • <?= number_format($volume_total, 1) ?> KM</p>
                <div class="flex gap-4">
                    <a href="?week=<?= $current_week - 1 ?>" class="text-white/20"><span class="material-symbols-outlined">west</span></a>
                    <a href="?week=<?= $current_week + 1 ?>" class="text-faf-neon"><span class="material-symbols-outlined">east</span></a>
                </div>
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
                <div data-day="<?= $dia ?>" id="card-<?= $dia ?>" class="workout-card <?= $isTarget ? 'focused' : 'minimized' ?>" style="z-index: <?= 50 - $idx ?>;" onclick="focusDay('<?= $dia ?>')">
                    <div class="glass-card rounded-[40px] p-7 shadow-2xl relative overflow-hidden">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-[11px] font-black uppercase text-faf-neon italic tracking-widest mb-1"><?= $dia ?></p>
                                <h4 class="text-3xl font-headline font-black italic uppercase leading-none"><?= $w['workout_type'] ?></h4>
                            </div>
                            <div class="drag-handle w-12 h-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-faf-neon text-2xl"><?= $icon ?></span>
                            </div>
                        </div>

                        <div class="mb-6">
                            <p class="text-[10px] text-white/50 font-medium leading-relaxed italic">
                                <?= !empty($w['description']) ? $w['description'] : 'Foco na manutenção aeróbica.' ?>
                            </p>
                        </div>

                        <?php if($concluido): ?>
                        <div class="bg-faf-neon/5 border border-faf-neon/20 rounded-3xl p-4 mb-6 grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[8px] font-black text-white/20 uppercase italic mb-1">Target Mission</p>
                                <p class="text-xs font-black text-white/60 italic"><?= number_format($w['distance'], 1) ?>k @ <?= $w['pace'] ?></p>
                            </div>
                            <div class="border-l border-white/10 pl-4">
                                <p class="text-[8px] font-black text-faf-neon uppercase italic mb-1">Actual Result</p>
                                <p class="text-xs font-black text-faf-neon italic"><?= number_format($w['real_distance'], 1) ?>k @ <?= $w['real_pace'] ?></p>
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
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="insights" class="tab-content space-y-8">
            <h2 class="text-4xl font-headline font-black italic uppercase tracking-tighter pt-4">Neural Data</h2>
            <div class="grid grid-cols-1 gap-4">
                <div class="glass-card p-8 rounded-[40px] flex justify-between items-center"><p class="text-xs font-black text-white/40 uppercase italic">VO2 Max</p><p class="text-4xl font-black italic text-faf-neon">54.2</p></div>
                <div class="glass-card p-8 rounded-[40px] flex justify-between items-center"><p class="text-xs font-black text-white/40 uppercase italic">Readiness</p><p class="text-4xl font-black italic">92%</p></div>
            </div>
        </div>

        <div id="club" class="tab-content space-y-6">
            <h2 class="text-4xl font-headline font-black italic uppercase tracking-tighter pt-4">The Syndicate</h2>
            <div class="glass-card p-5 rounded-[35px] flex items-center gap-5 border-l-4 border-faf-neon">
                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Rafa" class="w-14 h-14 rounded-full border border-faf-neon/30 p-1">
                <div class="flex-1"><p class="text-lg font-black italic uppercase leading-none">Rafa Silva</p><p class="text-[10px] text-white/40 mt-2 font-bold uppercase italic tracking-widest">12KM Mission @ 4:55 Pace</p></div>
            </div>
        </div>

        <div id="profile" class="tab-content space-y-8 text-center pt-8">
            <div class="relative w-32 h-32 mx-auto">
                <img src="<?= $userPic ?>" class="w-full h-full rounded-full border-4 border-faf-neon p-1 bg-zinc-900 shadow-2xl object-cover" referrerpolicy="no-referrer">
            </div>
            <h3 class="text-3xl font-headline font-black italic uppercase italic tracking-tighter"><?= $userName ?></h3>
            <div class="grid grid-cols-3 gap-3 px-4">
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">Peso</p><p class="text-xl font-black italic"><?= $userData['weight'] ?? '--' ?>kg</p></div>
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">Idade</p><p class="text-xl font-black italic"><?= $userData['age'] ?? '--' ?></p></div>
                <div class="glass-card py-6 rounded-3xl"><p class="text-[9px] text-white/30 uppercase mb-1">Alvo</p><p class="text-xl font-black italic"><?= $userData['target_distance'] ?? '--' ?>k</p></div>
            </div>

            <div class="mt-8 px-4">
                <div class="glass-card p-6 rounded-[40px] border-l-4 border-red-600/50 text-left">
                    <p class="text-[10px] font-black uppercase text-red-500 mb-1 tracking-widest">Danger Zone</p>
                    <h4 class="text-xl font-headline font-black italic uppercase italic tracking-tighter mb-2">Abort Protocol</h4>
                    <p class="text-[10px] text-white/40 mb-6 italic leading-relaxed">Se desativares este protocolo, todos os treinos e progressos atuais serão incinerados.</p>
                    <button onclick="openAbortModal()" class="w-full py-4 bg-red-600/10 border border-red-600/20 rounded-2xl text-[10px] font-black uppercase italic tracking-widest text-red-500 active:bg-red-600 active:text-white transition-all">Incinerar Plano Atual</button>
                </div>
            </div>

            <a href="logout.php" class="inline-block mt-8 text-red-500 font-black uppercase text-[10px] tracking-widest italic underline">Logout</a>
        </div>
    </main>

    <div id="abort-modal">
        <div class="glass-card p-10 rounded-[50px] border border-red-600/30 max-w-sm w-full text-center space-y-8">
            <span class="material-symbols-outlined text-red-600 text-6xl">warning</span>
            <div>
                <h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter text-white mb-2">Neural Reset</h3>
                <p class="text-xs text-white/40 italic leading-relaxed">Confirmas a destruição total do protocolo atual? Esta ação é irreversível.</p>
            </div>
            <div class="space-y-3">
                <button onclick="window.location.href='plan.php?action=abort'" class="w-full py-5 bg-red-600 text-white rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-[0_10px_20px_rgba(220,38,38,0.2)]">Confirmar Incineração</button>
                <button onclick="closeAbortModal()" class="w-full py-5 bg-white/5 text-white/40 rounded-2xl font-black uppercase italic text-xs tracking-widest">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="feedback-modal">
        <form action="plan.php?week=<?= $current_week ?>" method="POST" class="glass-card p-8 rounded-[50px] border border-faf-neon/20 max-w-sm w-full space-y-6">
            <input type="hidden" name="action" value="save_workout">
            <input type="hidden" id="modal_workout_id" name="workout_id">
            
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-headline font-black italic uppercase italic text-faf-neon">Workout Feedback</h3>
                <span onclick="closeCheckIn()" class="material-symbols-outlined text-white/20 cursor-pointer">close</span>
            </div>

            <select name="status" id="workout_status" onchange="toggleFeedbackFields()" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black uppercase italic outline-none text-white">
                <option value="completed">TREINO CONCLUÍDO</option>
                <option value="skipped">NÃO CONSEGUI FAZER</option>
            </select>

            <div id="feedback_fields" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-[9px] font-black uppercase text-white/30 ml-2 italic">Real KM</label>
                        <input type="number" step="0.01" name="real_distance" id="modal_dist" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none focus:border-faf-neon transition-all">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[9px] font-black uppercase text-white/30 ml-2 italic">Real Pace</label>
                        <input type="text" name="real_pace" placeholder="5:30" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none focus:border-faf-neon transition-all">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-[9px] font-black uppercase text-white/30 ml-2 italic">Esforço Sentido</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="effort_level" value="easy" class="hidden peer">
                            <div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-green-500/20 peer-checked:border-green-500 transition-all uppercase">Easy</div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="effort_level" value="perfect" checked class="hidden peer">
                            <div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-faf-neon/20 peer-checked:border-faf-neon transition-all uppercase text-faf-neon">Perfect</div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="effort_level" value="hard" class="hidden peer">
                            <div class="p-3 rounded-2xl border border-white/10 text-[9px] font-black text-center peer-checked:bg-red-500/20 peer-checked:border-red-500 transition-all uppercase text-red-500">Hard</div>
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full py-4 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg">Sincronizar Dados</button>
        </form>
    </div>

    <nav class="fixed bottom-0 left-0 w-full p-6 pb-[var(--safe-bottom)] z-[400] bg-gradient-to-t from-black via-black/80 to-transparent">
        <div class="bg-black/95 backdrop-blur-3xl rounded-[35px] border border-white/10 p-2 flex justify-around items-center shadow-2xl max-w-sm mx-auto">
            <button onclick="switchTab('home')" id="btn-home" class="nav-active flex flex-col items-center justify-center w-14 h-14">
                <span class="material-symbols-outlined text-2xl">directions_run</span>
                <span class="text-[8px] font-black uppercase mt-0.5 italic">Run</span>
            </button>
            <button onclick="switchTab('insights')" id="btn-insights" class="flex flex-col items-center justify-center w-14 h-14 text-white/30">
                <span class="material-symbols-outlined text-2xl">analytics</span>
                <span class="text-[8px] font-black uppercase mt-0.5 italic">Data</span>
            </button>
            <button onclick="switchTab('club')" id="btn-club" class="flex flex-col items-center justify-center w-14 h-14 text-white/30">
                <span class="material-symbols-outlined text-2xl">groups</span>
                <span class="text-[8px] font-black uppercase mt-0.5 italic">Club</span>
            </button>
            <button onclick="switchTab('profile')" id="btn-profile" class="flex flex-col items-center justify-center w-14 h-14 text-white/30">
                <span class="material-symbols-outlined text-2xl">person</span>
                <span class="text-[8px] font-black uppercase mt-0.5 italic">Me</span>
            </button>
        </div>
    </nav>

    <script>
        function openAbortModal() { document.getElementById('abort-modal').style.display = 'flex'; }
        function closeAbortModal() { document.getElementById('abort-modal').style.display = 'none'; }

        // Lógica de Feedback/Check-in
        function openCheckIn(id, type, dist) {
            document.getElementById('modal_workout_id').value = id;
            document.getElementById('modal_dist').value = dist;
            document.getElementById('feedback-modal').style.display = 'flex';
        }

        function closeCheckIn() {
            document.getElementById('feedback-modal').style.display = 'none';
        }

        function toggleFeedbackFields() {
            const status = document.getElementById('workout_status').value;
            const fields = document.getElementById('feedback_fields');
            fields.style.display = (status === 'completed') ? 'block' : 'none';
        }

        function focusDay(day) {
            document.querySelectorAll('.day-item').forEach(el => el.classList.remove('selected', 'opacity-100'));
            const targetDay = document.querySelector(`.day-item[data-day="${day}"]`);
            if(targetDay) targetDay.classList.add('selected');

            document.querySelectorAll('.workout-card').forEach(card => {
                card.classList.remove('focused');
                card.classList.add('minimized');
            });
            
            const selectedCard = document.getElementById(`card-${day}`);
            if(selectedCard) {
                selectedCard.classList.add('focused');
                selectedCard.classList.remove('minimized');
                
                const container = document.getElementById('app-main');
                const scrollPos = selectedCard.offsetTop - 15;
                
                container.scrollTo({ top: scrollPos, behavior: 'smooth' });
            }
        }

        function switchTab(id) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('nav button').forEach(b => b.classList.remove('nav-active'));
            document.getElementById(id).classList.add('active');
            const targetBtn = document.getElementById('btn-' + id);
            if(targetBtn) targetBtn.classList.add('nav-active');

            document.getElementById('run-header-extras').style.display = (id === 'home') ? 'block' : 'none';
            document.getElementById('app-main').scrollTo(0,0);
        }

        const syncOrder = async () => {
            const days = Array.from(document.querySelectorAll('.day-item')).map(i => i.getAttribute('data-day'));
            await fetch('reorder_engine.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({week: <?= $current_week ?>, days_order: days}) });
            location.reload();
        };

        Sortable.create(document.getElementById('days-nav'), { animation: 300, delay: 150, delayOnTouchOnly: true, onEnd: syncOrder });
        Sortable.create(document.getElementById('drag-container'), { animation: 400, handle: ".drag-handle", onEnd: syncOrder });
    </script>
</body>
</html>