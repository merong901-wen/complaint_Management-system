<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

$auditTotal = (int) $conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
$pagination = pagination_data($auditTotal, (int) ($_GET['page'] ?? 1));
$auditStmt = $conn->prepare('SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at, actor.username AS actor_username, effective.username AS effective_username FROM audit_logs a LEFT JOIN users actor ON actor.id = a.user_id LEFT JOIN users effective ON effective.id = a.acting_as_user_id ORDER BY a.created_at DESC LIMIT ? OFFSET ?');
$auditStmt->bind_param('ii', $pagination['per_page'], $pagination['offset']);
$auditStmt->execute();
$auditRows = $auditStmt->get_result();
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.audit-panel{padding:18px;border:1px solid var(--system-blue);background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(31,65,105,.08)}.audit-panel .table{--bs-table-bg:transparent;--bs-table-color:var(--system-text);--bs-table-hover-bg:rgba(20,103,213,.06);--bs-table-hover-color:var(--system-text);color:var(--system-text)}.audit-panel .table td,.audit-panel .table th{color:var(--system-text);vertical-align:middle}.audit-panel .table thead th{background:var(--system-sidebar);color:var(--system-white)}.pagination-nav{display:flex;justify-content:space-between;align-items:center;margin-top:14px}.pagination{margin:0}body.dark-mode .audit-panel{background:var(--system-surface)!important;color:var(--system-text)!important}body.dark-mode .audit-panel .table{--bs-table-bg:transparent;--bs-table-color:var(--system-text);--bs-table-hover-bg:rgba(169,156,255,.12);--bs-table-hover-color:var(--system-text);color:var(--system-text)!important}body.dark-mode .audit-panel .table td,body.dark-mode .audit-panel .table td small{background-color:transparent;color:var(--system-text)!important}body.dark-mode .audit-panel .table thead th{background:var(--system-sidebar)!important;color:var(--system-white)!important}body.dark-mode .audit-panel .table tbody tr:hover>*{background:rgba(169,156,255,.12)!important;color:var(--system-text)!important}body.dark-mode .audit-panel .pagination .page-link{background:var(--system-surface);border-color:var(--system-border);color:var(--system-text)}@media(max-width:760px){.content{padding:18px 12px}}
    </style>
</head>
<body>
<main class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 class="h3 mb-1">Audit logs</h1><p class="text-muted mb-0">Review account actions and system events.</p></div><span class="badge text-bg-secondary"><?= number_format($auditTotal) ?> events</span></div>
    <section class="audit-panel table-responsive">
        <table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Account</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
        <?php while ($event = $auditRows->fetch_assoc()): ?><tr><td><?= h($event['created_at']) ?></td><td><?= h($event['actor_username'] ?? 'Deleted account') ?><?php if ($event['effective_username']): ?><small class="d-block">as <?= h($event['effective_username']) ?></small><?php endif; ?></td><td><?= h(str_replace('_', ' ', $event['action'])) ?></td><td><?= h($event['entity_type'] ?? '') ?> <?= $event['entity_id'] ? '#' . (int) $event['entity_id'] : '' ?></td><td><?= h($event['details'] ?? '') ?></td><td><?= h($event['ip_address'] ?? '') ?></td></tr><?php endwhile; ?>
        <?php if ($auditTotal === 0): ?><tr><td colspan="6" class="text-center text-muted py-4">No activity recorded yet.</td></tr><?php endif; ?>
        </tbody></table><?= pagination_links($pagination) ?>
    </section>
</main>
</body>
</html>
