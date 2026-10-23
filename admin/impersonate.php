<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
post_csrf_or_exit();

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
if (!$userId) {
    http_response_code(400);
    exit('Invalid account.');
}

$stmt = $conn->prepare("SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.department_id, u.faculty_id
    FROM users u WHERE u.id = ? AND u.role <> 'admin' LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target || $target['status'] !== 'approved') {
    http_response_code(403);
    exit('Only approved non-admin accounts can be opened.');
}

if (!isset($_SESSION['admin_actor_id'])) {
    $_SESSION['admin_actor_id'] = (int) $_SESSION['user_id'];
    $_SESSION['admin_actor_username'] = (string) $_SESSION['username'];
    $_SESSION['admin_actor_full_name'] = (string) ($_SESSION['full_name'] ?? $_SESSION['username']);
    $_SESSION['admin_actor_role'] = (string) $_SESSION['role'];
    $_SESSION['admin_actor_department_id'] = $_SESSION['department_id'] ?? null;
    $_SESSION['admin_actor_faculty_id'] = $_SESSION['faculty_id'] ?? null;
}

$_SESSION['user_id'] = (int) $target['id'];
$_SESSION['username'] = (string) $target['username'];
$_SESSION['full_name'] = (string) $target['full_name'];
$_SESSION['role'] = (string) $target['role'];
$_SESSION['department_id'] = $target['department_id'] === null ? null : (int) $target['department_id'];
$_SESSION['faculty_id'] = $target['faculty_id'] === null ? null : (int) $target['faculty_id'];
$_SESSION['user_logged_in'] = true;
session_regenerate_id(true);

record_audit($conn, 'admin_impersonation_started', 'user', $userId, ['target_role' => $target['role']]);
redirect_for_role($target['role']);
