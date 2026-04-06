<?php
session_start();
require_once __DIR__ . '/../src/core/config.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];

/**
 * 1. DATA LAYER (Original + Social)
 */
$query = "SELECT u.name, u.profile_pic, u.circle_id, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

/**
 * 2. IDENTITY LAYER
 */
$userName = $userData['name'] ?? $_SESSION['user_name'] ?? 'Atleta';
$userPic  = $userData['profile_pic'] ?? $_SESSION['user_pic'] ?? 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($userName);
$first_name = explode(' ', $userName)[0];

/**
 * 3. SOCIAL ENGINE LOGIC
 */
$circle_energy = 0; $circle_name = "Solo Protocol"; $streak = 0;
if ($userData['circle_id']) {
    $c_id = $userData['circle_id'];
    $stmt_c = $conn->prepare("SELECT name, streak_count FROM circles WHERE id = ?");
    $stmt_c->bind_param("i", $c_id);
    $stmt_c->execute();
    $c_info = $stmt_c->get_result()->fetch_assoc();
    $circle_name = $c_info['name'] ?? "Alpha Circle";
    $streak = $c_info['streak_count'] ?? 0;
    
    $cw = isset($_GET['week']) ? (int)$_GET['week'] : 1;
    $stmt_stats = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as done 
                                  FROM training_plans tp JOIN users u ON tp.user_id = u.id 
                                  WHERE u.circle_id = ? AND tp.week_number = ?");
    $stmt_stats->bind_param("ii", $c_id, $cw);
    $stmt_stats->execute();
    $s = $stmt_stats->get_result()->fetch_assoc();
    $circle_energy = ($s['total'] > 0) ? round(($s['done'] / $s['total']) * 100) : 100;
}

$stmt_inbox = $conn->prepare("SELECT f.user_id as athlete_id, u.name FROM friendships f JOIN users u ON f.user_id = u.id WHERE f.friend_id = ? AND f.status = 'pending'");
$stmt_inbox->bind_param("i", $user_id);
$stmt_inbox->execute();
$notifications = $stmt_inbox->get_result();
$notif_count = $notifications->num_rows;

/**
 * 4. ENGINE LAYER
 */
$current_week = isset($_GET['week']) ? (int)$_GET['week'] : 1;
require_once __DIR__ . '/../src/engines/kernel_engine.php'; 

$total_cycle_weeks = (int)($userData['prep_cycle'] ?? 12);
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

$workout_hoje = $weekly_workouts[$hoje_nome] ?? null;
$coach_msg = $workout_hoje ? "Hey $first_name! Alvo identificado para hoje. Foca no ritmo e na consistência." : "Hey $first_name! Hoje o asfalto descansa. Recuperação neural é onde o músculo cresce.";
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"/>
    <title>FAF Neural - Master Fusion Build</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
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
        
        /* Overlays Sigma Estéticos */
        #neural-inbox, #search-overlay { display: none; position: fixed; right: 20px; top: calc(var(--safe-top) + 80px); width: 280px; max-height: 400px; background: rgba(15,15,15,0.98); backdrop-filter: blur(30px); z-index: 5000; border: 1px solid rgba(195,244,0,0.2); border-radius: 30px; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden; }
        .clan-feed-item { border-left: 2px solid #c3f400; background: linear-gradient(90deg, rgba(195,244,0,0.05) 0%, transparent 100%); }

        #abort-modal, #feedback-modal { display: none; position: fixed; inset: 0; z-index: 6000; background: rgba(0,0,0,0.92); backdrop-filter: blur(15px); align-items: center; justify-content: center; padding: 24px; }
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
                <img src="<?= $userPic ?>" class="w-9 h-9 rounded-full border border-faf-neon/30 object-cover" referrerpolicy="no-referrer" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=FAF'">
                <h1 class="text-xl font-headline font-black italic uppercase tracking-tighter">FAF<span class="text-faf-neon">.</span></h1>
            </div>
            <div class="flex items-center gap-4">
                <div onclick="toggleInbox()" class="relative cursor-pointer">
                    <span class="material-symbols-outlined text-white/40 text-2xl">notifications</span>
                    <?php if($notif_count > 0): ?><div class="absolute -top-1 -right-1 w-4 h-4 bg-faf-neon rounded-full flex items-center justify-center text-[10px] text-black font-black"><?= $notif_count ?></div><?php endif; ?>
                </div>
                <span onclick="switchTab('profile')" class="material-symbols-outlined text-faf-neon text-2xl cursor-pointer">qr_code_2</span>
            </div>
        </div>

        <div id="run-header-extras" class="pb-4 space-y-4">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-black uppercase text-faf-neon tracking-[0.2em] italic mb-0.5"><?= $circle_name ?> MISSION</p>
                    <h2 class="text-2xl font-headline font-black italic uppercase tracking-tighter leading-none italic">Neural Protocol</h2>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full border-2 border-faf-neon flex items-center justify-center bg-faf-neon/5">
                        <span class="text-[10px] font-black italic text-faf-neon"><?= $circle_energy ?>%</span>
                    </div>
                    <span class="text-[7px] font-black uppercase text-white/40 mt-1 italic">Circle</span>
                </div>
            </div>
            
            <div class="flex items-end gap-1.5 h-4">
                <?php $active_bars = (int)(($current_week / $total_cycle_weeks) * 20); if($active_bars < 1) $active_bars = 1;
                for($i=1; $i<=20; $i++): ?>
                    <div class="flex-1 rounded-sm <?= ($i <= $active_bars) ? 'bg-faf-neon shadow-[0_0_8px_#c3f400]' : 'bg-white/10' ?> h-<?= rand(2,4) ?>"></div>
                <?php endfor; ?>
            </div>

            <div onclick="openCoachChat()" class="flex gap-3 items-center bg-white/5 p-3 rounded-2xl border border-white/5 cursor-pointer active:scale-95 transition-all">
                <div class="relative">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=CoachK" class="w-8 h-8 rounded-full border border-faf-neon/30">
                    <div class="absolute -top-0.5 -right-0.5 w-2 h-2 bg-faf-neon rounded-full border border-black animate-pulse"></div>
                </div>
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
            <div class="flex justify-between items-center pt-4"><h2 class="text-4xl font-headline font-black italic uppercase tracking-tighter leading-none">Syndicate</h2><button onclick="toggleSearch()" class="w-12 h-12 rounded-full bg-faf-neon text-black flex items-center justify-center active:scale-90 shadow-lg"><span class="material-symbols-outlined font-black">person_add</span></button></div>
            <div class="flex gap-8 border-b border-white/5"><button onclick="toggleClubSubTab('syndicate')" id="btn-club-syn" class="pb-3 text-xs font-black uppercase italic tracking-tighter text-faf-neon border-b-2 border-faf-neon">Friends</button><button onclick="toggleClubSubTab('circle')" id="btn-club-cir" class="pb-3 text-xs font-black uppercase italic tracking-tighter text-white/30">The Circle</button></div>
            
            <div id="club-syndicate-hub" class="space-y-4">
                <div class="glass-card p-5 rounded-[35px] flex items-center gap-5 border-l-4 border-faf-neon">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Joel" class="w-14 h-14 rounded-full border border-faf-neon/30 p-1">
                    <div class="flex-1"><p class="text-lg font-black italic uppercase leading-none">Joelmo</p><p class="text-[10px] text-faf-neon mt-1 font-bold italic uppercase tracking-widest">DESTROYED 10KM MISSION</p></div>
                    <span class="material-symbols-outlined text-faf-neon">flash_on</span>
                </div>
            </div>

            <div id="club-circle-hub" class="hidden space-y-6">
                <?php if($userData['circle_id']): ?>
                    <div class="bg-faf-neon p-7 rounded-[45px] text-black shadow-2xl flex justify-between items-center"><div><h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter"><?= $circle_name ?></h3><p class="text-[9px] font-black uppercase tracking-widest opacity-60">Clan Sync Active</p></div><div class="text-center text-3xl">🔥 <span class="block text-xl font-black"><?= $streak ?></span></div></div>
                    <div class="glass-card rounded-[35px] p-6 space-y-4"><p class="text-[9px] font-black uppercase text-faf-neon tracking-widest italic">Clan Leaderboard</p><div class="flex justify-between items-center"><div class="flex items-center gap-3"><span class="text-xs font-black text-faf-neon">01</span><p class="text-xs font-black italic uppercase"><?= $first_name ?></p></div><span class="text-xs font-black italic">100%</span></div></div>
                    <div class="glass-card rounded-[35px] p-6 space-y-4"><p class="text-[9px] font-black uppercase text-white/30 tracking-widest italic">Mission Log</p><div class="clan-feed-item p-4 rounded-2xl"><p class="text-[11px] leading-relaxed"><span class="font-black italic uppercase faf-neon">@System:</span> Atleta Alpha sincronizou treino! Streak mantido 🔥</p></div></div>
                <?php else: ?><div class="glass-card p-12 rounded-[50px] text-center border-dashed border-2 border-white/10"><p class="text-[11px] text-white/40 mb-8 italic">No Circle Established.</p><button class="w-full py-5 bg-faf-neon text-black rounded-2xl font-black italic uppercase text-xs">Establish Unit</button></div><?php endif; ?>
            </div>
        </div>

        <div id="profile" class="tab-content space-y-8 text-center pt-8">
            <div class="relative w-32 h-32 mx-auto"><img src="<?= $userPic ?>" class="w-full h-full rounded-full border-4 border-faf-neon p-1 bg-zinc-900 shadow-2xl object-cover" referrerpolicy="no-referrer"></div>
            <div class="space-y-1"><h3 class="text-4xl font-headline font-black italic uppercase tracking-tighter italic leading-none"><?= $userName ?></h3><p class="text-[10px] font-black text-faf-neon uppercase tracking-[0.3em] italic">Athlete ID: #<?= str_pad($user_id, 4, '0', STR_PAD_LEFT) ?></p></div>
            
            <div class="px-6"><div class="glass-card p-8 rounded-[50px] bg-gradient-to-br from-white/5 to-transparent flex flex-col items-center"><div class="bg-white p-4 rounded-[35px] shadow-[0_0_40px_rgba(195,244,0,0.3)]"><canvas id="neural-qr"></canvas></div><p class="mt-8 text-[9px] font-black text-faf-neon uppercase italic tracking-widest opacity-60">Scan to Sync Syndicate</p></div></div>

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

    <div id="neural-inbox" style="display: none;">
        <div class="p-6 h-full flex flex-col"><div class="flex justify-between items-center mb-6"><p class="text-[10px] font-black uppercase text-faf-neon italic tracking-widest">Inbox</p><span onclick="toggleInbox()" class="material-symbols-outlined text-white/20 cursor-pointer">close</span></div><div class="space-y-4 flex-1 overflow-y-auto"><?php while($req = $notifications->fetch_assoc()): ?><div class="flex items-center justify-between bg-white/5 p-4 rounded-2xl border-l-2 border-faf-neon"><p class="text-xs font-black italic uppercase"><?= $req['name'] ?></p><button class="bg-faf-neon text-black p-1 rounded-lg"><span class="material-symbols-outlined text-sm font-black">done</span></button></div><?php endwhile; if($notif_count == 0) echo "<p class='text-[10px] text-white/20 text-center py-20 italic'>Neural link updated.</p>"; ?></div></div>
    </div>

    <div id="search-overlay">
        <header class="flex justify-between items-center mb-10"><h2 class="text-2xl font-headline font-black italic uppercase text-faf-neon tracking-tighter italic">Sync Unit</h2><span onclick="toggleSearch()" class="material-symbols-outlined text-white/40 cursor-pointer">close</span></header>
        <div class="space-y-6"><div class="glass-card p-1.5 rounded-[28px] border-faf-neon/20 flex items-center px-4"><span class="material-symbols-outlined text-white/20 mr-3">fingerprint</span><input type="number" id="sid" placeholder="Athlete ID..." class="flex-1 bg-transparent py-4 text-white font-black italic outline-none"></div><button class="w-full py-5 bg-faf-neon text-black rounded-3xl font-black uppercase italic shadow-lg">Identify</button><button onclick="alert('Camera Scan active...')" class="w-full py-4 border border-white/10 rounded-3xl text-[10px] font-black uppercase italic text-white/30 flex items-center justify-center gap-2"><span class="material-symbols-outlined text-sm">qr_code_scanner</span>Scan Friend QR</button></div>
    </div>

    <div id="coach-overlay">
        <div id="coach-modal-chat">
            <header class="p-6 border-b border-white/5 flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-3">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=CoachK" class="w-10 h-10 rounded-full border border-faf-neon/30">
                    <div>
                        <h3 class="font-headline font-black italic uppercase text-faf-neon text-sm">Coach Neural</h3>
                        <span class="text-[8px] text-white/30 uppercase font-black">Bio-Sincronização Ativa</span>
                    </div>
                </div>
                <button onclick="closeCoachChat()" class="w-8 h-8 rounded-full bg-white/5 flex items-center justify-center text-white/40"><span class="material-symbols-outlined text-sm">close</span></button>
            </header>
            <div id="chat-messages" class="flex-1 overflow-y-auto p-6 space-y-4 flex flex-col">
                <div class="coach-bubble italic">Boas, <?= $first_name ?>. Como te posso ajudar?</div>
            </div>
            <div class="p-6 bg-black">
                <div class="glass-card p-1.5 rounded-[28px] flex items-center gap-2 border-faf-neon/20 focus-within:border-faf-neon transition-all">
                    <input id="chat-input" type="text" placeholder="Reportar treino..." class="flex-1 bg-transparent border-none focus:ring-0 text-sm p-3 text-white placeholder:text-white/20">
                    <button onclick="sendMessage()" class="w-11 h-11 rounded-2xl bg-faf-neon text-black flex items-center justify-center active:scale-90 transition-all"><span class="material-symbols-outlined font-black">send</span></button>
                </div>
            </div>
        </div>
    </div>

    <div id="abort-modal">
        <div class="glass-card p-10 rounded-[50px] border border-red-600/30 max-w-sm w-full text-center space-y-8">
            <span class="material-symbols-outlined text-red-600 text-6xl">warning</span>
            <div>
                <h3 class="text-2xl font-headline font-black italic uppercase tracking-tighter text-white mb-2">Neural Reset</h3>
                <p class="text-xs text-white/40 italic leading-relaxed">Confirmas a destruição total do protocolo atual? Esta ação é irreversível.</p>
            </div>
            <div class="space-y-3">
                <button onclick="window.location.href='../src/api/abort_engine.php'" class="w-full py-5 bg-red-600 text-white rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-[0_10px_20px_rgba(220,38,38,0.2)]">Confirmar Incineração</button>
                <button onclick="closeAbortModal()" class="w-full py-5 bg-white/5 text-white/40 rounded-2xl font-black uppercase italic text-xs tracking-widest">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="feedback-modal">
        <div class="glass-card p-8 rounded-[50px] border border-faf-neon/20 max-w-sm w-full space-y-6">
            <input type="hidden" id="modal_workout_id">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-headline font-black italic uppercase italic text-faf-neon">Workout Feedback</h3>
                <span onclick="closeCheckIn()" class="material-symbols-outlined text-white/20 cursor-pointer">close</span>
            </div>
            <select id="workout_status" onchange="toggleFeedbackFields()" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black uppercase italic outline-none text-white">
                <option value="completed">TREINO CONCLUÍDO</option>
                <option value="skipped">NÃO CONSEGUI FAZER</option>
            </select>
            <div id="feedback_fields" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-[9px] font-black uppercase text-white/30 ml-2 italic">Real KM</label>
                        <input type="number" step="0.01" id="modal_real_dist" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none focus:border-faf-neon transition-all">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[9px] font-black uppercase text-white/30 ml-2 italic">Real Pace</label>
                        <input type="text" id="modal_real_pace" placeholder="5:30" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-xs font-black outline-none focus:border-faf-neon transition-all">
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
            <button onclick="submitWorkoutFeedback()" class="w-full py-4 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-xs tracking-widest shadow-lg">Sincronizar Dados</button>
        </div>
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
        // INIT QR
        (function(){ new QRious({ element: document.getElementById('neural-qr'), value: 'FAF-ATHLETE-<?= $user_id ?>', size: 180, background: 'white', foreground: 'black' }); })();

        // COACH UI
        function openCoachChat() {
            document.getElementById('coach-overlay').classList.add('active');
            setTimeout(() => document.getElementById('chat-input').focus(), 400);
        }
        function closeCoachChat() { document.getElementById('coach-overlay').classList.remove('active'); }

        async function sendMessage() {
            const input = document.getElementById('chat-input');
            const container = document.getElementById('chat-messages');
            if (!input.value.trim()) return;
            const msg = input.value; input.value = '';
            const userDiv = document.createElement('div'); userDiv.className = 'user-bubble'; userDiv.innerText = msg;
            container.appendChild(userDiv); container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
            const loading = document.createElement('div'); loading.className = 'coach-bubble opacity-50'; loading.innerText = 'Neural Process...';
            container.appendChild(loading);
            try {
                const response = await fetch('../src/engines/ai_engine.php', { method: 'POST', body: JSON.stringify({ message: msg }) });
                const data = await response.json(); container.removeChild(loading);
                const coachDiv = document.createElement('div'); coachDiv.className = 'coach-bubble italic'; coachDiv.innerText = data.reply;
                container.appendChild(coachDiv); container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
            } catch (e) { loading.innerText = "Connection Error."; }
        }

        // FEEDBACK AJAX
        async function submitWorkoutFeedback() {
            const data = {
                id: document.getElementById('modal_workout_id').value,
                status: document.getElementById('workout_status').value,
                dist: document.getElementById('modal_real_dist').value,
                pace: document.getElementById('modal_real_pace').value,
                effort: document.querySelector('input[name="effort_level"]:checked').value
            };
            await fetch('../src/api/checkin_engine.php', { method: 'POST', body: JSON.stringify(data) });
            location.reload();
        }

        // NAVIGATION & TABS
        function focusDay(day) {
            document.querySelectorAll('.day-item').forEach(el => el.classList.remove('selected', 'opacity-100'));
            const target = document.querySelector(`.day-item[data-day="${day}"]`);
            if(target) target.classList.add('selected');
            document.querySelectorAll('.workout-card').forEach(card => { card.classList.remove('focused'); card.classList.add('minimized'); });
            const selectedCard = document.getElementById(`card-${day}`);
            if(selectedCard) {
                selectedCard.classList.add('focused'); selectedCard.classList.remove('minimized');
                document.getElementById('app-main').scrollTo({ top: selectedCard.offsetTop - 15, behavior: 'smooth' });
            }
        }

        function switchTab(id) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('nav button').forEach(b => b.classList.remove('nav-active', 'text-faf-neon'));
            document.getElementById(id).classList.add('active');
            document.getElementById('btn-' + id).classList.add('nav-active');
            document.getElementById('run-header-extras').style.display = (id === 'home') ? 'block' : 'none';
            document.getElementById('app-main').scrollTo(0,0);
        }

        function toggleClubSubTab(sub) {
            document.getElementById('club-syndicate-hub').classList.toggle('hidden', sub !== 'syndicate');
            document.getElementById('club-circle-hub').classList.toggle('hidden', sub !== 'circle');
            document.getElementById('btn-club-syn').className = sub === 'syndicate' ? "pb-3 text-xs font-black uppercase italic text-faf-neon border-b-2 border-faf-neon" : "pb-3 text-xs font-black uppercase italic text-white/30";
            document.getElementById('btn-club-cir').className = sub === 'circle' ? "pb-3 text-xs font-black uppercase italic text-faf-neon border-b-2 border-faf-neon" : "pb-3 text-xs font-black uppercase italic text-white/30";
        }

        // OVERLAYS
        function toggleInbox() { const el = document.getElementById('neural-inbox'); el.style.display = (el.style.display === 'flex') ? 'none' : 'flex'; }
        function toggleSearch() { const el = document.getElementById('search-overlay'); el.style.display = (el.style.display === 'flex') ? 'none' : 'flex'; }
        function openAbortModal() { document.getElementById('abort-modal').style.display = 'flex'; }
        function closeAbortModal() { document.getElementById('abort-modal').style.display = 'none'; }
        function openCheckIn(id, type, dist) { document.getElementById('modal_workout_id').value = id; document.getElementById('modal_real_dist').value = dist; document.getElementById('feedback-modal').style.display = 'flex'; }
        function closeCheckIn() { document.getElementById('feedback-modal').style.display = 'none'; }
        function toggleFeedbackFields() { document.getElementById('feedback_fields').style.display = (document.getElementById('workout_status').value === 'completed') ? 'block' : 'none'; }

        // ENGINES
        const syncOrder = async () => {
            const days = Array.from(document.querySelectorAll('.day-item')).map(i => i.getAttribute('data-day'));
            await fetch('../src/api/reorder_engine.php', { method: 'POST', body: JSON.stringify({week: <?= $current_week ?>, days_order: days}) });
            location.reload();
        };

        Sortable.create(document.getElementById('days-nav'), { animation: 300, onEnd: syncOrder });
        Sortable.create(document.getElementById('drag-container'), { animation: 400, handle: ".drag-handle", onEnd: syncOrder });
        document.getElementById('chat-input').addEventListener('keypress', (e) => { if(e.key === 'Enter') sendMessage(); });
    </script>
</body>
</html>