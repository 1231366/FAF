<?php 
session_start(); 
if(!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
?>
<!DOCTYPE html>
<html class="dark" lang="pt">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"/>
    <title>FAF - Neural Diagnostic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-25..0" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;800&family=Inter:wght@400;600;900&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = { theme: { extend: { colors: { "faf-neon": "#c3f400", "faf-black": "#080808" }, fontFamily: { "headline": ["Plus Jakarta Sans"], "body": ["Inter"] } } } }
    </script>
    <style>
        :root { --safe-top: env(safe-area-inset-top); }
        body { background-color: #000; color: #fff; font-family: 'Inter', sans-serif; overflow: hidden; height: 100vh; position: fixed; width: 100%; }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.06); }
        .step-content { display: none; height: 100%; flex-direction: column; justify-content: center; }
        .step-content.active { display: flex; animation: slideUp 0.4s cubic-bezier(0.2, 1, 0.3, 1) forwards; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .progress-fill { height: 100%; background: #c3f400; transition: width 0.5s ease; box-shadow: 0 0 15px #c3f400; }
        .hex-icon { clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%); width: 60px; height: 60px; flex-shrink: 0; }
        .option-card.selected { border-color: #c3f400 !important; background: rgba(195, 244, 0, 0.1) !important; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.5; }
        .mission-glow { box-shadow: 0 0 20px rgba(195, 244, 0, 0.1); border-color: rgba(195, 244, 0, 0.2) !important; }
        .shake { animation: shake 0.4s ease-in-out; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
    </style>
</head>
<body class="antialiased">

    <form id="diag-form" action="save_diagnostic.php" method="POST" style="display:none;">
        <input type="hidden" name="weight" id="f-weight">
        <input type="hidden" name="height" id="f-height">
        <input type="hidden" name="age" id="f-age">
        <input type="hidden" name="volume_atual" id="f-volume-atual">
        <input type="hidden" name="target_dist" id="f-target-dist">
        <input type="hidden" name="race_date" id="f-race-date">
        <input type="hidden" name="target_pace" id="f-target-pace">
        <input type="hidden" name="current_pb_dist" id="f-pb-dist">
        <input type="hidden" name="current_pb_pace" id="f-pb-pace">
        <input type="hidden" name="weekly_days" id="f-days">
    </form>

    <nav class="fixed top-0 w-full z-[100] px-6 pt-[var(--safe-top)] bg-black/80 backdrop-blur-xl border-b border-white/5">
        <div class="flex items-center gap-4 py-4">
            <button onclick="prevStep()" id="back-btn" class="w-10 h-10 rounded-full glass-card flex items-center justify-center opacity-0 transition-all">
                <span class="material-symbols-outlined text-white text-xl">arrow_back</span>
            </button>
            <div class="flex-1 h-1 bg-white/10 rounded-full overflow-hidden">
                <div id="progress-bar" class="progress-fill" style="width: 10%;"></div>
            </div>
            <div class="w-10"></div>
        </div>
    </nav>

    <main id="main-content" class="h-full flex flex-col px-6 pt-24 pb-32 overflow-hidden">
        <div id="coach-section" class="mb-6 flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full border-2 border-faf-neon p-0.5 bg-zinc-900 overflow-hidden shadow-lg">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Tiago&backgroundColor=080808" class="w-full h-full scale-110">
                </div>
                <div>
                    <span class="font-black italic uppercase text-[10px] tracking-[0.3em] text-faf-neon block leading-none">Coach T</span>
                    <span class="text-[8px] text-white/30 uppercase font-black tracking-widest">Neural Calibration</span>
                </div>
            </div>
            <div class="glass-card p-4 rounded-3xl rounded-tl-none border-l-4 border-faf-neon">
                <p id="coach-text" class="text-sm font-semibold italic text-white/90 leading-relaxed"></p>
            </div>
        </div>

        <div class="flex-1 relative">
            <div id="step1" class="step-content active space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="glass-card p-6 rounded-[35px] text-center">
                        <span class="text-[9px] font-black uppercase text-white/30 block mb-2">Peso (kg)</span>
                        <input type="number" id="in-weight" placeholder="68" class="bg-transparent text-5xl font-black italic text-center w-full outline-none text-faf-neon">
                    </div>
                    <div class="glass-card p-6 rounded-[35px] text-center">
                        <span class="text-[9px] font-black uppercase text-white/30 block mb-2">Altura (cm)</span>
                        <input type="number" id="in-height" placeholder="175" class="bg-transparent text-5xl font-black italic text-center w-full outline-none text-faf-neon">
                    </div>
                </div>
                <div class="glass-card p-6 rounded-[35px] text-center">
                    <span class="text-[9px] font-black uppercase text-white/30 block mb-4 italic">Idade do Motor</span>
                    <div class="flex justify-center items-center gap-10">
                        <button onclick="adjustAge(-1)" class="w-12 h-12 rounded-2xl bg-white/5 flex items-center justify-center font-black text-2xl active:bg-faf-neon active:text-black transition-colors">-</button>
                        <span id="v-age" class="text-6xl font-black italic text-faf-neon tracking-tighter">25</span>
                        <button onclick="adjustAge(1)" class="w-12 h-12 rounded-2xl bg-white/5 flex items-center justify-center font-black text-2xl active:bg-faf-neon active:text-black transition-colors">+</button>
                    </div>
                </div>
                <button onclick="validateStep1()" class="w-full py-5 bg-white text-black rounded-[25px] font-black uppercase italic shadow-xl">Bio DNA Check</button>
            </div>

            <div id="step2" class="step-content space-y-3">
                <h2 class="text-3xl font-headline font-black italic uppercase tracking-tighter mb-4">Estado <span class="text-faf-neon">Real</span></h2>
                <div onclick="setExp('Zero', this)" class="option-card glass-card p-5 rounded-[30px] cursor-pointer flex justify-between items-center transition-all border border-white/5">
                    <div><span class="block font-black italic text-lg uppercase tracking-tight text-white/40">Sedentário</span><span class="text-[9px] text-faf-neon uppercase font-black tracking-widest">Não corro nem 1km</span></div>
                    <span class="material-symbols-outlined text-white/20">block</span>
                </div>
                <div onclick="setExp('Regular', this)" class="option-card glass-card p-5 rounded-[30px] cursor-pointer flex justify-between items-center transition-all border border-white/5">
                    <div><span class="block font-black italic text-lg uppercase tracking-tight">Ativo</span><span class="text-[9px] text-white/30 uppercase tracking-widest">Até 20km por semana</span></div>
                    <span class="material-symbols-outlined text-white/20">directions_run</span>
                </div>
                <div onclick="setExp('Pro', this)" class="option-card glass-card p-5 rounded-[30px] cursor-pointer flex justify-between items-center transition-all border border-white/5">
                    <div><span class="block font-black italic text-lg uppercase tracking-tight">Diesel DNA</span><span class="text-[9px] text-white/30 uppercase font-black tracking-widest">40km+ / Maratonista</span></div>
                    <span class="material-symbols-outlined text-faf-neon">bolt</span>
                </div>
            </div>

            <div id="step3" class="step-content space-y-3">
                <h2 class="text-3xl font-headline font-black italic uppercase tracking-tighter mb-4 text-faf-neon italic">The Mission</h2>
                <?php 
                $mS = [['v'=>5,'l'=>'5K','f'=>'Corrida 5km','c'=>'pink-500'], ['v'=>10,'l'=>'10K','f'=>'Corrida 10km','c'=>'orange-500'], ['v'=>21,'l'=>'21K','f'=>'Meia-maratona','c'=>'white'], ['v'=>42,'l'=>'42K','f'=>'Maratona','c'=>'faf-neon']];
                foreach($mS as $m): ?>
                <div onclick="setTargetDist(<?= $m['v'] ?>, this)" class="option-card glass-card p-4 rounded-[30px] flex items-center gap-5 cursor-pointer mission-glow">
                    <div class="hex-icon bg-<?= $m['c'] ?>/20 border border-<?= $m['c'] ?>/40 flex items-center justify-center w-12 h-12"><span class="font-black italic text-<?= $m['c'] ?> text-lg"><?= $m['l'] ?></span></div>
                    <span class="font-black italic text-xl uppercase text-white tracking-tighter"><?= $m['f'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="step4" class="step-content space-y-6">
                <div class="glass-card p-10 rounded-[45px] text-center border border-faf-neon/20">
                    <span class="text-[10px] font-black uppercase text-faf-neon tracking-[0.4em] block mb-4 italic">Target Date</span>
                    <input type="date" id="in-race-date" class="w-full bg-transparent text-4xl font-black italic outline-none text-center tracking-tighter text-white">
                </div>
                <button onclick="validateStep4()" class="w-full py-5 bg-white text-black rounded-[25px] font-black uppercase italic shadow-xl">Sincronizar Timeline</button>
            </div>

            <div id="step5" class="step-content space-y-6">
                <div class="glass-card p-6 rounded-[35px] text-center border-l-4 border-faf-neon">
                    <span class="text-[10px] font-black uppercase text-white/30 block mb-2 italic">Pace Objetivo</span>
                    <input type="text" id="in-target-pace" placeholder="4:30" class="bg-transparent text-6xl font-black italic text-center w-full outline-none text-faf-neon tracking-tighter">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="glass-card p-4 rounded-3xl text-center">
                        <span class="text-[8px] font-black uppercase text-white/30 block mb-1">Ref. KM</span>
                        <input type="number" id="in-pb-dist" placeholder="5" class="bg-transparent text-2xl font-black italic text-center w-full outline-none text-white">
                    </div>
                    <div class="glass-card p-4 rounded-3xl text-center">
                        <span class="text-[8px] font-black uppercase text-white/30 block mb-1">Pace Real</span>
                        <input type="text" id="in-pb-pace" placeholder="5:15" class="bg-transparent text-2xl font-black italic text-center w-full outline-none text-white">
                    </div>
                </div>
                <button onclick="validateStep5()" class="w-full py-5 bg-white text-black rounded-[25px] font-black uppercase italic shadow-xl">Calibrar Paces</button>
            </div>

            <div id="step6" class="step-content space-y-6">
                <div class="grid grid-cols-4 gap-3" id="week-selection">
                    <?php foreach(['Seg','Ter','Qua','Qui','Sex','Sab','Dom'] as $dia): ?>
                    <button onclick="toggleDay('<?= $dia ?>', this)" class="glass-card py-5 rounded-2xl font-black text-xs uppercase opacity-20 active:scale-90 transition-all"><?= $dia ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="bg-faf-neon/5 border border-faf-neon/20 p-5 rounded-3xl text-center">
                    <p class="text-[10px] text-faf-neon font-black uppercase italic tracking-widest leading-none">Define os teus dias de guerra</p>
                </div>
                <button onclick="save()" class="w-full py-6 bg-faf-neon text-black rounded-[25px] font-black uppercase italic shadow-[0_0_30px_#c3f40044]">Finalizar Setup</button>
            </div>

            <div id="step7" class="step-content items-center justify-center text-center">
                <div class="w-20 h-20 border-2 border-faf-neon/20 border-t-faf-neon rounded-full animate-spin"></div>
                <h2 class="text-2xl font-headline font-black italic uppercase text-faf-neon mt-8 tracking-tighter italic">Generating Protocol...</h2>
            </div>
        </div>
    </main>

    <script>
        let cur = 1; const total = 6; let days = [];
        const msgs = {
            1: "Eu sou o Coach T. Vamos configurar o teu motor biológico. Peso e altura definem o impacto mecânico.",
            2: "Sê sincero comigo: qual é a tua rodagem real hoje?",
            3: "A grande missão. Qual é a distância que vamos conquistar juntos?",
            4: "Data marcada. Quando pretendes elevar o teu DNA?",
            5: "Qual é o teu objetivo e que volume/ritmo consegues aguentar hoje?",
            6: "Último passo. Escolhe os dias da semana em que estás pronto para a guerra."
        };

        function showError(msg) {
            const coachText = document.getElementById('coach-text');
            const coachBubble = coachText.parentElement;
            coachText.innerText = msg;
            coachBubble.classList.add('shake', 'border-red-500');
            setTimeout(() => coachBubble.classList.remove('shake', 'border-red-500'), 500);
        }

        function validateStep1() {
            const w = document.getElementById('in-weight').value;
            const h = document.getElementById('in-height').value;
            if(!w || w < 30 || w > 250) return showError("Preciso de um peso real para calcular a carga neural.");
            if(!h || h < 100 || h > 250) return showError("Essa altura parece estranha. Verifica os dados.");
            nextStep();
        }

        function validateStep4() {
    const dateInput = document.getElementById('in-race-date').value;
    
    // Verifica se o campo está vazio
    if(!dateInput) {
        return showError("Tens de escolher uma data. O asfalto não espera.");
    }

    // Criar objetos de data do JavaScript
    const chosenDate = new Date(dateInput);
    const today = new Date();
    
    // Resetar as horas para comparar apenas os dias
    today.setHours(0, 0, 0, 0);

    if(chosenDate <= today) {
        return showError("A prova tem de ser no futuro, campeão!");
    }

    nextStep();
}

        function validateStep5() {
            const tp = document.getElementById('in-target-pace').value;
            const pd = document.getElementById('in-pb-dist').value;
            const pp = document.getElementById('in-pb-pace').value;
            if(!tp.includes(':')) return showError("Usa o formato M:SS para o Pace Objetivo.");
            if(!pd || pd <= 0) return showError("Diz-me qual foi a distância do teu último teste.");
            if(!pp.includes(':')) return showError("Usa o formato M:SS para o teu Pace Real.");
            nextStep();
        }

        function adjustAge(v) { let el = document.getElementById('v-age'); let n = parseInt(el.innerText) + v; if(n>=14 && n<=90) el.innerText = n; }
        
        function setExp(v, b) { 
            document.getElementById('f-volume-atual').value = v; 
            document.querySelectorAll('#step2 .option-card').forEach(c => c.classList.remove('selected')); 
            b.classList.add('selected'); 
            setTimeout(nextStep, 400); 
        }

        function setTargetDist(k, b) { 
            document.getElementById('f-target-dist').value = k; 
            document.querySelectorAll('#step3 .option-card').forEach(c => c.classList.remove('selected')); 
            b.classList.add('selected'); 
            setTimeout(nextStep, 400); 
        }

        function toggleDay(d, b) { 
            if(days.includes(d)) { 
                days = days.filter(x => x !== d); 
                b.classList.add('opacity-20'); 
                b.classList.remove('selected', 'border-faf-neon'); 
            } else { 
                days.push(d); 
                b.classList.remove('opacity-20'); 
                b.classList.add('selected', 'border-faf-neon'); 
            } 
            document.getElementById('f-days').value = days.join(','); 
        }

        function nextStep() {
            if(cur < total) { 
                document.getElementById('step'+cur).classList.remove('active'); 
                cur++; 
                document.getElementById('step'+cur).classList.add('active'); 
                updateUI(); 
            }
        }

        function prevStep() {
            if(cur > 1) { 
                document.getElementById('step'+cur).classList.remove('active'); 
                cur--; 
                document.getElementById('step'+cur).classList.add('active'); 
                updateUI(); 
            }
        }

        function updateUI() {
            document.getElementById('back-btn').style.opacity = cur > 1 ? "1" : "0";
            document.getElementById('back-btn').style.pointerEvents = cur > 1 ? "auto" : "none";
            document.getElementById('progress-bar').style.width = (cur/total * 100) + "%";
            document.getElementById('coach-text').innerText = msgs[cur];
        }

        function save() {
            if(days.length < 1) return showError("Escolhe pelo menos um dia para treinar!");
            
            // Sync final data to hidden fields
            document.getElementById('f-weight').value = document.getElementById('in-weight').value;
            document.getElementById('f-height').value = document.getElementById('in-height').value;
            document.getElementById('f-age').value = document.getElementById('v-age').innerText;
            document.getElementById('f-race-date').value = document.getElementById('in-race-date').value;
            document.getElementById('f-target-pace').value = document.getElementById('in-target-pace').value;
            document.getElementById('f-pb-dist').value = document.getElementById('in-pb-dist').value;
            document.getElementById('f-pb-pace').value = document.getElementById('in-pb-pace').value;

            document.getElementById('step'+total).classList.remove('active');
            document.getElementById('step7').classList.add('active');
            document.querySelector('nav').style.display = 'none';
            document.getElementById('coach-section').style.display = 'none';
            setTimeout(() => document.getElementById('diag-form').submit(), 3000);
        }

        window.onload = () => { document.getElementById('coach-text').innerText = msgs[1]; updateUI(); };
    </script>
</body>
</html>