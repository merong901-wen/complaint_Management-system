<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/chat_helpers.php';

$role = (string) ($_SESSION['role'] ?? '');
$roles = ['admin', 'faculty_director', 'department_head', 'teacher', 'student'];
if (!in_array($role, $roles, true)) {
    http_response_code(403);
    exit('Access denied.');
}

require_roles([$role]);
chat_init_database($conn);

$messageId = (int) ($_GET['id'] ?? 0);
$kind = (string) ($_GET['type'] ?? '');
$column = $kind === 'voice' ? 'voice_path' : ($kind === 'attachment' ? 'attachment_path' : '');
$mimeColumn = $kind === 'voice' ? 'voice_type' : 'attachment_type';
$nameColumn = $kind === 'attachment' ? 'attachment_name' : '';
if ($messageId <= 0 || $column === '') {
    http_response_code(404);
    exit('File not found.');
}

$currentUserId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT cm.$column AS file_path, cm.$mimeColumn AS file_mime, " .
    ($nameColumn !== '' ? "cm.$nameColumn" : "''") . " AS file_name, " .
    'IF(t.user_a = ?, t.user_b, t.user_a) AS other_user_id ' .
    'FROM chat_messages cm JOIN chat_threads t ON t.id = cm.thread_id ' .
    'WHERE cm.id = ? AND (t.user_a = ? OR t.user_b = ?) LIMIT 1');
$stmt->bind_param('iiii', $currentUserId, $messageId, $currentUserId, $currentUserId);
$stmt->execute();
$message = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$message || !chat_can_message($conn, $currentUserId, (int) $message['other_user_id'])) {
    http_response_code(404);
    exit('File not found.');
}

$relativePath = (string) ($message['file_path'] ?? '');
$uploadRoot = realpath(__DIR__ . '/../uploads/chat');
$filePath = realpath(__DIR__ . '/../' . $relativePath);
if (
    !$uploadRoot
    || !$filePath
    || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR)
    || !is_file($filePath)
) {
    http_response_code(404);
    exit('File not found.');
}

$mime = (string) ($message['file_mime'] ?: 'application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
if ($kind === 'voice') {
    header('Content-Disposition: inline');
} else {
    $fileName = (string) ($message['file_name'] ?: basename($filePath));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
}
readfile($filePath);
