<?php
require_once __DIR__ . '/../src/core/config.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null; $success = false;

$stmt = $conn->prepare("SELECT pr.user_id, pr.expires_at, pr.used_at FROM password_resets pr WHERE pr.token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$reset = $stmt->get_result()->fetch_assoc();

$valid = $reset && !$reset['used_at'] && strtotime($reset['expires_at']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $pass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (strlen($pass) < 6) {
        $error = "A password tem de ter pelo menos 6 caracteres.";
    } elseif ($pass !== $confirm) {
        $error = "As passwords não coincidem.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $hash, $reset['user_id']);
        $upd->execute();

        $mark = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
        $mark->bind_param("s", $token);
        $mark->execute();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>FAF - Nova Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,700;0,800;1,800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { "primary": "#CCFF00", "dark-bg": "#080808" }, fontFamily: { "headline": ["Plus Jakarta Sans"], "body": ["Inter"] } } } }
    </script>
    <style>
        body { background-color: #080808; color: #e5e2e1; font-family: 'Inter', sans-serif; }
        .glass-card { background: rgba(20, 20, 20, 0.8); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.05); }
        input:focus { border-color: #CCFF00 !important; box-shadow: 0 0 15px rgba(204, 255, 0, 0.1) !important; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-[380px] space-y-8">
        <div class="text-center">
            <h1 class="text-4xl font-black text-white italic tracking-tighter font-headline leading-none">FAF <span class="text-primary">RUNNING</span></h1>
        </div>
        <div class="glass-card rounded-[40px] p-8 shadow-[0_20px_50px_rgba(0,0,0,0.5)]">
            <?php if(!$valid && !$success): ?>
                <div class="text-center space-y-4">
                    <h3 class="text-lg font-black text-white italic uppercase">Link inválido ou expirado</h3>
                    <p class="text-xs text-gray-400">Pede um novo link de recuperação.</p>
                    <a href="login.php" class="inline-block mt-4 text-primary font-black italic underline text-sm">Voltar ao login</a>
                </div>
            <?php elseif($success): ?>
                <div class="text-center space-y-4">
                    <h3 class="text-lg font-black text-white italic uppercase">Password atualizada ✓</h3>
                    <a href="login.php" class="inline-block mt-4 w-full bg-primary py-4 rounded-2xl text-black font-black italic text-base tracking-tighter">ENTRAR</a>
                </div>
            <?php else: ?>
                <h3 class="text-lg font-black text-white italic uppercase mb-6 text-center">Nova Password</h3>
                <?php if($error): ?><div class="mb-4 p-3 bg-red-500/10 border border-red-500/20 rounded-2xl text-red-500 text-[10px] font-black uppercase text-center"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <input name="password" type="password" placeholder="Nova Password" required minlength="6" class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white outline-none">
                    <input name="confirm" type="password" placeholder="Confirmar Password" required minlength="6" class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white outline-none">
                    <button type="submit" class="w-full bg-primary py-4 rounded-2xl text-black font-black italic text-base tracking-tighter">DEFINIR PASSWORD</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
