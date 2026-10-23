<?php
if(session_status() === PHP_SESSION_NONE) session_start();
$user_name = $_SESSION['username'] ?? 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<link rel="stylesheet" href="../public/assets/css/style.css?v=20261015.4">
<script src="../public/assets/js/script.js?v=20261015.5" defer></script>

<style>
.sidebar {
    width: 240px; height: 100vh; position: fixed; top:0; left:0;
    background: #2c3e50; color:white; padding-top:20px; font-family:'Poppins',sans-serif;
    overflow-y:auto;
}
.sidebar h3 { text-align:center; margin-bottom:25px; font-size:22px; font-weight:600; }
.sidebar a {
    display:block; color:white; text-decoration:none; padding:12px 20px; margin:5px 10px;
    border-radius:8px; font-weight:500; transition:0.3s;
}
.sidebar a:hover, .sidebar a.active { background:#34495e; color:#ffd700; }
.content { margin-left:240px; padding:25px; min-height:100vh; background:#f4f6f8; }
</style>

<div class="sidebar">
    <div class="sidebar-controls">
        <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><span class="sidebar-toggle-symbol" aria-hidden="true">‹</span><span data-sidebar-label>Collapse</span></button>
    </div>
    <h3>Welcome, <?= htmlspecialchars($user_name) ?></h3>
    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false"><span class="sidebar-theme-icon" data-theme-icon aria-hidden="true">☾</span><span data-theme-label>Dark mode</span></button>
    <nav>
        <a href="admin_dashboard.php" title="Dashboard" aria-label="Dashboard" class="<?= $current_page=='admin_dashboard.php'?'active':'' ?>"><span class="sidebar-link-icon" aria-hidden="true">▦</span><span class="sidebar-link-label">Dashboard</span></a>
        <a href="faculty_setup.php#registered-faculties" title="Manage faculties" aria-label="Manage faculties" class="<?= in_array($current_page, ['faculty_setup.php', 'admin_registrations.php'], true) ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">＋</span><span class="sidebar-link-label">Faculties</span></a>
        <a href="system.php" title="System" aria-label="System" class="<?= $current_page=='system.php'?'active':'' ?>"><span class="sidebar-link-icon" aria-hidden="true">⚙</span><span class="sidebar-link-label">System</span></a>
        <a href="faculty_communications.php" title="Communications" aria-label="Communications" class="<?= $current_page === 'faculty_communications.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">@</span><span class="sidebar-link-label">Communications</span></a>
        <a href="audit_logs.php" title="Audit logs" aria-label="Audit logs" class="<?= $current_page === 'audit_logs.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">◷</span><span class="sidebar-link-label">Audit logs</span></a>
        <a href="admin_profile.php" title="Profile" aria-label="Profile" class="<?= $current_page=='admin_profile.php'?'active':'' ?>"><span class="sidebar-link-icon" aria-hidden="true">○</span><span class="sidebar-link-label">Profile</span></a>
    </nav>
    <div class="sidebar-bottom"><a href="../logout.php" title="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>" aria-label="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>"><span class="sidebar-link-icon" aria-hidden="true">↪</span><span class="sidebar-link-label"><?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?></span></a></div>
</div>
