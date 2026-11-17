<?php

if (!function_exists('chat_init_database')) {
    function chat_init_database(mysqli $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS chat_threads (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_a INT NOT NULL,
            user_b INT NOT NULL,
            last_message_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_chat_threads_pair (user_a, user_b),
            KEY idx_chat_threads_user_a (user_a, last_message_at),
            KEY idx_chat_threads_user_b (user_b, last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT NOT NULL,
            sender_id INT NOT NULL,
            recipient_id INT NOT NULL,
            message_text TEXT NULL,
            attachment_name VARCHAR(255) NULL,
            attachment_path VARCHAR(500) NULL,
            attachment_type VARCHAR(50) NULL,
            voice_path VARCHAR(500) NULL,
            voice_type VARCHAR(50) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_chat_messages_thread (thread_id, created_at),
            KEY idx_chat_messages_recipient (recipient_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS chat_presence (
            user_id INT PRIMARY KEY,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_chat_presence_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $voiceTypeColumn = $db->query("SHOW COLUMNS FROM chat_messages LIKE 'voice_type'");
        if ($voiceTypeColumn->num_rows === 0) {
            $db->query('ALTER TABLE chat_messages ADD COLUMN voice_type VARCHAR(50) NULL AFTER voice_path');
        }
    }
}

if (!function_exists('chat_normalize_pair')) {
    function chat_normalize_pair(int $userA, int $userB): array
    {
        if ($userA < $userB) {
            return [$userA, $userB];
        }

        return [$userB, $userA];
    }
}

if (!function_exists('chat_user_summary')) {
    function chat_user_summary(mysqli $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT id, full_name, username, role, status, department_id, faculty_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }
}

if (!function_exists('chat_can_message')) {
    function chat_can_message(mysqli $db, int $currentUserId, int $recipientId): bool
    {
        $me = chat_user_summary($db, $currentUserId);
        $recipient = chat_user_summary($db, $recipientId);

        if (!$me || !$recipient) {
            return false;
        }

        if ((string) $recipient['status'] !== 'approved' || (string) $me['status'] !== 'approved') {
            return false;
        }

        $myRole = (string) $me['role'];
        $theirRole = (string) $recipient['role'];
        $myDepartment = (int) ($me['department_id'] ?? 0);
        $theirDepartment = (int) ($recipient['department_id'] ?? 0);
        $myFaculty = (int) ($me['faculty_id'] ?? 0);
        $theirFaculty = (int) ($recipient['faculty_id'] ?? 0);

        if ($currentUserId === $recipientId) {
            return false;
        }

        switch ($myRole) {
            case 'admin':
                return $theirRole === 'faculty_director';
            case 'faculty_director':
                if ($theirRole === 'admin') {
                    return true;
                }
                return $theirRole === 'department_head' && $theirFaculty === $myFaculty;
            case 'department_head':
                if ($theirRole === 'faculty_director') {
                    return $theirFaculty === $myFaculty;
                }
                return $theirRole === 'teacher' && $theirDepartment === $myDepartment;
            case 'teacher':
                if ($theirRole === 'department_head') {
                    return $theirDepartment === $myDepartment;
                }
                return $theirRole === 'student' && $theirDepartment === $myDepartment;
            case 'student':
                if ($theirRole === 'teacher') {
                    return $theirDepartment === $myDepartment;
                }
                return $theirRole === 'department_head' && $theirDepartment === $myDepartment;
            default:
                return false;
        }
    }
}

if (!function_exists('chat_get_contacts')) {
    function chat_get_contacts(mysqli $db, int $userId): array
    {
        $me = chat_user_summary($db, $userId);
        if (!$me) {
            return [];
        }

        $role = (string) $me['role'];
        $departmentId = (int) ($me['department_id'] ?? 0);
        $facultyId = (int) ($me['faculty_id'] ?? 0);
        $where = [];
        $params = [];
        $types = '';

        switch ($role) {
            case 'admin':
                $where[] = "role = 'faculty_director'";
                break;
            case 'faculty_director':
                $where[] = "role = 'admin'";
                $where[] = "role = 'department_head' AND faculty_id = ?";
                $params[] = $facultyId;
                $types .= 'i';
                break;
            case 'department_head':
                $where[] = "role = 'faculty_director' AND faculty_id = ?";
                $params[] = $facultyId;
                $types .= 'i';
                $where[] = "role = 'teacher' AND department_id = ?";
                $params[] = $departmentId;
                $types .= 'i';
                break;
            case 'teacher':
                $where[] = "role = 'department_head' AND department_id = ?";
                $params[] = $departmentId;
                $types .= 'i';
                $where[] = "role = 'student' AND department_id = ?";
                $params[] = $departmentId;
                $types .= 'i';
                break;
            case 'student':
                $where[] = "role = 'teacher' AND department_id = ?";
                $params[] = $departmentId;
                $types .= 'i';
                $where[] = "role = 'department_head' AND department_id = ?";
                $params[] = $departmentId;
                $types .= 'i';
                break;
            default:
                return [];
        }

        $clauses = [];
        foreach ($where as $clause) {
            $clauses[] = '(' . $clause . ')';
        }

        $sql = 'SELECT u.id, u.full_name, u.username, u.role, u.status, u.department_id, u.faculty_id,
                    (SELECT cm.message_text FROM chat_messages cm WHERE cm.thread_id = t.id ORDER BY cm.id DESC LIMIT 1) AS latest_message,
                    (SELECT cm.attachment_name FROM chat_messages cm WHERE cm.thread_id = t.id ORDER BY cm.id DESC LIMIT 1) AS latest_attachment,
                    (SELECT cm.voice_path FROM chat_messages cm WHERE cm.thread_id = t.id ORDER BY cm.id DESC LIMIT 1) AS latest_voice,
                    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.thread_id = t.id AND cm.recipient_id = ? AND cm.sender_id = u.id AND cm.is_read = 0) AS unread_count,
                    (SELECT p.last_seen FROM chat_presence p WHERE p.user_id = u.id LIMIT 1) AS last_seen,
                    CASE WHEN (SELECT p.last_seen FROM chat_presence p WHERE p.user_id = u.id LIMIT 1) >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 MINUTE) THEN 1 ELSE 0 END AS is_online,
                    t.last_message_at
                FROM users u
                LEFT JOIN chat_threads t ON t.user_a = LEAST(?, u.id) AND t.user_b = GREATEST(?, u.id)
                WHERE u.status = \'approved\' AND (' . implode(' OR ', $where) . ')
                ORDER BY (t.last_message_at IS NULL) ASC, t.last_message_at DESC, u.full_name ASC, u.username ASC';
        $stmt = $db->prepare($sql);
        array_unshift($params, $userId, $userId, $userId);
        $types = 'iii' . $types;
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_values(array_filter($contacts, static fn (array $contact): bool => (int) $contact['id'] !== $userId));
    }
}

if (!function_exists('chat_get_or_create_thread')) {
    function chat_get_or_create_thread(mysqli $db, int $userA, int $userB): int
    {
        [$a, $b] = chat_normalize_pair($userA, $userB);

        $check = $db->prepare('SELECT id FROM chat_threads WHERE user_a = ? AND user_b = ? LIMIT 1');
        $check->bind_param('ii', $a, $b);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            return (int) $existing['id'];
        }

        $insert = $db->prepare('INSERT IGNORE INTO chat_threads (user_a, user_b) VALUES (?, ?)');
        $insert->bind_param('ii', $a, $b);
        $insert->execute();
        $threadId = (int) $insert->insert_id;
        $insert->close();

        if ($threadId === 0) {
            $check = $db->prepare('SELECT id FROM chat_threads WHERE user_a = ? AND user_b = ? LIMIT 1');
            $check->bind_param('ii', $a, $b);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$existing) {
                throw new RuntimeException('The conversation could not be opened. Please try again.');
            }
            $threadId = (int) $existing['id'];
        }

        return $threadId;
    }
}

if (!function_exists('chat_get_thread_messages')) {
    function chat_get_thread_messages(mysqli $db, int $threadId): array
    {
        $stmt = $db->prepare('SELECT cm.*, u.full_name, u.username, u.role FROM chat_messages cm JOIN users u ON u.id = cm.sender_id WHERE cm.thread_id = ? ORDER BY cm.id DESC LIMIT 100');
        $stmt->bind_param('i', $threadId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $messages;
    }
}

if (!function_exists('chat_mark_thread_read')) {
    function chat_mark_thread_read(mysqli $db, int $threadId, int $recipientId): void
    {
        $stmt = $db->prepare('UPDATE chat_messages SET is_read = 1 WHERE thread_id = ? AND recipient_id = ? AND is_read = 0');
        $stmt->bind_param('ii', $threadId, $recipientId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('chat_save_upload')) {
    function chat_save_upload(array $file, string $fieldName): ?array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The upload failed. Please choose the file again.');
        }

        if (!in_array($fieldName, ['attachment', 'voice_message'], true)) {
            throw new RuntimeException('Invalid upload type.');
        }

        $maxBytes = $fieldName === 'voice_message' ? 15 * 1024 * 1024 : 10 * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            throw new RuntimeException($fieldName === 'voice_message'
                ? 'Voice messages must be no larger than 15 MB.'
                : 'Attachments must be no larger than 10 MB.');
        }

        $originalName = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowedTypes = $fieldName === 'voice_message'
            ? [
                'webm' => ['audio/webm', 'video/webm'],
                'ogg' => ['audio/ogg', 'application/ogg'],
                'oga' => ['audio/ogg', 'application/ogg'],
                'mp3' => ['audio/mpeg', 'audio/mp3'],
                'm4a' => ['audio/mp4', 'audio/x-m4a'],
                'wav' => ['audio/wav', 'audio/x-wav'],
            ]
            : [
                'png' => ['image/png'],
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword', 'application/x-ole-storage'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'txt' => ['text/plain'],
                'zip' => ['application/zip', 'application/x-zip'],
            ];
        if (!isset($allowedTypes[$extension]) || !in_array($mime, $allowedTypes[$extension], true)) {
            throw new RuntimeException($fieldName === 'voice_message'
                ? 'Choose a supported audio file (WebM, OGG, MP3, M4A, or WAV).'
                : 'Choose a supported attachment (PNG, JPG, PDF, DOC, DOCX, TXT, or ZIP).');
        }

        $baseDir = __DIR__ . '/../uploads/chat';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
            throw new RuntimeException('The upload folder is unavailable. Please contact the administrator.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';
        $destinationName = $safeName . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $baseDir . '/' . $destinationName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('The file could not be saved. Please try again.');
        }

        $relative = 'uploads/chat/' . $destinationName;

        return [
            'name' => $originalName,
            'path' => $relative,
            'mime' => $fieldName === 'voice_message'
                ? ([
                    'webm' => 'audio/webm',
                    'ogg' => 'audio/ogg',
                    'oga' => 'audio/ogg',
                    'mp3' => 'audio/mpeg',
                    'm4a' => 'audio/mp4',
                    'wav' => 'audio/wav',
                ][$extension] ?? $mime)
                : $mime,
            'field' => $fieldName,
        ];
    }
}

if (!function_exists('chat_store_message')) {
    function chat_store_message(mysqli $db, int $threadId, int $senderId, int $recipientId, string $messageText, ?array $attachment = null, ?array $voice = null): void
    {
        $messageText = trim($messageText);
        $attachmentPath = $attachment['path'] ?? null;
        $attachmentName = $attachment['name'] ?? null;
        $attachmentType = $attachment['mime'] ?? null;
        $voicePath = $voice['path'] ?? null;

        $voiceType = $voice['mime'] ?? null;
        $stmt = $db->prepare('INSERT INTO chat_messages (thread_id, sender_id, recipient_id, message_text, attachment_name, attachment_path, attachment_type, voice_path, voice_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiissssss', $threadId, $senderId, $recipientId, $messageText, $attachmentName, $attachmentPath, $attachmentType, $voicePath, $voiceType);
        $stmt->execute();
        $stmt->close();

        $db->query('UPDATE chat_threads SET last_message_at = CURRENT_TIMESTAMP WHERE id = ' . (int) $threadId);
    }
}

if (!function_exists('chat_get_thread_for_users')) {
    function chat_get_thread_for_users(mysqli $db, int $userA, int $userB): ?array
    {
        [$a, $b] = chat_normalize_pair($userA, $userB);
        $stmt = $db->prepare('SELECT * FROM chat_threads WHERE user_a = ? AND user_b = ? LIMIT 1');
        $stmt->bind_param('ii', $a, $b);
        $stmt->execute();
        $thread = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $thread ?: null;
    }
}
