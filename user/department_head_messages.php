<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$studentId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$error = '';
$success = isset($_GET['sent']) ? 'Your message was sent to the department head.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');
    $messageText = trim((string) ($_POST['message_text'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    if ($messageText === '' || mb_strlen($messageText) > 3000) {
        $error = 'Enter a message of no more than 3,000 characters.';
    } elseif (in_array($action, ['start_conversation', 'start_sensitive_conversation'], true) && empty($_POST['acknowledge'])) {
        $error = 'Confirm that this is an appropriate department-head escalation.';
    } elseif ($action === 'start_sensitive_conversation' && ($subject === '' || mb_strlen($subject) > 180)) {
        $error = 'Enter a subject of no more than 180 characters for your sensitive matter.';
    } elseif (in_array($action, ['start_sensitive_conversation', 'start_conversation', 'send_reply'], true)) {
        try {
            $conn->begin_transaction();
            if ($action === 'start_sensitive_conversation') {
                $headCheck = $conn->prepare("SELECT id FROM users WHERE department_id = ? AND role = 'department_head' AND status = 'approved'");
                $headCheck->bind_param('i', $departmentId);
                $headCheck->execute();
                $heads = $headCheck->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!$heads) throw new RuntimeException('There is no approved department head assigned yet. Please contact the support office.');

                $conversationType = 'sensitive';
                $conversationInsert = $conn->prepare("INSERT INTO department_head_conversations (department_id, student_id, complaint_id, conversation_type, subject) VALUES (?, ?, NULL, ?, ?)");
                $conversationInsert->bind_param('iiss', $departmentId, $studentId, $conversationType, $subject);
                $conversationInsert->execute();
                $conversationId = (int) $conversationInsert->insert_id;
                $messageType = 'sensitive_request';
                $messageInsert = $conn->prepare('INSERT INTO department_head_messages (conversation_id, sender_id, message_type, message_text) VALUES (?, ?, ?, ?)');
                $messageInsert->bind_param('iiss', $conversationId, $studentId, $messageType, $messageText);
                $messageInsert->execute();
                $notificationText = 'A student sent a sensitive matter for department-head review: ' . $subject;
                $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                foreach ($heads as $head) {
                    $headId = (int) $head['id'];
                    $notification->bind_param('is', $headId, $notificationText);
                    $notification->execute();
                }
                record_audit($conn, 'department_head_sensitive_message_created', 'department_head_conversation', $conversationId, ['department_id' => $departmentId]);
            } elseif ($action === 'start_conversation') {
                $complaintId = (int) ($_POST['complaint_id'] ?? 0);
                $complaintCheck = $conn->prepare("SELECT c.id, c.title FROM complaints c LEFT JOIN department_head_conversations cv ON cv.complaint_id = c.id AND cv.student_id = c.user_id WHERE c.id = ? AND c.user_id = ? AND c.department_id = ? AND c.created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY) AND LOWER(REPLACE(TRIM(c.status), ' ', '')) NOT IN ('resolved', 'closed') AND cv.id IS NULL LIMIT 1");
                $complaintCheck->bind_param('iii', $complaintId, $studentId, $departmentId);
                $complaintCheck->execute();
                $complaint = $complaintCheck->get_result()->fetch_assoc();
                if (!$complaint) throw new RuntimeException('Choose an unresolved complaint from your department that has been waiting at least seven days.');

                $headCheck = $conn->prepare("SELECT id FROM users WHERE department_id = ? AND role = 'department_head' AND status = 'approved'");
                $headCheck->bind_param('i', $departmentId);
                $headCheck->execute();
                $heads = $headCheck->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!$heads) throw new RuntimeException('There is no approved department head assigned yet. Please contact the support office.');

                $conversationInsert = $conn->prepare("INSERT INTO department_head_conversations (department_id, student_id, complaint_id) VALUES (?, ?, ?)");
                $conversationInsert->bind_param('iii', $departmentId, $studentId, $complaintId);
                $conversationInsert->execute();
                $conversationId = (int) $conversationInsert->insert_id;
                $messageType = 'request';
                $messageInsert = $conn->prepare('INSERT INTO department_head_messages (conversation_id, sender_id, message_type, message_text) VALUES (?, ?, ?, ?)');
                $messageInsert->bind_param('iiss', $conversationId, $studentId, $messageType, $messageText);
                $messageInsert->execute();
                $notificationText = 'A student requested department-head review for complaint #' . $complaintId . '.';
                $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                foreach ($heads as $head) {
                    $headId = (int) $head['id'];
                    $notification->bind_param('is', $headId, $notificationText);
                    $notification->execute();
                }
                record_audit($conn, 'department_head_escalation_created', 'complaint', $complaintId, ['conversation_id' => $conversationId, 'department_id' => $departmentId]);
            } else {
                $conversationId = (int) ($_POST['conversation_id'] ?? 0);
                $conversationCheck = $conn->prepare("SELECT id, complaint_id FROM department_head_conversations WHERE id = ? AND student_id = ? AND department_id = ? AND status = 'open' LIMIT 1");
                $conversationCheck->bind_param('iii', $conversationId, $studentId, $departmentId);
                $conversationCheck->execute();
                $conversation = $conversationCheck->get_result()->fetch_assoc();
                if (!$conversation) throw new RuntimeException('This conversation is unavailable or has been resolved.');
                $messageType = 'message';
                $messageInsert = $conn->prepare('INSERT INTO department_head_messages (conversation_id, sender_id, message_type, message_text) VALUES (?, ?, ?, ?)');
                $messageInsert->bind_param('iiss', $conversationId, $studentId, $messageType, $messageText);
                $messageInsert->execute();
                $touchConversation = $conn->prepare('UPDATE department_head_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND department_id = ?');
                $touchConversation->bind_param('ii', $conversationId, $departmentId);
                $touchConversation->execute();
                $headCheck = $conn->prepare("SELECT id FROM users WHERE department_id = ? AND role = 'department_head' AND status = 'approved'");
                $headCheck->bind_param('i', $departmentId);
                $headCheck->execute();
                $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                $notificationText = 'A student sent a follow-up in department-head communication.';
                foreach ($headCheck->get_result()->fetch_all(MYSQLI_ASSOC) as $head) {
                    $headId = (int) $head['id'];
                    $notification->bind_param('is', $headId, $notificationText);
                    $notification->execute();
                }
                record_audit($conn, 'department_head_message_sent', 'complaint', (int) $conversation['complaint_id'], ['conversation_id' => $conversationId, 'department_id' => $departmentId]);
            }
            $conn->commit();
            header('Location: department_head_messages.php?conversation=' . $conversationId . '&sent=1');
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Your message could not be sent. Please try again.';
            error_log('Student department-head communication failed: ' . $exception->getMessage());
        }
    } else {
        $error = 'Choose a valid communication action.';
    }
}

$eligibleStmt = $conn->prepare("SELECT c.id, c.title, c.status, c.created_at FROM complaints c LEFT JOIN department_head_conversations cv ON cv.complaint_id = c.id AND cv.student_id = c.user_id WHERE c.user_id = ? AND c.department_id = ? AND c.created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY) AND LOWER(REPLACE(TRIM(c.status), ' ', '')) NOT IN ('resolved', 'closed') AND cv.id IS NULL ORDER BY c.created_at ASC");
$eligibleStmt->bind_param('ii', $studentId, $departmentId);
$eligibleStmt->execute();
$eligibleComplaints = $eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$conversationsStmt = $conn->prepare("SELECT cv.id, cv.complaint_id, cv.conversation_type, cv.subject, cv.status, cv.updated_at, c.title, (SELECT message_text FROM department_head_messages WHERE conversation_id = cv.id ORDER BY id DESC LIMIT 1) AS latest_message FROM department_head_conversations cv LEFT JOIN complaints c ON c.id = cv.complaint_id WHERE cv.student_id = ? AND cv.department_id = ? ORDER BY cv.updated_at DESC");
$conversationsStmt->bind_param('ii', $studentId, $departmentId);
$conversationsStmt->execute();
$conversations = $conversationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$selectedId = (int) ($_GET['conversation'] ?? ($conversations[0]['id'] ?? 0));
$selected = null;
$messages = [];
if ($selectedId > 0) {
    $selectedStmt = $conn->prepare("SELECT cv.id, cv.complaint_id, cv.conversation_type, cv.subject, cv.status, c.title, c.status AS complaint_status FROM department_head_conversations cv LEFT JOIN complaints c ON c.id = cv.complaint_id WHERE cv.id = ? AND cv.student_id = ? AND cv.department_id = ? LIMIT 1");
    $selectedStmt->bind_param('iii', $selectedId, $studentId, $departmentId);
    $selectedStmt->execute();
    $selected = $selectedStmt->get_result()->fetch_assoc();
    if ($selected) {
        $messagesStmt = $conn->prepare('SELECT m.sender_id, m.message_type, m.message_text, m.created_at, u.full_name, u.role FROM department_head_messages m JOIN users u ON u.id = m.sender_id WHERE m.conversation_id = ? ORDER BY m.id ASC');
        $messagesStmt->bind_param('i', $selectedId);
        $messagesStmt->execute();
        $messages = $messagesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
include __DIR__ . '/user_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department head communication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.communication-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:20px}.communication-layout{display:grid;grid-template-columns:minmax(260px,.72fr) minmax(0,1.28fr);gap:16px}.communication-panel{min-width:0;padding:20px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.communication-panel h2{font-size:18px}.rule-note{padding:14px;border-left:4px solid #d89b2b;border-radius:5px;background:rgba(240,180,75,.12);font-size:12px;line-height:1.6}.eligible-list{display:grid;gap:8px;margin:14px 0}.eligible-item{padding:10px;border:1px solid var(--system-border);border-radius:5px;background:var(--system-page);font-size:12px}.thread-list{display:grid;gap:8px;margin-top:16px}.thread-link{display:block;padding:12px;border:1px solid var(--system-border);border-radius:6px;color:var(--system-text)!important;text-decoration:none}.thread-link:hover,.thread-link.active{border-color:var(--system-blue);background:rgba(139,124,246,.1)}.thread-link strong,.thread-link span{display:block}.thread-link span{margin-top:4px;color:var(--system-muted);font-size:12px}.conversation-title{padding-bottom:12px;border-bottom:1px solid var(--system-border)}.message-list{display:grid;gap:10px;max-height:440px;overflow:auto;padding:14px 2px}.message-bubble{max-width:88%;padding:12px 14px;border:1px solid var(--system-border);border-radius:7px;background:var(--system-page);overflow-wrap:anywhere}.message-bubble.mine{justify-self:end;border-color:rgba(139,124,246,.35);background:rgba(139,124,246,.1)}.message-bubble.resolution{border-left:4px solid var(--system-blue)}.message-meta{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:6px;color:var(--system-muted);font-size:12px}.message-bubble p{margin:0;white-space:pre-wrap;line-height:1.55}.empty-state{padding:22px 10px;color:var(--system-muted);text-align:center;font-size:13px}@media(max-width:900px){.communication-layout{grid-template-columns:1fr}}@media(max-width:700px){.content{padding:20px 14px}.communication-heading{align-items:flex-start;flex-direction:column}.message-bubble{max-width:100%}}
    </style>
</head>
<body>
<main class="content">
    <header class="communication-heading"><div><h1 class="h3 mb-1">Department head communication</h1><p class="mb-0 text-muted">For sensitive matters or unresolved issues that need review beyond your teacher.</p></div><a class="btn btn-outline-primary" href="user_complaints.php">My complaints</a></header>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <div class="communication-layout">
        <section class="communication-panel" aria-labelledby="escalation-title">
            <h2>Send a sensitive complaint to the head</h2>
            <p class="text-muted">Use this only for a private or serious complaint that needs department-head review. General complaints still go to your assigned teacher.</p>
            <form method="post" class="mb-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="start_sensitive_conversation">
                <label class="form-label" for="sensitive-subject">Subject</label><input class="form-control mb-3" id="sensitive-subject" name="subject" maxlength="180" required>
                <label class="form-label" for="sensitive-message">Describe the sensitive complaint</label><textarea class="form-control mb-3" id="sensitive-message" name="message_text" rows="4" maxlength="3000" required></textarea>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="sensitive-ack" name="acknowledge" value="1" required><label class="form-check-label" for="sensitive-ack">I confirm this is accurate and appropriate for direct department-head review.</label></div>
                <button class="btn btn-primary" type="submit">Send sensitive complaint</button>
            </form>
            <hr>
            <h2 id="escalation-title">Request a department-head review</h2>
            <p class="text-muted">Start with your assigned teacher. You can escalate an unresolved complaint here after seven days.</p>
            <div class="rule-note">Do not use this channel to submit a new complaint that should go to a teacher. Share only relevant, accurate information.</div>
            <?php if ($eligibleComplaints): ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="start_conversation">
                    <label class="form-label" for="escalation-complaint">Unresolved complaint (waiting at least 7 days)</label>
                    <select class="form-select mb-3" id="escalation-complaint" name="complaint_id" required><option value="">Choose a complaint</option><?php foreach ($eligibleComplaints as $complaint): ?><option value="<?= (int) $complaint['id'] ?>">#<?= (int) $complaint['id'] ?> · <?= h($complaint['title']) ?> · <?= h($complaint['status']) ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="new-message">Sensitive matter or reason for escalation</label>
                    <textarea class="form-control mb-3" id="new-message" name="message_text" rows="5" maxlength="3000" required></textarea>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="appropriate-use" name="acknowledge" value="1" required><label class="form-check-label" for="appropriate-use">I confirm this is accurate and appropriate for department-head review.</label></div>
                    <button class="btn btn-primary" type="submit">Send to department head</button>
                </form>
            <?php else: ?>
                <p class="empty-state mb-0">New escalations are available for unresolved complaints after seven days. Continue following up with your teacher during that time.</p>
            <?php endif; ?>
            <h2 class="mt-4">Your conversations</h2>
            <div class="thread-list">
                <?php foreach ($conversations as $conversation): ?>
                    <a class="thread-link <?= $selectedId === (int) $conversation['id'] ? 'active' : '' ?>" href="?conversation=<?= (int) $conversation['id'] ?>"><strong><?= $conversation['complaint_id'] ? 'Complaint #' . (int) $conversation['complaint_id'] . ' · ' . h($conversation['title']) : 'Sensitive matter · ' . h($conversation['subject']) ?></strong><span><?= h(ucfirst($conversation['status'])) ?> · Updated <?= h($conversation['updated_at']) ?></span><span><?= h(mb_strimwidth((string) $conversation['latest_message'], 0, 110, '...')) ?></span></a>
                <?php endforeach; ?>
                <?php if (!$conversations): ?><p class="text-muted mb-0">No department-head conversations yet.</p><?php endif; ?>
            </div>
        </section>
        <section class="communication-panel" aria-labelledby="conversation-title">
            <?php if ($selected): ?>
                <div class="conversation-title"><h2 id="conversation-title"><?= $selected['complaint_id'] ? 'Complaint #' . (int) $selected['complaint_id'] . ' · ' . h($selected['title']) : 'Sensitive matter · ' . h($selected['subject']) ?></h2><p class="text-muted mb-0"><?= $selected['complaint_id'] ? 'Complaint status: ' . h($selected['complaint_status']) . ' · ' : 'Private department-head conversation · ' ?>Review: <?= h(ucfirst($selected['status'])) ?></p></div>
                <div class="message-list" aria-live="polite">
                    <?php foreach ($messages as $message): ?>
                        <article class="message-bubble <?= (int) $message['sender_id'] === $studentId ? 'mine' : '' ?> <?= $message['message_type'] === 'resolution' ? 'resolution' : '' ?>">
                            <div class="message-meta"><strong><?= h($message['full_name']) ?> · <?= h(str_replace('_', ' ', $message['role'])) ?><?= $message['message_type'] === 'resolution' ? ' · Resolution' : '' ?></strong><time><?= h($message['created_at']) ?></time></div>
                            <p><?= h($message['message_text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($selected['status'] === 'open'): ?>
                    <form method="post" class="mt-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="send_reply"><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                        <label class="form-label" for="reply-message">Add information</label><textarea class="form-control mb-3" id="reply-message" name="message_text" rows="3" maxlength="3000" required></textarea>
                        <button class="btn btn-primary" type="submit">Send message</button>
                    </form>
                <?php else: ?><div class="alert alert-success mb-0">The department head marked this conversation resolved.</div><?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><h2 id="conversation-title">Your escalation conversations</h2><p>Select a conversation to read the department head's response and resolution.</p></div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
