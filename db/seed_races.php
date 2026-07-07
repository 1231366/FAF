<?php
// db/seed_races.php — popula/atualiza a tabela `races`. Re-corre a qualquer
// altura para atualizar: usa (name, race_date) como chave para evitar
// duplicados. Uso: php db/seed_races.php
if (php_sapi_name() !== 'cli') {
    die("Run this from the CLI: php db/seed_races.php\n");
}

require_once __DIR__ . '/../src/core/config.php';

function upsertRace($conn, $name, $date, $distance_km, $city, $country, $source_url) {
    $stmt = $conn->prepare("INSERT INTO races (name, race_date, distance_km, city, country, source_url)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE distance_km = VALUES(distance_km), city = VALUES(city),
                                country = VALUES(country), source_url = VALUES(source_url), scraped_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("ssdsss", $name, $date, $distance_km, $city, $country, $source_url);
    return $stmt->execute();
}

function httpGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; FAFRaceBot/1.0; +https://faf.app)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);
    $ok = !curl_errno($ch);
    curl_close($ch);
    return $ok ? $body : null;
}

// --- 1. INTERNACIONAL: RunDida (API real, gratuita, sem chave) ---
// Cobre as grandes maratonas mundiais (Boston, Londres, Berlim, etc).
// Assume distância de maratona (42.195km) para todas as entradas.
function seedRunDida($conn) {
    $body = httpGet('https://rundida.com/api/marathons.json');
    if (!$body) { echo "RunDida: falha ao obter dados.\n"; return 0; }

    $data = json_decode($body, true);
    $marathons = $data['active'] ?? [];
    $count = 0;

    foreach ($marathons as $m) {
        $name = $m['name']['en'] ?? (is_string($m['name'] ?? null) ? $m['name'] : null);
        $date = $m['date'] ?? null;
        if (!$name || !$date) continue;

        // Nome vem como "2026 Chengdu Marathon - Oct 25" — remove o sufixo da data.
        $name = trim(preg_replace('/\s*-\s*[A-Za-z]{3}\s*\d{1,2}$/', '', $name));
        $date_only = substr($date, 0, 10); // ISO 8601 -> YYYY-MM-DD

        if (upsertRace($conn, $name, $date_only, 42.195, $m['city'] ?? null, $m['country'] ?? null, $m['url'] ?? null)) {
            $count++;
        }
    }
    return $count;
}

// --- 2. PORTUGAL: portugalrunning.com (scraping, permitido pelo robots.txt) ---
// A página tem blocos JSON-LD (schema.org/Event) por prova, mais fiáveis
// que fazer parsing do HTML circundante. Só grava a distância quando
// consegue extrair um valor razoável da descrição (evita dados incertos).
function seedPortugalRunning($conn, $url) {
    $body = httpGet($url);
    if (!$body) { echo "PortugalRunning ($url): falha ao obter página.\n"; return 0; }

    preg_match_all('/<script type="application\/ld\+json">(\{.*?"@type":\s*"Event".*?\})<\/script>/s', $body, $matches);
    $count = 0;

    foreach ($matches[1] as $json) {
        $event = json_decode($json, true);
        if (!$event || empty($event['name']) || empty($event['startDate'])) continue;

        $name = html_entity_decode(trim($event['name']), ENT_QUOTES, 'UTF-8');
        $date = date('Y-m-d', strtotime($event['startDate']));
        $city = null; $country = 'PT';
        if (!empty($event['location'][0]['name'])) {
            $city = html_entity_decode($event['location'][0]['name'], ENT_QUOTES, 'UTF-8');
        }

        // Tenta extrair "X km" da descrição; ignora se não encontrar nada plausível.
        $distance_km = null;
        if (!empty($event['description']) && preg_match('/(\d{1,3}(?:[.,]\d)?)\s*km/i', $event['description'], $dm)) {
            $val = (float)str_replace(',', '.', $dm[1]);
            if ($val >= 1 && $val <= 250) $distance_km = $val;
        }
        if ($distance_km === null) continue; // sem distância fiável, não vale a pena guardar

        if (upsertRace($conn, $name, $date, $distance_km, $city, $country, $event['url'] ?? $url)) {
            $count++;
        }
    }
    return $count;
}

echo "A sincronizar RunDida (internacional)...\n";
$n1 = seedRunDida($conn);
echo "  {$n1} maratonas internacionais.\n";

echo "A sincronizar Portugal Running (Portugal)...\n";
$n2 = seedPortugalRunning($conn, 'https://www.portugalrunning.com/calendario-de-corridas/');
$n3 = seedPortugalRunning($conn, 'https://www.portugalrunning.com/calendario-de-corridas-de-estrada/');
echo "  " . ($n2 + $n3) . " provas portuguesas.\n";

$total = $conn->query("SELECT COUNT(*) c FROM races")->fetch_assoc()['c'];
echo "Total na tabela races: {$total}\n";
