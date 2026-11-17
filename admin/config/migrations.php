<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function migration_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function migration_add_column(mysqli $db, string $table, string $column, string $definition): void
{
    if (!migration_column_exists($db, $table, $column)) {
        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function migration_index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function migration_add_index(mysqli $db, string $table, string $index, string $columns, bool $unique = false): void
{
    if (!migration_index_exists($db, $table, $index)) {
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $db->query("ALTER TABLE `$table` ADD $kind `$index` ($columns)");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    faculty_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS faculties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_faculties_category_name (category, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
migration_add_column($conn, 'departments', 'faculty_id', 'INT NULL');

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL DEFAULT '',
    student_number VARCHAR(64) NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'student',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    department_id INT NULL,
    faculty_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    teacher_id INT NULL,
    department_id INT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    description TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    priority VARCHAR(50) NOT NULL DEFAULT 'Medium',
    resolution TEXT NULL,
    resolution_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NULL,
    conversation_type VARCHAR(30) NOT NULL DEFAULT 'escalation',
    subject VARCHAR(180) NULL,
    user_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS department_head_conversations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    student_id INT NOT NULL,
    complaint_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_head_conversation_complaint (student_id, complaint_id),
    INDEX idx_department_head_conversation_department (department_id, updated_at),
    INDEX idx_department_head_conversation_student (student_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
migration_add_column($conn, 'department_head_conversations', 'conversation_type', "VARCHAR(30) NOT NULL DEFAULT 'escalation'");
migration_add_column($conn, 'department_head_conversations', 'subject', 'VARCHAR(180) NULL');
$conn->query('ALTER TABLE department_head_conversations MODIFY complaint_id INT NULL');

$conn->query("CREATE TABLE IF NOT EXISTS department_head_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT NOT NULL,
    sender_id INT NOT NULL,
    message_type VARCHAR(20) NOT NULL DEFAULT 'message',
    message_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_department_head_message_conversation (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS faculty_director_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_faculty_director_message_thread (faculty_id, created_at, id),
    INDEX idx_faculty_director_message_sender (sender_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chat_threads (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_a INT NOT NULL,
    user_b INT NOT NULL,
    last_message_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_threads_pair (user_a, user_b),
    INDEX idx_chat_threads_user_a (user_a, last_message_at),
    INDEX idx_chat_threads_user_b (user_b, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
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
    INDEX idx_chat_messages_thread (thread_id, created_at),
    INDEX idx_chat_messages_recipient (recipient_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach ([
    'voice_type' => 'VARCHAR(50) NULL AFTER voice_path',
] as $column => $definition) {
    migration_add_column($conn, 'chat_messages', $column, $definition);
}

$conn->query("CREATE TABLE IF NOT EXISTS chat_presence (
    user_id INT PRIMARY KEY,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_presence_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

foreach ([
    'full_name' => "VARCHAR(120) NOT NULL DEFAULT ''",
    'student_number' => 'VARCHAR(64) NULL',
    'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
    'department_id' => 'INT NULL',
    'faculty_id' => 'INT NULL',
    'created_by' => 'INT NULL',
    'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
] as $column => $definition) {
    migration_add_column($conn, 'users', $column, $definition);
}

$conn->query("UPDATE users SET full_name = username WHERE full_name = '' OR full_name IS NULL");
$conn->query("UPDATE users SET status = 'approved' WHERE role = 'user'");
$conn->query("UPDATE users SET role = 'student' WHERE role = 'user'");
$conn->query("UPDATE users SET status = 'approved' WHERE role IN ('admin', 'faculty_director', 'department_head', 'teacher') AND status = 'pending'");
$conn->query("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'student'");

foreach ([
    'teacher_id' => 'INT NULL',
    'department_id' => 'INT NULL',
    'category' => "VARCHAR(100) NOT NULL DEFAULT 'General'",
    'resolution_by' => 'INT NULL',
    'resolved_at' => 'DATETIME NULL',
    'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
] as $column => $definition) {
    migration_add_column($conn, 'complaints', $column, $definition);
}

$conn->query("UPDATE complaints c
    JOIN users u ON u.id = c.user_id
    SET c.department_id = u.department_id
    WHERE c.department_id IS NULL AND u.department_id IS NOT NULL");

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    acting_as_user_id INT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('system_open', '1')");

migration_add_index($conn, 'users', 'idx_users_department_role_status', 'department_id, role, status');
migration_add_index($conn, 'users', 'idx_users_faculty_role_status', 'faculty_id, role, status');
migration_add_index($conn, 'departments', 'idx_departments_faculty_active', 'faculty_id, is_active');
migration_add_index($conn, 'users', 'uq_users_student_number', 'student_number', true);
migration_add_index($conn, 'users', 'uq_users_faculty_role', 'faculty_id, role', true);
migration_add_index($conn, 'complaints', 'idx_complaints_department_teacher_status', 'department_id, teacher_id, status, created_at');
migration_add_index($conn, 'complaints', 'idx_complaints_user_created', 'user_id, created_at');
migration_add_index($conn, 'comments', 'idx_comments_complaint_created', 'complaint_id, created_at');

echo 'Role-based schema migration completed successfully.';
?>
