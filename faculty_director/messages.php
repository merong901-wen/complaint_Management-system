<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['faculty_director']);
$facultyId = (int) ($_SESSION['faculty_id'] ?? 0);
$senderId = (int) $_SESSION['user_id'];
$facultyStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ? AND is_active = 1 LIMIT 1');
$facultyStmt->bind_param('i', $facultyId);
$facultyStmt->execute();
$faculty = $facultyStmt->get_result()->fetch_assoc();
if (!$faculty) {
    http_response_code(403);
    exit('This faculty is unavailable. Contact the administrator.');
}
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $messageText = trim((string) ($_POST['message_text'] ?? ''));
    if ($messageText === '' || mb_strlen($messageText) > 3000) {
        $error = 'Enter a message of up to 3,000 characters.';
    } else {
        $sendStmt = $conn->prepare('INSERT INTO faculty_director_messages (faculty_id, sender_id, message_text) VALUES (?, ?, ?)');
        $sendStmt->bind_param('iis', $facultyId, $senderId, $messageText);
        if ($sendStmt->execute()) {
            record_audit($conn, 'admin_message_replied', 'faculty', $facultyId);
            header('Location: messages.php?sent=1');
            exit();
        }
        $error = 'Message could not be sent. Please try again.';
    }
}
if (isset($_GET['sent'])) $notice = 'Your message was sent to the administrators.';
$messagesStmt = $conn->prepare('SELECT m.message_text, m.created_at, u.full_name, u.username, u.role FROM faculty_director_messages m JOIN users u ON u.id = m.sender_id WHERE m.faculty_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 100');
$messagesStmt->bind_param('i', $facultyId);
$messagesStmt->execute();
$messages = array_reverse($messagesStmt->get_result()->fetch_all(MYSQLI_ASSOC));
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Messages from administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.content{padding:28px}.communication-panel{max-width:980px;margin:auto;padding:22px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);color:var(--system-text);box-shadow:0 10px 28px rgba(20,45,75,.07)}.message-list{display:flex;flex-direction:column;gap:12px;max-height:55vh;overflow:auto;padding:4px 4px 16px}.message-card{max-width:82%;padding:12px 15px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-page);color:var(--system-text);white-space:pre-wrap;overflow-wrap:anywhere}.message-card.from-director{align-self:flex-end;background:color-mix(in srgb,var(--system-blue) 12%,var(--system-surface))}.message-meta{display:flex;gap:8px;align-items:center;margin-bottom:5px;color:var(--system-muted);font-size:12px}.message-compose{border-top:1px solid var(--system-border);padding-top:16px}.empty-thread{padding:36px 12px;text-align:center;color:var(--system-muted)}@media(max-width:760px){.content{padding:18px 12px}.communication-panel{padding:16px}.message-card{max-width:95%}}
</style></head><body>
<main class="content"><section class="communication-panel">
<div class="mb-4"><h1 class="h3 mb-1">Messages with administration</h1><p class="text-muted mb-0">Private conversation for <?= h($faculty['name']) ?>.</p></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
<div class="message-list" aria-live="polite">
<?php if (!$messages): ?><div class="empty-thread">No messages yet. You can start a conversation with the administrators.</div><?php endif; ?>
<?php foreach ($messages as $message): $isDirector = $message['role'] === 'faculty_director'; ?><article class="message-card <?= $isDirector ? 'from-director' : '' ?>"><div class="message-meta"><strong><?= h($message['full_name'] ?: $message['username']) ?></strong><span><?= $isDirector ? 'You' : 'Admin' ?></span><time datetime="<?= h($message['created_at']) ?>"><?= h($message['created_at']) ?></time></div><?= h($message['message_text']) ?></article><?php endforeach; ?>
</div>
<form method="post" class="message-compose"><?= csrf_field() ?><label class="form-label" for="message-text">Reply to administration</label><textarea id="message-text" name="message_text" class="form-control mb-2" rows="4" maxlength="3000" placeholder="Write your message…" required></textarea><div class="d-flex justify-content-between align-items-center"><small class="text-muted">Up to 3,000 characters</small><button class="btn btn-primary" type="submit">Send message</button></div></form>
</section></main>
</body></html>
