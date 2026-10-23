<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);

$user_id = $_SESSION['user_id'];
include __DIR__ . '/user_sidebar.php'; // Sidebar include fixed

$message = '';

// FETCH USER DATA
$userStmt = $conn->prepare('SELECT * FROM users WHERE id=?');
$userStmt->bind_param('i', $user_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();

// HANDLE UPDATE
if($_SERVER['REQUEST_METHOD']=='POST'){
    post_csrf_or_exit();
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $duplicateStmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
    $duplicateStmt->bind_param('ssi', $username, $email, $user_id);
    $duplicateStmt->execute();
    if ($duplicateStmt->get_result()->num_rows > 0) {
        $message = 'That username or email is already in use.';
    } elseif(!empty($password)){
        if(!password_is_strong($password)){
            $message = 'Use a 12-character password with uppercase, lowercase, number, and symbol.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, password=? WHERE id=?");
            $stmt->bind_param("sssi",$username,$email,$hashed,$user_id);
        }
    } else {
        $stmt = $conn->prepare("UPDATE users SET username=?, email=? WHERE id=?");
        $stmt->bind_param("ssi",$username,$email,$user_id);
    }

    if(!isset($message) || empty($message)){
        if($stmt->execute()){
            $_SESSION['username'] = $username;
            record_audit($conn, 'profile_updated', 'user', $user_id);
            $message = "✅ Profile updated successfully";
            $user['username'] = $username;
            $user['email'] = $email;
        } else {
            $message = "❌ Error: ".$stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>User Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* Content & card */
.content { margin-left:240px; padding:20px; min-height:100vh; background:#f4f6f9; }
.card { max-width:600px; margin:auto; padding:25px; border-radius:20px; box-shadow:0 8px 20px rgba(0,0,0,0.15); background:white; }

/* Headings */
h3 { text-align:center; margin-bottom:25px; color:#34495e; }

/* Password strength bar */
.strength-bar { height:8px; border-radius:10px; margin-top:5px; transition:0.3s; }
.tips { font-size:13px; margin-top:5px; color:#555; }

/* Buttons */
.btn-custom { border-radius:50px; transition:0.3s; width:100%; }
.btn-custom:hover { transform:scale(1.05); }

/* Dark Mode */
body.dark-mode { background:#1c1c1c; color:#f4f6f8; }
body.dark-mode .card { background:#222; box-shadow:0 8px 20px rgba(0,0,0,0.5); }
body.dark-mode input { background:#333; color:white; border-color:#555; }
body.dark-mode h3 { color:#f4f6f8; }

/* Toggle Button */
</style>
</head>
<body>

<div class="content">
    <div class="card">
        <h3>👤 My Profile</h3>

        <?php if($message) echo "<div class='alert alert-info'>$message</div>"; ?>

        <form method="post">
                    <?= csrf_field() ?>
            <div class="mb-3">
                <label>Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
            <div class="mb-3">
                <label>Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="password">New password (optional)</label>
                <div class="auth-password-field"><input type="password" id="password" name="password" class="form-control" minlength="12" autocomplete="new-password" placeholder="Leave blank to keep current"><button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false">Show</button></div>
                <div id="strengthBar" class="strength-bar bg-secondary"></div>
                <div class="password-guidance">If changing it, use 12 or more characters with uppercase, lowercase, a number, and a symbol.</div>
                <div id="tips" class="tips" aria-live="polite"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-custom">Update Profile</button>
        </form>
    </div>
</div>

<script>
const password = document.getElementById('password');
const bar = document.getElementById('strengthBar');
const tips = document.getElementById('tips');
password.addEventListener('input', () => {
    if (!password.value) {
        bar.style.width = '0';
        tips.textContent = '';
        password.setCustomValidity('');
        return;
    }
    const checks = [password.value.length >= 12, /[A-Z]/.test(password.value), /[a-z]/.test(password.value), /[0-9]/.test(password.value), /[^a-zA-Z0-9]/.test(password.value)];
    const passed = checks.filter(Boolean).length;
    bar.style.width = `${passed * 20}%`;
    tips.textContent = passed === 5 ? 'Strong password' : `${passed} of 5 password requirements met`;
    password.setCustomValidity(passed === 5 ? '' : 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.');
});
</script>

</body>
</html>
