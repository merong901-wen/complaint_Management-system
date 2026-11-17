<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/chat_helpers.php';

$chatRoleFolders = [
    'admin' => 'admin',
    'faculty_director' => 'faculty_director',
    'department_head' => 'department_head',
    'teacher' => 'teacher',
    'student' => 'user',
];
require_roles(array_keys($chatRoleFolders));
$chatRole = (string) $_SESSION['role'];
chat_init_database($conn);
$currentUserId = (int) $_SESSION['user_id'];
$error = '';
$notice = isset($_GET['sent']) ? 'Message sent.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $recipientId = (int) ($_POST['recipient_id'] ?? 0);
    $messageText = trim((string) ($_POST['message_text'] ?? ''));
    $savedUploads = [];

    if (!$recipientId || !chat_can_message($conn, $currentUserId, $recipientId)) {
        $error = 'Select a valid contact to message.';
    } elseif (mb_strlen($messageText) > 3000) {
        $error = 'Messages must be no longer than 3,000 characters.';
    } else {
        try {
            $attachment = chat_save_upload($_FILES['attachment'] ?? [], 'attachment');
            if ($attachment) {
                $savedUploads[] = $attachment['path'];
            }
            $voice = chat_save_upload($_FILES['voice_message'] ?? [], 'voice_message');
            if ($voice) {
                $savedUploads[] = $voice['path'];
            }

            if ($messageText === '' && !$attachment && !$voice) {
                throw new RuntimeException('Write a message, attach a file, or record a voice message.');
            }

            $threadId = chat_get_or_create_thread($conn, $currentUserId, $recipientId);
            chat_store_message($conn, $threadId, $currentUserId, $recipientId, $messageText, $attachment, $voice);
            header('Location: chat.php?user=' . $recipientId . '&sent=1');
            exit();
        } catch (RuntimeException $exception) {
            foreach ($savedUploads as $savedUpload) {
                $uploadPath = __DIR__ . '/../' . $savedUpload;
                if (is_file($uploadPath)) {
                    unlink($uploadPath);
                }
            }
            $error = $exception->getMessage();
        }
    }
}

$contacts = chat_get_contacts($conn, $currentUserId);
$contactIds = array_map(static fn(array $contact): int => (int) $contact['id'], $contacts);
$selectedContactId = (int) ($_GET['user'] ?? ($contactIds[0] ?? 0));
if ($selectedContactId > 0 && !in_array($selectedContactId, $contactIds, true)) {
    $selectedContactId = 0;
}

$selectedContact = null;
$threadId = 0;
$messages = [];
if ($selectedContactId > 0) {
    foreach ($contacts as $contact) {
        if ((int) $contact['id'] === $selectedContactId) {
            $selectedContact = $contact;
            break;
        }
    }
    $threadId = chat_get_or_create_thread($conn, $currentUserId, $selectedContactId);
    chat_mark_thread_read($conn, $threadId, $currentUserId);
    foreach ($contacts as &$contact) {
        if ((int) $contact['id'] === $selectedContactId) {
            $contact['unread_count'] = 0;
            $selectedContact = $contact;
            break;
        }
    }
    unset($contact);
    $messages = chat_get_thread_messages($conn, $threadId);
}

$_SESSION['csrf_token'] = csrf_token();
if ($chatRole === 'admin') {
    require __DIR__ . '/../admin/admin_sidebar.php';
} elseif ($chatRole === 'faculty_director') {
    require __DIR__ . '/../faculty_director/sidebar.php';
} elseif ($chatRole === 'department_head') {
    require __DIR__ . '/../department_head/sidebar.php';
} elseif ($chatRole === 'teacher') {
    require __DIR__ . '/../teacher/sidebar.php';
} else {
    require __DIR__ . '/../user/user_sidebar.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #eef2ff; }
        .content { margin-left: 240px; padding: 24px; }
        .chat-shell { display: grid; grid-template-columns: minmax(270px, 340px) minmax(0, 1fr); height: calc(100vh - 80px); min-height: 480px; border-radius: 22px; overflow: hidden; border: 1px solid rgba(99, 102, 241, .18); background: rgba(255,255,255,.7); box-shadow: 0 18px 45px rgba(79,70,229,.12); }
        .chat-sidebar { display: flex; flex-direction: column; min-height: 0; background: linear-gradient(180deg, #171d2a, #1d2438); color: #fff; padding: 18px 12px; }
        .chat-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding: 0 8px; }
        .chat-brand h2 { color: #fff; font-size: 1.2rem; margin: 0; }
        .chat-brand-actions { display: flex; align-items: center; gap: 8px; }
        .chat-brand-actions .btn { padding: 4px 9px; font-size: .75rem; }
        .chat-search { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.1); color: #fff; border-radius: 12px; padding: 10px 12px; margin: 0 8px 12px; }
        .chat-contact-list { display: flex; flex-direction: column; gap: 8px; padding: 0 6px; overflow-y: auto; }
        .chat-contact { display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.04); color: #fff !important; text-decoration: none !important; }
        .chat-contact:hover, .chat-contact.active { background: linear-gradient(135deg, rgba(124,110,245,.42), rgba(91,160,255,.28)); border: 1px solid rgba(255,255,255,.12); }
        .chat-avatar { position: relative; display: flex; flex: 0 0 40px; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #3b82f6); }
        .presence-dot { position: absolute; right: -1px; bottom: -1px; width: 11px; height: 11px; border: 2px solid #1d2438; border-radius: 50%; background: #8992a3; }
        .presence-dot.online { background: #34d399; }
        .chat-contact-meta { flex: 1; min-width: 0; }
        .chat-contact-row { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
        .chat-contact-meta strong { display: block; min-width: 0; overflow: hidden; color: #fff; font-size: .86rem; text-overflow: ellipsis; white-space: nowrap; }
        .chat-contact-time { flex: 0 0 auto; color: rgba(255,255,255,.58); font-size: .65rem; }
        .chat-contact-preview { display: block; overflow: hidden; margin-top: 3px; color: rgba(255,255,255,.69); font-size: .72rem; text-overflow: ellipsis; white-space: nowrap; }
        .chat-unread { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #8b7cf6; color: #fff; font-size: .68rem; font-weight: 700; }
        .chat-panel { display: flex; min-width: 0; flex-direction: column; background: #f8fafc; }
        .chat-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid #e5e7eb; background: rgba(255,255,255,.8); }
        .chat-header h3 { margin: 0; font-size: 1.1rem; }
        .chat-header small { display: flex; align-items: center; gap: 7px; color: #64748b; }
        .chat-header .presence-dot { position: static; flex: 0 0 11px; }
        .chat-messages { display: flex; flex: 1; min-height: 0; flex-direction: column; gap: 12px; overflow-y: auto; padding: 18px; }
        .chat-bubble { max-width: min(75%, 700px); padding: 12px 14px; border-radius: 18px; box-shadow: 0 4px 16px rgba(15,23,42,.06); white-space: pre-wrap; overflow-wrap: anywhere; }
        .chat-bubble.me { align-self: flex-end; border-bottom-right-radius: 6px; background: linear-gradient(135deg, #8b5cf6, #6d5efc); color: #fff; }
        .chat-bubble.them { align-self: flex-start; border: 1px solid #e5e7eb; border-bottom-left-radius: 6px; background: #fff; }
        .chat-bubble small { display: block; margin-bottom: 4px; opacity: .78; }
        .chat-attachment { display: block; margin-top: 8px; padding: 8px 10px; border-radius: 10px; background: rgba(255,255,255,.14); color: inherit; text-decoration: underline; }
        .chat-bubble.them .chat-attachment { background: #f1f5f9; }
        .chat-composer { border-top: 1px solid #e5e7eb; background: rgba(255,255,255,.9); padding: 14px 18px 16px; }
        .composer-row { display: flex; align-items: end; gap: 12px; }
        .composer-row textarea { min-height: 44px; resize: vertical; }
        .compose-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 9px; }
        .compose-file { display: inline-flex; align-items: center; gap: 8px; margin: 0; }
        .recording-status { color: #b42318; font-size: .8rem; }
        .empty-state { display: flex; flex: 1; align-items: center; justify-content: center; padding: 24px; color: #64748b; text-align: center; }
        .chat-picker { width: min(460px, calc(100vw - 32px)); max-height: min(680px, calc(100vh - 40px)); padding: 0; border: 1px solid #e2e8f0; border-radius: 18px; color: #172230; box-shadow: 0 24px 70px rgba(15,23,42,.28); }
        .chat-picker::backdrop { background: rgba(15,23,42,.58); backdrop-filter: blur(3px); }
        .chat-picker-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 20px 20px 14px; border-bottom: 1px solid #e2e8f0; }
        .chat-picker-header h2 { margin: 0; font-size: 1.1rem; }
        .chat-picker-header p { margin: 4px 0 0; color: #64748b; font-size: .82rem; }
        .chat-picker-close { border: 0; background: transparent; color: #475569; font-size: 1.4rem; line-height: 1; }
        .chat-picker-search { margin: 14px 18px 8px; }
        .chat-picker-list { display: grid; gap: 7px; max-height: 55vh; overflow-y: auto; margin: 0; padding: 6px 18px 18px; list-style: none; }
        .chat-picker-contact { display: flex; align-items: center; gap: 12px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; color: #172230 !important; text-decoration: none !important; }
        .chat-picker-contact:hover { border-color: #8b7cf6; background: #f5f3ff; }
        .chat-picker-contact .chat-avatar { color: #fff; }
        .chat-picker-meta { min-width: 0; }
        .chat-picker-meta strong, .chat-picker-meta span { display: block; }
        .chat-picker-meta span { color: #64748b; font-size: .76rem; }
        .chat-picker-empty { padding: 22px 10px; color: #64748b; text-align: center; }
        @media (max-width: 900px) { .content { margin-left: 0; padding: 14px; } .chat-shell { grid-template-columns: 1fr; height: calc(100vh - 28px); } .chat-sidebar { max-height: 38vh; } }
        @media (max-width: 520px) { .chat-bubble { max-width: 92%; } .chat-header, .chat-composer { padding-right: 12px; padding-left: 12px; } .composer-row { align-items: stretch; flex-direction: column; } }
    </style>
</head>
<body>
    <main class="content">
        <div class="chat-shell" data-chat-current-user="<?= $currentUserId ?>">
            <aside class="chat-sidebar" aria-label="Chat contacts">
                <div class="chat-brand">
                    <h2>Chats</h2>
                    <div class="chat-brand-actions">
                        <span class="badge rounded-pill text-bg-light text-dark"><?= count($contacts) ?></span>
                        <button type="button" class="btn btn-sm btn-outline-light" data-chat-picker-open>+ New chat</button>
                    </div>
                </div>
                <input type="search" class="chat-search" data-chat-search placeholder="Search contacts" aria-label="Search contacts">
                <div class="chat-contact-list" data-chat-contact-list>
                    <?php if (!$contacts): ?>
                        <div class="px-2 pt-3 text-center text-white-50">No approved contacts available.</div>
                    <?php else: foreach ($contacts as $contact):
                        $contactName = (string) ($contact['full_name'] ?: $contact['username']);
                        $preview = trim((string) ($contact['latest_message'] ?? ''));
                        if ($preview === '' && !empty($contact['latest_attachment'])) {
                            $preview = '📎 ' . $contact['latest_attachment'];
                        } elseif ($preview === '' && !empty($contact['latest_voice'])) {
                            $preview = 'Voice message';
                        }
                        if ($preview === '') {
                            $preview = 'Start a conversation';
                        }
                        $lastMessageAt = (string) ($contact['last_message_at'] ?? '');
                        $isOnline = (int) ($contact['is_online'] ?? 0) === 1;
                    ?>
                        <a class="chat-contact <?= (int) $contact['id'] === $selectedContactId ? 'active' : '' ?>"
                           href="chat.php?user=<?= (int) $contact['id'] ?>"
                           data-chat-contact
                           data-contact-name="<?= h($contactName) ?>"
                           data-presence-user="<?= (int) $contact['id'] ?>">
                            <span class="chat-avatar"><?= h(strtoupper(substr($contactName, 0, 2))) ?><span class="presence-dot <?= $isOnline ? 'online' : '' ?>" data-presence-dot aria-label="<?= $isOnline ? 'Online' : 'Offline' ?>"></span></span>
                            <span class="chat-contact-meta">
                                <span class="chat-contact-row"><strong><?= h($contactName) ?></strong>
                                    <?php if ($lastMessageAt !== ''): ?><time class="chat-contact-time" datetime="<?= h($lastMessageAt) ?>"><?= h(date('M j', strtotime($lastMessageAt))) ?></time><?php endif; ?>
                                </span>
                                <span class="chat-contact-row"><span class="chat-contact-preview"><?= h(mb_strimwidth($preview, 0, 52, '…')) ?></span>
                                    <?php if ((int) $contact['unread_count'] > 0): ?><span class="chat-unread" aria-label="<?= (int) $contact['unread_count'] ?> unread messages"><?= (int) $contact['unread_count'] ?></span><?php endif; ?>
                                </span>
                            </span>
                        </a>
                    <?php endforeach; endif; ?>
                    <p class="px-2 pt-3 text-center text-white-50" data-chat-no-results hidden>No contacts match your search.</p>
                </div>
            </aside>

            <dialog class="chat-picker" data-chat-picker aria-labelledby="chat-picker-title">
                <div class="chat-picker-header">
                    <div>
                        <h2 id="chat-picker-title">Start a new chat</h2>
                        <p>Only accounts you are allowed to message are listed here.</p>
                    </div>
                    <button type="button" class="chat-picker-close" data-chat-picker-close aria-label="Close contact list">&times;</button>
                </div>
                <input type="search" class="form-control chat-picker-search" data-chat-picker-search placeholder="Search allowed users" aria-label="Search allowed users">
                <ul class="chat-picker-list" data-chat-picker-list>
                    <?php foreach ($contacts as $contact):
                        $pickerName = (string) ($contact['full_name'] ?: $contact['username']);
                    ?>
                        <li data-picker-item data-contact-name="<?= h($pickerName) ?>">
                            <a class="chat-picker-contact" href="chat.php?user=<?= (int) $contact['id'] ?>">
                                <span class="chat-avatar"><?= h(strtoupper(substr($pickerName, 0, 2))) ?></span>
                                <span class="chat-picker-meta">
                                    <strong><?= h($pickerName) ?></strong>
                                    <span><?= h(str_replace('_', ' ', ucfirst($contact['role']))) ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="chat-picker-empty" data-picker-empty <?= $contacts ? 'hidden' : '' ?>>No permitted users are currently available.</li>
                </ul>
            </dialog>

            <section class="chat-panel" aria-label="Conversation">
                <?php if ($selectedContact): ?>
                    <?php $isOnline = (int) ($selectedContact['is_online'] ?? 0) === 1; ?>
                    <header class="chat-header" data-chat-selected-user="<?= $selectedContactId ?>">
                        <div>
                            <h3><?= h($selectedContact['full_name'] ?: $selectedContact['username']) ?></h3>
                            <small><span class="presence-dot <?= $isOnline ? 'online' : '' ?>" data-selected-presence-dot></span><span data-selected-presence-text><?= $isOnline ? 'Online' : 'Offline' ?></span> · <?= h(str_replace('_', ' ', ucfirst($selectedContact['role']))) ?></small>
                        </div>
                    </header>
                    <?php if ($error): ?><div class="alert alert-danger m-3 mb-0" role="alert"><?= h($error) ?></div><?php endif; ?>
                    <?php if ($notice): ?><div class="alert alert-success m-3 mb-0" role="status"><?= h($notice) ?></div><?php endif; ?>
                    <div class="chat-messages" aria-live="polite">
                        <?php if (!$messages): ?>
                            <div class="empty-state">No messages yet. Start the conversation.</div>
                        <?php else: foreach ($messages as $message): $isMe = (int) $message['sender_id'] === $currentUserId; ?>
                            <article class="chat-bubble <?= $isMe ? 'me' : 'them' ?>" data-message-id="<?= (int) $message['id'] ?>">
                                <small><?= $isMe ? 'You' : h($message['full_name'] ?: $message['username']) ?> · <?= h(date('M j, g:i a', strtotime((string) $message['created_at']))) ?></small>
                                <?php if (!empty($message['message_text'])): ?><div><?= h($message['message_text']) ?></div><?php endif; ?>
                                <?php if (!empty($message['attachment_path'])): ?><a class="chat-attachment" href="../includes/chat_download.php?id=<?= (int) $message['id'] ?>&type=attachment"><?= h($message['attachment_name'] ?: 'Attachment') ?></a><?php endif; ?>
                                <?php if (!empty($message['voice_path'])): ?><audio controls class="mt-2 w-100"><source src="../includes/chat_download.php?id=<?= (int) $message['id'] ?>&type=voice" type="<?= h($message['voice_type'] ?: 'audio/webm') ?>">Your browser cannot play this audio.</audio><?php endif; ?>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="chat-composer" data-chat-composer>
                        <?= csrf_field() ?>
                        <input type="hidden" name="recipient_id" value="<?= $selectedContactId ?>">
                        <div class="composer-row">
                            <textarea name="message_text" class="form-control" rows="1" maxlength="3000" placeholder="Type a message…" aria-label="Message" data-chat-message></textarea>
                            <button type="submit" class="btn btn-primary px-4">Send</button>
                        </div>
                        <div class="compose-controls">
                            <label class="compose-file btn btn-outline-secondary btn-sm">
                                <input type="file" name="attachment" class="d-none" accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.txt,.zip">
                                <span>Attach file</span>
                            </label>
                            <label class="compose-file btn btn-outline-secondary btn-sm">
                                <input type="file" name="voice_message" class="d-none" accept="audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav">
                                <span>Choose audio</span>
                            </label>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-record-start>Record voice</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-record-stop hidden>Stop recording</button>
                            <span class="recording-status" data-recording-status role="status" aria-live="polite"></span>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">Choose a contact to open a conversation.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script src="../public/assets/js/chat.js?v=20261116.1" defer></script>
</body>
</html>
