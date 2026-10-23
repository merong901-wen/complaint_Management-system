<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
header('Content-Type: application/json');
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

// ✅ Validate POST data
if (!isset($_POST['id'], $_POST['title'], $_POST['description'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];

// Trim and sanitize inputs
$title = trim($_POST['title']);
$description = trim($_POST['description']);

if ($title === '' || $description === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Title and description cannot be empty.']);
    exit;
}

// Optional: Limit lengths
$max_title = 255;
$max_desc = 2000;
if (strlen($title) > $max_title) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => "Title too long (maximum {$max_title} characters)."]);
    exit;
}
if (strlen($description) > $max_desc) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => "Description too long (maximum {$max_desc} characters)."]);
    exit;
}

// ✅ Prepared statement to update complaint safely
$stmt = $conn->prepare("UPDATE complaints SET title=?, description=? WHERE id=? AND user_id=?");
$stmt->bind_param("ssii", $title, $description, $id, $user_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        record_audit($conn, 'student_complaint_updated', 'complaint', $id);
    } else {
        $existsStmt = $conn->prepare('SELECT id FROM complaints WHERE id = ? AND user_id = ?');
        $existsStmt->bind_param('ii', $id, $user_id);
        $existsStmt->execute();
        if (!$existsStmt->get_result()->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Complaint not found.']);
            exit;
        }
    }
    echo json_encode(['status' => 'success', 'message' => 'Complaint updated.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to update complaint.']);
}

$stmt->close();
$conn->close();
?>
