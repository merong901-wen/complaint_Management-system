<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

$facultyStmt = $conn->query("SELECT f.id, f.name, f.category, u.full_name, u.username FROM faculties f JOIN users u ON u.faculty_id = f.id AND u.role = 'faculty_director' AND u.status = 'approved' WHERE f.is_active = 1 ORDER BY f.name");
$faculties = $facultyStmt->fetch_all(MYSQLI_ASSOC);
$facultyIds = array_map(static fn(array $faculty): int => (int) $faculty['id'], $faculties);
$selectedFacultyId = (int) ($_POST['faculty_id'] ?? $_GET['faculty_id'] ?? ($facultyIds[0] ?? 0));
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $messageText = trim((string) ($_POST['message_text'] ?? ''));
    if (!in_array($selectedFacultyId, $facultyIds, true)) {
        $error = 'Choose an active faculty with a registered director.';
    } elseif ($messageText === '' || mb_strlen($messageText) > 3000) {
        $error = 'Enter a message of up to 3,000 characters.';
    } else {
        $senderId = (int) $_SESSION['user_id'];
        $sendStmt = $conn->prepare('INSERT INTO faculty_director_messages (faculty_id, sender_id, message_text) VALUES (?, ?, ?)');
        $sendStmt->bind_param('iis', $selectedFacultyId, $senderId, $messageText);
        if ($sendStmt->execute()) {
            record_audit($conn, 'faculty_director_message_sent', 'faculty', $selectedFacultyId);
            header('Location: faculty_communications.php?faculty_id=' . $selectedFacultyId . '&sent=1');
            exit();
        }
        $error = 'Message could not be sent. Please try again.';
    }
}
if (isset($_GET['sent'])) $notice = 'Your message was sent to the faculty director.';

$selectedFaculty = null;
$messages = [];
if (in_array($selectedFacultyId, $facultyIds, true)) {
    foreach ($faculties as $faculty) if ((int) $faculty['id'] === $selectedFacultyId) { $selectedFaculty = $faculty; break; }
    $messagesStmt = $conn->prepare('SELECT m.message_text, m.created_at, u.full_name, u.username, u.role FROM faculty_director_messages m JOIN users u ON u.id = m.sender_id WHERE m.faculty_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 100');
    $messagesStmt->bind_param('i', $selectedFacultyId);
    $messagesStmt->execute();
    $messages = array_reverse($messagesStmt->get_result()->fetch_all(MYSQLI_ASSOC));
}
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty communications</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.content{padding:28px}.communication-panel{max-width:980px;margin:auto;padding:22px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);color:var(--system-text);box-shadow:0 10px 28px rgba(20,45,75,.07)}.message-list{display:flex;flex-direction:column;gap:12px;max-height:55vh;overflow:auto;padding:4px 4px 16px}.message-card{max-width:82%;padding:12px 15px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-page);color:var(--system-text);white-space:pre-wrap;overflow-wrap:anywhere}.message-card.from-admin{align-self:flex-end;background:color-mix(in srgb,var(--system-blue) 12%,var(--system-surface))}.message-meta{display:flex;gap:8px;align-items:center;margin-bottom:5px;color:var(--system-muted);font-size:12px}.message-compose{border-top:1px solid var(--system-border);padding-top:16px}.empty-thread{padding:36px 12px;text-align:center;color:var(--system-muted)}@media(max-width:760px){.content{padding:18px 12px}.communication-panel{padding:16px}.message-card{max-width:95%}}
</style></head><body>
<main class="content"><section class="communication-panel">
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h3 mb-1">Faculty director communications</h1><p class="text-muted mb-0">Send messages and follow replies by faculty.</p></div><span class="badge text-bg-secondary"><?= count($faculties) ?> directors</span></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if (!$faculties): ?><div class="empty-thread">No active faculty directors are available. Register a director from Faculty management first.</div>
<?php else: ?>
<form method="get" class="row g-2 align-items-end mb-4"><div class="col-md-8"><label for="faculty-select" class="form-label">Faculty director</label><select id="faculty-select" class="form-select" name="faculty_id" onchange="this.form.submit()"><?php foreach ($faculties as $faculty): ?><option value="<?= (int) $faculty['id'] ?>" <?= (int) $faculty['id'] === $selectedFacultyId ? 'selected' : '' ?>><?= h($faculty['name']) ?> · <?= h($faculty['full_name'] ?: $faculty['username']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><noscript><button class="btn btn-outline-primary w-100" type="submit">Open conversation</button></noscript><div class="small text-muted pb-2"><?= $selectedFaculty ? h($selectedFaculty['category']) : '' ?></div></div></form>
<div class="message-list" aria-live="polite">
<?php if (!$messages): ?><div class="empty-thread">No messages yet. Start the conversation below.</div><?php endif; ?>
<?php foreach ($messages as $message): $isAdmin = $message['role'] === 'admin'; ?><article class="message-card <?= $isAdmin ? 'from-admin' : '' ?>"><div class="message-meta"><strong><?= h($message['full_name'] ?: $message['username']) ?></strong><span><?= $isAdmin ? 'Admin' : 'Faculty director' ?></span><time datetime="<?= h($message['created_at']) ?>"><?= h($message['created_at']) ?></time></div><?= h($message['message_text']) ?></article><?php endforeach; ?>
</div>
<form method="post" class="message-compose"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="faculty_id" value="<?= $selectedFacultyId ?>"><label class="form-label" for="message-text">Message <?= $selectedFaculty ? 'to ' . h($selectedFaculty['name']) : '' ?></label><textarea id="message-text" name="message_text" class="form-control mb-2" rows="4" maxlength="3000" placeholder="Write a message to the faculty director…" required></textarea><div class="d-flex justify-content-between align-items-center"><small class="text-muted">Up to 3,000 characters</small><button class="btn btn-primary" type="submit">Send message</button></div></form>
<?php endif; ?>
</section></main>
</body></html>
