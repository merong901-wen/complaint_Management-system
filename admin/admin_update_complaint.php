<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
post_csrf_or_exit();

// ✅ Validate POST data
if(!isset($_POST['id'], $_POST['status'], $_POST['priority'], $_POST['resolution'])){
    exit("Missing data");
}

$id = intval($_POST['id']);
$status = trim($_POST['status']);
$priority = trim($_POST['priority']);
$resolution = trim($_POST['resolution']);

// ✅ Optional: Validate allowed status and priority values
$allowed_status = ['Pending','In Progress','Resolved','Closed'];
$allowed_priority = ['High','Medium','Low'];

if(!in_array($status, $allowed_status) || !in_array($priority, $allowed_priority)){
    exit("Invalid status or priority");
}

// ✅ Prepared statement to update complaint
$adminId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("UPDATE complaints SET status=?, priority=?, resolution=?, resolution_by=?, resolved_at=CASE WHEN ?='Resolved' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=?");
$stmt->bind_param("sssisi", $status, $priority, $resolution, $adminId, $status, $id);

if($stmt->execute()){
    record_audit($conn, 'admin_complaint_updated', 'complaint', $id, ['status' => $status, 'priority' => $priority]);
    echo "success";
} else {
    echo $stmt->error;
}