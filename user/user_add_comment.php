<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
post_csrf_or_exit();

// ✅ Validate POST data
if(!isset($_POST['complaint_id'], $_POST['comment_text'])){
    exit("<script>showToast('Invalid request!', 4000, 'error');</script>");
}

$user_id = $_SESSION['user_id'];
$complaint_id = intval($_POST['complaint_id']);
$comment_text = trim($_POST['comment_text']);

$ownerStmt = $conn->prepare('SELECT id FROM complaints WHERE id = ? AND user_id = ?');
$ownerStmt->bind_param('ii', $complaint_id, $user_id);
$ownerStmt->execute();
if (!$ownerStmt->get_result()->fetch_row()) exit('Complaint not found.');

// ✅ Check empty comment
if($comment_text === ""){
    exit("<script>showToast('Comment cannot be empty!', 4000, 'warning');</script>");
}

// ✅ Optional: Limit comment length
$max_length = 1000;
if(strlen($comment_text) > $max_length){
    exit("<script>showToast('Comment is too long. Max {$max_length} characters.', 4000, 'warning');</script>");
}

// ✅ Insert comment safely
$stmt = $conn->prepare("
    INSERT INTO comments (complaint_id, user_id, comment_text, created_at) 
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param("iis", $complaint_id, $user_id, $comment_text);

if($stmt->execute()){
    record_audit($conn, 'student_comment_added', 'complaint', $complaint_id);
    echo "success"; // For AJAX, JS will handle showToast
} else {
    echo "<script>showToast('Error: ".$stmt->error."', 5000, 'error');</script>";
}
exit();