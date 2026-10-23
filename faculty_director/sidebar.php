<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Faculty director';
?>
<link rel="stylesheet" href="../public/assets/css/style.css?v=20261015.5">
<style>
    body .role-sidebar nav a,
    body .role-sidebar .sidebar-bottom a,
    body .role-sidebar nav a:hover,
    body .role-sidebar .sidebar-bottom a:hover,
    body .role-sidebar nav a.active { text-decoration: none !important; }
</style>
<script src="../public/assets/js/script.js?v=20261015.5" defer></script>
<aside class="sidebar role-sidebar" aria-label="Faculty director navigation">
    <div class="sidebar-controls"><button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><span class="sidebar-toggle-symbol" aria-hidden="true">‹</span><span data-sidebar-label>Collapse</span></button></div>
    <h3>Welcome, <?= h($displayName) ?></h3>
    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false"><span class="sidebar-theme-icon" data-theme-icon aria-hidden="true">☾</span><span data-theme-label>Dark mode</span></button>
    <nav>
        <a href="dashboard.php" title="Dashboard" aria-label="Dashboard" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">▦</span><span class="sidebar-link-label">Dashboard</span></a>
        <a href="registrations.php" title="Register" aria-label="Register" class="<?= $currentPage === 'registrations.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">＋</span><span class="sidebar-link-label">Register</span></a>
        <a href="messages.php" title="Messages" aria-label="Messages" class="<?= $currentPage === 'messages.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">@</span><span class="sidebar-link-label">Messages</span></a>
        <a href="audit.php" title="Audit log" aria-label="Audit log" class="<?= $currentPage === 'audit.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">≡</span><span class="sidebar-link-label">Audit log</span></a>
    </nav>
    <div class="sidebar-bottom"><a href="../logout.php" title="Logout" aria-label="Logout"><span class="sidebar-link-icon" aria-hidden="true">↪</span><span class="sidebar-link-label">Logout</span></a></div>
</aside>
