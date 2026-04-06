<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e gestão de sessão
require_once __DIR__ . '/../core/config.php';

// Define o cabeçalho como JSON para a comunicação com o frontend
header('Content-Type: application/json');

// Impede que avisos do PHP corrompam o output JSON
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada']);
    exit();
}

$my_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$friend_id = (int)($data['friend_id'] ?? 0);

// Validação básica: ID de amigo válido e diferente do próprio utilizador
if (!$friend_id || $friend_id == $my_id) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit();
}

try {
    switch ($action) {
        case 'request':
            // 1. Verificar se já existe relação em qualquer sentido (pendente ou aceite)
            $check = $conn->prepare("SELECT id, user_id, status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $check->bind_param("iiii", $my_id, $friend_id, $friend_id, $my_id);
            $check->execute();
            $res = $check->get_result();

            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                // Lógica Inteligente: Se o outro já me pediu amizade, eu aceito automaticamente
                if ($row['status'] == 'pending' && $row['user_id'] == $friend_id) {
                    $upd = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?");
                    $upd->bind_param("i", $row['id']);
                    $upd->execute();
                    echo json_encode(['success' => true, 'message' => 'Protocolo Sincronizado!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Já existe um pedido em curso.']);
                }
            } else {
                // Criar novo pedido de amizade (Protocolo Neural)
                $ins = $conn->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
                $ins->bind_param("ii", $my_id, $friend_id);
                $ins->execute();
                echo json_encode(['success' => true]);
            }
            break;

        case 'accept':
            // Aceitar explicitamente um pedido pendente
            $stmt = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE friend_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $my_id, $friend_id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            // Eliminar relação ou cancelar pedido (Incinerar ligação)
            $stmt = $conn->prepare("DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $stmt->bind_param("iiii", $my_id, $friend_id, $friend_id, $my_id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Ação desconhecida']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro no Motor Social: ' . $e->getMessage()]);
}

exit();