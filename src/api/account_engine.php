<?php
// src/api/account_engine.php — apagar a conta. Irreversível: exige password.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../engines/circle_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action !== 'delete') {
    echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
    exit();
}

$stmt = $conn->prepare("SELECT password, google_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Contas Google não têm password local — não pedimos confirmação nesse caso.
if (empty($user['google_id'])) {
    $password = $data['password'] ?? '';
    if (empty($user['password']) || !password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'error' => 'Password incorreta.']);
        exit();
    }
}

// Sai do Circle primeiro (resolve liderança/dissolução antes de apagar o utilizador)
leaveCircle($conn, $user_id);

$conn->begin_transaction();
try {
    $tables = ['training_plans', 'friendships']; // sem ON DELETE CASCADE no schema
    $del1 = $conn->prepare("DELETE FROM training_plans WHERE user_id = ?");
    $del1->bind_param("i", $user_id); $del1->execute();

    $del2 = $conn->prepare("DELETE FROM friendships WHERE user_id = ? OR friend_id = ?");
    $del2->bind_param("ii", $user_id, $user_id); $del2->execute();

    // Preserva as mensagens que a pessoa deixou no feed de circles onde ainda tem amigos,
    // só desvincula o autor (circle_feed.user_id não tem FK, mas fica limpo assim).
    $del3 = $conn->prepare("UPDATE circle_feed SET user_id = NULL WHERE user_id = ?");
    $del3->bind_param("i", $user_id); $del3->execute();

    // user_profiles, push_subscriptions, user_badges, password_resets têm
    // ON DELETE CASCADE — apagar o utilizador já os limpa.
    $del4 = $conn->prepare("DELETE FROM users WHERE id = ?");
    $del4->bind_param("i", $user_id); $del4->execute();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Erro ao apagar conta: ' . $e->getMessage()]);
    exit();
}

session_destroy();
echo json_encode(['success' => true]);
