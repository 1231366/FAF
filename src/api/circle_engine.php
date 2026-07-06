<?php
require_once '../core/config.php';
session_start();
header('Content-Type: application/json');

// Verificar se o utilizador está logado
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'create') {
    $name = $conn->real_escape_string($data['name'] ?? 'Novo Circle');
    
    // 1. Criar o Circle na tabela 'circles'
    $stmt = $conn->prepare("INSERT INTO circles (name, leader_id, streak_count) VALUES (?, ?, 0)");
    $stmt->bind_param("si", $name, $user_id);
    
    if ($stmt->execute()) {
        $circle_id = $conn->insert_id;
        
        // 2. Atualizar a tabela 'users' com o novo circle_id
        $update = $conn->prepare("UPDATE users SET circle_id = ? WHERE id = ?");
        $update->bind_param("ii", $circle_id, $user_id);
        
        if ($update->execute()) {
            // --- CORREÇÃO CRÍTICA ---
            // Atualizamos a sessão para que o public_hub.php saiba IMEDIATAMENTE 
            // que o utilizador já pertence a um Circle após o reload.
            $_SESSION['circle_id'] = $circle_id;
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao vincular utilizador: ' . $conn->error]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro ao criar Circle: ' . $conn->error]);
    }
}
?>