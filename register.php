<?php
require_once __DIR__ . '/includes/app.php';

$error = '';
$registrationOpen = system_is_open();
$departments = $conn->query("SELECT d.id, d.name, f.category, f.name AS faculty_name FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE d.is_active = 1 AND f.is_active = 1 ORDER BY f.category, f.name, d.name")->fetch_all(MYSQLI_ASSOC);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$registrationOpen) {
        $error = 'Registration is temporarily closed.';
    } elseif (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'This form expired. Refresh the page and try again.';
    }

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $studentNumber = trim($_POST['student_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $password = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    // VALIDATION
    if($error === '' && (empty($username) || empty($fullName) || empty($studentNumber) || empty($email) || empty($departmentId) || empty($password) || empty($confirm_password))){
        $error = "All fields are required.";
    } elseif($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)){
        $error = "Invalid email format!";
    } elseif($error === '' && $password !== $confirm_password){
        $error = "Passwords do not match.";
    } elseif($error === '' && !password_is_strong($password)){
        $error = "Use at least 12 characters with uppercase, lowercase, number, and special character.";
    } elseif($error === '') {
        $departmentCheck = $conn->prepare('SELECT d.id FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE d.id = ? AND d.is_active = 1 AND f.is_active = 1 LIMIT 1');
        $departmentCheck->bind_param('i', $departmentId);
        $departmentCheck->execute();
        if (!$departmentCheck->get_result()->fetch_row()) $error = 'Choose an active department.';
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? OR student_number=? LIMIT 1");
        $stmt->bind_param("sss", $username, $email, $studentNumber);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows > 0){
            $error = "Username, student ID, or email already exists.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, student_number, email, password, role, status, department_id) VALUES (?, ?, ?, ?, ?, 'student', 'pending', ?)");
            $stmt->bind_param("sssssi", $username, $fullName, $studentNumber, $email, $hashed_password, $departmentId);

            if($stmt->execute()){
                $studentId = $stmt->insert_id;
                record_audit($conn, 'student_registration_submitted', 'user', $studentId, ['department_id' => $departmentId]);
                header("Location: login.php?registered=1");
                exit();
            } else {
                $error = "Registration failed. Try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/style.css?v=20261015.4">
    <script src="public/assets/js/script.js?v=20261015.4" defer></script>
</head>
<body class="auth-page register-page">

<button type="button" class="theme-toggle position-fixed top-0 end-0 m-3 px-3 py-2" data-theme-toggle aria-pressed="false">Dark mode</button>

<main class="register-box auth-card">
    <span class="auth-eyebrow">STUDENT COMPLAINT PORTAL</span>
    <h1>Create your account</h1>
    <p class="auth-intro">Add your details to start tracking complaints and responses.</p>
    <?php if($error): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if(!$registrationOpen): ?><div class="alert alert-info" role="status">Student registration is temporarily closed.</div><?php endif; ?>

    <form method="post" id="registration-form">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="register-username">Username</label>
            <input type="text" id="register-username" name="username" class="form-control" value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            <small class="form-text" id="username-hint">You can change this suggestion.</small>
        </div>

        <div class="mb-3">
            <label for="register-full-name">Full name</label>
            <input type="text" id="register-full-name" name="full_name" class="form-control" value="<?= h($_POST['full_name'] ?? '') ?>" autocomplete="name" required>
        </div>

        <div class="mb-3">
            <label for="register-student-number">Student ID</label>
            <input type="text" id="register-student-number" name="student_number" class="form-control" value="<?= h($_POST['student_number'] ?? '') ?>" autocomplete="off" required>
        </div>

        <div class="mb-3">
            <label for="register-email">Email</label>
            <input type="email" id="register-email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>" autocomplete="email" inputmode="email" required>
        </div>

        <div class="mb-3 auth-full-row">
            <label for="register-department">Department</label>
            <select id="register-department" name="department_id" class="form-control" autocomplete="organization-title" required>
                <option value="">Choose your department</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= (string) ($_POST['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= h($department['category'] . ' · ' . $department['faculty_name'] . ' · ' . $department['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$departments): ?><small>No departments are open for registration yet.</small><?php endif; ?>
        </div>

        <div class="mb-3 password-wrapper auth-full-row">
            <label for="password">Password</label>
            <div class="auth-password-field"><input type="password" id="password" name="password" class="form-control" minlength="12" autocomplete="new-password" aria-describedby="password-guidance password-strength" required><button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false">Show</button></div>
            <div class="password-meter" aria-hidden="true"><span id="strengthBar"></span></div>
            <div id="password-guidance" class="password-guidance">Use 12 or more characters, including uppercase, lowercase, a number, and a symbol.</div>
            <div id="password-strength" class="tips" aria-live="polite"></div>
        </div>

        <div class="mb-3 password-wrapper auth-full-row">
            <label for="confirm-password">Confirm password</label>
            <div class="auth-password-field"><input type="password" id="confirm-password" name="confirm_password" class="form-control" minlength="12" autocomplete="new-password" aria-describedby="password-match" required><button type="button" class="password-toggle" data-password-toggle="confirm-password" aria-label="Show password" aria-pressed="false">Show</button></div>
            <div id="password-match" class="tips" aria-live="polite"></div>
        </div>

        <button type="submit" name="register" class="btn btn-primary btn-custom auth-submit auth-full-row mt-2" <?= !$registrationOpen ? 'disabled' : '' ?>>Create account</button>
    </form>

    <p class="mt-3 text-center">
        Already have an account? <a href="login.php">Login</a>
    </p>
</main>

<script>
const password = document.getElementById('password');
const bar = document.getElementById('strengthBar');
const strengthText = document.getElementById('password-strength');
const confirmation = document.getElementById('confirm-password');
const matchText = document.getElementById('password-match');
const fullName = document.getElementById('register-full-name');
const username = document.getElementById('register-username');
const usernameHint = document.getElementById('username-hint');
let lastSuggestedUsername = '';

function updatePasswordMatch() {
    if (!confirmation.value) {
        matchText.textContent = '';
        confirmation.setCustomValidity('');
    } else if (confirmation.value === password.value) {
        matchText.textContent = 'Passwords match';
        matchText.dataset.state = 'valid';
        confirmation.setCustomValidity('');
    } else {
        matchText.textContent = 'Passwords do not match yet';
        matchText.dataset.state = 'invalid';
        confirmation.setCustomValidity('Passwords must match.');
    }
}

function updatePasswordGuidance() {
    const value = password.value;
    const checks = [value.length >= 12, /[A-Z]/.test(value), /[a-z]/.test(value), /[0-9]/.test(value), /[^a-zA-Z0-9]/.test(value)];
    const passed = checks.filter(Boolean).length;
    bar.style.width = `${passed * 20}%`;
    bar.dataset.strength = passed === 5 ? 'strong' : passed >= 3 ? 'medium' : 'weak';
    strengthText.textContent = value ? (passed === 5 ? 'Strong password' : `${passed} of 5 requirements met`) : '';
    password.setCustomValidity(passed === 5 || !value ? '' : 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.');
    updatePasswordMatch();
}

password.addEventListener('input', updatePasswordGuidance);
confirmation.addEventListener('input', updatePasswordMatch);
fullName.addEventListener('input', () => {
    const current = username.value.trim();
    if (current && current !== lastSuggestedUsername) return;
    const suggestion = fullName.value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '').slice(0, 30);
    username.value = suggestion;
    lastSuggestedUsername = suggestion;
    usernameHint.textContent = suggestion ? `Suggested username: ${suggestion} · you can edit it` : 'You can change this suggestion.';
});

document.getElementById('registration-form').addEventListener('submit', event => {
    updatePasswordGuidance();
    updatePasswordMatch();
    if (!event.currentTarget.reportValidity()) event.preventDefault();
});
</script>

</body>
</html>
