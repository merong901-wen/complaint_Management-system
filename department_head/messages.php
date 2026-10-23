<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['department_head']);
$headId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$error = '';
$success = isset($_GET['sent']) ? 'Your response was sent to the student.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    $messageText = trim((string) ($_POST['message_text'] ?? ''));
    $messageType = (string) ($_POST['message_type'] ?? 'reply');
    if (!in_array($messageType, ['reply', 'resolution'], true)) {
        $error = 'Choose a valid response type.';
    } elseif ($messageText === '' || mb_strlen($messageText) > 3000) {
        $error = 'Enter a response of no more than 3,000 characters.';
    } else {
        try {
            $conn->begin_transaction();
            $conversationCheck = $conn->prepare("SELECT id, student_id, complaint_id, conversation_type, subject FROM department_head_conversations WHERE id = ? AND department_id = ? AND status = 'open' LIMIT 1");
            $conversationCheck->bind_param('ii', $conversationId, $departmentId);
            $conversationCheck->execute();
            $conversation = $conversationCheck->get_result()->fetch_assoc();
            if (!$conversation) throw new RuntimeException('This conversation is unavailable or already resolved.');

            $messageInsert = $conn->prepare('INSERT INTO department_head_messages (conversation_id, sender_id, message_type, message_text) VALUES (?, ?, ?, ?)');
            $messageInsert->bind_param('iiss', $conversationId, $headId, $messageType, $messageText);
            $messageInsert->execute();
            if ($messageType === 'resolution' && $conversation['complaint_id'] !== null) {
                $complaintId = (int) $conversation['complaint_id'];
                $complaintResolution = $conn->prepare("UPDATE complaints SET status = 'Resolved', resolution = ?, resolution_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ? AND department_id = ? AND LOWER(REPLACE(TRIM(status), ' ', '')) NOT IN ('resolved', 'closed')");
                $complaintResolution->bind_param('siii', $messageText, $headId, $complaintId, $departmentId);
                $complaintResolution->execute();
            }
            $newStatus = $messageType === 'resolution' ? 'resolved' : 'open';
            $updateConversation = $conn->prepare('UPDATE department_head_conversations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND department_id = ?');
            $updateConversation->bind_param('sii', $newStatus, $conversationId, $departmentId);
            $updateConversation->execute();
            $subjectReference = $conversation['complaint_id'] !== null
                ? 'complaint #' . (int) $conversation['complaint_id']
                : 'sensitive matter: ' . (string) $conversation['subject'];
            $notificationText = $messageType === 'resolution'
                ? 'Your department head sent a resolution update for your ' . $subjectReference . '.'
                : 'Your department head replied to your ' . $subjectReference . '.';
            $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
            $studentId = (int) $conversation['student_id'];
            $notification->bind_param('is', $studentId, $notificationText);
            $notification->execute();
            record_audit($conn, $messageType === 'resolution' ? 'department_head_resolution_sent' : 'department_head_reply_sent', $conversation['complaint_id'] !== null ? 'complaint' : 'department_head_conversation', $conversation['complaint_id'] !== null ? (int) $conversation['complaint_id'] : $conversationId, ['conversation_id' => $conversationId, 'department_id' => $departmentId]);
            $conn->commit();
            header('Location: messages.php?conversation=' . $conversationId . '&sent=1');
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Your response could not be sent. Please try again.';
            error_log('Department-head communication action failed: ' . $exception->getMessage());
        }
    }
}

$listStmt = $conn->prepare("SELECT cv.id, cv.complaint_id, cv.conversation_type, cv.subject, cv.status, cv.updated_at, c.title, s.full_name AS student_name, s.username AS student_username, (SELECT message_text FROM department_head_messages WHERE conversation_id = cv.id ORDER BY id DESC LIMIT 1) AS latest_message FROM department_head_conversations cv LEFT JOIN complaints c ON c.id = cv.complaint_id JOIN users s ON s.id = cv.student_id WHERE cv.department_id = ? ORDER BY (cv.status = 'open') DESC, cv.updated_at DESC");
$listStmt->bind_param('i', $departmentId);
$listStmt->execute();
$conversations = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$selectedId = (int) ($_GET['conversation'] ?? ($conversations[0]['id'] ?? 0));
$selected = null;
$messages = [];
if ($selectedId > 0) {
    $selectedStmt = $conn->prepare("SELECT cv.id, cv.complaint_id, cv.conversation_type, cv.subject, cv.status, cv.student_id, c.title, c.description, c.status AS complaint_status, c.priority, c.resolution AS teacher_resolution, c.created_at AS complaint_created, s.full_name AS student_name, s.student_number FROM department_head_conversations cv LEFT JOIN complaints c ON c.id = cv.complaint_id JOIN users s ON s.id = cv.student_id WHERE cv.id = ? AND cv.department_id = ? LIMIT 1");
    $selectedStmt->bind_param('ii', $selectedId, $departmentId);
    $selectedStmt->execute();
    $selected = $selectedStmt->get_result()->fetch_assoc();
    if ($selected) {
        $messagesStmt = $conn->prepare('SELECT m.sender_id, m.message_type, m.message_text, m.created_at, u.full_name, u.role FROM department_head_messages m JOIN users u ON u.id = m.sender_id WHERE m.conversation_id = ? ORDER BY m.id ASC');
        $messagesStmt->bind_param('i', $selectedId);
        $messagesStmt->execute();
        $messages = $messagesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student communication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.communication-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:20px}.communication-layout{display:grid;grid-template-columns:minmax(260px,.72fr) minmax(0,1.28fr);gap:16px}.communication-panel{min-width:0;padding:20px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.communication-panel h2{font-size:18px}.thread-list{display:grid;gap:8px;margin-top:16px}.thread-link{display:block;padding:12px;border:1px solid var(--system-border);border-radius:6px;color:var(--system-text)!important;text-decoration:none}.thread-link:hover,.thread-link.active{border-color:var(--system-blue);background:rgba(139,124,246,.1)}.thread-link strong,.thread-link span{display:block}.thread-link span{margin-top:4px;color:var(--system-muted);font-size:12px}.thread-link .thread-status{color:var(--system-blue);font-weight:700}.conversation-title{padding-bottom:12px;border-bottom:1px solid var(--system-border)}.complaint-record{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0;padding:14px;border:1px solid var(--system-border);border-radius:6px;background:var(--system-page)}.complaint-record p{margin:0;overflow-wrap:anywhere}.complaint-record .record-description{grid-column:1/-1;white-space:pre-wrap}.message-list{display:grid;gap:10px;max-height:400px;overflow:auto;padding:14px 2px}.message-bubble{max-width:88%;padding:12px 14px;border:1px solid var(--system-border);border-radius:7px;background:var(--system-page);overflow-wrap:anywhere}.message-bubble.mine{justify-self:end;border-color:rgba(139,124,246,.35);background:rgba(139,124,246,.1)}.message-bubble.resolution{border-left:4px solid var(--system-blue)}.message-meta{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:6px;color:var(--system-muted);font-size:12px}.message-bubble p{margin:0;white-space:pre-wrap;line-height:1.55}.empty-state{padding:22px 10px;color:var(--system-muted);text-align:center;font-size:13px}@media(max-width:900px){.communication-layout{grid-template-columns:1fr}}@media(max-width:700px){.content{padding:20px 14px}.communication-heading{align-items:flex-start;flex-direction:column}.message-bubble{max-width:100%}.complaint-record{grid-template-columns:1fr}.complaint-record .record-description{grid-column:auto}}
    </style>
</head>
<body>
<main class="content">
    <header class="communication-heading"><div><h1 class="h3 mb-1">Student communication</h1><p class="mb-0 text-muted">Review unresolved escalations, the original complaint, and teacher responses.</p></div><a class="btn btn-outline-primary" href="approvals.php">Student approvals</a></header>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <div class="communication-layout">
        <section class="communication-panel" aria-labelledby="inbox-title"><h2 id="inbox-title">Department escalation inbox</h2><p class="text-muted">Open threads appear first. Review the student's message and reply or send a resolution.</p>
            <div class="thread-list">
                <?php foreach ($conversations as $conversation): ?>
                    <a class="thread-link <?= $selectedId === (int) $conversation['id'] ? 'active' : '' ?>" href="?conversation=<?= (int) $conversation['id'] ?>"><strong><?= h($conversation['student_name'] ?: $conversation['student_username']) ?> · <?= $conversation['complaint_id'] ? 'Complaint #' . (int) $conversation['complaint_id'] : 'Sensitive matter' ?></strong><span><?= h($conversation['complaint_id'] ? $conversation['title'] : $conversation['subject']) ?></span><span class="thread-status"><?= h(ucfirst($conversation['status'])) ?> · Updated <?= h($conversation['updated_at']) ?></span><span><?= h(mb_strimwidth((string) $conversation['latest_message'], 0, 105, '...')) ?></span></a>
                <?php endforeach; ?>
                <?php if (!$conversations): ?><p class="empty-state mb-0">No student escalations have been received.</p><?php endif; ?>
            </div>
        </section>
        <section class="communication-panel" aria-labelledby="conversation-title">
            <?php if ($selected): ?>
                <div class="conversation-title"><h2 id="conversation-title"><?= h($selected['student_name']) ?> · <?= $selected['complaint_id'] ? 'Complaint #' . (int) $selected['complaint_id'] . ' · ' . h($selected['title']) : 'Sensitive matter · ' . h($selected['subject']) ?></h2><p class="text-muted mb-0">Student ID: <?= h($selected['student_number'] ?: 'Not provided') ?> · Review: <?= h(ucfirst($selected['status'])) ?></p></div>
                <?php if ($selected['complaint_id']): ?><div class="complaint-record"><p><strong>Complaint</strong><br><?= h($selected['title']) ?></p><p><strong>Submitted</strong><br><?= h($selected['complaint_created']) ?></p><p><strong>Priority and status</strong><br><?= h($selected['priority']) ?> · <?= h($selected['complaint_status']) ?></p><p><strong>Teacher resolution</strong><br><?= h($selected['teacher_resolution'] ?: 'No teacher resolution recorded') ?></p><p class="record-description"><strong>Original description</strong><br><?= h($selected['description']) ?></p></div><?php else: ?><div class="complaint-record"><p class="record-description"><strong>Sensitive matter</strong><br><?= h($selected['subject']) ?></p></div><?php endif; ?>
                <div class="message-list" aria-live="polite">
                    <?php foreach ($messages as $message): ?>
                        <article class="message-bubble <?= (int) $message['sender_id'] === $headId ? 'mine' : '' ?> <?= $message['message_type'] === 'resolution' ? 'resolution' : '' ?>">
                            <div class="message-meta"><strong><?= h($message['full_name']) ?> · <?= h(str_replace('_', ' ', $message['role'])) ?><?= $message['message_type'] === 'resolution' ? ' · Resolution' : '' ?></strong><time><?= h($message['created_at']) ?></time></div>
                            <p><?= h($message['message_text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($selected['status'] === 'open'): ?>
                    <form method="post" class="mt-2">
                        <?= csrf_field() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                        <label class="form-label" for="head-message-type">Response type</label><select class="form-select mb-3" id="head-message-type" name="message_type"><option value="reply">Reply</option><option value="resolution">Resolution (close this conversation)</option></select>
                        <label class="form-label" for="head-message-text">Message to student</label><textarea class="form-control mb-3" id="head-message-text" name="message_text" rows="4" maxlength="3000" required></textarea>
                        <button class="btn btn-primary" type="submit">Send to student</button>
                    </form>
                <?php else: ?><div class="alert alert-success mb-0">This escalation has been marked resolved.</div><?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><h2 id="conversation-title">Student escalation</h2><p>Select a thread to review the complaint and communicate with the student.</p></div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
