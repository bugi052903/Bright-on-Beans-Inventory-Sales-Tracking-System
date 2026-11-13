<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT ingredient_id, name, date_added, expiration_date FROM ingredients ORDER BY ingredient_id ASC LIMIT 15");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
