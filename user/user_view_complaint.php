<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);

$user_id = $_SESSION['user_id'];
$complaint_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if(!$complaint_id){
    echo "Invalid Complaint ID"; exit();
}

// ✅ Fetch complaint
$stmt = $conn->prepare("
SELECT complaints.*, users.username, users.full_name AS student_name, users.student_number, teachers.full_name AS teacher_name
FROM complaints
JOIN users ON complaints.user_id = users.id
LEFT JOIN users teachers ON complaints.teacher_id = teachers.id
WHERE complaints.id = ? AND complaints.user_id = ?
");
$stmt->bind_param("ii", $complaint_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$complaint = $result->fetch_assoc();

if(!$complaint){
    echo "Complaint not found"; exit();
}
record_audit($conn, 'complaint_viewed', 'complaint', (int) $complaint_id);

$comments_stmt = $conn->prepare('SELECT comments.*, users.username, users.full_name, users.role FROM comments LEFT JOIN users ON comments.user_id = users.id WHERE comments.complaint_id = ? ORDER BY comments.id ASC');
$comments_stmt->bind_param('i', $complaint_id);
$comments_stmt->execute();
$comments_result = $comments_stmt->get_result();

$comments = [];
if($comments_result){
    while($c = $comments_result->fetch_assoc()){
        $comments[] = $c;
    }
}
?>

<div class="modal-header">
    <h5>Complaint Details</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

    <p><strong>Student:</strong> <?= h($complaint['student_name'] ?: $complaint['username']) ?> (<?= h($complaint['student_number']) ?>)</p>
    <p><strong>Assigned teacher:</strong> <?= h($complaint['teacher_name'] ?? 'Unassigned') ?></p>
    <p><strong>Title:</strong> <?= h($complaint['title']) ?></p>
    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($complaint['description'])) ?></p>

    <p><strong>Status:</strong> 
        <span class="badge-status <?= strtolower(str_replace(' ','',$complaint['status'])) ?>">
            <?= $complaint['status'] ?>
        </span>
    </p>

    <p><strong>Priority:</strong> 
        <span class="badge-status <?= strtolower($complaint['priority']) ?>">
            <?= $complaint['priority'] ?>
        </span>
    </p>

    <!-- ✅ SHOW ADMIN RESOLUTION -->
    <hr>
    <h6>Teacher solution</h6>
    <?php if(!empty($complaint['resolution'])): ?>
        <div class="alert alert-success">
            <?= nl2br(htmlspecialchars($complaint['resolution'])) ?>
        </div>
    <?php else: ?>
        <p>No resolution yet.</p>
    <?php endif; ?>

    <hr>

    <!-- ✅ COMMENTS -->
    <h6>Comments</h6>

    <?php if(!empty($comments)): ?>
        <ul class="list-group">
        <?php foreach($comments as $comment): ?>
            <li class="list-group-item">
                <strong><?= h($comment['full_name'] ?: $comment['username'] ?? 'User') ?> (<?= h($comment['role'] ?? 'user') ?>):</strong><br>
                <?= htmlspecialchars($comment['comment_text']) ?>
                <small class="text-muted float-end"><?= $comment['created_at'] ?></small>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No comments yet.</p>
    <?php endif; ?>

    <hr>

    <!-- ✅ ADD COMMENT FORM (FIXED) -->
    <form id="addCommentForm">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label>Add Comment:</label>
            <textarea name="comment_text" class="form-control" required></textarea>
        </div>

        <input type="hidden" name="complaint_id" value="<?= $complaint_id ?>">

        <button type="submit" class="btn btn-primary">Submit</button>
    </form>

</div>

<script>
$(document).on('submit','#addCommentForm', function(e){
    e.preventDefault();

    $.ajax({
        url: 'user_add_comment.php',
        type: 'POST',
        data: $(this).serialize(),
        success: function(res){
            if(res.trim() === 'success'){
                alert('Comment added successfully!');
                location.reload();
            } else {
                alert(res);
            }
        }
    });
});
</script>