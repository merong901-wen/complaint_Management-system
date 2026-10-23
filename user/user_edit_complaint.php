<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id, title, description FROM complaints WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $id, $userId);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found.');
}
?>
<div class="modal-header">
    <h5 class="modal-title">Edit complaint</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
    <form id="editComplaintForm" action="user_update_complaint.php" method="post" onsubmit="return submitComplaintEdit(event, this)">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $complaint['id'] ?>">
        <div class="mb-3">
            <label for="edit-complaint-title" class="form-label">Title</label>
            <input id="edit-complaint-title" type="text" name="title" class="form-control" maxlength="255" value="<?= h($complaint['title']) ?>" required>
        </div>
        <div class="mb-3">
            <label for="edit-complaint-description" class="form-label">Description</label>
            <textarea id="edit-complaint-description" name="description" class="form-control" maxlength="2000" required><?= h($complaint['description']) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </form>
</div>
