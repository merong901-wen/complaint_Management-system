<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$userId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Refresh the page and try again.']);
        exit();
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? 'General'));
    $description = trim((string) ($_POST['description'] ?? ''));
    $teacherId = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
    $allowedCategories = ['General', 'Facilities', 'Academic', 'Safety', 'Technology', 'Other'];
    if ($title === '' || $description === '' || !$teacherId || !in_array($category, $allowedCategories, true) || mb_strlen($title) > 255 || mb_strlen($description) > 5000) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Enter a title, description, and teacher.']);
        exit();
    }

    $teacherCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND department_id = ? AND role = 'teacher' AND status = 'approved' LIMIT 1");
    $teacherCheck->bind_param('ii', $teacherId, $departmentId);
    $teacherCheck->execute();
    if (!$teacherCheck->get_result()->fetch_row()) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Choose a teacher in your department.']);
        exit();
    }

    $status = 'Pending';
    $priority = in_array($_POST['priority'] ?? '', ['High','Medium','Low'], true) ? $_POST['priority'] : 'Medium';
    $insert = $conn->prepare('INSERT INTO complaints (user_id, teacher_id, department_id, title, category, description, status, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('iiisssss', $userId, $teacherId, $departmentId, $title, $category, $description, $status, $priority);

    $conn->begin_transaction();
    try {
        if (!$insert->execute()) throw new RuntimeException('Complaint insert failed.');
        $complaintId = $insert->insert_id;
        $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
        $notice = 'A new complaint has been assigned to you.';
        $notification->bind_param('is', $teacherId, $notice);
        if (!$notification->execute()) throw new RuntimeException('Teacher notification failed.');
        $conn->commit();
        record_audit($conn, 'student_complaint_submitted', 'complaint', $complaintId, ['teacher_id'=>$teacherId,'department_id'=>$departmentId]);
        echo json_encode(['status'=>'success','message'=>'Complaint sent to your teacher.']);
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Student complaint submission failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'The complaint could not be submitted.']);
    }
    exit();
}

$teachersStmt = $conn->prepare("SELECT id, full_name FROM users WHERE department_id = ? AND role = 'teacher' AND status = 'approved' ORDER BY full_name");
$teachersStmt->bind_param('i', $departmentId);
$teachersStmt->execute();
$teachers = $teachersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Submit complaint</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../public/assets/css/style.css?v=20261015.4"><script src="../public/assets/js/script.js?v=20261015.4" defer></script><style>.submission-card{max-width:680px;margin:64px auto;padding:28px;border:1px solid var(--system-blue);background:#fff}body.dark-mode .submission-card{background:#000!important;color:#fff!important}@media(max-width:700px){.submission-card{margin:62px 16px 20px;padding:20px}}</style></head><body>
<button type="button" class="theme-toggle position-fixed top-0 end-0 m-3 px-3 py-2" data-theme-toggle aria-pressed="false">Dark mode</button>
<main class="submission-card"><h1 class="h3">Submit a complaint</h1><?php if (!$teachers): ?><div class="alert alert-info">There are no approved teachers in your department yet.</div><?php else: ?><form id="complaintForm"><?= csrf_field() ?><div class="mb-3"><label class="form-label" for="complaint-title">Title</label><input id="complaint-title" class="form-control" name="title" maxlength="255" required></div><div class="mb-3"><label class="form-label" for="complaint-category">Category</label><select id="complaint-category" class="form-select" name="category"><option>General</option><option>Facilities</option><option>Academic</option><option>Safety</option><option>Technology</option><option>Other</option></select></div><div class="mb-3"><label class="form-label" for="complaint-teacher">Send to teacher</label><select id="complaint-teacher" class="form-select" name="teacher_id" required><option value="">Select a teacher</option><?php foreach($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>"><?= h($teacher['full_name']) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label" for="complaint-priority">Priority</label><select id="complaint-priority" class="form-select" name="priority"><option>Medium</option><option>Low</option><option>High</option></select></div><div class="mb-3"><label class="form-label" for="complaint-description">Description</label><textarea id="complaint-description" class="form-control" name="description" rows="6" maxlength="5000" required></textarea></div><button class="btn btn-primary" type="submit">Submit complaint</button><a class="btn btn-secondary" href="user_complaints.php">Cancel</a></form><?php endif; ?></main>
<script>
document.getElementById('complaintForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const response = await fetch('user_add_complaint.php', {method:'POST',body:new FormData(event.currentTarget),headers:{'Accept':'application/json'}});
    const result = await response.json();
    showToast(result.message, 3500, result.status === 'success' ? 'success' : 'error');
    if (result.status === 'success') window.location.href = 'user_complaints.php';
});
</script></body></html>