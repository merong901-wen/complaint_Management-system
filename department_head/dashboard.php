<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['department_head']);
$departmentId = (int) ($_SESSION['department_id'] ?? 0);

function department_count(mysqli $db, int $departmentId, string $where): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM $where");
    $stmt->bind_param('i', $departmentId);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

$counts = [
    'complaints' => department_count($conn, $departmentId, 'complaints WHERE department_id = ?'),
    'students' => department_count($conn, $departmentId, "users WHERE department_id = ? AND role = 'student' AND status = 'approved'"),
    'teachers' => department_count($conn, $departmentId, "users WHERE department_id = ? AND role = 'teacher' AND status = 'approved'"),
    'pending_students' => department_count($conn, $departmentId, "users WHERE department_id = ? AND role = 'student' AND status = 'pending'"),
];

$progressCounts = ['pending' => 0, 'inprogress' => 0, 'resolved' => 0, 'closed' => 0];
$statusStmt = $conn->prepare("SELECT LOWER(REPLACE(TRIM(status), ' ', '')) AS status_key, COUNT(*) AS total FROM complaints WHERE department_id = ? GROUP BY status_key");
$statusStmt->bind_param('i', $departmentId);
$statusStmt->execute();
$statusRows = $statusStmt->get_result();
while ($statusRow = $statusRows->fetch_assoc()) {
    if (array_key_exists($statusRow['status_key'], $progressCounts)) $progressCounts[$statusRow['status_key']] = (int) $statusRow['total'];
}
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

$departmentStmt = $conn->prepare('SELECT name FROM departments WHERE id = ?');
$departmentStmt->bind_param('i', $departmentId);
$departmentStmt->execute();
$departmentName = $departmentStmt->get_result()->fetch_assoc()['name'] ?? 'Department';
$recentStmt = $conn->prepare('SELECT c.id, c.title, c.status, c.priority, c.created_at, s.full_name AS student_name, t.full_name AS teacher_name FROM complaints c JOIN users s ON s.id = c.user_id LEFT JOIN users t ON t.id = c.teacher_id WHERE c.department_id = ? ORDER BY c.created_at DESC LIMIT 10');
$recentStmt->bind_param('i', $departmentId);
$recentStmt->execute();
$recent = $recentStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Department dashboard</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script><style>.content{padding:28px}.head-row{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:20px}.head-row h1{font-size:25px;margin:0}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}.stat{padding:18px;border:1px solid var(--system-blue);background:#fff}.stat span{display:block;font-size:12px}.stat strong{display:block;margin-top:6px;color:var(--system-blue);font-size:26px}.panel{padding:20px;border:1px solid var(--system-blue);background:#fff;margin-bottom:20px}.chart-wrap{position:relative;height:280px}.table-responsive{overflow-x:auto}body.dark-mode .stat,body.dark-mode .panel{background:#000!important;color:#fff!important}@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.content{padding:18px 12px}.head-row{align-items:flex-start;flex-direction:column}.stat-grid{gap:8px}.stat{padding:12px}}</style></head>
<body><main class="content"><header class="head-row"><div><h1><?= h($departmentName) ?> dashboard</h1><p class="mb-0">Complaint activity and registrations in your department.</p></div><span class="badge-status"><?= number_format($counts['pending_students']) ?> awaiting approval</span></header>
<section class="stat-grid"><div class="stat"><span>Complaints</span><strong data-department-count="complaints"><?= number_format($counts['complaints']) ?></strong></div><div class="stat"><span>Approved students</span><strong data-department-count="students"><?= number_format($counts['students']) ?></strong></div><div class="stat"><span>Teachers</span><strong data-department-count="teachers"><?= number_format($counts['teachers']) ?></strong></div><div class="stat"><span>Awaiting approval</span><strong data-department-count="pending_students"><?= number_format($counts['pending_students']) ?></strong></div></section>
<section class="panel"><h2 class="h5">Complaint status</h2><div class="chart-wrap"><canvas id="department-progress-chart" aria-label="Department complaint status doughnut chart"></canvas></div><p class="small mt-3 mb-0" id="department-progress-updated" role="status">Loading live progress...</p><button class="btn btn-primary btn-sm mt-2" id="department-progress-refresh" type="button">Refresh chart</button></section>
<section class="panel"><h2 class="h5">Recent department complaints</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Student</th><th>Complaint</th><th>Teacher</th><th>Status</th><th>Date</th></tr></thead><tbody><?php while($row=$recent->fetch_assoc()): ?><tr><td><?= h($row['student_name']) ?></td><td><?= h($row['title']) ?></td><td><?= h($row['teacher_name'] ?? 'Unassigned') ?></td><td><?= h($row['status']) ?></td><td><?= h($row['created_at']) ?></td></tr><?php endwhile; ?><?php if($counts['complaints']===0): ?><tr><td colspan="5">No complaints in this department.</td></tr><?php endif; ?></tbody></table></div></section></main><script>async function refreshDepartmentCounts(){try{const response=await fetch('dashboard.php?summary=1',{cache:'no-store'});if(!response.ok)return;const counts=await response.json();document.querySelectorAll('[data-department-count]').forEach(element=>{element.textContent=Number(counts[element.dataset.departmentCount]||0).toLocaleString();});}catch(error){}}window.setInterval(refreshDepartmentCounts,60000);document.addEventListener('DOMContentLoaded',()=>initializeComplaintProgressChart('department-progress-chart','dashboard.php?progress=1','department-progress-updated','department-progress-refresh','doughnut'));</script></body></html>
