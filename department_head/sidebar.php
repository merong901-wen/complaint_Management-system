<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Department head';
?>
<link rel="stylesheet" href="../public/assets/css/style.css?v=20261015.4">
<style>
    body .role-sidebar nav a,
    body .role-sidebar .sidebar-bottom a,
    body .role-sidebar nav a:hover,
    body .role-sidebar .sidebar-bottom a:hover { text-decoration: none !important; }

</style>
<script src="../public/assets/js/script.js?v=20261015.4" defer></script>
<aside class="sidebar role-sidebar" aria-label="Department head navigation">
    <div class="sidebar-controls"><button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><span class="sidebar-toggle-symbol" aria-hidden="true">‹</span><span data-sidebar-label>Collapse</span></button></div>
    <h3><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h3>
    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false"><span class="sidebar-theme-icon" data-theme-icon aria-hidden="true">☾</span><span data-theme-label>Dark mode</span></button>
    <nav>
        <a href="dashboard.php" title="Dashboard" aria-label="Dashboard" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">▦</span><span class="sidebar-link-label">Dashboard</span></a>
        <a href="teachers.php" title="Teachers" aria-label="Teachers" class="<?= $currentPage === 'teachers.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">○</span><span class="sidebar-link-label">Teachers</span></a>
        <a href="approvals.php" title="Approvals" aria-label="Approvals" class="<?= $currentPage === 'approvals.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">✓</span><span class="sidebar-link-label">Approvals</span></a>
        <a href="chat.php" title="Chat" aria-label="Chat" class="<?= $currentPage === 'chat.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">✉</span><span class="sidebar-link-label">Chat</span></a>
    </nav>
    <div class="sidebar-bottom">
        <a href="../logout.php" title="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>" aria-label="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>"><span class="sidebar-link-icon" aria-hidden="true">↪</span><span class="sidebar-link-label"><?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?></span></a>
    </div>
</aside>
