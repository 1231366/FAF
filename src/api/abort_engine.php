<?php
// AJUSTADO: Usa o config centralizado para a ligação à DB e sessão
require_once __DIR__ . '/../core/config.php';

// Ativa erros para debugging (opcional em produção, mas útil agora)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificação de segurança: Se não houver sessão, volta para o login
if (!isset($_SESSION['user_id'])) { 
    header("Location: ../../public/login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];

try {
    // 1. Limpa os treinos da tabela de planos
    $stmt1 = $conn->prepare("DELETE FROM training_plans WHERE user_id = ?");
    $stmt1->bind_param("i", $user_id);
    $stmt1->execute();

    // 2. Reset ao Perfil Neural na tabela user_profiles
    $stmt2 = $conn->prepare("UPDATE user_profiles SET target_distance = NULL, race_date = NULL, prep_cycle = NULL WHERE user_id = ?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();

    // 3. NOVO: Reset ao status de diagnóstico no perfil do utilizador
    // Isto garante que o sistema o obrigue a passar pelo ecrã de métricas novamente
    $stmt3 = $conn->prepare("UPDATE users SET diagnostic_completed = 0 WHERE id = ?");
    $stmt3->bind_param("i", $user_id);
    
    if($stmt3->execute()) {
        // 4. Redireciona para o Diagnóstico Neural dentro da pasta public/
        header("Location: ../../public/methricsdiagonostic.php"); 
        exit();
    } else {
        throw new Exception($conn->error);
    }

} catch (Exception $e) {
    die("Erro Crítico no Engine de Abort: " . $e->getMessage());
}
?>