<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_name = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$current_page = basename($_SERVER['PHP_SELF']); // get current page
?>

<link rel="stylesheet" href="../public/assets/css/style.css?v=20261015.4">
<script src="../public/assets/js/script.js?v=20261015.4" defer></script>

<style>
/* Sidebar Styles */
.sidebar {
    width: 240px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: #2c3e50;
    color: white;
    padding-top: 20px;
    font-family: 'Poppins', sans-serif;
    overflow-y: auto;
}

.sidebar h3 {
    text-align: center;
    margin-bottom: 25px;
    font-size: 22px;
    font-weight: 600;
}

.sidebar a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 12px 20px;
    margin: 5px 10px;
    border-radius: 8px;
    font-weight: 500;
    transition: 0.3s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #34495e;
    color: #ffd700;
}

.sidebar a i {
    margin-right: 8px;
}

.content {
    margin-left: 240px;
    padding: 25px;
    min-height: 100vh;
    background: #f4f6f8;
}
</style>

<div class="sidebar">
    <div class="sidebar-controls">
        <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><span class="sidebar-toggle-symbol" aria-hidden="true">‹</span><span data-sidebar-label>Collapse</span></button>
    </div>
    <h3>Welcome, <?= htmlspecialchars($user_name) ?></h3>
    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false"><span class="sidebar-theme-icon" data-theme-icon aria-hidden="true">☾</span><span data-theme-label>Dark mode</span></button>
    <nav>
        <a href="user_dashboard.php" title="Dashboard" aria-label="Dashboard" class="<?= $current_page == 'user_dashboard.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">▦</span><span class="sidebar-link-label">Dashboard</span></a>
        <a href="user_complaints.php" title="Complaints" aria-label="Complaints" class="<?= $current_page == 'user_complaints.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">≡</span><span class="sidebar-link-label">Complaints</span></a>
        <a href="department_head_messages.php" title="Department head communication" aria-label="Department head communication" class="<?= $current_page == 'department_head_messages.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">✉</span><span class="sidebar-link-label">Head communication</span></a>
        <a href="resolutions.php" title="Resolutions" aria-label="Resolutions" class="<?= $current_page == 'resolutions.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">✓</span><span class="sidebar-link-label">Resolutions</span></a>
        <a href="user_profile.php" title="Profile" aria-label="Profile" class="<?= $current_page == 'user_profile.php' ? 'active' : '' ?>"><span class="sidebar-link-icon" aria-hidden="true">○</span><span class="sidebar-link-label">Profile</span></a>
    </nav>
    <div class="sidebar-bottom"><a href="../logout.php" title="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>" aria-label="<?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?>"><span class="sidebar-link-icon" aria-hidden="true">↪</span><span class="sidebar-link-label"><?= isset($_SESSION['admin_actor_id']) ? 'Return to admin' : 'Logout' ?></span></a></div>
</div>