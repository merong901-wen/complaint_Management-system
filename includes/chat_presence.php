<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/chat_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request token. Refresh the page and try again.']);
    exit();
}

$role = (string) ($_SESSION['role'] ?? '');
$roles = ['admin', 'faculty_director', 'department_head', 'teacher', 'student'];
if (!in_array($role, $roles, true) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Chat presence is unavailable.']);
    exit();
}

require_roles([$role]);
chat_init_database($conn);
$userId = (int) $_SESSION['user_id'];
$presence = $conn->prepare('INSERT INTO chat_presence (user_id, last_seen) VALUES (?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP');
$presence->bind_param('i', $userId);
$presence->execute();
$presence->close();

$contacts = chat_get_contacts($conn, $userId);
$contactIds = array_values(array_filter(
    array_map(static fn(array $contact): int => (int) $contact['id'], $contacts),
    static fn(int $contactId): bool => $contactId !== $userId
));
$selectedContactId = (int) ($_POST['selected_user'] ?? 0);
$messages = [];
if ($selectedContactId > 0 && in_array($selectedContactId, $contactIds, true)) {
    $thread = chat_get_thread_for_users($conn, $userId, $selectedContactId);
    if ($thread) {
        $threadId = (int) $thread['id'];
        chat_mark_thread_read($conn, $threadId, $userId);
        $messages = chat_get_thread_messages($conn, $threadId);
    }
}

foreach ($contacts as &$contact) {
    if ((int) $contact['id'] === $selectedContactId) {
        $contact['unread_count'] = 0;
        break;
    }
}
unset($contact);

$contactData = array_map(static function (array $contact): array {
    $preview = trim((string) ($contact['latest_message'] ?? ''));
    if ($preview === '' && !empty($contact['latest_attachment'])) {
        $preview = 'Attachment: ' . $contact['latest_attachment'];
    } elseif ($preview === '' && !empty($contact['latest_voice'])) {
        $preview = 'Voice message';
    } elseif ($preview === '') {
        $preview = 'Start a conversation';
    }

    return [
        'id' => (int) $contact['id'],
        'preview' => $preview,
        'unread' => (int) $contact['unread_count'],
        'last_message_at' => $contact['last_message_at'],
        'online' => (int) $contact['is_online'] === 1,
    ];
}, $contacts);

echo json_encode(['contacts' => $contactData, 'messages' => $messages], JSON_UNESCAPED_SLASHES);
