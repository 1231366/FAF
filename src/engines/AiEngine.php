<?php
// src/engines/AiEngine.php
require_once __DIR__ . '/../core/config.php';

class AiEngine {
    /**
     * @param string $message Mensagem do user
     * @param array $userData Perfil biométrico
     * @param string $historico Resultados dos treinos passados
     * @param string $futuro Próximos treinos do plano
     */
    public static function ask($message, $userData, $historico, $futuro) {
        $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
        
        // BIOMETRIA E DADOS FIXOS
        $peso = $userData['weight'] ?? '78';
        $alvo = $userData['target_distance'] ?? '21';
        $nome = explode(' ', $userData['name'] ?? 'Tiago')[0];

        $systemPrompt = "És o FAF Neural Coach. ANALISTA DE PERFORMANCE DE ELITE. Estás proibido de ser um chatbot genérico. 

        BIO-DATA DO ATLETA (NÃO PERGUNTES):
        - Atleta: {$nome} | Peso: {$peso}kg | Objetivo: {$alvo}K (Meia Maratona).
        - Budget para Gear: 150€ | Terreno: Estrada | Lesões: Nenhuma.

        NEURAL CONTEXT (TU JÁ SABES ISTO):
        - HISTÓRICO: {$historico}
        - PLANO FUTURO: {$futuro}

        DIRETRIZES DE RESPOSTA (CRITICAL):
        1. ANÁLISE DE ERRO: Se o utilizador reportou um treino (ex: 6k @ 5:15) e o alvo era 4:42, identifica o desvio de 33s/km. Não digas 'bom desempenho'. Diz: 'Pace 33s acima do alvo. Faltou gestão de lactato ou oxigenação?'.
        2. CONSULTORIA DE SAPATILHAS: Se o user pedir sapatilhas, TU JÁ TENS OS DADOS (78kg, 150€, estrada). NÃO PERGUNTES O BUDGET. Recomenda modelos específicos como: ASICS Novablast 4, Saucony Ride ou Brooks Ghost. Explica que com 78kg e alvo de 21k, o amortecimento versátil é inegociável.
        3. PRÓXIMO PASSO: Refere sempre o próximo treino (Ex: 'Amanhã tens 3.5k Easy @ 6:43'). Proíbe o utilizador de acelerar em treinos de recuperação.
        4. ZERO AMNÉSIA: Se a informação está no contexto acima, usa-a. Repetir perguntas é falha de protocolo.
        5. TOM: Elite, seco, biomecânico, brutalmente honesto. PT-PT (Portugal).
        6. LIMITE: Máximo 45 palavras. Sê cirúrgico.";

        $data = [
            'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => 0.3, // Baixa temperatura = Maior precisão e menos 'conversa'
            'max_tokens' => 300
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . GROQ_KEY,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            return 'Sincronização Offline.';
        }

        $res = json_decode($response, true);
        curl_close($ch);

        return $res['choices'][0]['message']['content'] ?? 'Erro de processamento neural.';
    }

    /**
     * Reescreve as descrições de um plano gerado para variedade/motivação,
     * num único pedido em lote. As regras já fixaram distância/pace/fase —
     * a IA só pode mudar o texto. Qualquer falha (rede, JSON inválido,
     * contagem errada) devolve as descrições originais sem quebrar a geração.
     *
     * @param array $workouts Lista de treinos com 'type','dist','pace','phase','desc'
     * @return array Lista de descrições (mesma ordem/tamanho que $workouts)
     */
    public static function varietyPass(array $workouts) {
        $fallback = array_column($workouts, 'desc');
        if (empty($workouts) || empty(GROQ_KEY)) return $fallback;

        $lines = [];
        foreach ($workouts as $i => $w) {
            $lines[] = "{$i}|{$w['type']}|" . round($w['dist'], 1) . "km|{$w['pace']}/km|{$w['phase']}";
        }

        $systemPrompt = "Cada linha de input tem o formato indice|tipo|distancia|pace|fase (ex: \"0|LONGÃO|8km|6:00/km|BASE\"). "
            . "Para cada linha, escreve APENAS uma frase motivacional curta em PT-PT (máx 20 palavras) que descreva esse treino, "
            . "mantendo o objetivo fisiológico do tipo. NUNCA incluas o índice, o tipo, a distância, o pace ou o caractere '|' "
            . "no texto de saída — só a frase. Exemplo de output correto para duas linhas: "
            . "[\"Ritmo confortável para construir a tua base de resistência.\", \"Recuperação ativa para limpar o lactato.\"]. "
            . "Responde SÓ com esse array JSON de strings, na mesma ordem e tamanho do input, sem mais texto.";

        $data = [
            'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => implode("\n", $lines)]
            ],
            'temperature' => 0.6,
            'max_tokens' => 4000,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . GROQ_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $failed = curl_errno($ch);
        curl_close($ch);
        if ($failed) return $fallback;

        $res = json_decode($response, true);
        $content = trim($res['choices'][0]['message']['content'] ?? '');
        $content = preg_replace('/^```(json)?|```$/m', '', $content);
        $rewritten = json_decode(trim($content), true);

        if (!is_array($rewritten) || count($rewritten) !== count($workouts)) return $fallback;
        foreach ($rewritten as $r) {
            // Rejeita a resposta inteira se alguma linha ainda tiver a cara do
            // formato de input (índice|tipo|dist|pace) em vez de texto motivacional.
            if (!is_string($r) || trim($r) === '' || str_contains($r, '|') || preg_match('/^\d+[\s|]/', trim($r))) {
                return $fallback;
            }
        }
        return array_values($rewritten);
    }
}