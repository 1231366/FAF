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

            $stmt_f = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'system')");
            $sys_msg = "Circle fundado. Que comece o fogo. 🔥";
            $stmt_f->bind_param("iis", $circle_id, $user_id, $sys_msg);
            $stmt_f->execute();

            echo json_encode(['success' => true, 'circle_id' => $circle_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao vincular utilizador: ' . $conn->error]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro ao criar Circle: ' . $conn->error]);
    }
}

// Entrar num circle existente através do código de convite (o próprio ID do circle).
if ($action === 'join') {
    $circle_id = intval($data['invite_code'] ?? 0);

    $stmt = $conn->prepare("SELECT id, name FROM circles WHERE id = ?");
    $stmt->bind_param("i", $circle_id);
    $stmt->execute();
    $circle = $stmt->get_result()->fetch_assoc();

    if (!$circle) {
        echo json_encode(['success' => false, 'error' => 'Circle não encontrado.']);
        exit();
    }

    $update = $conn->prepare("UPDATE users SET circle_id = ? WHERE id = ?");
    $update->bind_param("ii", $circle_id, $user_id);

    if ($update->execute()) {
        $_SESSION['circle_id'] = $circle_id;

        $stmt_u = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $joiner_name = $stmt_u->get_result()->fetch_assoc()['name'] ?? 'Um atleta';

        $stmt_f = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'system')");
        $sys_msg = "{$joiner_name} juntou-se ao Circle. Bem-vindo à unidade.";
        $stmt_f->bind_param("iis", $circle_id, $user_id, $sys_msg);
        $stmt_f->execute();

        echo json_encode(['success' => true, 'circle_name' => $circle['name']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro ao entrar no Circle: ' . $conn->error]);
    }
}

// Enviar uma mensagem de chat para o feed do circle do utilizador.
if ($action === 'send_message') {
    $stmt_c = $conn->prepare("SELECT circle_id FROM users WHERE id = ?");
    $stmt_c->bind_param("i", $user_id);
    $stmt_c->execute();
    $circle_id = $stmt_c->get_result()->fetch_assoc()['circle_id'] ?? null;

    if (!$circle_id) {
        echo json_encode(['success' => false, 'error' => 'Não pertences a nenhum Circle.']);
        exit();
    }

    $message = trim($data['message'] ?? '');
    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Mensagem vazia.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'user_action')");
    $stmt->bind_param("iis", $circle_id, $user_id, $message);
    echo json_encode(['success' => $stmt->execute()]);
}

// Buscar as últimas mensagens/eventos do feed do circle do utilizador.
if ($action === 'get_messages') {
    $stmt_c = $conn->prepare("SELECT circle_id FROM users WHERE id = ?");
    $stmt_c->bind_param("i", $user_id);
    $stmt_c->execute();
    $circle_id = $stmt_c->get_result()->fetch_assoc()['circle_id'] ?? null;

    if (!$circle_id) {
        echo json_encode(['success' => true, 'messages' => []]);
        exit();
    }

    $stmt = $conn->prepare("SELECT f.message, f.type, f.created_at, u.name, u.profile_pic
                             FROM circle_feed f LEFT JOIN users u ON f.user_id = u.id
                             WHERE f.circle_id = ? ORDER BY f.created_at DESC LIMIT 30");
    $stmt->bind_param("i", $circle_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
}
