<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['teacher']);
$teacherId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);

if (isset($_GET['details'])) {
    header('Content-Type: application/json');
    $complaintId = (int) $_GET['details'];
    $detailStmt = $conn->prepare('SELECT c.id,c.title,c.category,c.description,c.status,c.priority,c.resolution,c.created_at,c.updated_at,s.id AS student_id,s.full_name AS student_name,s.student_number,s.email FROM complaints c JOIN users s ON s.id=c.user_id WHERE c.id=? AND c.teacher_id=? AND c.department_id=? LIMIT 1');
    $detailStmt->bind_param('iii', $complaintId, $teacherId, $departmentId);
    $detailStmt->execute();
    $complaint = $detailStmt->get_result()->fetch_assoc();
    if (!$complaint) { http_response_code(404); echo json_encode(['error'=>'Complaint not found.']); exit(); }
    record_audit($conn, 'assigned_complaint_viewed', 'complaint', $complaintId);
    $commentsStmt = $conn->prepare('SELECT c.comment_text,c.created_at,u.full_name,u.role FROM comments c JOIN users u ON u.id=c.user_id WHERE c.complaint_id=? ORDER BY c.created_at ASC');
    $commentsStmt->bind_param('i', $complaintId);
    $commentsStmt->execute();
    echo json_encode(['complaint'=>$complaint,'comments'=>$commentsStmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit();
}

$totalStmt = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE teacher_id=? AND department_id=?');
$totalStmt->bind_param('ii', $teacherId, $departmentId);
$totalStmt->execute();
$total = (int) $totalStmt->get_result()->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
$listStmt = $conn->prepare('SELECT c.id,c.title,c.category,c.status,c.priority,c.created_at,c.updated_at,s.full_name AS student_name,s.student_number FROM complaints c JOIN users s ON s.id=c.user_id WHERE c.teacher_id=? AND c.department_id=? ORDER BY c.created_at DESC LIMIT ? OFFSET ?');
$listStmt->bind_param('iiii', $teacherId, $departmentId, $pagination['per_page'], $pagination['offset']);
$listStmt->execute();
$complaints = $listStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Assigned complaints</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>.content{padding:28px}.table-panel{padding:16px;border:1px solid var(--system-blue);background:#fff}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}.conversation{max-height:260px;overflow:auto;padding:12px;border:1px solid var(--system-blue)}.conversation-entry{padding:10px 0;border-bottom:1px solid var(--system-blue)}.conversation-entry:last-child{border:0}body.dark-mode .table-panel{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}}</style></head>
<body><main class="content"><h1 class="h3">Assigned complaints</h1><p>Complaints submitted by approved students in your department.</p><section class="table-panel table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Student</th><th>Student ID</th><th>Complaint</th><th>Status</th><th>Priority</th><th>Submitted</th><th>Action</th></tr></thead><tbody><?php while($row=$complaints->fetch_assoc()): ?><tr><td><?= h($row['student_name']) ?></td><td><?= h($row['student_number']) ?></td><td><?= h($row['title']) ?><small class="d-block"><?= h($row['category']) ?></small></td><td><?= h($row['status']) ?></td><td><?= h($row['priority']) ?></td><td><?= h($row['created_at']) ?></td><td><button class="btn btn-primary btn-sm complaint-view" data-id="<?= (int)$row['id'] ?>" data-bs-toggle="modal" data-bs-target="#complaintDetailsModal">View / respond</button></td></tr><?php endwhile; ?><?php if($total===0): ?><tr><td colspan="7">No complaints are assigned to your account.</td></tr><?php endif; ?></tbody></table><?= pagination_links($pagination) ?></section></main>
<div class="modal fade" id="complaintDetailsModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Complaint details</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body" id="complaint-details-body">Loading...</div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script>
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const csrfToken=<?= json_encode(csrf_token()) ?>;
document.querySelectorAll('.complaint-view').forEach(button=>button.addEventListener('click',async()=>{const body=document.getElementById('complaint-details-body');body.textContent='Loading...';const response=await fetch(`complaints.php?details=${button.dataset.id}`);const data=await response.json();if(!response.ok){body.textContent=data.error||'Could not load complaint.';return;}const item=data.complaint;const comments=data.comments.map(comment=>`<div class="conversation-entry"><strong>${esc(comment.full_name)} · ${esc(comment.role)}</strong><small class="d-block">${esc(comment.created_at)}</small><div>${esc(comment.comment_text)}</div></div>`).join('');body.innerHTML=`<dl class="row"><dt class="col-sm-3">Student</dt><dd class="col-sm-9">${esc(item.student_name)} · ID ${esc(item.student_number)} · ${esc(item.student_id)}</dd><dt class="col-sm-3">Email</dt><dd class="col-sm-9">${esc(item.email)}</dd><dt class="col-sm-3">Submitted</dt><dd class="col-sm-9">${esc(item.created_at)}</dd><dt class="col-sm-3">Complaint</dt><dd class="col-sm-9"><strong>${esc(item.title)}</strong> · ${esc(item.category)}<br>${esc(item.description)}</dd></dl><h3 class="h6">Conversation</h3><div class="conversation">${comments||'<p class="mb-0">No comments yet.</p>'}</div><form method="post" action="resolve.php" class="mt-3"><input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="complaint_id" value="${Number(item.id)}"><label class="form-label" for="teacher-resolution">Response / solution</label><textarea id="teacher-resolution" name="resolution" class="form-control mb-3" rows="4" required>${esc(item.resolution)}</textarea><label class="form-label" for="teacher-status">Status</label><select id="teacher-status" name="status" class="form-select mb-3"><option ${item.status==='In Progress'?'selected':''}>In Progress</option><option ${item.status==='Resolved'?'selected':''}>Resolved</option><option ${item.status==='Closed'?'selected':''}>Closed</option></select><button class="btn btn-primary" type="submit">Send solution</button></form>`;}));
</script></body></html>
