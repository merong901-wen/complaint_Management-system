<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
post_csrf_or_exit();

// ✅ Validate POST data
if(!isset($_POST['id'])) {
    exit("<script>showToast('Invalid request!', 4000, 'error');</script>");
}

$id = intval($_POST['id']);

if($stmt = $conn->prepare('DELETE FROM complaints WHERE id = ?')){
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) record_audit($conn, 'admin_complaint_deleted', 'complaint', $id);
    // Use success toast for AJAX
    echo "success";
} else {
    http_response_code(500);
    echo 'Unable to delete complaint.';
}