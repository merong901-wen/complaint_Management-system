<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['faculty_director']);
$facultyId = (int) $_SESSION['faculty_id'];
$directorId = (int) $_SESSION['user_id'];

$scope = "(a.user_id = ? OR a.acting_as_user_id = ?
    OR (a.entity_type = 'faculty' AND a.entity_id = ?)
    OR (a.entity_type = 'department' AND a.entity_id IN (SELECT id FROM departments WHERE faculty_id = ?))
    OR (a.entity_type = 'user' AND a.entity_id IN (SELECT u.id FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.faculty_id = ? OR d.faculty_id = ?))
    OR (a.entity_type = 'complaint' AND a.entity_id IN (SELECT c.id FROM complaints c JOIN departments d ON d.id = c.department_id WHERE d.faculty_id = ?))
    OR EXISTS (SELECT 1 FROM users actor_scope LEFT JOIN departments actor_department ON actor_department.id = actor_scope.department_id WHERE actor_scope.id = a.user_id AND (actor_scope.faculty_id = ? OR actor_department.faculty_id = ?)))";
$countStmt = $conn->prepare("SELECT COUNT(*) FROM audit_logs a WHERE $scope");
$countStmt->bind_param('iiiiiiiii', $directorId, $directorId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
$auditStmt = $conn->prepare("SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at, actor.username AS actor_username, effective.username AS effective_username FROM audit_logs a LEFT JOIN users actor ON actor.id = a.user_id LEFT JOIN users effective ON effective.id = a.acting_as_user_id WHERE $scope ORDER BY a.created_at DESC LIMIT ? OFFSET ?");
$auditStmt->bind_param('iiiiiiiiiii', $directorId, $directorId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId, $facultyId, $pagination['per_page'], $pagination['offset']);
$auditStmt->execute();
$events = $auditStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty audit log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.audit-panel{padding:20px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.audit-panel h1{font-size:24px}.table-responsive{overflow-x:auto}.pagination-nav{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px}.pagination{margin:0}.pagination-total{font-size:12px}@media(max-width:700px){.content{padding:20px 14px}}
    </style>
</head>
<body>
<main class="content"><section class="audit-panel"><h1>Faculty audit log</h1><p class="text-muted">Activity for this faculty, its departments, and their accounts.</p><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Account</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
    <?php while ($event = $events->fetch_assoc()): ?><tr><td><?= h($event['created_at']) ?></td><td><?= h($event['actor_username'] ?? 'Deleted account') ?><?php if ($event['effective_username']): ?><small class="d-block">as <?= h($event['effective_username']) ?></small><?php endif; ?></td><td><?= h(str_replace('_', ' ', $event['action'])) ?></td><td><?= h($event['entity_type'] ?? '') ?> <?= $event['entity_id'] ? '#' . (int) $event['entity_id'] : '' ?></td><td><?= h($event['details'] ?? '') ?></td><td><?= h($event['ip_address'] ?? '') ?></td></tr><?php endwhile; ?>
    <?php if ($total === 0): ?><tr><td colspan="6">No activity recorded for this faculty yet.</td></tr><?php endif; ?>
</tbody></table></div><?= pagination_links($pagination) ?></section></main>
</body>
</html>
