# 🧬 FAF Running: The Scientific Kernel & Technical Manual (v1.0)
> **Motto:** "Elite Physiology. Zero Bullshit. Fast As Fuck."

Este documento serve como a especificação técnica oficial para o motor de treino adaptativo do **FAF Running**. Ao contrário de apps genéricas, o FAF utiliza um modelo de **Autorregulação Biológica** para ajustar o treino em tempo real.

---

## 1. Mapeamento de Perfis (The DNA Matrix)

O algoritmo classifica o utilizador em eixos multidimensionais para evitar o erro do "treino médio".

### A. Fenótipos Musculares (Recrutamento de Fibras)
| Perfil | Bio-assinatura | Estratégia FAF |
| :--- | :--- | :--- |
| **Explosive Sprinter** | VAM alta / Decaimento >15% após 3km. | Foco em capilarização (Z2) e intervalos longos. |
| **Endurance Diesel** | VAM baixa / Ritmo constante 10km+. | Foco em recrutamento neuromuscular (Sprints/Subidas). |
| **Balanced Athlete** | Progressão linear em todas as distâncias. | Periodização clássica (Base -> Construção -> Pico). |

### B. Variáveis Bio-Sociais
* **Active Smoker:** Fator de correção de $V_O2$ (Redução de 3-5% no limiar). Rácio de descanso em intervalos aumentado para 1:1.5 ou 1:2.
* **High-Cortisol User:** Identificado por HRV baixo ou RPE alto em repouso. O sistema converte treinos de Z4 em Z2 para proteção do sistema imunitário.
* **Cycle Syncing (Feminino):** * *Fase Folicular:* Máxima carga e intensidade.
    * *Fase Lútea:* Redução de volume em 15% e aumento de hidratação programada.

---

## 2. Zonas de Intensidade FAF (The Paces)

Baseado no **Pace de Referência ($P_{ref}$)** (Tempo atual de 5km).

| Zona | Nome | Intensidade | Fisiologia Aplicada |
| :--- | :--- | :--- | :--- |
| **Z1** | **Recovery** | > 130% $P_{ref}$ | Lavagem de lactato e regeneração ativa. |
| **Z2** | **$P_{base}$** | 115% - 125% | Biogénese mitocondrial. Onde se ganha "caixa". |
| **Z3** | **Tempo** | 105% - 110% | Eficiência metabólica (mistura gordura/glicogénio). |
| **Z4** | **Threshold** | 98% - 102% | Expansão do Limiar de Lactato. Velocidade de cruzeiro. |
| **Z5** | **FAF Speed** | 90% - 95% | $V_O2$ Máximo. Recrutamento de fibras tipo II. |

---

## 3. Algoritmo de Progressão e Carga (ACWR)

O FAF utiliza o **Acute:Chronic Workload Ratio (ACWR)** para prever lesões.

1.  **Carga Aguda (7 dias):** Soma do esforço da última semana.
2.  **Carga Crónica (28 dias):** Média das últimas 4 semanas.
3.  **The Sweet Spot:** O rácio entre Aguda/Crónica deve manter-se entre **0.8 e 1.3**. 
    * *Se ACWR > 1.5:* **RISCO DE LESÃO ALTO.** O app bloqueia treinos de impacto.

### Regras de Ouro:
* **Aumento de Volume:** Máximo 10% por semana.
* **Semana de Deload:** Cada 4ª semana tem redução de 30% em kms para permitir supercompensação.

---

## 4. O Ciclo de Feedback (Real-Time Adaptation)

O plano reescreve-se após cada treino baseado no **RPE (Rate of Perceived Exertion)**.

### Matriz de Reajuste:
* **RPE 9-10 (Inesperado):** O algoritmo assume fadiga acumulada. Reduz o volume do próximo treino em 20%.
* **RPE < 4 (Em treino de intensidade):** O algoritmo assume evolução. Aumenta os paces alvo em 2% para a semana seguinte.
* **Feedback "Falta de Ar" (Fumadores/Asmáticos):** O sistema não mexe na velocidade, mas insere **pausas de micro-recuperação** no meio de séries longas.

---

## 5. Estrutura de Treino Semanal (Native App View)

O ecrã do utilizador apresenta 3 pilares:

### I. Corrida (The Mission)
* Visualização clara de kms, pace e **porquê** técnico (Ex: *"Este treino ensina o teu corpo a reciclar o lactato como combustível"*).

### II. Força em Casa (FAF Strong)
* **Sprinter:** Treinos de resistência muscular (muitas reps, pouco peso).
* **Diesel:** Pliometria e explosão (saltos, saltos de caixa).
* **Fumador:** Treinos de mobilidade torácica para melhorar a expansão pulmonar.

### III. Recovery & Wellness
* Sugestões de sono e hidratação baseadas na intensidade do dia anterior.

---

## 6. Implementação de Dados (Exemplo JSON)

```json
{
  "workout_engine": {
    "calculation_method": "Modified_Jack_Daniels_VDOT",
    "adaptation_trigger": "Post_Run_RPE",
    "safety_layer": "ACWR_1.3_Limit"
  },
  "adjustments": {
    "smoker_correction": -0.05,
    "recovery_buffer_hours": 36
  }
}