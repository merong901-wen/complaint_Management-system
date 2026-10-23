<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['faculty_director']);
$facultyId = (int) $_SESSION['faculty_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_department') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($name === '') throw new RuntimeException('Department name is required.');
            $stmt = $conn->prepare('INSERT INTO departments (name, description, faculty_id, created_by) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssii', $name, $description, $facultyId, $_SESSION['user_id']);
            $stmt->execute();
            record_audit($conn, 'department_created', 'department', $stmt->insert_id, ['faculty_id' => $facultyId, 'name' => $name]);
            $message = 'Department registered.';
        } elseif ($action === 'update_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($name === '') throw new RuntimeException('Department name is required.');
            $departmentCheck = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ? LIMIT 1');
            $departmentCheck->bind_param('ii', $departmentId, $facultyId);
            $departmentCheck->execute();
            if (!$departmentCheck->get_result()->fetch_row()) throw new RuntimeException('That department is not in your faculty.');
            $stmt = $conn->prepare('UPDATE departments SET name = ?, description = ? WHERE id = ? AND faculty_id = ?');
            $stmt->bind_param('ssii', $name, $description, $departmentId, $facultyId);
            $stmt->execute();
            record_audit($conn, 'department_updated', 'department', $departmentId, ['faculty_id' => $facultyId, 'name' => $name]);
            $message = 'Department details updated.';
        } elseif ($action === 'delete_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $departmentCheck = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ? LIMIT 1');
            $departmentCheck->bind_param('ii', $departmentId, $facultyId);
            $departmentCheck->execute();
            if (!$departmentCheck->get_result()->fetch_row()) throw new RuntimeException('That department is not in your faculty.');
            $usageCheck = $conn->prepare('SELECT (SELECT COUNT(*) FROM users WHERE department_id = ?) AS members, (SELECT COUNT(*) FROM complaints WHERE department_id = ?) AS complaints');
            $usageCheck->bind_param('ii', $departmentId, $departmentId);
            $usageCheck->execute();
            $usage = $usageCheck->get_result()->fetch_assoc();
            if ((int) $usage['members'] > 0 || (int) $usage['complaints'] > 0) {
                throw new RuntimeException('This department has accounts or complaint history and cannot be deleted. Close it instead.');
            }
            $stmt = $conn->prepare('DELETE FROM departments WHERE id = ? AND faculty_id = ?');
            $stmt->bind_param('ii', $departmentId, $facultyId);
            $stmt->execute();
            record_audit($conn, 'department_deleted', 'department', $departmentId, ['faculty_id' => $facultyId]);
            $message = 'Department deleted.';
        } elseif ($action === 'adopt_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $stmt = $conn->prepare('UPDATE departments SET faculty_id = ? WHERE id = ? AND faculty_id IS NULL');
            $stmt->bind_param('ii', $facultyId, $departmentId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('That legacy department has already been assigned. Refresh and try again.');
            record_audit($conn, 'legacy_department_assigned', 'department', $departmentId, ['faculty_id' => $facultyId]);
            $message = 'Existing department assigned to this faculty.';
        } elseif ($action === 'toggle_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $conn->prepare('UPDATE departments SET is_active = ? WHERE id = ? AND faculty_id = ?');
            $stmt->bind_param('iii', $isActive, $departmentId, $facultyId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Department state was not changed. Refresh and try again.');
            record_audit($conn, 'department_status_changed', 'department', $departmentId, ['faculty_id' => $facultyId, 'is_active' => $isActive]);
            $message = 'Department state updated.';
        } elseif ($action === 'create_head') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $departmentId < 1) {
                throw new RuntimeException('Enter a name, username, valid email, and department.');
            }
            if (!password_is_strong($password)) throw new RuntimeException('Use a 12-character password with uppercase, lowercase, number, and symbol.');
            $departmentCheck = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ? AND is_active = 1 LIMIT 1');
            $departmentCheck->bind_param('ii', $departmentId, $facultyId);
            $departmentCheck->execute();
            if (!$departmentCheck->get_result()->fetch_row()) throw new RuntimeException('Choose an open department in your faculty.');
            $duplicateCheck = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $duplicateCheck->bind_param('ss', $username, $email);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->fetch_row()) throw new RuntimeException('That username or email is already in use.');
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, department_id, created_by) VALUES (?, ?, ?, ?, 'department_head', 'approved', ?, ?)");
            $stmt->bind_param('ssssii', $username, $fullName, $email, $passwordHash, $departmentId, $_SESSION['user_id']);
            $stmt->execute();
            record_audit($conn, 'department_head_created', 'user', $stmt->insert_id, ['department_id' => $departmentId, 'faculty_id' => $facultyId]);
            $message = 'Department head registered.';
        } elseif ($action === 'update_head') {
            $headId = (int) ($_POST['head_id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $departmentId < 1) {
                throw new RuntimeException('Enter a name, username, valid email, and department.');
            }
            if ($password !== '' && !password_is_strong($password)) throw new RuntimeException('A new password must have 12 characters with uppercase, lowercase, number, and symbol.');
            $scopeCheck = $conn->prepare("SELECT u.id FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.role = 'department_head' AND d.faculty_id = ? LIMIT 1");
            $scopeCheck->bind_param('ii', $headId, $facultyId);
            $scopeCheck->execute();
            if (!$scopeCheck->get_result()->fetch_row()) throw new RuntimeException('That account is not a department head in your faculty.');
            $departmentCheck = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ? LIMIT 1');
            $departmentCheck->bind_param('ii', $departmentId, $facultyId);
            $departmentCheck->execute();
            if (!$departmentCheck->get_result()->fetch_row()) throw new RuntimeException('Choose a department in your faculty.');
            $duplicateCheck = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
            $duplicateCheck->bind_param('ssi', $username, $email, $headId);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->fetch_row()) throw new RuntimeException('That username or email is already in use.');
            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, department_id = ?, password = ? WHERE id = ? AND role = 'department_head'");
                $update->bind_param('sssisi', $fullName, $username, $email, $departmentId, $passwordHash, $headId);
            } else {
                $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, department_id = ? WHERE id = ? AND role = 'department_head'");
                $update->bind_param('sssii', $fullName, $username, $email, $departmentId, $headId);
            }
            $update->execute();
            record_audit($conn, 'department_head_updated', 'user', $headId, ['department_id' => $departmentId, 'faculty_id' => $facultyId]);
            $message = 'Department head details updated.';
        } elseif ($action === 'delete_head') {
            $headId = (int) ($_POST['head_id'] ?? 0);
            $scopeCheck = $conn->prepare("SELECT u.id, u.full_name, u.username FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.role = 'department_head' AND d.faculty_id = ? LIMIT 1");
            $scopeCheck->bind_param('ii', $headId, $facultyId);
            $scopeCheck->execute();
            $headAccount = $scopeCheck->get_result()->fetch_assoc();
            if (!$headAccount) throw new RuntimeException('That account is not a department head in your faculty.');
            $usageCheck = $conn->prepare('SELECT (SELECT COUNT(*) FROM comments WHERE user_id = ?) + (SELECT COUNT(*) FROM complaints WHERE user_id = ? OR teacher_id = ? OR resolution_by = ?) + (SELECT COUNT(*) FROM notifications WHERE user_id = ?) + (SELECT COUNT(*) FROM department_head_messages WHERE sender_id = ?) + (SELECT COUNT(*) FROM faculty_director_messages WHERE sender_id = ?) + (SELECT COUNT(*) FROM audit_logs WHERE user_id = ? OR acting_as_user_id = ?) + (SELECT COUNT(*) FROM users WHERE created_by = ?) + (SELECT COUNT(*) FROM department_head_conversations WHERE student_id = ?) AS history_count');
            $usageCheck->bind_param('iiiiiiiiiii', $headId, $headId, $headId, $headId, $headId, $headId, $headId, $headId, $headId, $headId, $headId);
            $usageCheck->execute();
            $hasHistory = (int) $usageCheck->get_result()->fetch_assoc()['history_count'] > 0;
            if ($hasHistory) {
                $delete = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'department_head'");
                $delete->bind_param('i', $headId);
                $delete->execute();
                record_audit($conn, 'department_head_deactivated', 'user', $headId, ['faculty_id' => $facultyId, 'reason' => 'Account history retained']);
                $message = 'This account has linked records, so it was deactivated and its history was preserved.';
            } else {
                $delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'department_head'");
                $delete->bind_param('i', $headId);
                $delete->execute();
                record_audit($conn, 'department_head_deleted', 'user', $headId, ['faculty_id' => $facultyId, 'username' => $headAccount['username']]);
                $message = 'Department head account deleted.';
            }
        } else {
            throw new RuntimeException('Choose a valid registration action.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The change could not be saved. Check for duplicate department names, usernames, or emails.';
        error_log('Faculty director registration action failed: ' . $exception->getMessage());
    }
}

$departmentTotalResult = $conn->prepare('SELECT COUNT(*) AS total FROM departments WHERE faculty_id = ?');
$departmentTotalResult->bind_param('i', $facultyId);
$departmentTotalResult->execute();
$departmentPagination = pagination_data((int) $departmentTotalResult->get_result()->fetch_assoc()['total'], (int) ($_GET['departments_page'] ?? 1));
$departmentsStmt = $conn->prepare("SELECT d.id, d.name, d.description, d.is_active, COUNT(DISTINCT CASE WHEN u.role = 'department_head' THEN u.id END) AS heads FROM departments d LEFT JOIN users u ON u.department_id = d.id WHERE d.faculty_id = ? GROUP BY d.id ORDER BY d.name LIMIT ? OFFSET ?");
$departmentsStmt->bind_param('iii', $facultyId, $departmentPagination['per_page'], $departmentPagination['offset']);
$departmentsStmt->execute();
$departments = $departmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$headTotalResult = $conn->prepare("SELECT COUNT(*) AS total FROM users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'department_head'");
$headTotalResult->bind_param('i', $facultyId);
$headTotalResult->execute();
$headPagination = pagination_data((int) $headTotalResult->get_result()->fetch_assoc()['total'], (int) ($_GET['heads_page'] ?? 1));
$headsStmt = $conn->prepare("SELECT u.id, u.full_name, u.username, u.email, u.status, u.department_id, d.name AS department_name FROM users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'department_head' ORDER BY d.name, u.full_name LIMIT ? OFFSET ?");
$headsStmt->bind_param('iii', $facultyId, $headPagination['per_page'], $headPagination['offset']);
$headsStmt->execute();
$heads = $headsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$openDepartments = $conn->prepare('SELECT id, name FROM departments WHERE faculty_id = ? AND is_active = 1 ORDER BY name');
$openDepartments->bind_param('i', $facultyId);
$openDepartments->execute();
$openDepartments = $openDepartments->get_result()->fetch_all(MYSQLI_ASSOC);
$departmentOptions = $conn->prepare('SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name');
$departmentOptions->bind_param('i', $facultyId);
$departmentOptions->execute();
$departmentOptions = $departmentOptions->get_result()->fetch_all(MYSQLI_ASSOC);
$legacyDepartments = $conn->query('SELECT id, name FROM departments WHERE faculty_id IS NULL ORDER BY name')->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty registrations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.page-heading{margin-bottom:20px}.registration-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:20px}.registration-panel,.data-panel{padding:20px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.registration-panel h2,.data-panel h2{font-size:18px}.registration-panel p{color:var(--system-muted);font-size:13px}.data-panel{margin-bottom:18px}.table-responsive{overflow-x:auto}.legacy-list{display:flex;flex-wrap:wrap;gap:8px}.legacy-item{display:flex;align-items:center;gap:12px;padding:8px 10px;border:1px solid var(--system-border);border-radius:5px;font-size:12px}.row-actions{display:flex;flex-wrap:wrap;gap:6px}.password-field{position:relative;margin-bottom:1rem}.password-field .form-control{padding-right:48px}.password-visibility{position:absolute;right:7px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:34px;height:34px;border:0;border-radius:6px;background:transparent;color:var(--system-muted);cursor:pointer}.password-visibility:hover{background:var(--system-border);color:var(--system-text)}.password-visibility:focus-visible{outline:2px solid var(--system-blue);outline-offset:2px}.password-visibility svg{width:19px;height:19px}.registration-dialog{width:min(560px,calc(100% - 24px));max-height:90vh;overflow:auto;border:1px solid var(--system-border);border-radius:14px;padding:22px;background:var(--system-surface);color:var(--system-text,#172033);box-shadow:0 24px 80px #0005}.registration-dialog::backdrop{background:#0b1220a6;backdrop-filter:blur(3px)}.dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}.dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}.pagination-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.pagination-total{font-size:13px;color:var(--system-muted)}@media(max-width:850px){.registration-grid{grid-template-columns:1fr}.content{padding:20px 14px}}
    </style>
</head>
<body>
<main class="content">
    <header class="page-heading"><h1 class="h3 mb-1">Register departments and department heads</h1><p class="mb-0 text-muted">All registrations are limited to your faculty.</p></header>
    <?php if ($message): ?><div class="alert alert-success" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <section class="registration-grid" aria-label="Faculty registration actions">
        <form class="registration-panel" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_department">
            <h2>Register department</h2><p>Create a department under your assigned faculty.</p>
            <label class="form-label" for="department-name">Department name</label><input class="form-control mb-3" id="department-name" name="name" maxlength="120" required>
            <label class="form-label" for="department-description">Description</label><textarea class="form-control mb-3" id="department-description" name="description" rows="2"></textarea>
            <button class="btn btn-primary" type="submit">Create department</button>
        </form>
        <form class="registration-panel" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_head">
            <h2>Register department head</h2><p>Department heads can register teachers and approve student registrations.</p>
            <label class="form-label" for="head-department">Department</label><select class="form-select mb-3" id="head-department" name="department_id" required><option value="">Choose an open department</option><?php foreach ($openDepartments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= h($department['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label" for="head-name">Full name</label><input class="form-control mb-3" id="head-name" name="full_name" autocomplete="name" required>
            <label class="form-label" for="head-username">Username</label><input class="form-control mb-3" id="head-username" name="username" autocomplete="username" required>
            <label class="form-label" for="head-email">Email</label><input class="form-control mb-3" type="email" id="head-email" name="email" autocomplete="email" required>
            <label class="form-label" for="head-password">Temporary password</label><div class="password-field"><input class="form-control" type="password" id="head-password" name="password" minlength="12" autocomplete="new-password" required><button class="password-visibility" type="button" data-password-toggle="head-password" aria-label="Show temporary password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
            <button class="btn btn-primary" type="submit" <?= !$openDepartments ? 'disabled' : '' ?>>Create head account</button>
        </form>
    </section>
    <?php if ($legacyDepartments): ?><section class="data-panel"><h2>Unassigned existing departments</h2><p class="text-muted">Assign a legacy department to this faculty to bring its existing department accounts under the new structure.</p><div class="legacy-list"><?php foreach ($legacyDepartments as $legacy): ?><form class="legacy-item" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="adopt_department"><input type="hidden" name="department_id" value="<?= (int) $legacy['id'] ?>"><span><?= h($legacy['name']) ?></span><button class="btn btn-sm btn-outline-primary" type="submit">Assign here</button></form><?php endforeach; ?></div></section><?php endif; ?>
    <section class="data-panel"><h2>Departments</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Name</th><th>Department heads</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($departments as $department): ?><tr><td><strong><?= h($department['name']) ?></strong><small class="d-block text-muted"><?= h($department['description']) ?></small></td><td><?= (int) $department['heads'] ?></td><td><?= $department['is_active'] ? 'Open' : 'Closed' ?></td><td><div class="row-actions"><button class="btn btn-sm btn-outline-secondary" type="button" data-open-dialog="department-view-<?= (int) $department['id'] ?>">View</button><button class="btn btn-sm btn-outline-primary" type="button" data-open-dialog="department-edit-<?= (int) $department['id'] ?>">Edit</button><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_department"><input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>"><input type="hidden" name="is_active" value="<?= $department['is_active'] ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-primary" type="submit"><?= $department['is_active'] ? 'Close' : 'Open' ?></button></form></div>
    <dialog class="registration-dialog" id="department-view-<?= (int) $department['id'] ?>"><div class="dialog-head"><h2 class="h5 mb-0">Department details</h2><button class="btn-close" type="button" aria-label="Close" data-close-dialog></button></div><dl class="row mb-0"><dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= h($department['name']) ?></dd><dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(h($department['description'] ?: 'No description provided.')) ?></dd><dt class="col-sm-4">Department heads</dt><dd class="col-sm-8"><?= (int) $department['heads'] ?></dd><dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= $department['is_active'] ? 'Open' : 'Closed' ?></dd></dl><div class="dialog-actions"><button class="btn btn-secondary" type="button" data-close-dialog>Close</button></div></dialog>
    <dialog class="registration-dialog" id="department-edit-<?= (int) $department['id'] ?>"><div class="dialog-head"><h2 class="h5 mb-0">Edit department</h2><button class="btn-close" type="button" aria-label="Close" data-close-dialog></button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_department"><input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>"><label class="form-label">Department name</label><input class="form-control mb-3" name="name" maxlength="120" value="<?= h($department['name']) ?>" required><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"><?= h($department['description']) ?></textarea><div class="dialog-actions"><button class="btn btn-secondary" type="button" data-close-dialog>Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div></form></dialog>
    </td></tr><?php endforeach; ?><?php if (!$departments): ?><tr><td colspan="4">No departments have been registered in this faculty.</td></tr><?php endif; ?></tbody></table></div><?= pagination_links($departmentPagination, 'departments_page') ?></section>
    <section class="data-panel"><h2>Department heads</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Name</th><th>Email</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($heads as $head): ?><tr><td><?= h($head['full_name'] ?: $head['username']) ?><small class="d-block text-muted">@<?= h($head['username']) ?></small></td><td><?= h($head['email']) ?></td><td><?= h($head['department_name']) ?></td><td><?= h(ucfirst($head['status'])) ?></td><td><div class="row-actions"><button class="btn btn-sm btn-outline-secondary" type="button" data-open-dialog="head-view-<?= (int) $head['id'] ?>">View</button><button class="btn btn-sm btn-outline-primary" type="button" data-open-dialog="head-edit-<?= (int) $head['id'] ?>">Edit</button><form method="post" onsubmit="return confirm('Delete this department head account? Accounts with linked history will be deactivated to preserve those records.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_head"><input type="hidden" name="head_id" value="<?= (int) $head['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form></div>
    <dialog class="registration-dialog" id="head-view-<?= (int) $head['id'] ?>"><div class="dialog-head"><h2 class="h5 mb-0">Department head details</h2><button class="btn-close" type="button" aria-label="Close" data-close-dialog></button></div><dl class="row mb-0"><dt class="col-sm-4">Full name</dt><dd class="col-sm-8"><?= h($head['full_name'] ?: $head['username']) ?></dd><dt class="col-sm-4">Username</dt><dd class="col-sm-8">@<?= h($head['username']) ?></dd><dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= h($head['email']) ?></dd><dt class="col-sm-4">Department</dt><dd class="col-sm-8"><?= h($head['department_name']) ?></dd><dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= h(ucfirst($head['status'])) ?></dd></dl><div class="dialog-actions"><button class="btn btn-secondary" type="button" data-close-dialog>Close</button></div></dialog>
    <dialog class="registration-dialog" id="head-edit-<?= (int) $head['id'] ?>"><div class="dialog-head"><h2 class="h5 mb-0">Edit department head</h2><button class="btn-close" type="button" aria-label="Close" data-close-dialog></button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_head"><input type="hidden" name="head_id" value="<?= (int) $head['id'] ?>"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" value="<?= h($head['full_name']) ?>" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" value="<?= h($head['username']) ?>" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" value="<?= h($head['email']) ?>" required><label class="form-label">Department</label><select class="form-select mb-3" name="department_id" required><?php foreach ($departmentOptions as $departmentOption): ?><option value="<?= (int) $departmentOption['id'] ?>" <?= (int) $head['department_id'] === (int) $departmentOption['id'] ? 'selected' : '' ?>><?= h($departmentOption['name']) ?></option><?php endforeach; ?></select><label class="form-label">New password <span class="text-muted">(leave blank to keep current)</span></label><input class="form-control" type="password" name="password" minlength="12" autocomplete="new-password"><div class="dialog-actions"><button class="btn btn-secondary" type="button" data-close-dialog>Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div></form></dialog>
    </td></tr><?php endforeach; ?><?php if (!$heads): ?><tr><td colspan="5">No department heads have been registered yet.</td></tr><?php endif; ?></tbody></table></div><?= pagination_links($headPagination, 'heads_page') ?></section>
</main>
<script>
document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
document.querySelectorAll('dialog.registration-dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }));
document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} temporary password`);
    button.setAttribute('aria-pressed', String(reveal));
    button.innerHTML = reveal
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0112 5c6.4 0 10 7 10 7a14 14 0 01-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 004.1-.9"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
}));
</script>
</body>
</html>
