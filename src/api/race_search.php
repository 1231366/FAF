<?php
require_once __DIR__ . '/../core/config.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'races' => []]);
    exit();
}

$like = '%' . $q . '%';
$stmt = $conn->prepare("SELECT id, name, race_date, distance_km, city, country FROM races
                         WHERE name LIKE ? AND race_date >= CURDATE()
                         ORDER BY race_date ASC LIMIT 15");
$stmt->bind_param("s", $like);
$stmt->execute();
$races = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['success' => true, 'races' => $races]);
