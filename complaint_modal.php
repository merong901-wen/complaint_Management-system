<?php
require_once __DIR__ . '/includes/app.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$type = (string) ($_GET['type'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');
$is_admin = $role === 'admin';

if (!$id || !in_array($type, ['view', 'edit', 'delete'], true)) {
    http_response_code(400);
    exit('Invalid complaint request.');
}

if ($is_admin) {
    require_roles(['admin']);
    $stmt = $conn->prepare('SELECT * FROM complaints WHERE id = ?');
    $stmt->bind_param('i', $id);
} elseif (in_array($role, ['student', 'user'], true)) {
    require_roles(['student']);
    $stmt = $conn->prepare('SELECT * FROM complaints WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
} elseif ($role === 'teacher') {
    require_roles(['teacher']);
    if ($type !== 'view') {
        http_response_code(403);
        exit('Teachers can only view assigned complaints here.');
    }
    $departmentId = (int) ($_SESSION['department_id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM complaints WHERE id = ? AND teacher_id = ? AND department_id = ?');
    $stmt->bind_param('iii', $id, $userId, $departmentId);
} else {
    require_roles(['admin']);
}

$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found.');
}
record_audit($conn, 'complaint_viewed', 'complaint', $id);

// Fetch comments
$comment_stmt = $conn->prepare("
    SELECT comments.*, users.username 
    FROM comments 
    JOIN users ON comments.user_id = users.id 
    WHERE complaint_id=? 
    ORDER BY created_at ASC
");
$comment_stmt->bind_param("i", $id);
$comment_stmt->execute();
$comments = $comment_stmt->get_result();
?>

<?php if($type=='view'): ?>

<div class="modal-header">
    <h5>Complaint Detail</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

    <p><b>Title:</b> <?= htmlspecialchars($complaint['title']) ?></p>
    <p><b>Description:</b> <?= nl2br(htmlspecialchars($complaint['description'])) ?></p>
    <p><b>Status:</b> <?= $complaint['status'] ?></p>
    <p><b>Priority:</b> <?= $complaint['priority'] ?></p>

    <!-- ✅ SHOW ADMIN RESOLUTION -->
    <hr>
    <h6>Admin Resolution:</h6>
    <?php if(!empty($complaint['resolution'])): ?>
        <div class="alert alert-success">
            <?= nl2br(htmlspecialchars($complaint['resolution'])) ?>
        </div>
    <?php else: ?>
        <div class="text-muted">No resolution yet</div>
    <?php endif; ?>

    <!-- ✅ COMMENTS -->
    <hr>
    <h6>User Comments:</h6>
    <div id="commentSection">
        <?php while($c = $comments->fetch_assoc()): ?>
            <p>
                <b><?= htmlspecialchars($c['username']) ?>:</b> 
                <?= nl2br(htmlspecialchars($c['comment_text'])) ?>
                <small class="text-muted">(<?= $c['created_at'] ?>)</small>
            </p>
        <?php endwhile; ?>
    </div>

    <!-- ✅ USER CAN ADD COMMENT -->
    <?php if(in_array($role, ['student', 'user'], true)): ?>
        <textarea id="newComment" class="form-control" placeholder="Add a comment..."></textarea>
        <button class="btn btn-primary mt-2" onclick="addComment(<?= $complaint['id'] ?>)">Add Comment</button>
    <?php endif; ?>

    <!-- ✅ ADMIN FULL CONTROL -->
    <?php if($is_admin): ?>
        <hr>

        <form id="adminUpdateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $complaint['id'] ?>">

            <label>Status</label>
            <select name="status" class="form-control">
                <option value="Pending" <?= $complaint['status']=='Pending'?'selected':'' ?>>Pending</option>
                <option value="In Progress" <?= $complaint['status']=='In Progress'?'selected':'' ?>>In Progress</option>
                <option value="Resolved" <?= $complaint['status']=='Resolved'?'selected':'' ?>>Resolved</option>
            </select>

            <label class="mt-2">Priority</label>
            <select name="priority" class="form-control">
                <option value="Low" <?= $complaint['priority']=='Low'?'selected':'' ?>>Low</option>
                <option value="Medium" <?= $complaint['priority']=='Medium'?'selected':'' ?>>Medium</option>
                <option value="High" <?= $complaint['priority']=='High'?'selected':'' ?>>High</option>
            </select>

            <label class="mt-2">Resolution</label>
            <textarea name="resolution" class="form-control"><?= htmlspecialchars($complaint['resolution']) ?></textarea>

            <button type="button" class="btn btn-success mt-2" onclick="updateAdmin()">Save Changes</button>
        </form>
    <?php endif; ?>

</div>

<?php elseif($type=='edit'): ?>

<div class="modal-header">
    <h5>Edit Complaint</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <form id="editForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $complaint['id'] ?>">

        <label>Title</label>
        <input class="form-control" name="title" value="<?= htmlspecialchars($complaint['title']) ?>">

        <label>Description</label>
        <textarea class="form-control" name="description"><?= htmlspecialchars($complaint['description']) ?></textarea>

        <button type="button" class="btn btn-primary mt-2" onclick="saveComplaint()">Update</button>
    </form>
</div>

<?php elseif($type=='delete'): ?>

<div class="modal-header bg-danger text-white">
    <h5>Confirm Delete</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    Are you sure you want to delete this complaint?
</div>

<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">No</button>
    <button class="btn btn-danger" onclick="deleteComplaint(<?= $complaint['id'] ?>)">Yes</button>
</div>

<?php endif; ?>