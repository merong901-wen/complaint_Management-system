<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/../admin/config/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$adminCount = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0];
if ($adminCount > 0) {
    fwrite(STDERR, "An administrator already exists; bootstrap is disabled.\n");
    exit(1);
}

$fullName = trim((string) (getenv('BOOTSTRAP_ADMIN_FULL_NAME') ?: readline('Administrator full name: ')));
$username = trim((string) (getenv('BOOTSTRAP_ADMIN_USERNAME') ?: readline('Administrator username: ')));
$email = trim((string) (getenv('BOOTSTRAP_ADMIN_EMAIL') ?: readline('Administrator email: ')));
$password = (string) getenv('BOOTSTRAP_ADMIN_PASSWORD');

if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a full name, username, and valid email.\n");
    exit(1);
}
if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
    fwrite(STDERR, "Set BOOTSTRAP_ADMIN_PASSWORD to a 12-character password with uppercase, lowercase, number, and symbol.\n");
    exit(1);
}

$duplicate = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
$duplicate->bind_param('ss', $username, $email);
$duplicate->execute();
if ($duplicate->get_result()->num_rows > 0) {
    fwrite(STDERR, "That username or email is already in use.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status) VALUES (?, ?, ?, ?, 'admin', 'approved')");
$stmt->bind_param('ssss', $username, $fullName, $email, $hash);
$stmt->execute();
fwrite(STDOUT, "Initial administrator created. Remove BOOTSTRAP_ADMIN_PASSWORD from the environment.\n");
