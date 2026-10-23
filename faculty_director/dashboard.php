<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['faculty_director']);
$facultyId = (int) $_SESSION['faculty_id'];

function faculty_director_count(mysqli $db, int $facultyId, string $source): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM $source");
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

$facultyStmt = $conn->prepare('SELECT category, name FROM faculties WHERE id = ? AND is_active = 1 LIMIT 1');
$facultyStmt->bind_param('i', $facultyId);
$facultyStmt->execute();
$faculty = $facultyStmt->get_result()->fetch_assoc();
if (!$faculty) {
    http_response_code(403);
    exit('This faculty is unavailable. Contact the administrator.');
}

$counts = [
    'departments' => faculty_director_count($conn, $facultyId, 'departments WHERE faculty_id = ?'),
    'heads' => faculty_director_count($conn, $facultyId, "users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'department_head'"),
    'teachers' => faculty_director_count($conn, $facultyId, "users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'teacher' AND u.status = 'approved'"),
    'students' => faculty_director_count($conn, $facultyId, "users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'student' AND u.status = 'approved'"),
    'pending' => faculty_director_count($conn, $facultyId, "users u JOIN departments d ON d.id = u.department_id WHERE d.faculty_id = ? AND u.role = 'student' AND u.status = 'pending'"),
];
$departmentsStmt = $conn->prepare("SELECT d.id, d.name, d.is_active, COUNT(DISTINCT CASE WHEN u.role = 'department_head' THEN u.id END) AS head_count FROM departments d LEFT JOIN users u ON u.department_id = d.id WHERE d.faculty_id = ? GROUP BY d.id ORDER BY d.name LIMIT 8");
$departmentsStmt->bind_param('i', $facultyId);
$departmentsStmt->execute();
$departments = $departmentsStmt->get_result();
include __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty director dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content{padding:28px}.director-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:22px}.director-heading h1{font-size:26px;margin:0}.director-heading p{margin:6px 0 0;color:var(--system-muted)}.faculty-tag{padding:7px 11px;border:1px solid var(--system-border);border-radius:5px;background:var(--system-surface);font-size:12px}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}.stat-card,.dashboard-panel{padding:20px;border:1px solid var(--system-border);border-radius:8px;background:var(--system-surface);box-shadow:0 8px 24px rgba(20,45,75,.05)}.stat-card span{display:block;color:var(--system-muted);font-size:12px}.stat-card strong{display:block;margin-top:7px;color:var(--system-blue);font-size:28px}.dashboard-panel{margin-bottom:20px}.dashboard-panel h2{font-size:18px}.table-responsive{overflow-x:auto}@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr)}}@media(max-width:620px){.content{padding:20px 14px}.director-heading{align-items:flex-start;flex-direction:column}.stat-grid{gap:8px}.stat-card{padding:14px}}
    </style>
</head>
<body>
<main class="content">
    <header class="director-heading"><div><h1><?= h($faculty['name']) ?> dashboard</h1><p><?= h($faculty['category']) ?> · Faculty operations and department oversight</p></div><span class="faculty-tag"><?= number_format($counts['pending']) ?> students awaiting department approval</span></header>
    <section class="stat-grid" aria-label="Faculty totals">
        <article class="stat-card"><span>Departments</span><strong><?= number_format($counts['departments']) ?></strong></article>
        <article class="stat-card"><span>Department heads</span><strong><?= number_format($counts['heads']) ?></strong></article>
        <article class="stat-card"><span>Teachers</span><strong><?= number_format($counts['teachers']) ?></strong></article>
        <article class="stat-card"><span>Approved students</span><strong><?= number_format($counts['students']) ?></strong></article>
    </section>
    <section class="dashboard-panel">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h2 class="mb-0">Departments</h2><a class="btn btn-primary btn-sm" href="registrations.php">Register department or head</a></div>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Department</th><th>Department heads</th><th>State</th></tr></thead><tbody>
            <?php while ($department = $departments->fetch_assoc()): ?><tr><td><?= h($department['name']) ?></td><td><?= (int) $department['head_count'] ?></td><td><?= $department['is_active'] ? 'Open' : 'Closed' ?></td></tr><?php endwhile; ?>
            <?php if ($counts['departments'] === 0): ?><tr><td colspan="3">No departments yet. Register the first department for this faculty.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
