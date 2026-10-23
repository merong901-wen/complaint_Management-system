<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$username = $_SESSION['username'];
include 'user_sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
<title>Welcome User</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.hero {
    background: linear-gradient(135deg, #6C5B7B, #C06C84);
    height: 90vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    color: white;
    text-align: center;
    margin-left: 240px; 
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    animation: fadeIn 1.5s ease-in-out;
}
.hero h1 {
    font-size: 3em;
    margin-bottom: 20px;
    animation: bounce 2s infinite;
}
.hero p { font-size: 1.5em; margin-bottom: 30px; }
.btn-custom {
    padding: 15px 40px;
    border-radius: 50px;
    font-size: 1.2em;
    background-color: #355C7D;
    color: white;
    border: none;
    transition: 0.3s;
}
.btn-custom:hover {
    background-color: #6C5B7B;
    transform: scale(1.1);
}
@keyframes fadeIn {0% {opacity:0;} 100% {opacity:1;}}
@keyframes bounce {0%,100%{ transform: translateY(0);}50%{ transform: translateY(-10px);}}
</style>
</head>
<body>

<div class="hero">
    <h1>🎉 Welcome, <?= htmlspecialchars($username) ?>!</h1>
    <p>Manage your complaints smartly and efficiently with our system.</p>
    <a href="user_dashboard.php" class="btn btn-custom">Go to Dashboard</a>
</div>

</body>
</html>