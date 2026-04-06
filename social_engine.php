<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) exit(json_encode(['success' => false]));

$my_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$friend_id = (int)($data['friend_id'] ?? 0);

if (!$friend_id || $friend_id == $my_id) exit(json_encode(['success' => false]));

switch ($action) {
    case 'request':
        // 1. Verificar se já existe relação em qualquer sentido
        $check = $conn->prepare("SELECT id, user_id, status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $check->bind_param("iiii", $my_id, $friend_id, $friend_id, $my_id);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            // Se o outro já me pediu amizade, eu aceito logo
            if ($row['status'] == 'pending' && $row['user_id'] == $friend_id) {
                $upd = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?");
                $upd->bind_param("i", $row['id']);
                $upd->execute();
                echo json_encode(['success' => true, 'message' => 'Protocolo Sincronizado!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Já existe um pedido.']);
            }
        } else {
            // Criar novo pedido
            $ins = $conn->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
            $ins->bind_param("ii", $my_id, $friend_id);
            $ins->execute();
            echo json_encode(['success' => true]);
        }
        break;

    case 'accept':
        $stmt = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE friend_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $my_id, $friend_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $stmt = $conn->prepare("DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->bind_param("iiii", $my_id, $friend_id, $friend_id, $my_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;
}