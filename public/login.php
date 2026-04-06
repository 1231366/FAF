<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
// Se já estiver logado, salta o login e vai para o plano
if(isset($_SESSION['user_id'])) { 
    header("Location: plan.php"); 
    exit(); 
}

// INTEGRAÇÃO GOOGLE AUTH
require_once 'vendor/autoload.php'; // Certifica-te que correstes 'composer require google/apiclient'

$client = new Google_Client();
$client->setClientId('35388883787-pco59ltnsthb73c1ho8o4iqafoir9cfu.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('http://localhost/FAF/google-callback.php');
$client->addScope("email");
$client->addScope("profile");

$google_login_url = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>FAF Running - Auth</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,700;0,800;1,800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { "primary": "#CCFF00", "dark-bg": "#080808", "card-bg": "#131313" },
                    fontFamily: { "headline": ["Plus Jakarta Sans"], "body": ["Inter"] }
                }
            }
        }
    </script>
    <style>
        body { background-color: #080808; color: #e5e2e1; font-family: 'Inter', sans-serif; overflow-x: hidden; }
        .glass-card { background: rgba(20, 20, 20, 0.8); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.05); }
        .auth-section { display: none; }
        .auth-section.active { display: block; animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .social-btn { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; gap: 0.75rem; width: 100%; border-radius: 1rem; padding: 1rem 0; }
        .social-btn:hover { background: rgba(255,255,255,0.05); border-color: rgba(204, 255, 0, 0.4); transform: translateY(-2px); }
        input:focus { border-color: #CCFF00 !important; box-shadow: 0 0 15px rgba(204, 255, 0, 0.1) !important; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="fixed -top-20 -left-20 w-80 h-80 bg-primary/10 blur-[100px] rounded-full -z-10 animate-pulse"></div>
    <div class="fixed -bottom-20 -right-20 w-80 h-80 bg-primary/5 blur-[100px] rounded-full -z-10"></div>

    <div class="w-full max-w-[380px] space-y-8">
        <div class="text-center">
            <h1 class="text-4xl font-black text-white italic tracking-tighter font-headline leading-none">FAF <span class="text-primary tracking-tighter">RUNNING</span></h1>
            <p class="text-[9px] uppercase tracking-[0.5em] text-gray-500 mt-3 font-bold opacity-70">The Athlete's Standard</p>
        </div>

        <div class="glass-card rounded-[40px] p-8 shadow-[0_20px_50px_rgba(0,0,0,0.5)]">
            
            <?php if(isset($_GET['error'])): ?>
                <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-2xl text-red-500 text-[10px] font-black uppercase tracking-widest text-center">
                    Credenciais Inválidas
                </div>
            <?php endif; ?>

            <?php if(isset($_GET['success'])): ?>
                <div class="mb-6 p-4 bg-primary/10 border border-primary/20 rounded-2xl text-primary text-[10px] font-black uppercase tracking-widest text-center">
                    Conta Criada! Faz Login
                </div>
            <?php endif; ?>
            
            <div id="socialButtons" class="space-y-3 mb-8">
                <a href="<?= $google_login_url ?>" class="social-btn bg-white/5">
                    <img src="https://www.svgrepo.com/show/475656/google-color.svg" class="w-5 h-5" alt="Google">
                    <span class="text-xs font-black italic tracking-tighter uppercase text-white">Continue com Google</span>
                </a>
                
                <button class="social-btn bg-white text-black">
                    <svg class="w-5 h-5" viewBox="0 0 384 512" fill="currentColor"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 21.8-88.5 21.8-11.4 0-51.1-20.8-83.6-20.1-42.9 .6-82.7 24.1-104.5 61.9-44.4 77.2-11.4 191.1 31.3 252.8 21 30.2 46.1 63.9 77.7 62.7 30.7-1.2 42.3-19.8 79.5-19.8 37.2 0 47.9 19.8 79.5 19.2 32.2-.6 53.6-30.7 73.4-59.5 22.8-33.4 32.2-65.7 32.7-67.4-.7-.3-63.5-24.4-63.8-96.9zm-63.1-150.3c15.4-18.7 25.8-44.6 23-70.5-22.3 1-49.1 15-65.1 33.7-14.3 16.5-26.8 43.1-23.4 68.3 24.9 1.9 50.1-12.8 65.5-31.5z"/></svg>
                    <span class="text-xs font-black italic tracking-tighter uppercase">Continue com Apple</span>
                </button>
            </div>

            <div class="relative flex items-center mb-8 opacity-30">
                <div class="flex-grow border-t border-white/20"></div>
                <span class="flex-shrink mx-4 text-[9px] font-black uppercase tracking-widest text-white">Ou Email</span>
                <div class="flex-grow border-t border-white/20"></div>
            </div>

            <section id="login" class="auth-section active">
                <form action="auth.php" method="POST" class="space-y-4">
                    <input name="email" type="email" placeholder="Email ID" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white outline-none focus:ring-0">
                    <div class="space-y-2">
                        <input name="password" type="password" placeholder="Password" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white outline-none focus:ring-0">
                        <div class="text-right px-1">
                            <button type="button" onclick="showSection('forgot')" class="text-[9px] font-black text-primary/40 hover:text-primary uppercase italic tracking-tighter transition-colors">Esqueceste-te da senha?</button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-primary/10 border border-primary/20 py-4 rounded-2xl text-primary font-black italic text-base tracking-tighter hover:bg-primary hover:text-black transition-all active:scale-95">
                        ENTRAR NA SESSÃO ➔
                    </button>
                </form>
                <p class="text-center mt-6 text-[11px] text-gray-500 font-medium uppercase tracking-tight">
                    És novo? <button onclick="showSection('signup')" class="text-white font-black italic ml-1 hover:text-primary transition-colors">CRIAR CONTA</button>
                </p>
            </section>

            <section id="signup" class="auth-section">
                <form action="register_action.php" method="POST" class="space-y-4">
                    <input name="name" type="text" placeholder="Nome Completo" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white placeholder:text-gray-600 focus:ring-0">
                    <input name="email" type="email" placeholder="Email" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white placeholder:text-gray-600 focus:ring-0">
                    <input name="password" type="password" placeholder="Criar Password" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white placeholder:text-gray-600 focus:ring-0">
                    <button type="submit" class="w-full bg-primary py-4 rounded-2xl text-black font-black italic text-base tracking-tighter shadow-lg hover:scale-[1.02] active:scale-95 transition-all">
                        FINALIZAR REGISTO ⚡
                    </button>
                </form>
                <p class="text-center mt-6 text-[11px] text-gray-500 font-medium uppercase tracking-tight">
                    Já tens conta? <button onclick="showSection('login')" class="text-white font-black italic ml-1 hover:text-primary transition-colors">FAZER LOGIN</button>
                </p>
            </section>

            <section id="forgot" class="auth-section">
                <div class="text-center space-y-2 mb-6">
                    <h3 class="text-lg font-black text-white italic uppercase tracking-tighter">Recuperar Acesso</h3>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest leading-relaxed px-4">Enviaremos um link para redefinir a tua password.</p>
                </div>
                <form action="recovery_action.php" method="POST" class="space-y-4">
                    <input name="email" type="email" placeholder="O teu email de atleta" required class="w-full bg-black/40 border-white/5 rounded-2xl p-4 text-sm text-white outline-none focus:ring-0">
                    <button type="submit" class="w-full bg-primary py-4 rounded-2xl text-black font-black italic text-base tracking-tighter">ENVIAR LINK</button>
                </form>
                <button onclick="showSection('login')" class="w-full mt-4 text-[10px] font-black text-gray-500 uppercase italic hover:text-white transition-colors">Voltar</button>
            </section>
        </div>
    </div>

    <script>
        function showSection(id) {
            document.querySelectorAll('.auth-section').forEach(s => s.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            
            if (id === 'forgot') {
                document.getElementById('socialButtons').style.display = 'none';
            } else {
                document.getElementById('socialButtons').style.display = 'block';
            }
        }
    </script>
</body>
</html>