<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
include 'admin_sidebar.php'; // ✅ already correct
$username = $_SESSION['username'];
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Home</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.welcome-box {
    margin:100px auto; max-width:700px; text-align:center; padding:40px;
    background:#3498db; color:#fff; border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,0.3); animation: fadeIn 1.5s ease;
}
@keyframes fadeIn { 0% {opacity:0; transform:translateY(-50px);} 100% {opacity:1; transform:translateY(0);} }
.welcome-box h1 { font-size:2.5em; margin-bottom:20px; }
.welcome-box p { font-size:1.2em; }
</style>
</head>
<body>
<div class="content">
    <div class="welcome-box">
        <h1>🎉 Welcome Admin <?= htmlspecialchars($username) ?>!</h1>
        <p>Manage complaints smartly and efficiently.</p>
        <p>Use the sidebar to navigate: Dashboard, Complaints, Profile.</p>
    </div>
</div>
</body>
</html>