<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['department_head']);
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $submittedDecision = (string) ($_POST['decision'] ?? '');
    if (!in_array($submittedDecision, ['approve', 'reject'], true)) {
        http_response_code(400);
        exit('Invalid approval decision.');
    }
    $decision = $submittedDecision === 'approve' ? 'approved' : 'rejected';
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND department_id = ? AND role = 'student' AND status = 'pending'");
    $stmt->bind_param('sii', $decision, $studentId, $departmentId);
    $conn->begin_transaction();
    try {
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('Student is no longer awaiting approval in this department.');
        $notificationText = $decision === 'approved'
            ? 'Your account has been approved. You can now sign in and submit complaints to your department teachers.'
            : 'Your account registration was declined. Contact your department head for more information.';
        $notification = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
        $notification->bind_param('is', $studentId, $notificationText);
        if (!$notification->execute()) throw new RuntimeException('Approval notification failed.');
        $conn->commit();
        record_audit($conn, 'student_' . $decision, 'user', $studentId, ['department_id' => $departmentId]);
        $message = $decision === 'approved' ? 'Student approved and notified.' : 'Student declined and notified.';
    } catch (Throwable $exception) {
        $conn->rollback();
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The approval could not be saved.';
        error_log('Student approval transaction failed: ' . $exception->getMessage());
    }
    if (!$message && !$error) {
        $error = 'That student is no longer awaiting approval in your department.';
    }
}

$total = (int) $conn->query("SELECT COUNT(*) FROM users WHERE department_id = $departmentId AND role = 'student' AND status = 'pending'")->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
$stmt = $conn->prepare("SELECT id, full_name, student_number, username, email, created_at FROM users WHERE department_id = ? AND role = 'student' AND status = 'pending' ORDER BY created_at ASC LIMIT ? OFFSET ?");
$stmt->bind_param('iii', $departmentId, $pagination['per_page'], $pagination['offset']);
$stmt->execute();
$students = $stmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Student approvals</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>.content{padding:28px}.approval-table{padding:16px;border:1px solid var(--system-blue);background:#fff}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}body.dark-mode .approval-table{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}}</style></head>
<body><main class="content"><h1 class="h3">Student approvals</h1><p>Review student registrations assigned to your department.</p><?php if($message): ?><div class="alert alert-info" role="status"><?= h($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?><section class="approval-table table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Student</th><th>Student ID</th><th>Email</th><th>Requested</th><th>Decision</th></tr></thead><tbody><?php while($student=$students->fetch_assoc()): ?><tr><td><?= h($student['full_name']) ?><small class="d-block">@<?= h($student['username']) ?></small></td><td><?= h($student['student_number']) ?></td><td><?= h($student['email']) ?></td><td><?= h($student['created_at']) ?></td><td><form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="btn btn-primary btn-sm" name="decision" value="approve">Approve</button><button class="btn btn-secondary btn-sm" name="decision" value="reject">Decline</button></form></td></tr><?php endwhile; ?><?php if($total===0): ?><tr><td colspan="5">No students are awaiting approval.</td></tr><?php endif; ?></tbody></table><?= pagination_links($pagination) ?></section></main></body></html>
