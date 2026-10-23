<?php
require_once __DIR__ . '/includes/app.php';

$error = '';
$notice = '';
if (isset($_GET['registered'])) $notice = 'Registration received. Sign in will be available after your department head approves your account.';
if (($_GET['approval'] ?? '') === 'pending') $notice = 'This student account is awaiting department approval.';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!csrf_valid($_POST['csrf_token'] ?? null)){
        $error = "Invalid CSRF token";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if(empty($username) || empty($password)){
            $error = "All fields are required";
        } else {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, password, role, status, department_id, faculty_id FROM users WHERE username=? OR email=? OR student_number=? LIMIT 1");
            $stmt->bind_param("sss", $username, $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result->num_rows === 1){
                $user = $result->fetch_assoc();
                if(password_verify($password, $user['password'])){
                    if ($user['status'] !== 'approved') {
                        $notice = $user['status'] === 'pending'
                            ? 'Your account is waiting for department approval.'
                            : 'This account is not approved. Contact your department head.';
                    } elseif ($user['role'] !== 'admin' && !system_is_open()) {
                        $error = 'The system is temporarily closed. Please try again later.';
                    } else {
                    session_regenerate_id(true);
                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department_id'] = $user['department_id'];
                    $_SESSION['faculty_id'] = $user['faculty_id'];
                    record_audit($conn, 'login', 'user', (int) $user['id']);
                    if ($user['role'] === 'student' && ($_GET['redirect'] ?? '') === 'track') {
                        $trackQuery = trim((string) ($_GET['ref'] ?? ''));
                        header('Location: user/user_complaints.php' . ($trackQuery !== '' ? '?search=' . rawurlencode($trackQuery) : ''));
                        exit();
                    }
                    redirect_for_role($user['role']);
                    }
                } else {
                    $error = "Invalid username or password";
                }
            } else {
                $error = "Invalid username or password";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/style.css?v=20261015.4">
    <script src="public/assets/js/script.js?v=20261015.4" defer></script>
</head>
<body class="auth-page login-page">

<button type="button" class="theme-toggle position-fixed top-0 end-0 m-3 px-3 py-2" data-theme-toggle aria-pressed="false">Dark mode</button>

<main class="login-box auth-card">
    <span class="auth-eyebrow">STUDENT COMPLAINT PORTAL</span>
    <h1>Welcome back</h1>
    <p class="auth-intro">Sign in to follow your complaints and updates.</p>

    <?php if($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if($notice): ?>
        <div class="alert alert-info" role="status"><?= h($notice) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label for="login-identifier">Username, student ID, or email</label>
            <input id="login-identifier" type="text" name="username" class="form-control" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>

        <div class="mb-3">
            <label for="login-password">Password</label>
            <div class="auth-password-field"><input id="login-password" type="password" name="password" class="form-control" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="login-password" aria-label="Show password" aria-pressed="false">Show</button></div>
        </div>

        <label class="auth-remember"><input type="checkbox" id="remember-identifier"> Remember this sign-in name on this device</label>
        <button type="submit" name="login" class="btn btn-primary btn-custom auth-submit">Sign in</button>
    </form>

    <p class="mt-3">
        Don't have an account? <a href="register.php">Register here</a>
    </p>
</main>

</body>
</html>
