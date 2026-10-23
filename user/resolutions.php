<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['student']);
$userId = (int) $_SESSION['user_id'];
$totalStmt = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE user_id = ? AND resolution IS NOT NULL AND TRIM(resolution) <> ''");
$totalStmt->bind_param('i', $userId);
$totalStmt->execute();
$total = (int) $totalStmt->get_result()->fetch_row()[0];
$pagination = pagination_data($total, (int) ($_GET['page'] ?? 1));
$listStmt = $conn->prepare("SELECT c.id,c.title,c.status,c.resolution,c.updated_at,c.created_at,t.full_name AS teacher_name FROM complaints c LEFT JOIN users t ON t.id=c.resolution_by WHERE c.user_id = ? AND c.resolution IS NOT NULL AND TRIM(c.resolution) <> '' ORDER BY COALESCE(c.resolved_at,c.updated_at,c.created_at) DESC LIMIT ? OFFSET ?");
$listStmt->bind_param('iii', $userId, $pagination['per_page'], $pagination['offset']);
$listStmt->execute();
$resolutions = $listStmt->get_result();
include __DIR__ . '/user_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teacher resolutions</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>.content{padding:28px}.solution-list{display:grid;gap:12px}.solution-item{padding:18px;border:1px solid var(--system-blue);background:#fff}.solution-meta{display:flex;justify-content:space-between;gap:12px;font-size:12px}.solution-text{white-space:pre-wrap;margin:14px 0 0}body.dark-mode .solution-item{background:#000!important;color:#fff!important}@media(max-width:700px){.content{padding:18px 12px}.solution-meta{flex-direction:column;gap:3px}}</style></head><body><main class="content"><h1 class="h3">Teacher resolutions</h1><p>Solutions sent to you by your department teachers.</p><section class="solution-list"><?php while($item=$resolutions->fetch_assoc()): ?><article class="solution-item"><div class="solution-meta"><strong><?= h($item['title']) ?> · <?= h($item['status']) ?></strong><span><?= h($item['updated_at'] ?? $item['created_at']) ?></span></div><p class="mb-1">From <?= h($item['teacher_name'] ?? 'Department teacher') ?></p><p class="solution-text"><?= h($item['resolution']) ?></p><a class="btn btn-sm btn-primary mt-2" href="user_complaints.php?page=1">Open complaint #<?= (int)$item['id'] ?></a></article><?php endwhile; ?><?php if($total===0): ?><p>No teacher solutions yet.</p><?php endif; ?></section><?= pagination_links($pagination) ?></main></body></html>
