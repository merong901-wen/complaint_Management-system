<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

if(!isset($_GET['id']) || empty($_GET['id'])){
    die("Invalid request");
}

$id = intval($_GET['id']); // ensure integer

// ==========================
// FETCH COMPLAINT DETAILS
// ==========================
$stmt = $conn->prepare("
    SELECT complaints.*, users.username 
    FROM complaints 
    JOIN users ON complaints.user_id = users.id
    WHERE complaints.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if(!$result || $result->num_rows == 0){
    die("Complaint not found");
}

$data = $result->fetch_assoc();
$stmt->close();
record_audit($conn, 'complaint_viewed', 'complaint', $id);

// ==========================
// FETCH COMMENTS SECURELY
// ==========================
$stmt = $conn->prepare("
    SELECT comments.*, users.username 
    FROM comments
    LEFT JOIN users ON comments.user_id = users.id
    WHERE comments.complaint_id = ?
    ORDER BY comments.id ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$comments = $stmt->get_result();
$stmt->close();
?>

<div class="modal-header">
    <h5 class="modal-title">Complaint #<?= $data['id'] ?> Details</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<form id="updateComplaintForm">
    <?= csrf_field() ?>

    <p><strong>User:</strong> <?= htmlspecialchars($data['username']) ?></p>
    <p><strong>Title:</strong> <?= htmlspecialchars($data['title']) ?></p>
    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($data['description'])) ?></p>

    <div class="mb-3">
        <label>Status</label>
        <select name="status" class="form-control">
            <option <?= $data['status']=='Pending'?'selected':'' ?>>Pending</option>
            <option <?= $data['status']=='In Progress'?'selected':'' ?>>In Progress</option>
            <option <?= $data['status']=='Resolved'?'selected':'' ?>>Resolved</option>
            <option <?= $data['status']=='Closed'?'selected':'' ?>>Closed</option>
        </select>
    </div>

    <div class="mb-3">
        <label>Priority</label>
        <select name="priority" class="form-control">
            <option <?= $data['priority']=='High'?'selected':'' ?>>High</option>
            <option <?= $data['priority']=='Medium'?'selected':'' ?>>Medium</option>
            <option <?= $data['priority']=='Low'?'selected':'' ?>>Low</option>
        </select>
    </div>

    <div class="mb-3">
        <label>Resolution</label>
        <textarea name="resolution" class="form-control"><?= htmlspecialchars($data['resolution']) ?></textarea>
    </div>

    <input type="hidden" name="id" value="<?= $data['id'] ?>">
    <button type="submit" class="btn btn-success">Save Changes</button>
</form>

<hr>

<!-- COMMENTS SECTION -->
<h6>Comments</h6>
<?php if($comments && $comments->num_rows > 0): ?>
    <ul class="list-group">
    <?php while($c = $comments->fetch_assoc()): ?>
        <li class="list-group-item">
            <strong><?= htmlspecialchars($c['username'] ?? 'User') ?>:</strong><br>
            <?= nl2br(htmlspecialchars($c['comment_text'])) ?>
            <small class="text-muted float-end"><?= $c['created_at'] ?></small>
        </li>
    <?php endwhile; ?>
    </ul>
<?php else: ?>
    <p>No comments yet.</p>
<?php endif; ?>

</div>

<script>
$(document).on('submit','#updateComplaintForm', function(e){
    e.preventDefault();

   $.post('admin_update_complaint.php', $(this).serialize(), function(res){
    res = res.trim();
    if(res=='success'){
        showToast('Complaint updated successfully!', 3000, 'success');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('complaintModal')).hide();
        setTimeout(()=>{ location.reload(); }, 500);
    } else {
        showToast(res, 4000, 'error');
    }
});
});
</script>