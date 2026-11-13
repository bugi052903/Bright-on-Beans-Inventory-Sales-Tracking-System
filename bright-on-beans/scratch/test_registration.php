<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Testing Staff Registration...\n";

$testUsername = 'teststaff_' . time();
$password = 'StaffPass123';
$firstName = 'Jane';
$lastName = 'Doe';

// 1. Validate password
$pwdErrors = validatePassword($password);
echo "Password errors for '{$password}': " . (empty($pwdErrors) ? "None (Valid!)" : implode(', ', $pwdErrors)) . "\n";
assert(empty($pwdErrors), "Password should be valid");

// 2. Validate username availability
$isAvail = isUsernameAvailable($pdo, $testUsername);
echo "Username '{$testUsername}' available: " . ($isAvail ? "Yes" : "No") . "\n";
assert($isAvail, "Username should be available");

// 3. Register user
$pdo->beginTransaction();
$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, status, created_at) VALUES (?, ?, ?, ?, 'staff', 'active', NOW())");
$stmt->execute([$testUsername, $hashed, $firstName, $lastName]);
$userId = $pdo->lastInsertId();

$questions = getSecretQuestions();
$stmtSq = $pdo->prepare("INSERT INTO secret_questions (user_id, question_number, question, answer) VALUES (?, ?, ?, ?)");
foreach ($questions as $qNum => $qText) {
    $hashedAns = password_hash('sample answer', PASSWORD_DEFAULT);
    $stmtSq->execute([$userId, $qNum, $qText, $hashedAns]);
}
$pdo->commit();

echo "✔ User registered successfully! User ID: {$userId}\n";

// 4. Verify user exists and can login
$stmtCheck = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtCheck->execute([$userId]);
$user = $stmtCheck->fetch();
assert($user['role'] === 'staff', "Role should be staff");
assert(password_verify($password, $user['password']), "Password verification should pass");

echo "✔ User verification and login check passed for '{$testUsername}'!\n";

// Cleanup test user
$pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
echo "✔ Test cleanup completed successfully!\n";
