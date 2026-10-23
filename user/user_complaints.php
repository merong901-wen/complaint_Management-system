<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$userId = (int) $_SESSION['user_id'];
$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);

$searchPattern = '%' . $search . '%';
if ($search !== '') {
    $totalStmt = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE user_id = ? AND (title LIKE ? OR category LIKE ? OR status LIKE ? OR priority LIKE ? OR CAST(id AS CHAR) LIKE ?)");
    $totalStmt->bind_param('isssss', $userId, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
} else {
    $totalStmt = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE user_id = ?');
    $totalStmt->bind_param('i', $userId);
}
$totalStmt->execute();
$total = (int) $totalStmt->get_result()->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
if ($search !== '') {
    $listStmt = $conn->prepare("SELECT c.id,c.title,c.category,c.status,c.priority,c.created_at,c.updated_at,t.full_name AS teacher_name FROM complaints c LEFT JOIN users t ON t.id = c.teacher_id WHERE c.user_id = ? AND (c.title LIKE ? OR c.category LIKE ? OR c.status LIKE ? OR c.priority LIKE ? OR CAST(c.id AS CHAR) LIKE ?) ORDER BY c.created_at DESC LIMIT ? OFFSET ?");
    $listStmt->bind_param('isssssii', $userId, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $pagination['per_page'], $pagination['offset']);
} else {
    $listStmt = $conn->prepare('SELECT c.id,c.title,c.category,c.status,c.priority,c.created_at,c.updated_at,t.full_name AS teacher_name FROM complaints c LEFT JOIN users t ON t.id = c.teacher_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT ? OFFSET ?');
    $listStmt->bind_param('iii', $userId, $pagination['per_page'], $pagination['offset']);
}
$listStmt->execute();
$complaints = $listStmt->get_result();
$unreadStmt = $conn->prepare('SELECT id,message,created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5');
$unreadStmt->bind_param('i', $userId);
$unreadStmt->execute();
$notifications = $unreadStmt->get_result();
include __DIR__ . '/user_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>My complaints</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>.content{padding:28px}.page-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.table-panel{padding:16px;border:1px solid var(--system-blue);background:#fff}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}.notice-list{padding:12px;border-left:3px solid var(--system-blue);margin:10px 0;background:#fff}body.dark-mode .table-panel,body.dark-mode .notice-list{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}.page-heading{align-items:flex-start;flex-direction:column}}</style></head><body>
<main class="content"><header class="page-heading"><div><h1 class="h3 mb-1">My complaints</h1><p class="mb-0">Track every complaint sent to your department teachers.</p></div><a class="btn btn-primary" href="user_add_complaint.php">New complaint</a></header>
<form class="complaint-search mb-3" method="get" role="search"><label class="visually-hidden" for="complaint-search">Search your complaints</label><input id="complaint-search" class="form-control" type="search" name="search" value="<?= h($search) ?>" placeholder="Search by ticket number, title, status, or category"><button class="btn btn-primary" type="submit">Search</button><?php if ($search !== ''): ?><a class="btn btn-outline-secondary" href="user_complaints.php">Clear</a><?php endif; ?></form>
<?php while($notification=$notifications->fetch_assoc()): ?><div class="notice-list"><strong>Update</strong> · <?= h($notification['created_at']) ?><br><?= h($notification['message']) ?></div><?php endwhile; ?>
<section class="table-panel table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Complaint</th><th>Teacher</th><th>Status</th><th>Priority</th><th>Submitted</th><th>Actions</th></tr></thead><tbody>
<?php while($row=$complaints->fetch_assoc()): ?><tr id="complaint-row-<?= (int)$row['id'] ?>"><td><?= h($row['title']) ?><small class="d-block"><?= h($row['category']) ?></small></td><td><?= h($row['teacher_name'] ?? 'Unassigned') ?></td><td><?= h($row['status']) ?></td><td><?= h($row['priority']) ?></td><td><?= h($row['created_at']) ?></td><td class="text-nowrap"><button type="button" class="btn btn-primary btn-sm view-complaint" data-id="<?= (int)$row['id'] ?>">View</button> <button type="button" class="btn btn-primary btn-sm edit-complaint" data-id="<?= (int)$row['id'] ?>">Edit</button> <button type="button" class="btn btn-secondary btn-sm delete-complaint" data-id="<?= (int)$row['id'] ?>">Delete</button></td></tr><?php endwhile; ?>
<?php if($total===0): ?><tr><td colspan="6"><?= $search !== '' ? 'No complaints match “' . h($search) . '”.' : 'No complaints submitted yet.' ?></td></tr><?php endif; ?></tbody></table><?= pagination_links($pagination) ?></section></main>
<div class="modal fade" id="complaintModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content" id="modalContent"></div></div></div>
<form id="csrf-holder" class="d-none"><?= csrf_field() ?></form>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const complaintModal = new bootstrap.Modal(document.getElementById('complaintModal'));
async function submitComplaintEdit(event, form) {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Unable to update this complaint.');
        showToast(result.message || 'Complaint updated.', 3000, 'success');
        complaintModal.hide();
        window.setTimeout(() => window.location.reload(), 250);
    } catch (error) {
        showToast(error.message || 'Unable to update this complaint.', 4000, 'error');
        if (submit) submit.disabled = false;
    }
    return false;
}
document.addEventListener('click', async event => {
    const view = event.target.closest('.view-complaint');
    const edit = event.target.closest('.edit-complaint');
    const remove = event.target.closest('.delete-complaint');
    if (view || edit) {
        const id = (view || edit).dataset.id;
        try {
            const response = await fetch(`${view ? 'user_view_complaint.php' : 'user_edit_complaint.php'}?id=${encodeURIComponent(id)}`);
            const content = await response.text();
            if (!response.ok) throw new Error(content || 'Unable to load this complaint.');
            document.getElementById('modalContent').innerHTML = content;
            complaintModal.show();
        } catch (error) {
            showToast(error.message || 'Unable to load this complaint.', 4000, 'error');
        }
    }
    if (remove) {
        if (!confirm('Delete this complaint?')) return;
        remove.disabled = true;
        const body = new URLSearchParams({ id: remove.dataset.id, csrf_token: document.querySelector('#csrf-holder [name=csrf_token]').value });
        try {
            const response = await fetch('user_delete_complaint.php', { method: 'POST', body });
            const result = (await response.text()).trim();
            if (!response.ok || result !== 'success') throw new Error(result || 'Unable to delete this complaint.');
            document.getElementById(`complaint-row-${remove.dataset.id}`)?.remove();
            showToast('Complaint deleted.', 3000, 'success');
        } catch (error) {
            remove.disabled = false;
            showToast(error.message || 'Unable to delete this complaint.', 4000, 'error');
        }
    }
});
document.addEventListener('submit', async event => {
    const form = event.target;
    if (form.id !== 'addCommentForm') return;
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    try {
        const response = await fetch('user_add_comment.php', { method: 'POST', body: new FormData(form) });
        const result = (await response.text()).trim();
        if (!response.ok || result !== 'success') throw new Error(result || 'Unable to add your comment.');
        showToast('Comment added.', 3000, 'success');
        complaintModal.hide();
        location.reload();
    } catch (error) {
        showToast(error.message || 'Something went wrong. Please try again.', 4000, 'error');
        if (submit) submit.disabled = false;
    }
});
</script>
</body></html>
