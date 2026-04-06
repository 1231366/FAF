<?php
session_start();
// Ativa erros para debugging total
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];

try {
    // 1. Limpa os treinos da tabela CORRETA: training_plans
    $stmt1 = $conn->prepare("DELETE FROM training_plans WHERE user_id = ?");
    $stmt1->bind_param("i", $user_id);
    $stmt1->execute();

    // 2. Reset ao Perfil Neural na tabela user_profiles
    // Nota: Como race_date é do tipo DATE, usamos NULL ou '0000-00-00'. 
    // Vamos usar NULL para ser aceite pelo motor.
    $stmt2 = $conn->prepare("UPDATE user_profiles SET target_distance = NULL, race_date = NULL, prep_cycle = NULL WHERE user_id = ?");
    $stmt2->bind_param("i", $user_id);
    
    if($stmt2->execute()) {
        // 3. Redireciona para o Diagnóstico Neural
        header("Location: methricsdiagonostic.php"); 
        exit();
    } else {
        throw new Exception($conn->error);
    }

} catch (Exception $e) {
    die("Erro Crítico no Engine: " . $e->getMessage());
}
?>