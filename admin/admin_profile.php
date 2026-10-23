<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
$id = (int) $_SESSION['user_id'];
$dataStmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$dataStmt->bind_param('i', $id);
$dataStmt->execute();
$data = $dataStmt->get_result()->fetch_assoc();
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a name and a valid email address.';
        $messageType = 'danger';
    } elseif ($password !== '' && $currentPassword === '') {
        $message = 'Enter your current password before changing it.';
        $messageType = 'danger';
    } elseif ($password !== '' && !password_verify($currentPassword, (string) $data['password'])) {
        $message = 'Your current password is incorrect.';
        $messageType = 'danger';
    } elseif ($password !== '' && $password !== $confirmPassword) {
        $message = 'The new password and confirmation do not match.';
        $messageType = 'danger';
    } elseif ($password !== '' && !password_is_strong($password)) {
        $message = 'Use a 12-character password with uppercase, lowercase, number, and symbol.';
        $messageType = 'danger';
    } elseif ($password === '' && ($currentPassword !== '' || $confirmPassword !== '')) {
        $message = 'Leave all password fields blank or provide a new password and confirmation.';
        $messageType = 'danger';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $check->bind_param('si', $email, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = 'That email address is already in use.';
            $messageType = 'danger';
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, password = ? WHERE id = ?');
                $stmt->bind_param('sssi', $fullName, $email, $hash, $id);
            } else {
                $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
                $stmt->bind_param('ssi', $fullName, $email, $id);
            }
            if ($stmt->execute()) {
                $message = 'Profile updated.';
                $messageType = 'success';
                $_SESSION['full_name'] = $fullName;
                $data['full_name'] = $fullName;
                $data['email'] = $email;
                if ($password !== '') $data['password'] = $hash;
                record_audit($conn, 'profile_updated', 'user', $id);
            } else {
                $message = 'The profile could not be updated. Please try again.';
                $messageType = 'danger';
            }
        }
    }
}
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.content{padding:28px}.profile-card{max-width:680px;margin:auto;padding:26px;border-radius:20px;background:#fff;box-shadow:0 12px 32px rgba(0,0,0,.09)}.password-field{position:relative}.password-field .form-control{padding-right:3rem}.password-toggle{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#526276;padding:.35rem;display:grid;place-items:center}.password-toggle svg{width:19px;height:19px}.password-toggle:focus-visible{outline:2px solid #2563eb;border-radius:6px}.strength-bar{height:7px;border-radius:8px;margin-top:8px;transition:width .25s ease,background-color .25s ease}.tips{font-size:.82rem;margin-top:5px;color:#64748b}body.dark-mode .profile-card{background:#111!important;color:#fff!important}@media(max-width:760px){.content{padding:18px 12px}}
</style>
</head>
<body>
<main class="content">
<section class="profile-card">
<h1 class="h3 text-center mb-4">Admin profile</h1>
<?php if ($message !== ''): ?><div class="alert alert-<?= h($messageType) ?>" role="status"><?= h($message) ?></div><?php endif; ?>
<form method="post" autocomplete="on" id="profileForm">
<?= csrf_field() ?>
<div class="mb-3"><label class="form-label" for="full-name">Name</label><input id="full-name" type="text" name="full_name" class="form-control" value="<?= h($data['full_name'] ?: $data['username']) ?>" autocomplete="name" required></div>
<div class="mb-3"><label class="form-label" for="email">Email</label><input id="email" type="email" name="email" class="form-control" value="<?= h($data['email']) ?>" autocomplete="email" required></div>
<div id="profileState" class="small text-muted mb-3" aria-live="polite">Your profile is up to date.</div>
<hr><p class="fw-semibold mb-3">Change password <span class="text-muted fw-normal">(optional)</span></p>
<div class="mb-3"><label class="form-label" for="current-password">Current password</label><div class="password-field"><input id="current-password" type="password" name="current_password" class="form-control" autocomplete="current-password"><button type="button" class="password-toggle" data-password-toggle="current-password" aria-label="Show current password" aria-pressed="false"><svg data-eye-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg data-eye-off-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 3.2-.5"/></svg></button></div></div>
<div class="mb-3"><label class="form-label" for="password">New password</label><div class="password-field"><input type="password" id="password" name="password" class="form-control" minlength="12" autocomplete="new-password" placeholder="At least 12 characters"><button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show new password" aria-pressed="false"><svg data-eye-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg data-eye-off-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 3.2-.5"/></svg></button></div><div id="strengthBar" class="strength-bar bg-secondary" style="width:0"></div><div id="tips" class="tips" aria-live="polite">Use 12+ characters with uppercase, lowercase, number, and symbol.</div></div>
<div class="mb-4"><label class="form-label" for="confirm-password">Confirm new password</label><div class="password-field"><input type="password" id="confirm-password" name="confirm_password" class="form-control" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="confirm-password" aria-label="Show password confirmation" aria-pressed="false"><svg data-eye-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg data-eye-off-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 3.2-.5"/></svg></button></div><div id="confirmFeedback" class="tips" aria-live="polite"></div></div>
<div class="d-flex gap-2"><button type="reset" class="btn btn-outline-secondary flex-fill">Reset changes</button><button id="profileSubmit" type="submit" class="btn btn-primary flex-fill">Save profile</button></div>
</form>
</section>
</main>
<script>
const password = document.getElementById('password');
const confirmation = document.getElementById('confirm-password');
const bar = document.getElementById('strengthBar');
const tips = document.getElementById('tips');
const confirmFeedback = document.getElementById('confirmFeedback');
const profileForm = document.getElementById('profileForm');
const currentPassword = document.getElementById('current-password');
const emailField = document.getElementById('email');
const profileState = document.getElementById('profileState');
function updateProfileState() {
    const changed = Array.from(profileForm.querySelectorAll('input:not([type="hidden"]):not([type="password"])')).some((field) => field.value !== field.defaultValue)
        || currentPassword.value !== '' || password.value !== '' || confirmation.value !== '';
    profileState.textContent = changed ? 'Unsaved changes · save when you are ready.' : 'Your profile is up to date.';
    profileState.className = `small mb-3 ${changed ? 'text-primary' : 'text-muted'}`;
}
function checkPassword() {
    const value = password.value;
    if (!value) {
        bar.style.width = '0'; bar.className = 'strength-bar bg-secondary';
        tips.textContent = 'Use 12+ characters with uppercase, lowercase, number, and symbol.';
        password.setCustomValidity('');
    } else {
        const checks = [value.length >= 12, /[A-Z]/.test(value), /[a-z]/.test(value), /[0-9]/.test(value), /[^a-zA-Z0-9]/.test(value)];
        const score = checks.filter(Boolean).length;
        bar.style.width = `${score * 20}%`;
        bar.className = `strength-bar ${score < 3 ? 'bg-danger' : score < 5 ? 'bg-warning' : 'bg-success'}`;
        const missing = ['12+ characters', 'uppercase letter', 'lowercase letter', 'number', 'symbol'].filter((_, index) => !checks[index]);
        tips.textContent = missing.length ? `Add: ${missing.join(', ')}` : 'Strong password';
        password.setCustomValidity(score === 5 ? '' : 'Use 12+ characters with uppercase, lowercase, number, and symbol.');
    }
    currentPassword.required = value !== '';
    confirmation.required = value !== '';
    currentPassword.setCustomValidity(value !== '' && currentPassword.value === '' ? 'Enter your current password to change it.' : '');
    checkConfirmation();
    updateProfileState();
}
function checkConfirmation() {
    const missing = password.value !== '' && confirmation.value === '';
    const mismatch = confirmation.value !== '' && confirmation.value !== password.value;
    confirmation.setCustomValidity(missing ? 'Confirm your new password.' : mismatch ? 'The new passwords do not match.' : '');
    confirmFeedback.textContent = missing ? 'Confirm your new password.' : confirmation.value ? (mismatch ? 'Passwords do not match.' : 'Passwords match.') : '';
    confirmFeedback.className = `tips ${missing || mismatch ? 'text-danger' : confirmation.value ? 'text-success' : ''}`;
    updateProfileState();
}
password.addEventListener('input', checkPassword);
confirmation.addEventListener('input', checkConfirmation);
currentPassword.addEventListener('input', () => { currentPassword.setCustomValidity(password.value !== '' && currentPassword.value === '' ? 'Enter your current password to change it.' : ''); updateProfileState(); });
emailField.addEventListener('input', updateProfileState);
profileForm.addEventListener('input', updateProfileState);
profileForm.addEventListener('reset', () => setTimeout(() => { currentPassword.required = false; confirmation.required = false; checkPassword(); updateProfileState(); }, 0));
checkPassword();
</script>
</body>
</html>
