<?php
// src/core/invite.php — completa um convite de Circle pendente após o login.
// Chamado por auth.php e google-callback.php logo a seguir à autenticação.
function consumePendingCircleInvite($conn, $user_id) {
    $circle_id = (int)($_SESSION['pending_circle_invite'] ?? 0);
    if (!$circle_id) return;
    unset($_SESSION['pending_circle_invite']);

    $stmt = $conn->prepare("SELECT id FROM circles WHERE id = ?");
    $stmt->bind_param("i", $circle_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) return;

    $upd = $conn->prepare("UPDATE users SET circle_id = ? WHERE id = ?");
    $upd->bind_param("ii", $circle_id, $user_id);
    $upd->execute();
    $_SESSION['circle_id'] = $circle_id;

    $stmt_u = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $stmt_u->bind_param("i", $user_id);
    $stmt_u->execute();
    $joiner = $stmt_u->get_result()->fetch_assoc()['name'] ?? 'Um atleta';
    $msg = "{$joiner} juntou-se ao Circle via convite. Bem-vindo à unidade.";
    $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, ?, ?, 'system')");
    $ins->bind_param("iis", $circle_id, $user_id, $msg);
    $ins->execute();
}
