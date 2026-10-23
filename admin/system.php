<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $open = ($_POST['system_open'] ?? '') === '1' ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('system_open', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
    $stmt->bind_param('si', $open, $_SESSION['user_id']);
    $stmt->execute();
    record_audit($conn, $open === '1' ? 'system_opened' : 'system_closed', 'system', null);
    $notice = $open === '1' ? 'System is open for users.' : 'System is temporarily closed to non-admin users.';
}

$systemRow = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_open'")->fetch_assoc();
$systemOpen = ($systemRow['setting_value'] ?? '1') === '1';
$databaseStatus = $conn->ping() ? 'Connected' : 'Unavailable';
$openComplaints = (int) $conn->query("SELECT COUNT(*) FROM complaints WHERE LOWER(REPLACE(TRIM(status), ' ', '')) NOT IN ('resolved', 'closed')")->fetch_row()[0];
$pendingAccounts = (int) $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetch_row()[0];
$activeFaculties = (int) $conn->query('SELECT COUNT(*) FROM faculties WHERE is_active = 1')->fetch_row()[0];
$lastAudit = $conn->query('SELECT created_at FROM audit_logs ORDER BY created_at DESC LIMIT 1')->fetch_assoc();
include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System health</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.health-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:20px 0}.health-card{padding:18px;border:1px solid var(--system-blue);background:#fff;border-radius:14px;transition:transform .2s ease,box-shadow .2s ease}.health-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(20,45,75,.1)}.health-card strong{display:block;margin-top:6px;color:var(--system-blue);font-size:22px}.system-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:20px 0}.system-metric{padding:16px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);color:var(--system-text)}.system-metric strong{display:block;font-size:25px;color:var(--system-blue)}.system-actions{display:flex;flex-wrap:wrap;gap:10px}.system-footnote{color:var(--system-muted);font-size:13px}body.dark-mode .health-card{background:var(--system-surface)!important;color:var(--system-text)!important}@media(max-width:760px){.content{padding:18px 12px}.health-grid,.system-metrics{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="content">
    <h1 class="h3">System health</h1>
    <?php if ($notice): ?><div class="alert alert-info" role="status"><?= h($notice) ?></div><?php endif; ?>
    <section class="health-grid" aria-label="System status">
        <div class="health-card">Database connection<strong><span class="badge <?= $databaseStatus === 'Connected' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= h($databaseStatus) ?></span></strong><span class="system-footnote">Connection check performed when this page loaded.</span></div>
        <div class="health-card">User access<strong><span class="badge <?= $systemOpen ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $systemOpen ? 'Open' : 'Temporarily closed' ?></span></strong><span class="system-footnote">Admins can still access the system while it is closed.</span></div>
    </section>
    <section class="system-metrics" aria-label="Operational overview">
        <div class="system-metric"><span>Open complaints</span><strong><?= number_format($openComplaints) ?></strong><a href="admin_dashboard.php">View dashboard</a></div>
        <div class="system-metric"><span>Pending accounts</span><strong><?= number_format($pendingAccounts) ?></strong><a href="admin_registrations.php">Review registrations</a></div>
        <div class="system-metric"><span>Active faculties</span><strong><?= number_format($activeFaculties) ?></strong><a href="faculty_setup.php#registered-faculties">Manage faculties</a></div>
    </section>
    <div class="system-actions mb-3">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="system_open" value="<?= $systemOpen ? '0' : '1' ?>">
        <button class="btn btn-primary" type="submit"><?= $systemOpen ? 'Close system temporarily' : 'Reopen system' ?></button>
    </form>
    <a class="btn btn-outline-primary" href="audit_logs.php">Review audit logs</a>
    </div>
    <p class="system-footnote">Last recorded activity: <?= $lastAudit ? h($lastAudit['created_at']) : 'No audit events yet' ?> · Snapshot refreshed <?= h(date('H:i:s')) ?></p>
</main>
</body>
</html>
