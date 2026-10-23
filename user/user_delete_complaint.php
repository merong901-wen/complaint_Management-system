<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
post_csrf_or_exit();

$id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("DELETE FROM complaints WHERE id=? AND user_id=?");
$stmt->bind_param("ii",$id,$user_id);

if($stmt->execute()){
	if ($stmt->affected_rows) record_audit($conn, 'student_complaint_deleted', 'complaint', $id);
	echo "success";
} else{ echo "Unable to delete complaint."; }