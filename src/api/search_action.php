<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e gestão de sessão
require_once __DIR__ . '/../core/config.php';

// Define o cabeçalho como JSON para a comunicação com o frontend
header('Content-Type: application/json');

// Impede que avisos do PHP corrompam o output JSON
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Verificação de segurança: Apenas utilizadores logados podem pesquisar
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Sessão expirada');
    }

    $q = $_GET['q'] ?? '';
    $my_id = $_SESSION['user_id'];

    if (empty($q)) {
        echo json_encode([]);
        exit();
    }

    // Procura nomes que combinem, excluindo o próprio utilizador para não aparecer na própria busca
    // LIMIT 5 para manter a performance e o design da interface
    $stmt = $conn->prepare("SELECT id, name, profile_pic FROM users WHERE name LIKE ? AND id != ? LIMIT 5");
    $search = "%$q%";
    $stmt->bind_param("si", $search, $my_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while($row = $result->fetch_assoc()) {
        // Formata o caminho da imagem se necessário ou usa o fallback do DiceBear
        if (empty($row['profile_pic'])) {
            $row['profile_pic'] = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($row['name']);
        }
        $users[] = $row;
    }

    echo json_encode($users);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
exit();