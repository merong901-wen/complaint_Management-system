<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['teacher']);
$teacherId = (int) $_SESSION['user_id'];
$departmentId = (int) ($_SESSION['department_id'] ?? 0);
$counts = ['all' => 0, 'pending' => 0, 'inprogress' => 0, 'resolved' => 0, 'closed' => 0];
$countStmt = $conn->prepare("SELECT LOWER(REPLACE(TRIM(status), ' ', '')) AS status_key, COUNT(*) AS total FROM complaints WHERE teacher_id = ? AND department_id = ? GROUP BY status_key");
$countStmt->bind_param('ii', $teacherId, $departmentId);
$countStmt->execute();
$countResult = $countStmt->get_result();
while ($row = $countResult->fetch_assoc()) {
    $counts['all'] += (int) $row['total'];
    if (array_key_exists($row['status_key'], $counts)) $counts[$row['status_key']] = (int) $row['total'];
}
if (isset($_GET['progress'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['pending'=>$counts['pending'],'inprogress'=>$counts['inprogress'],'resolved'=>$counts['resolved'],'closed'=>$counts['closed']]);
    exit();
}
$recentStmt = $conn->prepare('SELECT c.title,c.status,c.created_at,s.full_name AS student_name FROM complaints c JOIN users s ON s.id=c.user_id WHERE c.teacher_id=? AND c.department_id=? ORDER BY c.created_at DESC LIMIT 10');
$recentStmt->bind_param('ii', $teacherId, $departmentId);
$recentStmt->execute();
$recent = $recentStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teacher dashboard</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script><style>.content{padding:28px}.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:20px 0}.stat,.panel{padding:18px;border:1px solid var(--system-blue);background:#fff}.stat span{display:block;font-size:12px}.stat strong{display:block;margin-top:6px;color:var(--system-blue);font-size:25px}.panel{margin-bottom:20px}.chart-wrap{position:relative;height:280px}body.dark-mode .stat,body.dark-mode .panel{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}.stat-grid{grid-template-columns:1fr}}</style></head><body><main class="content"><h1 class="h3">Teacher dashboard</h1><p>Live progress of complaints assigned to you.</p><section class="stat-grid"><div class="stat"><span>Assigned complaints</span><strong><?= number_format($counts['all']) ?></strong></div><div class="stat"><span>In progress</span><strong><?= number_format($counts['inprogress']) ?></strong></div><div class="stat"><span>Resolved</span><strong><?= number_format($counts['resolved']) ?></strong></div></section><section class="panel"><h2 class="h5">Complaint status</h2><div class="chart-wrap"><canvas id="teacher-progress-chart" aria-label="Your complaint progress"></canvas></div><p class="small mt-3 mb-0" id="teacher-progress-updated" role="status">Loading live progress...</p><button class="btn btn-primary btn-sm mt-2" id="teacher-progress-refresh" type="button">Refresh chart</button></section><section class="panel"><h2 class="h5">Recent assigned complaints</h2><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Student</th><th>Complaint</th><th>Status</th><th>Date</th></tr></thead><tbody><?php while($row=$recent->fetch_assoc()): ?><tr><td><?= h($row['student_name']) ?></td><td><?= h($row['title']) ?></td><td><?= h($row['status']) ?></td><td><?= h($row['created_at']) ?></td></tr><?php endwhile; ?><?php if($counts['all']===0): ?><tr><td colspan="4">No complaints have been assigned to you.</td></tr><?php endif; ?></tbody></table></div></section></main><script>document.addEventListener('DOMContentLoaded',()=>initializeComplaintProgressChart('teacher-progress-chart','dashboard.php?progress=1','teacher-progress-updated','teacher-progress-refresh'));</script></body></html>
