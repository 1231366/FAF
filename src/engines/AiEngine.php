<?php
class AiEngine {
    public static function ask($message, $userData, $missao) {
        $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
        
        $systemPrompt = "És o FastAsFuckAiCoach... [Teu Prompt Aqui]";

        $data = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => 0.4,
            'max_tokens' => 120
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . GROQ_KEY,
            'Content-Type: application/json'
        ]);

        $res = json_decode(curl_exec($ch), true);
        return $res['choices'][0]['message']['content'] ?? 'Erro neural.';
    }
}