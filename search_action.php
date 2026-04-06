<?php
session_start();
require_once 'db.php';

$q = $_GET['q'] ?? '';
$my_id = $_SESSION['user_id'];

// Procura nomes que combinem, excluindo o próprio utilizador
$stmt = $conn->prepare("SELECT id, name, profile_pic FROM users WHERE name LIKE ? AND id != ? LIMIT 5");
$search = "%$q%";
$stmt->bind_param("si", $search, $my_id);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode($users);