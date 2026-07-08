<?php
require_once __DIR__ . '/../core/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'subscribe') {
    $sub = $data['subscription'] ?? [];
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh = $sub['keys']['p256dh'] ?? '';
    $auth = $sub['keys']['auth'] ?? '';

    if (!$endpoint || !$p256dh || !$auth) {
        echo json_encode(['success' => false, 'error' => 'Subscrição inválida.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)");
    $stmt->bind_param("isss", $user_id, $endpoint, $p256dh, $auth);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

if ($action === 'unsubscribe') {
    $endpoint = $data['endpoint'] ?? '';
    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
    $stmt->bind_param("si", $endpoint, $user_id);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
