<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['department_head']);
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$message = isset($_GET['deleted']) ? 'Teacher account deleted.' : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $deleteTransactionStarted = false;

    try {
        if ($action === 'delete_teacher') {
            $teacherCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND department_id = ? AND role = 'teacher' LIMIT 1");
            $teacherCheck->bind_param('ii', $teacherId, $departmentId);
            $teacherCheck->execute();
            if (!$teacherCheck->get_result()->fetch_row()) throw new RuntimeException('That teacher is not in your department.');
            $complaintCheck = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE teacher_id = ? AND department_id = ?');
            $complaintCheck->bind_param('ii', $teacherId, $departmentId);
            $complaintCheck->execute();
            $commentCheck = $conn->prepare('SELECT COUNT(*) FROM comments m JOIN complaints c ON c.id = m.complaint_id WHERE m.user_id = ? AND c.department_id = ?');
            $commentCheck->bind_param('ii', $teacherId, $departmentId);
            $commentCheck->execute();
            if ((int) $complaintCheck->get_result()->fetch_row()[0] > 0 || (int) $commentCheck->get_result()->fetch_row()[0] > 0) {
                throw new RuntimeException('This teacher has complaint or comment history and cannot be deleted. Inactivate access through an administrator instead.');
            }
            $conn->begin_transaction();
            $deleteTransactionStarted = true;
            $notificationDelete = $conn->prepare('DELETE FROM notifications WHERE user_id = ?');
            $notificationDelete->bind_param('i', $teacherId);
            $notificationDelete->execute();
            $teacherDelete = $conn->prepare("DELETE FROM users WHERE id = ? AND department_id = ? AND role = 'teacher'");
            $teacherDelete->bind_param('ii', $teacherId, $departmentId);
            $teacherDelete->execute();
            if ($teacherDelete->affected_rows !== 1) throw new RuntimeException('The teacher could not be deleted. Refresh and try again.');
            record_audit($conn, 'teacher_deleted', 'user', $teacherId, ['department_id' => $departmentId]);
            $conn->commit();
            $deleteTransactionStarted = false;
            header('Location: teachers.php?deleted=1');
            exit();
        } elseif (!in_array($action, ['create_teacher', 'update_teacher'], true) || $fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a full name, username, and valid email.');
        }
        if ($action !== 'delete_teacher' && ($action === 'create_teacher' || $password !== '') && !password_is_strong($password)) {
            throw new RuntimeException('Use a 12-character password with uppercase, lowercase, number, and symbol.');
        }
        $check = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $check->bind_param('ssi', $username, $email, $teacherId);
        $check->execute();
        if ($check->get_result()->num_rows) throw new RuntimeException('That username or email is already in use.');

        if ($action === 'create_teacher') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, department_id, created_by) VALUES (?, ?, ?, ?, 'teacher', 'approved', ?, ?)");
            $stmt->bind_param('ssssii', $username, $fullName, $email, $hash, $departmentId, $_SESSION['user_id']);
            $stmt->execute();
            $teacherId = $stmt->insert_id;
            record_audit($conn, 'teacher_created', 'user', $teacherId, ['department_id' => $departmentId]);
            $message = 'Teacher account created.';
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, password = ? WHERE id = ? AND role = 'teacher' AND department_id = ?");
                $stmt->bind_param('ssssii', $fullName, $username, $email, $hash, $teacherId, $departmentId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ? AND role = 'teacher' AND department_id = ?");
                $stmt->bind_param('sssii', $fullName, $username, $email, $teacherId, $departmentId);
            }
            $stmt->execute();
            record_audit($conn, 'teacher_updated', 'user', $teacherId, ['department_id' => $departmentId]);
            $message = 'Teacher details updated.';
        }
    } catch (Throwable $exception) {
        if ($deleteTransactionStarted) $conn->rollback();
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The teacher account could not be saved.';
        error_log('Department teacher action failed: ' . $exception->getMessage());
    }
}

if (isset($_GET['teacher'])) {
    header('Content-Type: application/json');
    $teacherId = (int) $_GET['teacher'];
    $teacherStmt = $conn->prepare("SELECT id, full_name, username, email FROM users WHERE id = ? AND role = 'teacher' AND department_id = ? LIMIT 1");
    $teacherStmt->bind_param('ii', $teacherId, $departmentId);
    $teacherStmt->execute();
    $teacher = $teacherStmt->get_result()->fetch_assoc();
    if (!$teacher) { http_response_code(404); echo json_encode(['error' => 'Teacher not found in this department.']); exit(); }
    record_audit($conn, 'teacher_department_details_viewed', 'user', $teacherId, ['department_id' => $departmentId]);
    $totalStmt = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE teacher_id = ? AND department_id = ?');
    $totalStmt->bind_param('ii', $teacherId, $departmentId);
    $totalStmt->execute();
    $pagination = pagination_data((int) $totalStmt->get_result()->fetch_row()[0], (int) ($_GET['page'] ?? 1));
    $complaintsStmt = $conn->prepare('SELECT c.id, c.title, c.description, c.status, c.priority, c.resolution, c.created_at, c.updated_at, s.full_name AS student_name, s.student_number FROM complaints c JOIN users s ON s.id = c.user_id WHERE c.teacher_id = ? AND c.department_id = ? ORDER BY c.created_at DESC LIMIT ? OFFSET ?');
    $complaintsStmt->bind_param('iiii', $teacherId, $departmentId, $pagination['per_page'], $pagination['offset']);
    $complaintsStmt->execute();
    $complaints = $complaintsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $conversationStmt = $conn->prepare('SELECT c.comment_text,c.created_at,u.full_name,u.role FROM comments c JOIN users u ON u.id=c.user_id WHERE c.complaint_id=? ORDER BY c.created_at ASC');
    foreach ($complaints as &$complaint) {
        $complaintId = (int) $complaint['id'];
        $conversationStmt->bind_param('i', $complaintId);
        $conversationStmt->execute();
        $complaint['conversation'] = $conversationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($complaint);
    echo json_encode(['teacher' => $teacher, 'complaints' => $complaints, 'pagination' => $pagination]);
    exit();
}

$total = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND department_id = $departmentId")->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
$teachersStmt = $conn->prepare("SELECT u.id, u.full_name, u.username, u.email, u.status, (SELECT COUNT(*) FROM complaints c WHERE c.teacher_id = u.id AND c.department_id = u.department_id) AS complaint_count FROM users u WHERE u.role = 'teacher' AND u.department_id = ? ORDER BY u.full_name LIMIT ? OFFSET ?");
$teachersStmt->bind_param('iii', $departmentId, $pagination['per_page'], $pagination['offset']);
$teachersStmt->execute();
$teachers = $teachersStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Department teachers</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>.content{padding:28px}.table-panel{padding:16px;border:1px solid var(--system-blue);background:#fff}.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.page-heading h1{font-size:24px;margin:0}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}.detail-table{max-height:320px;overflow:auto}body.dark-mode .table-panel{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}.page-heading{align-items:flex-start;flex-direction:column}}</style></head>
<body><main class="content"><header class="page-heading"><div><h1>Department teachers</h1><p class="mb-0">Create and manage teacher accounts in your department.</p></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teacherModal">Register teacher</button></header><?php if($message): ?><div class="alert alert-info" role="status"><?= h($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?><section class="table-panel table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Teacher</th><th>Email</th><th>Status</th><th>Assigned complaints</th><th>Actions</th></tr></thead><tbody><?php while($teacher=$teachers->fetch_assoc()): $teacherJson=h(json_encode(['id'=>(int)$teacher['id'],'full_name'=>$teacher['full_name'],'username'=>$teacher['username'],'email'=>$teacher['email']])); ?><tr><td><?= h($teacher['full_name']) ?><small class="d-block">@<?= h($teacher['username']) ?></small></td><td><?= h($teacher['email']) ?></td><td><?= h($teacher['status']) ?></td><td><?= (int)$teacher['complaint_count'] ?></td><td class="text-nowrap"><button class="btn btn-primary btn-sm view-teacher" data-teacher="<?= $teacherJson ?>" data-bs-toggle="modal" data-bs-target="#viewTeacherModal">View</button> <button class="btn btn-primary btn-sm edit-teacher" data-teacher="<?= $teacherJson ?>" data-bs-toggle="modal" data-bs-target="#editTeacherModal">Edit</button> <button class="btn btn-primary btn-sm details-teacher" data-id="<?= (int)$teacher['id'] ?>" data-bs-toggle="modal" data-bs-target="#teacherDetailsModal">Details</button> <button class="btn btn-outline-danger btn-sm delete-teacher" data-id="<?= (int)$teacher['id'] ?>" data-name="<?= h($teacher['full_name'] ?: $teacher['username']) ?>" data-bs-toggle="modal" data-bs-target="#deleteTeacherModal">Delete</button></td></tr><?php endwhile; ?><?php if($total===0): ?><tr><td colspan="5">No teachers are registered in this department.</td></tr><?php endif; ?></tbody></table><?= pagination_links($pagination) ?></section></main>
<div class="modal fade" id="teacherModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_teacher"><div class="modal-header"><h2 class="modal-title fs-5">Register teacher</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Temporary password</label><input class="form-control" type="password" name="password" minlength="12" required></div><div class="modal-footer"><button class="btn btn-primary">Create teacher account</button></div></form></div></div>
<div class="modal fade" id="editTeacherModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_teacher"><input type="hidden" name="teacher_id" id="teacher-edit-id"><div class="modal-header"><h2 class="modal-title fs-5">Edit teacher</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" id="teacher-edit-name" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" id="teacher-edit-username" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" id="teacher-edit-email" required><label class="form-label">New password <small>(optional)</small></label><input class="form-control" type="password" name="password" minlength="12"></div><div class="modal-footer"><button class="btn btn-primary">Save teacher</button></div></form></div></div>
<div class="modal fade" id="viewTeacherModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Teacher information</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="teacher-view-content"></div></div></div></div>
<div class="modal fade" id="teacherDetailsModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="teacher-details-title">Teacher complaint conversations</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="teacher-details-content">Loading...</div></div></div></div>
<div class="modal fade" id="deleteTeacherModal" tabindex="-1" aria-labelledby="deleteTeacherTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_teacher"><input type="hidden" name="teacher_id" id="delete-teacher-id"><div class="modal-header"><h2 class="modal-title fs-5" id="deleteTeacherTitle">Delete teacher account?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="mb-0">Delete <strong id="delete-teacher-name"></strong>? Teachers with complaint or comment history cannot be deleted.</p></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Delete teacher</button></div></form></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script>
const escapeTeacherHtml=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
document.querySelectorAll('.delete-teacher').forEach(button=>button.addEventListener('click',()=>{document.getElementById('delete-teacher-id').value=button.dataset.id;document.getElementById('delete-teacher-name').textContent=button.dataset.name;}));
document.querySelectorAll('.edit-teacher').forEach(button=>button.addEventListener('click',()=>{const item=JSON.parse(button.dataset.teacher);document.getElementById('teacher-edit-id').value=item.id;document.getElementById('teacher-edit-name').value=item.full_name;document.getElementById('teacher-edit-username').value=item.username;document.getElementById('teacher-edit-email').value=item.email;}));
document.querySelectorAll('.view-teacher').forEach(button=>button.addEventListener('click',()=>{const item=JSON.parse(button.dataset.teacher);document.getElementById('teacher-view-content').innerHTML=`<dl><dt>Name</dt><dd>${escapeTeacherHtml(item.full_name)}</dd><dt>Username</dt><dd>${escapeTeacherHtml(item.username)}</dd><dt>Email</dt><dd>${escapeTeacherHtml(item.email)}</dd></dl>`;}));
let detailsTeacherId=0;
async function loadTeacherDetails(page=1){const target=document.getElementById('teacher-details-content');target.textContent='Loading...';const response=await fetch(`teachers.php?teacher=${detailsTeacherId}&page=${page}`);const data=await response.json();if(!response.ok){target.textContent=data.error||'Unable to load conversations.';return;}document.getElementById('teacher-details-title').textContent=`${data.teacher.full_name} · assigned complaints`;const rows=data.complaints.map(item=>{const conversation=item.conversation.map(entry=>`<p><strong>${escapeTeacherHtml(entry.full_name)} (${escapeTeacherHtml(entry.role)})</strong><br>${escapeTeacherHtml(entry.comment_text)}<small class="d-block">${escapeTeacherHtml(entry.created_at)}</small></p>`).join('');return `<tr><td>${escapeTeacherHtml(item.student_name)} (${escapeTeacherHtml(item.student_number)})</td><td>${escapeTeacherHtml(item.title)}<small class="d-block">${escapeTeacherHtml(item.description)}</small></td><td>${escapeTeacherHtml(item.status)}</td><td>${escapeTeacherHtml(item.resolution||'No solution yet')}</td><td>${conversation||'No messages'}</td><td>${escapeTeacherHtml(item.updated_at||item.created_at)}</td></tr>`;}).join('');target.innerHTML=`<div class="detail-table"><table class="table table-sm"><thead><tr><th>Student</th><th>Complaint</th><th>Status</th><th>Resolution</th><th>Conversation</th><th>Date</th></tr></thead><tbody>${rows||'<tr><td colspan="6">No complaints assigned.</td></tr>'}</tbody></table></div><div class="d-flex justify-content-between"><span>Page ${data.pagination.page} of ${data.pagination.page_count} · ${data.pagination.total} complaints</span><span><button class="btn btn-sm btn-primary" data-page="${data.pagination.page-1}" ${data.pagination.page<=1?'disabled':''}>Previous</button> <button class="btn btn-sm btn-primary" data-page="${data.pagination.page+1}" ${data.pagination.page>=data.pagination.page_count?'disabled':''}>Next</button></span></div>`;target.querySelectorAll('[data-page]').forEach(button=>button.addEventListener('click',()=>loadTeacherDetails(Number(button.dataset.page))));}
document.querySelectorAll('.details-teacher').forEach(button=>button.addEventListener('click',()=>{detailsTeacherId=button.dataset.id;loadTeacherDetails();}));
</script></body></html>
