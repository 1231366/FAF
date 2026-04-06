<?php
require_once '../core/config.php';
session_start();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$friend_id = intval($data['friend_id'] ?? 0);

if ($action === 'request') {
    $stmt = $conn->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("ii", $user_id, $friend_id);
    echo json_encode(['success' => $stmt->execute()]);
}

if ($action === 'accept') {
    // Atualiza o pedido original
    $stmt = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE user_id = ? AND friend_id = ?");
    $stmt->bind_param("ii", $friend_id, $user_id);
    $stmt->execute();
    
    // Cria a relação bilateral para facilitar as queries do Circle
    $stmt2 = $conn->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'accepted') ON DUPLICATE KEY UPDATE status='accepted'");
    $stmt2->bind_param("ii", $user_id, $friend_id);
    echo json_encode(['success' => $stmt2->execute()]);
}

if ($action === 'delete') {
    $stmt = $conn->prepare("DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
    $stmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
    echo json_encode(['success' => $stmt->execute()]);
}