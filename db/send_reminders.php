<?php
// db/send_reminders.php — envia notificações push. Correr por cron:
//   Diário de manhã (ex: 8h):  php db/send_reminders.php daily
//   Domingo à noite (ex: 20h): php db/send_reminders.php weekly
if (php_sapi_name() !== 'cli') {
    die("Run this from the CLI: php db/send_reminders.php [daily|weekly]\n");
}

require_once __DIR__ . '/../src/core/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$mode = $argv[1] ?? 'daily';

$vapid_public = $_ENV['VAPID_PUBLIC_KEY'] ?? '';
$vapid_private = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
if (!$vapid_public || !$vapid_private) {
    die("VAPID keys em falta no .env\n");
}

$webPush = new WebPush([
    'VAPID' => [
        'subject' => $_ENV['VAPID_SUBJECT'] ?? 'mailto:admin@faf.app',
        'publicKey' => $vapid_public,
        'privateKey' => $vapid_private,
    ],
]);

// Constrói a lista de (subscrição, payload) conforme o modo
$queue = [];

if ($mode === 'daily') {
    // Todos os utilizadores subscritos com treino pendente hoje
    $res = $conn->query("SELECT ps.endpoint, ps.p256dh, ps.auth, tp.workout_type, tp.distance, tp.pace, tp.phase, tp.is_deload
                          FROM push_subscriptions ps
                          JOIN training_plans tp ON tp.user_id = ps.user_id
                          WHERE tp.workout_date = CURDATE() AND tp.status = 'pending'");
    while ($row = $res->fetch_assoc()) {
        $body = "{$row['workout_type']} — " . number_format((float)$row['distance'], 1) . "km @ {$row['pace']}/km.";
        if ((int)$row['is_deload']) $body .= " Semana de descarga: leva com calma.";
        elseif ($row['phase'] === 'TAPER') $body .= " Taper: curto e afiado.";
        $queue[] = [$row, ['title' => 'FAF. Treino de hoje 🔥', 'body' => $body, 'tag' => 'faf-daily']];
    }
} elseif ($mode === 'weekly') {
    // Resumo semanal: km reais e consistência dos últimos 7 dias
    $res = $conn->query("SELECT ps.endpoint, ps.p256dh, ps.auth,
                            SUM(CASE WHEN tp.status='completed' THEN COALESCE(tp.real_distance, tp.distance) ELSE 0 END) km,
                            SUM(tp.status='completed') done, COUNT(*) total
                          FROM push_subscriptions ps
                          JOIN training_plans tp ON tp.user_id = ps.user_id
                          WHERE tp.workout_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND tp.workout_date <= CURDATE()
                          GROUP BY ps.id");
    while ($row = $res->fetch_assoc()) {
        if ((int)$row['total'] === 0) continue;
        $pct = round(((int)$row['done'] / (int)$row['total']) * 100);
        $body = number_format((float)$row['km'], 1) . "km esta semana · {$pct}% do plano cumprido. Abre a app para o recap completo.";
        $queue[] = [$row, ['title' => 'FAF. Weekly Recap 📊', 'body' => $body, 'tag' => 'faf-weekly']];
    }
}

$sent = 0;
foreach ($queue as [$row, $payload]) {
    $subscription = Subscription::create([
        'endpoint' => $row['endpoint'],
        'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
    ]);
    $webPush->queueNotification($subscription, json_encode($payload));
    $sent++;
}

// Envia tudo e limpa subscrições mortas (dispositivos que desativaram)
foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
        $dead = $report->getEndpoint();
        $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $stmt->bind_param("s", $dead);
        $stmt->execute();
        echo "Removida subscrição expirada.\n";
    }
}

echo "Modo '{$mode}': {$sent} notificações enfileiradas e enviadas.\n";
