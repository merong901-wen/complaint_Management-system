<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

if (($_SESSION['role'] ?? '') === 'user') {
    $_SESSION['role'] = 'student';
}

require_once __DIR__ . '/../admin/config/config.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function password_is_strong(string $password): bool
{
    return strlen($password) >= 12
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^a-zA-Z0-9]/', $password);
}

function record_audit(mysqli $db, string $action, ?string $entity = null, ?int $entityId = null, array $details = []): void
{
    try {
        $actorId = (int) ($_SESSION['admin_actor_id'] ?? $_SESSION['user_id'] ?? 0);
        $effectiveId = isset($_SESSION['admin_actor_id']) ? (int) ($_SESSION['user_id'] ?? 0) : null;
        $encodedDetails = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stmt = $db->prepare('INSERT INTO audit_logs (user_id, acting_as_user_id, action, entity_type, entity_id, details, ip_address) VALUES (NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?)');
        $stmt->bind_param('iississ', $actorId, $effectiveId, $action, $entity, $entityId, $encodedDetails, $ipAddress);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $exception) {
        error_log('Audit log write failed: ' . $exception->getMessage());
    }
}

function redirect_for_role(?string $role = null): never
{
    $role ??= (string) ($_SESSION['role'] ?? '');
    $destination = match ($role) {
        'admin' => 'admin/admin_dashboard.php',
        'faculty_director' => 'faculty_director/dashboard.php',
        'department_head' => 'department_head/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student', 'user' => 'user/user_home.php',
        default => 'login.php',
    };

    header('Location: ' . (str_starts_with($destination, '../') ? $destination : (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/user/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/teacher/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/department_head/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/faculty_director/') ? '../' : '') . $destination));
    exit();
}

function require_roles(array $roles): void
{
    global $conn;
    if (empty($_SESSION['user_logged_in']) || !in_array($_SESSION['role'] ?? '', $roles, true)) {
        redirect_for_role('');
    }

    if (($_SESSION['role'] ?? '') !== 'admin') {
        $stmt = $conn->prepare('SELECT status, department_id, faculty_id FROM users WHERE id = ?');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$account || $account['status'] !== 'approved') {
            $_SESSION = [];
            session_destroy();
            header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/user/') ? '../login.php?approval=pending' : 'login.php?approval=pending'));
            exit();
        }

        if (in_array($_SESSION['role'], ['department_head', 'teacher'], true) && empty($account['department_id'])) {
            http_response_code(403);
            exit('This account is not assigned to a department. Contact the administrator.');
        }
        if ($_SESSION['role'] === 'faculty_director' && empty($account['faculty_id'])) {
            http_response_code(403);
            exit('This account is not assigned to a faculty. Contact the administrator.');
        }
    }

    if (($_SESSION['role'] ?? '') !== 'admin' && !system_is_open()) {
        http_response_code(503);
        exit('The system is temporarily unavailable. Please try again later.');
    }

    $dataRequest = array_intersect(['progress', 'summary', 'metrics', 'details', 'teacher', 'id'], array_keys($_GET));
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$dataRequest) {
        record_audit($conn, 'page_access', 'page', null, ['path' => basename($_SERVER['SCRIPT_NAME'] ?? '')]);
    }
}

function pagination_data(int $total, int $requestedPage = 1, int $perPage = 10): array
{
    $pageCount = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $requestedPage), $pageCount);

    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'page_count' => $pageCount,
        'total' => $total,
    ];
}

function pagination_links(array $pagination, string $pageParameter = 'page'): string
{
    if ($pagination['page_count'] < 2) {
        return '';
    }

    $query = $_GET;
    $html = '<nav class="pagination-nav" aria-label="Pagination"><ul class="pagination">';
    $start = max(1, $pagination['page'] - 2);
    $end = min($pagination['page_count'], $pagination['page'] + 2);

    for ($page = $start; $page <= $end; $page++) {
        $query[$pageParameter] = $page;
        $url = h((string) strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($query));
        $active = $page === $pagination['page'] ? ' aria-current="page"' : '';
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '"' . $active . '>' . $page . '</a></li>';
    }

    $html .= '</ul><span class="pagination-total">' . (int) $pagination['total'] . ' records</span></nav>';
    return $html;
}

function post_csrf_or_exit(): void
{
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request token. Refresh the page and try again.');
    }
}

function system_is_open(): bool
{
    global $conn;
    try {
        $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_open' LIMIT 1");
        $setting = $result ? $result->fetch_assoc() : null;
        return $setting && $setting['setting_value'] === '1';
    } catch (Throwable) {
        return false;
    }
}
