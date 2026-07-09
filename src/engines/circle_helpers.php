<?php
// src/engines/circle_helpers.php — lógica partilhada de saída de um Circle,
// usada tanto pela ação 'leave' do circle_engine.php como pelo apagar de conta.
if (!function_exists('leaveCircle')) {
    function leaveCircle($conn, $user_id) {
        $stmt = $conn->prepare("SELECT circle_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $circle_id = $stmt->get_result()->fetch_assoc()['circle_id'] ?? null;
        if (!$circle_id) return;

        $stmt_u = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $leaver_name = $stmt_u->get_result()->fetch_assoc()['name'] ?? 'Um atleta';

        // Desvincula o utilizador do circle já a seguir
        $upd = $conn->prepare("UPDATE users SET circle_id = NULL WHERE id = ?");
        $upd->bind_param("i", $user_id);
        $upd->execute();

        $stmt_c = $conn->prepare("SELECT leader_id FROM circles WHERE id = ?");
        $stmt_c->bind_param("i", $circle_id);
        $stmt_c->execute();
        $circle = $stmt_c->get_result()->fetch_assoc();
        if (!$circle) return;

        $stmt_rem = $conn->prepare("SELECT id FROM users WHERE circle_id = ? ORDER BY id LIMIT 1");
        $stmt_rem->bind_param("i", $circle_id);
        $stmt_rem->execute();
        $remaining = $stmt_rem->get_result()->fetch_assoc();

        if ((int)$circle['leader_id'] !== (int)$user_id) {
            // Membro normal: só regista a saída no feed.
            $msg = "{$leaver_name} saiu do Circle.";
            $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, NULL, ?, 'system')");
            $ins->bind_param("is", $circle_id, $msg);
            $ins->execute();
            return;
        }

        if ($remaining) {
            // Líder saiu mas ficam membros: promove o mais antigo.
            $new_leader_id = (int)$remaining['id'];
            $upd_leader = $conn->prepare("UPDATE circles SET leader_id = ? WHERE id = ?");
            $upd_leader->bind_param("ii", $new_leader_id, $circle_id);
            $upd_leader->execute();

            $msg = "{$leaver_name} saiu do Circle. Nova liderança atribuída.";
            $ins = $conn->prepare("INSERT INTO circle_feed (circle_id, user_id, message, type) VALUES (?, NULL, ?, 'system')");
            $ins->bind_param("is", $circle_id, $msg);
            $ins->execute();
        } else {
            // Líder era o último membro: dissolve o circle.
            $del_feed = $conn->prepare("DELETE FROM circle_feed WHERE circle_id = ?");
            $del_feed->bind_param("i", $circle_id);
            $del_feed->execute();

            $del_circle = $conn->prepare("DELETE FROM circles WHERE id = ?");
            $del_circle->bind_param("i", $circle_id);
            $del_circle->execute();
        }
    }
}
