<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

$message = '';
$error = '';
$transactionActive = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_faculty') {
            $category = (string) ($_POST['category'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!in_array($category, ['Social Science', 'Natural Science'], true) || $name === '') {
                throw new RuntimeException('Choose a science category and enter a faculty name.');
            }
            $stmt = $conn->prepare('INSERT INTO faculties (category, name, created_by) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $category, $name, $_SESSION['user_id']);
            $stmt->execute();
            record_audit($conn, 'faculty_created', 'faculty', $stmt->insert_id, ['category' => $category, 'name' => $name]);
            $message = 'Faculty created. Assign a faculty director to open department registration.';
        } elseif ($action === 'update_faculty') {
            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $category = (string) ($_POST['category'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($facultyId < 1 || !in_array($category, ['Social Science', 'Natural Science'], true) || $name === '') {
                throw new RuntimeException('Choose a science category and enter a faculty name.');
            }
            $existsStmt = $conn->prepare('SELECT id FROM faculties WHERE id = ? LIMIT 1');
            $existsStmt->bind_param('i', $facultyId);
            $existsStmt->execute();
            if (!$existsStmt->get_result()->fetch_row()) throw new RuntimeException('That faculty no longer exists.');
            $stmt = $conn->prepare('UPDATE faculties SET category = ?, name = ? WHERE id = ?');
            $stmt->bind_param('ssi', $category, $name, $facultyId);
            $stmt->execute();
            record_audit($conn, 'faculty_updated', 'faculty', $facultyId, ['category' => $category, 'name' => $name]);
            $message = 'Faculty details updated.';
        } elseif ($action === 'toggle_faculty') {
            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? -1);
            if ($facultyId < 1 || !in_array($isActive, [0, 1], true)) throw new RuntimeException('Choose a valid faculty state.');
            $stmt = $conn->prepare('UPDATE faculties SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $isActive, $facultyId);
            $stmt->execute();
            record_audit($conn, $isActive ? 'faculty_activated' : 'faculty_deactivated', 'faculty', $facultyId);
            $message = $isActive ? 'Faculty is active.' : 'Faculty is inactive.';
        } elseif ($action === 'delete_faculty') {
            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            if ($facultyId < 1) throw new RuntimeException('Choose a valid faculty to delete.');
            $conn->begin_transaction();
            $transactionActive = true;
            $facultyCheck = $conn->prepare('SELECT id, name FROM faculties WHERE id = ? FOR UPDATE');
            $facultyCheck->bind_param('i', $facultyId);
            $facultyCheck->execute();
            $facultyToDelete = $facultyCheck->get_result()->fetch_assoc();
            if (!$facultyToDelete) throw new RuntimeException('That faculty no longer exists.');

            $departmentCountStmt = $conn->prepare('SELECT COUNT(*) FROM departments WHERE faculty_id = ?');
            $departmentCountStmt->bind_param('i', $facultyId);
            $departmentCountStmt->execute();
            $linkedDepartments = (int) $departmentCountStmt->get_result()->fetch_row()[0];
            $accountCountStmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE faculty_id = ?');
            $accountCountStmt->bind_param('i', $facultyId);
            $accountCountStmt->execute();
            $linkedAccounts = (int) $accountCountStmt->get_result()->fetch_row()[0];
            if ($linkedDepartments || $linkedAccounts) {
                throw new RuntimeException("Cannot delete {$facultyToDelete['name']}: it still has {$linkedDepartments} department(s) and {$linkedAccounts} linked account(s). Deactivate it instead.");
            }

            $deleteStmt = $conn->prepare('DELETE FROM faculties WHERE id = ?');
            $deleteStmt->bind_param('i', $facultyId);
            $deleteStmt->execute();
            $conn->commit();
            $transactionActive = false;
            record_audit($conn, 'faculty_deleted', 'faculty', $facultyId, ['name' => $facultyToDelete['name']]);
            $message = 'Faculty deleted.';
        } elseif ($action === 'create_director') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $facultyId < 1) {
                throw new RuntimeException('Enter the director details and choose a faculty.');
            }
            if (!password_is_strong($password)) {
                throw new RuntimeException('Use a 12-character password with uppercase, lowercase, number, and symbol.');
            }
            $facultyCheck = $conn->prepare('SELECT id FROM faculties WHERE id = ? AND is_active = 1 LIMIT 1');
            $facultyCheck->bind_param('i', $facultyId);
            $facultyCheck->execute();
            if (!$facultyCheck->get_result()->fetch_row()) throw new RuntimeException('Choose an active faculty.');

            $directorCheck = $conn->prepare("SELECT id FROM users WHERE faculty_id = ? AND role = 'faculty_director' LIMIT 1");
            $directorCheck->bind_param('i', $facultyId);
            $directorCheck->execute();
            if ($directorCheck->get_result()->fetch_row()) throw new RuntimeException('That faculty already has a director.');

            $duplicateCheck = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $duplicateCheck->bind_param('ss', $username, $email);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->fetch_row()) throw new RuntimeException('That username or email is already in use.');

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, faculty_id, created_by) VALUES (?, ?, ?, ?, 'faculty_director', 'approved', ?, ?)");
            $stmt->bind_param('ssssii', $username, $fullName, $email, $passwordHash, $facultyId, $_SESSION['user_id']);
            $stmt->execute();
            record_audit($conn, 'faculty_director_created', 'user', $stmt->insert_id, ['faculty_id' => $facultyId]);
            $message = 'Faculty director registered.';
        } else {
            throw new RuntimeException('Choose a valid registration action.');
        }
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $conn->rollback();
            $transactionActive = false;
        }
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The registration could not be saved. Check for duplicate faculty names, usernames, or emails.';
        error_log('Faculty setup action failed: ' . $exception->getMessage());
    }
}

$faculties = $conn->query("SELECT f.id, f.category, f.name, f.is_active, f.created_at,
    u.full_name AS director_name, u.username AS director_username, u.email AS director_email,
    (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.id) AS department_count,
    (SELECT COUNT(*) FROM users m WHERE m.faculty_id = f.id) AS account_count
    FROM faculties f LEFT JOIN users u ON u.faculty_id = f.id AND u.role = 'faculty_director'
    ORDER BY f.category, f.name")->fetch_all(MYSQLI_ASSOC);
$availableFaculties = $conn->query("SELECT f.id, f.category, f.name FROM faculties f LEFT JOIN users u ON u.faculty_id = f.id AND u.role = 'faculty_director' WHERE f.is_active = 1 AND u.id IS NULL ORDER BY f.category, f.name")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.page-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:22px}.setup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:24px}.setup-panel,.faculty-panel{padding:22px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.06)}.setup-panel h2{font-size:18px;margin-bottom:6px}.setup-panel p{color:var(--system-muted);font-size:13px}.faculty-panel h2{font-size:18px}.faculty-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.faculty-item{padding:18px;border:1px solid var(--system-border);border-top:3px solid var(--system-blue);border-radius:10px;background:var(--system-page);transition:transform .2s ease,box-shadow .2s ease}.faculty-item:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(20,45,75,.1)}.faculty-item h3{font-size:16px;margin:8px 0 4px}.faculty-item p{margin:0;color:var(--system-muted);font-size:12px}.faculty-category{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--system-blue)}.faculty-card-top,.faculty-card-actions{display:flex;justify-content:space-between;align-items:center;gap:8px}.faculty-card-actions{justify-content:flex-start;flex-wrap:wrap;margin-top:14px}.faculty-metrics{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.faculty-metric{padding:4px 8px;border:1px solid var(--system-border);border-radius:999px;color:var(--system-muted);font-size:10px}.faculty-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 14px}.faculty-toolbar input{max-width:340px}.faculty-empty{padding:24px;border:1px dashed var(--system-border);border-radius:10px;color:var(--system-muted);text-align:center}.faculty-state{padding:4px 8px;border-radius:999px;background:rgba(44,168,121,.12);color:#258260;font-size:10px;font-weight:700}.faculty-state.inactive{background:rgba(115,121,142,.14);color:var(--system-muted)}@media(max-width:800px){.setup-grid,.faculty-list{grid-template-columns:1fr}.content{padding:20px 14px}.page-heading,.faculty-toolbar{align-items:flex-start;flex-direction:column}.faculty-toolbar input{max-width:none}}
    </style>
</head>
<body>
<main class="content">
    <header class="page-heading"><div><h1 class="h3 mb-1">Faculty setup</h1><p class="mb-0 text-muted">Create and manage faculties, directors, and their registration access.</p></div><a class="btn btn-outline-primary" href="admin_dashboard.php">Back to dashboard</a></header>
    <?php if ($message): ?><div class="alert alert-success" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <section class="setup-grid" aria-label="Faculty registration">
        <form class="setup-panel" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_faculty">
            <h2>Register faculty</h2><p>Choose its academic category and enter the major faculty name.</p>
            <label class="form-label" for="faculty-category">Science category</label>
            <select class="form-select mb-3" id="faculty-category" name="category" required><option value="">Choose category</option><option>Social Science</option><option>Natural Science</option></select>
            <label class="form-label" for="faculty-name">Major faculty</label>
            <input class="form-control mb-3" id="faculty-name" name="name" maxlength="120" placeholder="Technology, Health Science..." required>
            <button class="btn btn-primary" type="submit">Create faculty</button>
        </form>
        <form class="setup-panel" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_director">
            <h2>Register faculty director</h2><p>Each faculty has one director who manages its departments and department heads.</p>
            <label class="form-label" for="director-faculty">Faculty</label>
            <select class="form-select mb-3" id="director-faculty" name="faculty_id" required><option value="">Choose faculty</option><?php foreach ($availableFaculties as $faculty): ?><option value="<?= (int) $faculty['id'] ?>"><?= h($faculty['category'] . ' · ' . $faculty['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label" for="director-name">Full name</label><input class="form-control mb-3" id="director-name" name="full_name" autocomplete="name" required>
            <label class="form-label" for="director-username">Username</label><input class="form-control mb-3" id="director-username" name="username" autocomplete="username" required>
            <label class="form-label" for="director-email">Email</label><input class="form-control mb-3" type="email" id="director-email" name="email" autocomplete="email" required>
            <label class="form-label" for="director-password">Temporary password</label><input class="form-control mb-3" type="password" id="director-password" name="password" minlength="12" autocomplete="new-password" required>
            <button class="btn btn-primary" type="submit" <?= !$availableFaculties ? 'disabled' : '' ?>>Create director account</button>
        </form>
    </section>
    <section class="faculty-panel" id="registered-faculties">
        <div class="faculty-toolbar"><div><h2 class="mb-1">Registered faculties</h2><p class="text-muted mb-1"><?= count($faculties) ?> total · <?= count(array_filter($faculties, static fn(array $item): bool => (int) $item['is_active'] === 1)) ?> active</p><small class="text-muted">Delete is available when no departments or accounts are linked. Deactivate a faculty to stop new assignments and faculty director access.</small></div><input class="form-control" type="search" id="faculty-filter" placeholder="Search by faculty or director" aria-label="Search registered faculties"></div>
        <div class="faculty-list" id="faculty-list">
            <?php foreach ($faculties as $faculty): $facultyJson = h(json_encode(['id' => (int) $faculty['id'], 'category' => $faculty['category'], 'name' => $faculty['name'], 'is_active' => (int) $faculty['is_active'], 'director_name' => $faculty['director_name'], 'director_username' => $faculty['director_username'], 'director_email' => $faculty['director_email'], 'department_count' => (int) $faculty['department_count'], 'account_count' => (int) $faculty['account_count'], 'created_at' => $faculty['created_at']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>
                <article class="faculty-item" data-faculty-card data-search-text="<?= h(strtolower($faculty['category'] . ' ' . $faculty['name'] . ' ' . ($faculty['director_name'] ?? '') . ' ' . ($faculty['director_username'] ?? ''))) ?>">
                    <div class="faculty-card-top"><span class="faculty-category"><?= h($faculty['category']) ?></span><span class="faculty-state <?= $faculty['is_active'] ? '' : 'inactive' ?>"><?= $faculty['is_active'] ? 'Active' : 'Inactive' ?></span></div>
                    <h3><?= h($faculty['name']) ?></h3>
                    <p><?= $faculty['director_name'] ? h($faculty['director_name'] . ' · @' . $faculty['director_username']) : 'Director not assigned' ?></p>
                    <div class="faculty-metrics"><span class="faculty-metric"><?= (int) $faculty['department_count'] ?> departments</span><span class="faculty-metric"><?= (int) $faculty['account_count'] ?> linked accounts</span></div>
                    <div class="faculty-card-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary faculty-view-button" data-faculty="<?= $facultyJson ?>" data-bs-toggle="modal" data-bs-target="#facultyViewModal">View</button>
                        <button type="button" class="btn btn-sm btn-primary faculty-edit-button" data-faculty="<?= $facultyJson ?>" data-bs-toggle="modal" data-bs-target="#facultyEditModal">Edit</button>
                        <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_faculty"><input type="hidden" name="faculty_id" value="<?= (int) $faculty['id'] ?>"><input type="hidden" name="is_active" value="<?= $faculty['is_active'] ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= $faculty['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                        <form method="post" class="m-0" data-faculty-delete-form data-linked="<?= ($faculty['department_count'] || $faculty['account_count']) ? 'true' : 'false' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_faculty"><input type="hidden" name="faculty_id" value="<?= (int) $faculty['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" <?= ($faculty['department_count'] || $faculty['account_count']) ? 'disabled title="Move or remove linked departments and accounts first"' : '' ?>>Delete</button></form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$faculties): ?><div class="faculty-empty" id="faculty-empty">No faculties have been registered yet. Use “Register faculty” above to create the first one.</div><?php endif; ?>
            <?php if ($faculties): ?><div class="faculty-empty d-none" id="faculty-no-results">No faculties match that search.</div><?php endif; ?>
        </div>
    </section>
</main>
<div class="modal fade" id="facultyViewModal" tabindex="-1" aria-labelledby="facultyViewTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="facultyViewTitle">Faculty details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="faculty-view-body"></div></div></div></div>
<div class="modal fade" id="facultyEditModal" tabindex="-1" aria-labelledby="facultyEditTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_faculty"><input type="hidden" name="faculty_id" id="edit-faculty-id"><div class="modal-header"><h2 class="modal-title fs-5" id="facultyEditTitle">Edit faculty</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="edit-faculty-category">Science category</label><select class="form-select mb-3" id="edit-faculty-category" name="category" required><option>Social Science</option><option>Natural Science</option></select><label class="form-label" for="edit-faculty-name">Faculty name</label><input class="form-control" id="edit-faculty-name" name="name" maxlength="120" required></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div></form></div></div>
<div class="modal fade" id="facultyDeleteModal" tabindex="-1" aria-labelledby="facultyDeleteTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_faculty"><input type="hidden" name="faculty_id" id="delete-faculty-id"><div class="modal-header"><h2 class="modal-title fs-5" id="facultyDeleteTitle">Delete faculty?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="faculty-delete-message" class="mb-0"></p></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Delete faculty</button></div></form></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const facultyFilter = document.getElementById('faculty-filter');
facultyFilter?.addEventListener('input', () => {
    const query = facultyFilter.value.trim().toLocaleLowerCase();
    let shown = 0;
    document.querySelectorAll('[data-faculty-card]').forEach(card => {
        const matches = card.dataset.searchText.includes(query);
        card.classList.toggle('d-none', !matches);
        if (matches) shown++;
    });
    document.getElementById('faculty-no-results')?.classList.toggle('d-none', shown > 0 || !document.querySelector('[data-faculty-card]'));
});
document.querySelectorAll('.faculty-view-button').forEach(button => button.addEventListener('click', () => {
    const faculty = JSON.parse(button.dataset.faculty);
    const body = document.getElementById('faculty-view-body');
    body.replaceChildren();
    const details = [['Category', faculty.category], ['Name', faculty.name], ['Status', faculty.is_active ? 'Active' : 'Inactive'], ['Director', faculty.director_name || 'Not assigned'], ['Director username', faculty.director_username ? `@${faculty.director_username}` : '—'], ['Director email', faculty.director_email || '—'], ['Departments', faculty.department_count], ['Linked accounts', faculty.account_count], ['Created', faculty.created_at]];
    const list = document.createElement('dl');
    list.className = 'row mb-0';
    details.forEach(([label, value]) => {
        const term = document.createElement('dt'); term.className = 'col-sm-4'; term.textContent = label;
        const description = document.createElement('dd'); description.className = 'col-sm-8'; description.textContent = String(value);
        list.append(term, description);
    });
    body.appendChild(list);
}));
document.querySelectorAll('.faculty-edit-button').forEach(button => button.addEventListener('click', () => {
    const faculty = JSON.parse(button.dataset.faculty);
    document.getElementById('edit-faculty-id').value = faculty.id;
    document.getElementById('edit-faculty-name').value = faculty.name;
    document.getElementById('edit-faculty-category').value = faculty.category;
}));
document.querySelectorAll('[data-faculty-delete-form]').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.linked === 'true') { event.preventDefault(); return; }
    event.preventDefault();
    const faculty = JSON.parse(form.closest('[data-faculty-card]').querySelector('.faculty-view-button').dataset.faculty);
    document.getElementById('delete-faculty-id').value = faculty.id;
    document.getElementById('faculty-delete-message').textContent = `Delete ${faculty.name}? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('facultyDeleteModal')).show();
}));
</script>
</body>
</html>
