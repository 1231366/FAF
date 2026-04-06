<?php
require_once '../core/config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

// Ação de Pesquisa
if (($data['action'] ?? '') === 'search') {
    $search_id = intval($data['athlete_id']);
    $stmt = $conn->prepare("SELECT id, name, profile_pic FROM users WHERE id = ? AND id != ?");
    $stmt->bind_param("ii", $search_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        echo json_encode(['success' => true, 'user' => $result]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Atleta não encontrado']);
    }
}