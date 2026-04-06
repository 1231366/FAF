<?php
require_once '../core/config.php';
session_start();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'create') {
    $name = $conn->real_escape_string($data['name'] ?? 'Novo Circle');
    
    // IMPORTANTE: Incluir o leader_id no INSERT para bater com a tua tabela
    $stmt = $conn->prepare("INSERT INTO circles (name, leader_id, streak_count) VALUES (?, ?, 0)");
    $stmt->bind_param("si", $name, $user_id);
    
    if ($stmt->execute()) {
        $circle_id = $conn->insert_id;
        // Atualiza o perfil do utilizador com o novo circle_id
        $update = $conn->prepare("UPDATE users SET circle_id = ? WHERE id = ?");
        $update->bind_param("ii", $circle_id, $user_id);
        $update->execute();
        echo json_encode(['success' => true]);
    } else {
        // Isso vai ajudar-te a ver o erro real no Console do Navegador
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
}