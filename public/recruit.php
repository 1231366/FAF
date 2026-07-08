<?php
// public/recruit.php — link de convite para um Circle.
// Logado: entra já. Não logado: guarda o convite e completa após o login.
require_once __DIR__ . '/../src/core/config.php';

$circle_id = (int)($_GET['circle'] ?? 0);

$stmt = $conn->prepare("SELECT c.name, c.streak_count, COUNT(u.id) members FROM circles c LEFT JOIN users u ON u.circle_id = c.id WHERE c.id = ? GROUP BY c.id");
$stmt->bind_param("i", $circle_id);
$stmt->execute();
$circle = $stmt->get_result()->fetch_assoc();

if (!$circle) {
    header("Location: login.php");
    exit();
}

// Já autenticado: junta-se imediatamente e segue para o Club
if (isset($_SESSION['user_id'])) {
    $upd = $conn->prepare("UPDATE users SET circle_id = ? WHERE id = ?");
    $upd->bind_param("ii", $circle_id, $_SESSION['user_id']);
    $upd->execute();
    $_SESSION['circle_id'] = $circle_id;

    $stmt_u = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $stmt_u->bind_param("i", $_SESSION['user_id']);
    $stmt_u->execute();
    $joiner = $stmt_u->get_result()->fetch_assoc()['name'] ?? 'Um atleta';
    $msg = "{$joiner} juntou-se ao Circle via convite. Bem-vindo à unidade.";
    $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'system')");
    $ins->bind_param("iis", $circle_id, $_SESSION['user_id'], $msg);
    $ins->execute();

    header("Location: plan.php?joined=1");
    exit();
}

// Não autenticado: memoriza o convite; auth.php/google-callback.php completam o join
$_SESSION['pending_circle_invite'] = $circle_id;
$fire = ($circle['streak_count'] >= 7) ? '🔥🔥🔥' : (($circle['streak_count'] >= 3) ? '🔥🔥' : '🔥');
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>FAF - Convite de Circle</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@800&family=Inter:wght@400;600;900&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = { theme: { extend: { colors: { "faf-neon": "#c3f400" }, fontFamily: { "headline": ["Plus Jakarta Sans"], "body": ["Inter"] } } } }
    </script>
    <style>
        body { background-color: #080808; color: #fff; font-family: 'Inter', sans-serif; }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.05); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="fixed -top-20 -left-20 w-80 h-80 bg-faf-neon/10 blur-[100px] rounded-full -z-10"></div>

    <div class="max-w-sm w-full space-y-8 text-center">
        <h1 class="text-3xl font-headline font-black italic tracking-tighter uppercase">FAF<span class="text-faf-neon">.</span></h1>

        <div class="glass-card rounded-[45px] p-10 space-y-6">
            <div class="text-6xl"><?= $fire ?></div>
            <div>
                <p class="text-[9px] font-black uppercase tracking-[0.3em] text-faf-neon italic mb-2">Foste recrutado para</p>
                <h2 class="text-3xl font-headline font-black italic uppercase tracking-tighter leading-none"><?= htmlspecialchars($circle['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-[10px] font-black uppercase text-white/40 mt-3 tracking-widest"><?= (int)$circle['members'] ?> atletas · streak de <?= (int)$circle['streak_count'] ?> dias</p>
            </div>
            <p class="text-xs text-white/50 italic leading-relaxed">Se todos cumprirem os treinos do dia, o fogo do Circle cresce. Se alguém falhar, apaga-se — e toda a gente vê. Aceitas a pressão?</p>
            <a href="login.php" class="block w-full py-5 bg-faf-neon text-black rounded-2xl font-black uppercase italic text-sm tracking-widest shadow-[0_0_30px_rgba(195,244,0,0.2)] active:scale-95 transition-all">Entrar e juntar-me 🔥</a>
            <p class="text-[9px] text-white/25 italic">Cria conta ou entra — o convite fica guardado.</p>
        </div>
    </div>
</body>
</html>
