<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$studentId = (int) $_SESSION['user_id'];
$counts = ['all' => 0, 'pending' => 0, 'inprogress' => 0, 'resolved' => 0, 'closed' => 0, 'comments' => 0];
$countStmt = $conn->prepare("SELECT COUNT(*) AS all_count,
    SUM(LOWER(REPLACE(TRIM(status), ' ', '')) = 'pending') AS pending_count,
    SUM(LOWER(REPLACE(TRIM(status), ' ', '')) = 'inprogress') AS inprogress_count,
    SUM(LOWER(REPLACE(TRIM(status), ' ', '')) = 'resolved') AS resolved_count,
    SUM(LOWER(REPLACE(TRIM(status), ' ', '')) = 'closed') AS closed_count
    FROM complaints WHERE user_id = ?");
$countStmt->bind_param('i', $studentId);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$counts['all'] = (int) ($countRow['all_count'] ?? 0);
$counts['pending'] = (int) ($countRow['pending_count'] ?? 0);
$counts['inprogress'] = (int) ($countRow['inprogress_count'] ?? 0);
$counts['resolved'] = (int) ($countRow['resolved_count'] ?? 0);
$counts['closed'] = (int) ($countRow['closed_count'] ?? 0);
$commentStmt = $conn->prepare('SELECT COUNT(*) FROM comments c JOIN complaints cp ON cp.id = c.complaint_id WHERE cp.user_id = ?');
$commentStmt->bind_param('i', $studentId);
$commentStmt->execute();
$counts['comments'] = (int) $commentStmt->get_result()->fetch_row()[0];
$progressCounts = ['pending' => $counts['pending'], 'inprogress' => $counts['inprogress'], 'resolved' => $counts['resolved'], 'closed' => $counts['closed']];

if (isset($_GET['progress'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($progressCounts);
    exit();
}
if (isset($_GET['summary'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($counts);
    exit();
}

$recentStmt = $conn->prepare('SELECT id, title, category, status, priority, created_at, updated_at FROM complaints WHERE user_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 6');
$recentStmt->bind_param('i', $studentId);
$recentStmt->execute();
$recentComplaints = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$displayName = (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Student');
$firstName = trim(explode(' ', $displayName)[0]);
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
include __DIR__ . '/user_sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
    <style>
        .content{padding:28px}.student-heading{position:relative;display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px;padding:24px 26px;border:1px solid var(--system-border);border-radius:16px;background:linear-gradient(120deg,var(--system-surface),rgba(139,124,246,.08));box-shadow:0 12px 30px rgba(20,45,75,.06)}.student-heading h1{margin:0;font-size:clamp(23px,3vw,32px);font-weight:700;letter-spacing:-.04em}.student-heading p{margin:7px 0 0;color:var(--system-muted)}.student-greeting{display:inline-block;margin-bottom:6px;color:var(--system-blue);font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.student-actions{display:flex;flex-wrap:wrap;gap:8px}.student-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.student-stat{position:relative;display:block;min-width:0;padding:18px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);color:var(--system-text)!important;text-decoration:none!important;box-shadow:0 5px 16px rgba(20,45,75,.04);transition:transform .18s ease,box-shadow .18s ease}.student-stat:hover{transform:translateY(-3px);box-shadow:0 12px 24px rgba(20,45,75,.1)}.student-stat-label{display:block;color:var(--system-muted);font-size:12px;font-weight:600}.student-stat-value{display:block;margin-top:7px;color:var(--system-text);font-size:30px;font-weight:700;line-height:1}.student-stat-hint{display:block;margin-top:8px;color:var(--system-muted);font-size:12px}.student-stat-meter{height:3px;margin-top:14px;overflow:hidden;border-radius:5px;background:var(--system-border)}.student-stat-meter span{display:block;width:var(--stat-percent,0%);height:100%;border-radius:inherit;background:var(--system-blue);transition:width .3s ease}.student-dashboard-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:16px}.student-panel{min-width:0;padding:20px;border:1px solid var(--system-border);border-radius:12px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.student-panel-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}.student-panel-heading h2{margin:0;font-size:17px}.student-panel-heading p{margin:4px 0 0;color:var(--system-muted);font-size:12px}.student-chart-wrap{position:relative;height:260px}.student-recent-list{display:grid;gap:8px}.student-complaint{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-page);color:var(--system-text)!important;text-decoration:none!important;transition:border-color .18s ease,transform .18s ease}.student-complaint:hover{transform:translateY(-2px);border-color:var(--system-blue)}.student-complaint h3{margin:0;font-size:13px;overflow-wrap:anywhere}.student-complaint p{margin:4px 0 0;color:var(--system-muted);font-size:12px}.student-complaint-status{padding:5px 8px;border-radius:5px;background:rgba(139,124,246,.12);color:var(--system-blue);font-size:12px;font-weight:700;white-space:nowrap}.student-empty{padding:24px 12px;text-align:center;color:var(--system-muted);font-size:13px}.student-empty a{display:inline-block;margin-top:10px}@media(max-width:1000px){.student-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.student-dashboard-grid{grid-template-columns:1fr}}@media(max-width:700px){.content{padding:20px 14px}.student-heading{align-items:flex-start;flex-direction:column;padding:20px}.student-stat-grid{gap:9px}.student-stat{padding:14px}.student-chart-wrap{height:230px}}
    </style>
</head>
<body>
<main class="content">
    <header class="student-heading">
        <div><span class="student-greeting"><?= h($greeting) ?> · <?= h(date('l, j F')) ?></span><h1>Welcome back, <?= h($firstName) ?></h1><p>Your cases and latest updates, all in one place.</p></div>
        <div class="student-actions"><a class="btn btn-primary" href="user_add_complaint.php">New complaint</a><a class="btn btn-outline-primary" href="department_head_messages.php">Head communication</a></div>
    </header>

    <section class="student-stat-grid" aria-label="Your complaint summary">
        <a class="student-stat dashboard-card-link" href="user_complaints.php"><span class="student-stat-label">Total complaints</span><strong class="student-stat-value" data-student-count="all"><?= number_format($counts['all']) ?></strong><span class="student-stat-hint">Open your complaint list</span></a>
        <a class="student-stat dashboard-card-link" href="user_complaints.php"><span class="student-stat-label">Awaiting response</span><strong class="student-stat-value" data-student-count="pending"><?= number_format($counts['pending']) ?></strong><span class="student-stat-hint">Submitted to your department</span></a>
        <a class="student-stat dashboard-card-link" href="user_complaints.php"><span class="student-stat-label">In progress</span><strong class="student-stat-value" data-student-count="inprogress"><?= number_format($counts['inprogress']) ?></strong><span class="student-stat-hint">Being handled by a teacher</span></a>
        <a class="student-stat dashboard-card-link" href="resolutions.php"><span class="student-stat-label">Resolved</span><strong class="student-stat-value" data-student-count="resolved"><?= number_format($counts['resolved']) ?></strong><span class="student-stat-hint">Review teacher resolutions</span></a>
    </section>

    <div class="student-dashboard-grid">
        <section class="student-panel" aria-labelledby="student-progress-title">
            <header class="student-panel-heading"><div><h2 id="student-progress-title">Complaint progress</h2><p>Status breakdown for your submissions</p></div><button type="button" class="btn btn-outline-primary btn-sm" id="user-progress-refresh" aria-label="Refresh complaint progress">Refresh</button></header>
            <?php if ($counts['all'] > 0): ?>
                <div class="student-chart-wrap"><canvas id="user-progress-chart" role="img" aria-label="Bar chart of your complaints by status"></canvas></div>
                <p class="progress-updated mb-0" id="user-progress-updated" role="status">Loading progress...</p>
            <?php else: ?>
                <div class="student-empty">Your status breakdown will appear here after your first complaint.<br><a class="btn btn-primary btn-sm" href="user_add_complaint.php">Create a complaint</a></div>
            <?php endif; ?>
        </section>
        <section class="student-panel" aria-labelledby="recent-complaints-title">
            <header class="student-panel-heading"><div><h2 id="recent-complaints-title">Recent complaints</h2><p>Your latest updates and case status</p></div><a class="btn btn-outline-primary btn-sm" href="user_complaints.php">View all</a></header>
            <div class="student-recent-list">
                <?php foreach ($recentComplaints as $complaint): ?>
                    <a class="student-complaint" href="user_complaints.php#complaint-row-<?= (int) $complaint['id'] ?>"><span><h3><?= h($complaint['title']) ?></h3><p><?= h($complaint['category']) ?> · Updated <?= h($complaint['updated_at'] ?: $complaint['created_at']) ?></p></span><span class="student-complaint-status"><?= h($complaint['status']) ?></span></a>
                <?php endforeach; ?>
                <?php if (!$recentComplaints): ?><div class="student-empty">You have not submitted a complaint yet.<br><a class="btn btn-primary btn-sm" href="user_add_complaint.php">Start a complaint</a></div><?php endif; ?>
            </div>
            <div class="d-flex justify-content-between gap-3 mt-3 pt-3 border-top" style="border-color:var(--system-border)!important"><span class="text-muted">Conversation updates</span><strong data-student-count="comments"><?= number_format($counts['comments']) ?></strong></div>
        </section>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initializeComplaintProgressChart('user-progress-chart', 'user_dashboard.php?progress=1', 'user-progress-updated', 'user-progress-refresh');
    async function refreshStudentSummary() {
        try {
            const response = await fetch('user_dashboard.php?summary=1', { cache: 'no-store' });
            if (!response.ok) return;
            const counts = await response.json();
            document.querySelectorAll('[data-student-count]').forEach(element => {
                element.textContent = Number(counts[element.dataset.studentCount] || 0).toLocaleString();
            });
        } catch (error) {
            return;
        }
    }
    window.setInterval(refreshStudentSummary, 60000);
});
</script>
</body>
</html>
