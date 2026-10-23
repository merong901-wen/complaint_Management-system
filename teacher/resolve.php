<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
post_csrf_or_exit();

$teacherId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$complaintId = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
$resolution = trim((string) ($_POST['resolution'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'In Progress'));
$allowedStatuses = ['In Progress', 'Resolved', 'Closed'];

if (!$complaintId || $resolution === '' || !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    exit('Provide a response and a valid status.');
}

$ownerStmt = $conn->prepare('SELECT user_id FROM complaints WHERE id = ? AND teacher_id = ? AND department_id = ? LIMIT 1');
$ownerStmt->bind_param('iii', $complaintId, $teacherId, $departmentId);
$ownerStmt->execute();
$complaint = $ownerStmt->get_result()->fetch_assoc();
if (!$complaint) {
    http_response_code(404);
    exit('Complaint not assigned to this teacher.');
}

$studentId = (int) $complaint['user_id'];
$notificationText = 'Your teacher updated complaint #' . $complaintId . ': ' . $status . '.';

$conn->begin_transaction();
try {
    $updateStmt = $conn->prepare("UPDATE complaints SET resolution = ?, status = ?, resolution_by = ?, resolved_at = CASE WHEN ? = 'Resolved' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ? AND teacher_id = ? AND department_id = ?");
    $updateStmt->bind_param('ssisiii', $resolution, $status, $teacherId, $status, $complaintId, $teacherId, $departmentId);
    if (!$updateStmt->execute()) throw new RuntimeException('Complaint update failed.');

    $commentStmt = $conn->prepare('INSERT INTO comments (complaint_id, user_id, comment_text) VALUES (?, ?, ?)');
    $commentStmt->bind_param('iis', $complaintId, $teacherId, $resolution);
    if (!$commentStmt->execute()) throw new RuntimeException('Conversation update failed.');

    $notificationStmt = $conn->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
    $notificationStmt->bind_param('is', $studentId, $notificationText);
    if (!$notificationStmt->execute()) throw new RuntimeException('Student notification failed.');
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Teacher resolution transaction failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The response could not be saved.');
}

record_audit($conn, 'teacher_resolution_sent', 'complaint', $complaintId, ['status' => $status, 'student_id' => $studentId]);
header('Location: complaints.php?updated=1');
exit();
