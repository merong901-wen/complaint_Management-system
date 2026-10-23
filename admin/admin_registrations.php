<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
header('Location: faculty_setup.php');
exit();

$message = '';
$error = '';
$activeTab = ($_GET['tab'] ?? 'heads') === 'departments' ? 'departments' : 'heads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_department') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($name === '') throw new RuntimeException('Department name is required.');
            $stmt = $conn->prepare('INSERT INTO departments (name, description, created_by) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $name, $description, $_SESSION['user_id']);
            $stmt->execute();
            record_audit($conn, 'department_created', 'department', $stmt->insert_id, ['name' => $name]);
            $message = 'Department created.';
            $activeTab = 'departments';
        } elseif ($action === 'toggle_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $conn->prepare('UPDATE departments SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $isActive, $departmentId);
            $stmt->execute();
            record_audit($conn, 'department_status_changed', 'department', $departmentId, ['is_active' => $isActive]);
            $message = 'Department status updated.';
            $activeTab = 'departments';
        } elseif ($action === 'create_head' || $action === 'update_head') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $headId = (int) ($_POST['head_id'] ?? 0);

            if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $departmentId < 1) {
                throw new RuntimeException('Enter a name, username, valid email, and department.');
            }
            if (($action === 'create_head' || $password !== '') && !password_is_strong($password)) {
                throw new RuntimeException('Use a 12-character password with uppercase, lowercase, number, and symbol.');
            }

            $departmentCheck = $conn->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1 LIMIT 1');
            $departmentCheck->bind_param('i', $departmentId);
            $departmentCheck->execute();
            if (!$departmentCheck->get_result()->fetch_row()) throw new RuntimeException('Choose an active department.');

            $check = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
            $check->bind_param('ssi', $username, $email, $headId);
            $check->execute();
            if ($check->get_result()->num_rows > 0) throw new RuntimeException('That username or email is already in use.');

            if ($action === 'create_head') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, department_id, created_by) VALUES (?, ?, ?, ?, 'department_head', 'approved', ?, ?)");
                $stmt->bind_param('ssssii', $username, $fullName, $email, $hash, $departmentId, $_SESSION['user_id']);
                $stmt->execute();
                record_audit($conn, 'department_head_created', 'user', $stmt->insert_id, ['department_id' => $departmentId]);
                $message = 'Department head registered.';
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, department_id = ?, password = ? WHERE id = ? AND role = 'department_head'");
                    $stmt->bind_param('sssisi', $username, $fullName, $email, $departmentId, $hash, $headId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, department_id = ? WHERE id = ? AND role = 'department_head'");
                    $stmt->bind_param('sssii', $username, $fullName, $email, $departmentId, $headId);
                }
                $stmt->execute();
                record_audit($conn, 'department_head_updated', 'user', $headId, ['department_id' => $departmentId]);
                $message = 'Department head updated.';
            }
            $activeTab = 'heads';
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The change could not be saved. Check for duplicate names or emails.';
        error_log('Admin registration action failed: ' . $exception->getMessage());
    }
}

if (isset($_GET['details'])) {
    header('Content-Type: application/json');
    $headId = (int) $_GET['details'];
    $stmt = $conn->prepare("SELECT u.id, u.full_name, u.username, u.email, u.department_id, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.role = 'department_head' LIMIT 1");
    $stmt->bind_param('i', $headId);
    $stmt->execute();
    $head = $stmt->get_result()->fetch_assoc();
    if (!$head) { http_response_code(404); echo json_encode(['error' => 'Department head not found.']); exit(); }
    record_audit($conn, 'department_head_details_viewed', 'user', $headId, ['department_id' => $head['department_id']]);

    $departmentId = (int) $head['department_id'];
    $peopleTotalStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE department_id = ? AND role IN ('teacher', 'student')");
    $peopleTotalStmt->bind_param('i', $departmentId);
    $peopleTotalStmt->execute();
    $peopleTotal = (int) $peopleTotalStmt->get_result()->fetch_row()[0];
    $peoplePage = pagination_data($peopleTotal, (int) ($_GET['people_page'] ?? 1));
    $peopleStmt = $conn->prepare("SELECT full_name, username, student_number, email, role, status, created_at FROM users WHERE department_id = ? AND role IN ('teacher', 'student') ORDER BY role, full_name LIMIT ? OFFSET ?");
    $peopleStmt->bind_param('iii', $departmentId, $peoplePage['per_page'], $peoplePage['offset']);
    $peopleStmt->execute();
    $people = $peopleStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $historyTotalStmt = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE department_id = ?');
    $historyTotalStmt->bind_param('i', $departmentId);
    $historyTotalStmt->execute();
    $historyTotal = (int) $historyTotalStmt->get_result()->fetch_row()[0];
    $historyPage = pagination_data($historyTotal, (int) ($_GET['history_page'] ?? 1));
    $historyStmt = $conn->prepare('SELECT c.id, c.title, c.description, c.status, c.resolution, c.created_at, c.updated_at, s.full_name AS student_name, s.student_number, t.full_name AS teacher_name FROM complaints c JOIN users s ON s.id = c.user_id LEFT JOIN users t ON t.id = c.teacher_id WHERE c.department_id = ? ORDER BY c.created_at DESC LIMIT ? OFFSET ?');
    $historyStmt->bind_param('iii', $departmentId, $historyPage['per_page'], $historyPage['offset']);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $conversationStmt = $conn->prepare('SELECT c.comment_text, c.created_at, u.full_name, u.role FROM comments c JOIN users u ON u.id = c.user_id WHERE c.complaint_id = ? ORDER BY c.created_at ASC');
    foreach ($history as &$complaint) {
        $complaintId = (int) $complaint['id'];
        $conversationStmt->bind_param('i', $complaintId);
        $conversationStmt->execute();
        $complaint['conversation'] = $conversationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($complaint);
    echo json_encode(['head' => $head, 'people' => $people, 'people_page' => $peoplePage, 'history' => $history, 'history_page' => $historyPage]);
    exit();
}

$departmentTotal = (int) $conn->query('SELECT COUNT(*) FROM departments')->fetch_row()[0];
$departmentPagination = pagination_data($departmentTotal, (int) ($_GET['department_page'] ?? 1));
$departmentsStmt = $conn->prepare('SELECT d.id, d.name, d.description, d.is_active, COUNT(DISTINCT CASE WHEN u.role = \'department_head\' THEN u.id END) AS heads, COUNT(DISTINCT CASE WHEN u.role = \'teacher\' THEN u.id END) AS teachers, COUNT(DISTINCT CASE WHEN u.role = \'student\' THEN u.id END) AS students FROM departments d LEFT JOIN users u ON u.department_id = d.id GROUP BY d.id ORDER BY d.name LIMIT ? OFFSET ?');
$departmentsStmt->bind_param('ii', $departmentPagination['per_page'], $departmentPagination['offset']);
$departmentsStmt->execute();
$departmentRows = $departmentsStmt->get_result();

$headTotal = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'department_head'")->fetch_row()[0];
$headPagination = pagination_data($headTotal, (int) ($_GET['page'] ?? 1));
$headsStmt = $conn->prepare("SELECT u.id, u.username, u.full_name, u.email, u.status, u.department_id, d.name AS department_name, (SELECT COUNT(*) FROM users t WHERE t.department_id = u.department_id AND t.role = 'teacher') AS teacher_count, (SELECT COUNT(*) FROM users s WHERE s.department_id = u.department_id AND s.role = 'student') AS student_count FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.role = 'department_head' ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$headsStmt->bind_param('ii', $headPagination['per_page'], $headPagination['offset']);
$headsStmt->execute();
$heads = $headsStmt->get_result();
$activeDepartments = $conn->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.registration-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:20px}.registration-heading h1{font-size:25px;margin:0}.registration-actions{display:flex;gap:8px}.table-wrap{padding:16px;border:1px solid var(--system-blue);background:#fff}.modal-body p{overflow-wrap:anywhere}.detail-table{max-height:280px;overflow:auto}.detail-section{margin:20px 0}.detail-section h6{font-weight:700}.detail-pagination{display:flex;justify-content:space-between;align-items:center;font-size:12px}.detail-pagination button{border:1px solid var(--system-blue);background:white;padding:5px 9px}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}body.dark-mode .table-wrap{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}.registration-heading{align-items:flex-start;flex-direction:column}.registration-actions{flex-wrap:wrap}}
    </style>
</head>
<body>
<main class="content">
    <header class="registration-heading"><div><h1>Registrations</h1><p class="mb-0">Manage department access and department heads.</p></div><div class="registration-actions"><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#departmentModal">Register department</button><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#headModal">Register department head</button></div></header>
    <?php if ($message): ?><div class="alert alert-success" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link <?= $activeTab === 'heads' ? 'active' : '' ?>" href="?tab=heads">Department heads</a></li><li class="nav-item"><a class="nav-link <?= $activeTab === 'departments' ? 'active' : '' ?>" href="?tab=departments">Departments</a></li></ul>
    <?php if ($activeTab === 'heads'): ?>
    <div class="table-wrap table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Name</th><th>Email</th><th>Department</th><th>Teachers</th><th>Students</th><th>Actions</th></tr></thead><tbody>
    <?php while ($head = $heads->fetch_assoc()): $headJson = h(json_encode(['id'=>(int)$head['id'],'full_name'=>$head['full_name'],'username'=>$head['username'],'email'=>$head['email'],'department_id'=>(int)$head['department_id']])); ?>
        <tr><td><?= h($head['full_name'] ?: $head['username']) ?><small class="d-block">@<?= h($head['username']) ?></small></td><td><?= h($head['email']) ?></td><td><?= h($head['department_name'] ?? 'Unassigned') ?></td><td><?= (int)$head['teacher_count'] ?></td><td><?= (int)$head['student_count'] ?></td><td class="text-nowrap"><button class="btn btn-sm btn-primary view-head" data-head="<?= $headJson ?>" data-bs-toggle="modal" data-bs-target="#viewModal">View</button> <button class="btn btn-sm btn-primary edit-head" data-head="<?= $headJson ?>" data-bs-toggle="modal" data-bs-target="#editModal">Edit</button> <button class="btn btn-sm btn-primary details-head" data-id="<?= (int)$head['id'] ?>" data-bs-toggle="modal" data-bs-target="#detailsModal">Details</button></td></tr>
    <?php endwhile; ?>
    <?php if ($headTotal === 0): ?><tr><td colspan="6">No department heads registered.</td></tr><?php endif; ?>
    </tbody></table><?= pagination_links($headPagination) ?></div>
    <?php else: ?>
    <div class="table-wrap table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Department</th><th>Heads</th><th>Teachers</th><th>Students</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php while ($department = $departmentRows->fetch_assoc()): ?><tr><td><strong><?= h($department['name']) ?></strong><small class="d-block"><?= h($department['description']) ?></small></td><td><?= (int)$department['heads'] ?></td><td><?= (int)$department['teachers'] ?></td><td><?= (int)$department['students'] ?></td><td><?= $department['is_active'] ? 'Open' : 'Closed' ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_department"><input type="hidden" name="department_id" value="<?= (int)$department['id'] ?>"><input type="hidden" name="is_active" value="<?= $department['is_active'] ? 0 : 1 ?>"><button class="btn btn-sm btn-primary" type="submit"><?= $department['is_active'] ? 'Close' : 'Open' ?></button></form></td></tr><?php endwhile; ?>
    <?php if ($departmentTotal === 0): ?><tr><td colspan="6">No departments registered.</td></tr><?php endif; ?>
    </tbody></table><?= pagination_links($departmentPagination, 'department_page') ?></div>
    <?php endif; ?>
</main>

<div class="modal fade" id="departmentModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_department"><div class="modal-header"><h2 class="modal-title fs-5">Register department</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Department name</label><input class="form-control mb-3" name="name" maxlength="120" required><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div><div class="modal-footer"><button class="btn btn-primary" type="submit">Save department</button></div></form></div></div>
<div class="modal fade" id="headModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_head"><div class="modal-header"><h2 class="modal-title fs-5">Register department head</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Department</label><select class="form-select mb-3" name="department_id" required><option value="">Choose department</option><?php foreach($activeDepartments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= h($department['name']) ?></option><?php endforeach; ?></select><label class="form-label">Temporary password</label><input class="form-control" type="password" name="password" minlength="12" required></div><div class="modal-footer"><button class="btn btn-primary" type="submit">Create account</button></div></form></div></div>
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_head"><input type="hidden" name="head_id" id="edit-head-id"><div class="modal-header"><h2 class="modal-title fs-5">Edit department head</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" id="edit-full-name" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" id="edit-username" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" id="edit-email" required><label class="form-label">Department</label><select class="form-select mb-3" name="department_id" id="edit-department" required><?php foreach($activeDepartments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= h($department['name']) ?></option><?php endforeach; ?></select><label class="form-label">New password <small>(leave empty to keep current)</small></label><input class="form-control" type="password" name="password" minlength="12"></div><div class="modal-footer"><button class="btn btn-primary" type="submit">Save changes</button></div></form></div></div>
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Department head details</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="view-head-body"></div></div></div></div>
<div class="modal fade" id="detailsModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="details-title">Department details</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="details-body"><p>Loading records...</p></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
document.querySelectorAll('.edit-head').forEach(button => button.addEventListener('click', () => {
    const head = JSON.parse(button.dataset.head);
    document.getElementById('edit-head-id').value = head.id;
    document.getElementById('edit-full-name').value = head.full_name;
    document.getElementById('edit-username').value = head.username;
    document.getElementById('edit-email').value = head.email;
    document.getElementById('edit-department').value = head.department_id;
}));
document.querySelectorAll('.view-head').forEach(button => button.addEventListener('click', () => {
    const head = JSON.parse(button.dataset.head);
    document.getElementById('view-head-body').innerHTML = `<dl><dt>Full name</dt><dd>${escapeHtml(head.full_name)}</dd><dt>Username</dt><dd>${escapeHtml(head.username)}</dd><dt>Email</dt><dd>${escapeHtml(head.email)}</dd></dl>`;
}));

const detailsModal = document.getElementById('detailsModal');
let detailsHeadId = 0;
async function loadDepartmentDetails(peoplePage = 1, historyPage = 1) {
    const body = document.getElementById('details-body');
    body.innerHTML = '<p>Loading records...</p>';
    const response = await fetch(`admin_registrations.php?details=${detailsHeadId}&people_page=${peoplePage}&history_page=${historyPage}`);
    const data = await response.json();
    if (!response.ok || data.error) { body.textContent = data.error || 'Could not load details.'; return; }
    document.getElementById('details-title').textContent = `${data.head.full_name || data.head.username} · ${data.head.department_name || 'Department'}`;
    const peopleRows = data.people.map(person => `<tr><td>${escapeHtml(person.full_name || person.username)}</td><td>${escapeHtml(person.role)}</td><td>${escapeHtml(person.student_number)}</td><td>${escapeHtml(person.email)}</td><td>${escapeHtml(person.status)}</td><td>${escapeHtml(person.created_at)}</td></tr>`).join('');
    const historyRows = data.history.map(item => {
        const conversation = item.conversation.map(entry => `<p><strong>${escapeHtml(entry.full_name)} (${escapeHtml(entry.role)}) · ${escapeHtml(entry.created_at)}</strong><br>${escapeHtml(entry.comment_text)}</p>`).join('');
        return `<tr><td>${escapeHtml(item.student_name)} (${escapeHtml(item.student_number)})</td><td>${escapeHtml(item.title)}<small class="d-block">${escapeHtml(item.description)}</small></td><td>${escapeHtml(item.teacher_name || 'Unassigned')}</td><td>${escapeHtml(item.status)}</td><td>${escapeHtml(item.resolution || 'No solution yet')}</td><td>${conversation || 'No messages'}</td><td>${escapeHtml(item.updated_at || item.created_at)}</td></tr>`;
    }).join('');
    body.innerHTML = `<section class="detail-section"><h6>Teachers and students</h6><div class="detail-table"><table class="table table-sm"><thead><tr><th>Name</th><th>Role</th><th>Student ID</th><th>Email</th><th>Status</th><th>Registered</th></tr></thead><tbody>${peopleRows || '<tr><td colspan="6">No department members.</td></tr>'}</tbody></table></div><div class="detail-pagination"><span>Page ${data.people_page.page} of ${data.people_page.page_count} · ${data.people_page.total} members</span><span><button data-detail-page="people" data-page="${data.people_page.page - 1}" ${data.people_page.page <= 1 ? 'disabled' : ''}>Previous</button> <button data-detail-page="people" data-page="${data.people_page.page + 1}" ${data.people_page.page >= data.people_page.page_count ? 'disabled' : ''}>Next</button></span></div></section><section class="detail-section"><h6>Complaint conversations and solutions</h6><div class="detail-table"><table class="table table-sm"><thead><tr><th>Student</th><th>Complaint</th><th>Teacher</th><th>Status</th><th>Solution</th><th>Conversation</th><th>Date</th></tr></thead><tbody>${historyRows || '<tr><td colspan="7">No complaints in this department.</td></tr>'}</tbody></table></div><div class="detail-pagination"><span>Page ${data.history_page.page} of ${data.history_page.page_count} · ${data.history_page.total} complaints</span><span><button data-detail-page="history" data-page="${data.history_page.page - 1}" ${data.history_page.page <= 1 ? 'disabled' : ''}>Previous</button> <button data-detail-page="history" data-page="${data.history_page.page + 1}" ${data.history_page.page >= data.history_page.page_count ? 'disabled' : ''}>Next</button></span></div></section>`;
    body.querySelectorAll('[data-detail-page]').forEach(button => button.addEventListener('click', () => {
        const people = Number(body.querySelector('[data-detail-page="people"][data-current]')?.dataset.current || peoplePage);
        const history = Number(body.querySelector('[data-detail-page="history"][data-current]')?.dataset.current || historyPage);
        loadDepartmentDetails(button.dataset.detailPage === 'people' ? Number(button.dataset.page) : people, button.dataset.detailPage === 'history' ? Number(button.dataset.page) : history);
    }));
    body.querySelectorAll('[data-detail-page="people"]').forEach(button => button.dataset.current = data.people_page.page);
    body.querySelectorAll('[data-detail-page="history"]').forEach(button => button.dataset.current = data.history_page.page);
}
document.querySelectorAll('.details-head').forEach(button => button.addEventListener('click', () => { detailsHeadId = button.dataset.id; loadDepartmentDetails(); }));
</script>
</body>
</html>
