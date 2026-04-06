# 🧬 FAF Running: Neural Performance Engine

> **"Elite Physiology. Zero Bullshit. Fast As Fuck."**

![FAF Banner](https://img.shields.io/badge/Architecture-Neural--Adaptative-c3f400?style=for-the-badge&logo=cpu)
![PHP](https://img.shields.io/badge/Backend-PHP%208.2-777BB4?style=for-the-badge&logo=php)
![AI](https://img.shields.io/badge/AI-Llama%203.3%20(Groq)-orange?style=for-the-badge&logo=meta)
![UI](https://img.shields.io/badge/UI-Glassmorphism-black?style=for-the-badge&logo=tailwind-css)

**Live Demo:** [faf-running.tiagosilva.org](https://fastasfuck.tiagosilva.org)  
*(Nota: Neural Coach funcional em ambiente local; em produção requer hosting com suporte a cURL externo)*

---

## 🏗️ Stack Tecnológica

| Camada | Tecnologia |
|--------|------------|
| **Frontend** | HTML5 + Tailwind CSS + SortableJS |
| **Backend** | PHP 8.2 (Pure Vanilla - Zero Frameworks) |
| **AI Engine** | Llama 3.3 70B via Groq API (Inference < 1s) |
| **Database** | MySQL |
| **Auth** | Google OAuth 2.0 |

---

## 🎯 O Problema & A Solução

O mercado está inundado de apps de corrida com planos estáticos e caros (Runna, Strava Premium). O **FAF Running** introduz o conceito de **Autorregulação Biológica**: um sistema que reage ao desempenho real, peso e nível de fadiga, utilizando IA para ajustar o protocolo de treino em tempo real.

---

## 🎬 Demonstração Visual (UI aesthetic optimized)

<div align="left">

### 1. Diagnóstico e DNA do Atleta
Geração de plano baseada em biometria real: idade, peso, histórico de fumador e volume atual.
<br>
<img src="./img/perguntasplano.gif" alt="Onboarding Process" width="350" style="border-radius: 20px; border: 1px solid rgba(195,244,0,0.2); box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-top: 10px;">

<br><br>

### 2. Neural Coach (Interface Imersiva)
Interação direta com o Llama 3.3. O Coach analisa desvios de pace e sugere modelos de sapatilhas baseados no teu IMC e budget.
<br>
<img src="./img/chatbot.gif" alt="Neural Coach Chat" width="350" style="border-radius: 20px; border: 1px solid rgba(195,244,0,0.2); box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-top: 10px;">

<br><br>

### 3. Gestão e Reordenação Neural
Feedback de treinos com análise de RPE e reordenação da semana via Drag & Drop (AJAX Sync).
<br>

</div>

---

## 🧠 O Kernel Fisiológico (The Math)

O motor do FAF não usa "ifs" genéricos, usa ciência:

### 🧪 VDOT (Jack Daniels Formula)
Cálculo de zonas de intensidade precisas (Easy, Threshold, Interval) baseadas no consumo de oxigénio do atleta.

### 📉 ACWR (Prevenção de Lesões)
Monitorização do rácio de carga (Aguda vs Crónica). Se o ACWR ultrapassar **1.5**, o Coach ativa o "Safe Mode" para evitar fraturas de stress e sobrecarga.

### ⚡ RPE-Matrix (Autorregulação)
Se o utilizador falha o pace alvo (ex: 5:15 vs 4:42) com esforço elevado, o sistema identifica fadiga neuromuscular e recalcula o volume da semana seguinte.

---

## 🗺️ Roadmap de Evolução

### Fase 1: Infraestrutura & Segurança 🔒
* **Migração de Hosting:** Sair de ambientes restritos para VPS (DigitalOcean) para total conectividade de IA.
* **Auth Core:** Sistema de confirmação de email e recuperação de password nativa.

### Fase 2: Social Syndicate (The Circle) 🛡️
* **Circles/Clãs:** Grupos de treino fechados com ranking interno.
* **Team Flame:** Uma chama de equipa inspirada em streaks. Se o clã cumpre o plano, a chama brilha; se alguém falhar, a chama esvanece (Responsabilidade Social).
* **Syndicate Feed:** Mural de atividades para motivação e cobrança entre membros.

### Fase 3: Deep Integration ⌚
* **Sync com Garmin/Strava:** Importação automática de atividades via API.
* **Análise de Segmentos:** O Coach analisará parciais de intervalos para detetar quebras de resistência específicas.

---

## 📁 Estrutura do Projeto

```text
faf-running/
├── public/                 # Interface (plan.php, login.php)
├── src/
│   ├── api/                # Endpoints (checkin, reorder, check)
│   ├── core/               # Configuração e Base de Dados
│   └── engines/            # Kernel VDOT e AI Engine
├── img/                    # Assets e GIFs Otimizados
└── vendor/                 # Google & Groq SDKs
