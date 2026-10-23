<?php
require_once __DIR__ . '/includes/app.php';

if (isset($_SESSION['admin_actor_id'])) {
    record_audit($conn, 'admin_impersonation_ended', 'user', (int) $_SESSION['user_id']);
    $_SESSION['user_id'] = $_SESSION['admin_actor_id'];
    $_SESSION['username'] = $_SESSION['admin_actor_username'];
    $_SESSION['full_name'] = $_SESSION['admin_actor_full_name'];
    $_SESSION['role'] = $_SESSION['admin_actor_role'];
    $_SESSION['department_id'] = $_SESSION['admin_actor_department_id'];
    $_SESSION['faculty_id'] = $_SESSION['admin_actor_faculty_id'];
    $_SESSION['user_logged_in'] = true;
    unset($_SESSION['admin_actor_id'], $_SESSION['admin_actor_username'], $_SESSION['admin_actor_full_name'], $_SESSION['admin_actor_role'], $_SESSION['admin_actor_department_id'], $_SESSION['admin_actor_faculty_id']);
    session_regenerate_id(true);
    header('Location: admin/admin_dashboard.php');
    exit();
}

if (!empty($_SESSION['user_id'])) {
    record_audit($conn, 'logout', 'user', (int) $_SESSION['user_id']);
}

$_SESSION = [];
session_destroy();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

header('Location: index.php');
exit();