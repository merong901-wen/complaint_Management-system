<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

function admin_dashboard_count(mysqli $db, string $query): int
{
    return (int) ($db->query($query)->fetch_row()[0] ?? 0);
}

$counts = [
    'complaints' => admin_dashboard_count($conn, 'SELECT COUNT(*) FROM complaints'),
    'students' => admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'approved'"),
    'pending' => admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'pending'"),
    'teachers' => admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'approved'"),
    'heads' => admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'department_head' AND status = 'approved'"),
    'departments' => admin_dashboard_count($conn, 'SELECT COUNT(*) FROM departments WHERE is_active = 1'),
    'faculties' => admin_dashboard_count($conn, 'SELECT COUNT(*) FROM faculties WHERE is_active = 1'),
    'directors' => admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'faculty_director' AND status = 'approved'"),
];

$progressCounts = ['pending' => 0, 'inprogress' => 0, 'resolved' => 0, 'closed' => 0];
$statusResult = $conn->query("SELECT LOWER(REPLACE(TRIM(status), ' ', '')) AS status_key, COUNT(*) AS total FROM complaints GROUP BY status_key");
while ($statusRow = $statusResult->fetch_assoc()) {
    if (array_key_exists($statusRow['status_key'], $progressCounts)) {
        $progressCounts[$statusRow['status_key']] = (int) $statusRow['total'];
    }
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

include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
    <style>
        .content { padding: 28px; }
        .dashboard-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:22px; }
        .dashboard-heading h1 { margin:0; font-size:26px; }
        .dashboard-heading p { margin:5px 0 0; font-size:13px; }
        .system-stat-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:22px; }
        .system-stat { padding:18px; border:1px solid var(--system-border); border-radius:8px; background:var(--system-surface); box-shadow:0 8px 24px rgba(20,45,75,.05); }
        .system-stat span { display:block; font-size:12px; }
        .system-stat strong { display:block; margin-top:6px; color:var(--system-blue); font-size:27px; }
        .dashboard-grid { display:grid; grid-template-columns:minmax(260px,.8fr) minmax(0,1.2fr); gap:18px; margin-bottom:24px; }
        .dashboard-panel { min-width:0; padding:20px; border:1px solid var(--system-border); border-radius:8px; background:var(--system-surface); box-shadow:0 8px 24px rgba(20,45,75,.05); }
        .dashboard-panel h2 { margin:0 0 16px; font-size:18px; }
        .admin-chart-wrap { position:relative; height:280px; }
        .table-responsive { overflow-x:auto; }
        .pagination-nav { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:14px; }
        .pagination { margin:0; }
        .pagination-total { font-size:12px; }
        .status-badge { display:inline-block; padding:6px 10px; border:1px solid var(--system-border); border-radius:5px; background:var(--system-surface); font-size:12px; }
        .role-dashboard-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .role-dashboard-item { padding:14px; border:1px solid var(--system-border); border-radius:6px; background:var(--system-page); }
        .role-dashboard-item strong { display:block; font-size:14px; }
        .role-dashboard-item span { color:var(--system-muted); font-size:12px; }
        @media(max-width:900px){.system-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.dashboard-grid{grid-template-columns:1fr;}}
        @media(max-width:600px){.content{padding:18px 12px;}.system-stat-grid{gap:8px;}.system-stat{padding:12px;}.dashboard-heading{align-items:flex-start;flex-direction:column;}.role-dashboard-list{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<main class="content">
    <header class="dashboard-heading">
        <div><h1>System dashboard</h1><p>Live service health and role-level activity.</p><div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-primary btn-sm" href="admin_complaints.php">Manage all complaints</a><a class="btn btn-outline-primary btn-sm" href="faculty_setup.php#registered-faculties">Manage faculties</a></div></div>
        <span class="status-badge">System <?= $counts['departments'] ? 'ready' : 'needs setup' ?></span>
    </header>
    <section class="system-stat-grid" aria-label="System totals">
        <a class="system-stat system-stat-card" href="admin_complaints.php"><span>Complaints</span><strong data-system-count="complaints"><?= number_format($counts['complaints']) ?></strong></a>
        <a class="system-stat system-stat-card" href="admin_complaints.php"><span>Approved students</span><strong data-system-count="students"><?= number_format($counts['students']) ?></strong></a>
        <a class="system-stat system-stat-card" href="admin_complaints.php"><span>Awaiting approval</span><strong data-system-count="pending"><?= number_format($counts['pending']) ?></strong></a>
        <a class="system-stat system-stat-card" href="admin_complaints.php"><span>Teachers</span><strong data-system-count="teachers"><?= number_format($counts['teachers']) ?></strong></a>
        <a class="system-stat system-stat-card" href="faculty_setup.php"><span>Department heads</span><strong data-system-count="heads"><?= number_format($counts['heads']) ?></strong></a>
        <a class="system-stat system-stat-card" href="faculty_setup.php"><span>Departments</span><strong data-system-count="departments"><?= number_format($counts['departments']) ?></strong></a>
        <a class="system-stat system-stat-card" href="faculty_setup.php#registered-faculties"><span>Faculties</span><strong data-system-count="faculties"><?= number_format($counts['faculties']) ?></strong></a>
        <a class="system-stat system-stat-card" href="faculty_setup.php"><span>Faculty directors</span><strong data-system-count="directors"><?= number_format($counts['directors']) ?></strong></a>
    </section>

    <section class="dashboard-grid">
        <div class="dashboard-panel">
            <h2>Complaint status</h2>
            <div class="admin-chart-wrap"><canvas id="admin-progress-chart" aria-label="Complaint status doughnut chart"></canvas></div>
            <p id="admin-progress-updated" class="small mt-3 mb-0" role="status">Loading live status...</p>
            <button type="button" class="btn btn-primary btn-sm mt-2" id="admin-progress-refresh">Refresh chart</button>
        </div>
        <div class="dashboard-panel">
            <h2>Role dashboards</h2>
            <div class="role-dashboard-list" aria-label="Dashboards available in the system">
                <a class="role-dashboard-item" href="faculty_setup.php"><strong>Faculty director dashboard</strong><span><b data-role-count="directors"><?= number_format($counts['directors']) ?></b> director accounts · Faculty setup, departments, heads, and audit log</span></a>
                <a class="role-dashboard-item" href="faculty_setup.php"><strong>Department head dashboard</strong><span><b data-role-count="heads"><?= number_format($counts['heads']) ?></b> department heads · Teachers, student approvals, and complaints</span></a>
                <a class="role-dashboard-item" href="admin_complaints.php"><strong>Teacher dashboard</strong><span><b data-role-count="teachers"><?= number_format($counts['teachers']) ?></b> teacher accounts · Assigned complaints and resolutions</span></a>
                <a class="role-dashboard-item" href="admin_complaints.php"><strong>Student dashboard</strong><span><b data-role-count="students"><?= number_format($counts['students']) ?></b> approved students · Complaint submission and tracking</span></a>
            </div>
        </div>
    </section>
</main>
<script>
async function refreshSystemCounts(){try{const response=await fetch('admin_dashboard.php?summary=1',{cache:'no-store'});if(!response.ok)return;const counts=await response.json();document.querySelectorAll('[data-system-count]').forEach(element=>{element.textContent=Number(counts[element.dataset.systemCount]||0).toLocaleString();});document.querySelectorAll('[data-role-count]').forEach(element=>{element.textContent=Number(counts[element.dataset.roleCount]||0).toLocaleString();});}catch(error){}}
window.setInterval(refreshSystemCounts,60000);
document.addEventListener('DOMContentLoaded', () => initializeComplaintProgressChart('admin-progress-chart', 'admin_dashboard.php?progress=1', 'admin-progress-updated', 'admin-progress-refresh', 'doughnut'));
</script>
</body>
</html>
