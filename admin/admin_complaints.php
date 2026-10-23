<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

include 'admin_sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Complaints</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<style>
.content { margin-left:240px; padding:25px; background:#f4f6f9; min-height:100vh; }
.table-box { background:white; padding:20px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }

.badge-status { padding:6px 12px; border-radius:10px; color:#fff; }
.pending { background:#f39c12; }
.inprogress { background:#3498db; }
.resolved { background:var(--system-blue); }
.closed { background:#95a5a6; }
.high { background:#e74c3c; }
.medium { background:#f1c40f; color:black; }
.low { background:var(--system-blue); }

#searchInput { margin-bottom:15px; border-radius:10px; padding:8px 12px; border:2px solid #3498db; }
</style>
</head>
<body>
<div class="content">
<h3>📋 All Complaints</h3>

<input type="text" id="searchInput" class="form-control" placeholder="Search by User, Status, or Priority...">
<input type="hidden" id="adminCsrfToken" value="<?= h(csrf_token()) ?>">

<div class="table-box">
<table id="complaintsTable" class="table table-hover">
<thead class="table-dark">
<tr>
<th>ID</th>
<th>User</th>
<th>Title</th>
<th>Status</th>
<th>Priority</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php
$result = $conn->query("
SELECT complaints.*, users.username 
FROM complaints 
JOIN users ON complaints.user_id = users.id
ORDER BY complaints.id DESC
");
while($row=$result->fetch_assoc()):
?>
<tr id="complaintRow<?= $row['id'] ?>">
<td><?= $row['id'] ?></td>
<td><?= htmlspecialchars($row['username']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><span class="badge-status <?= strtolower(str_replace(' ','',$row['status'])) ?>"><?= $row['status'] ?></span></td>
<td><span class="badge-status <?= strtolower($row['priority']) ?>"><?= $row['priority'] ?></span></td>
<td>
<button class="btn btn-primary btn-sm view-btn" data-id="<?= $row['id'] ?>">👁 View</button>
<button class="btn btn-danger btn-sm delete-btn" data-id="<?= $row['id'] ?>">🗑 Delete</button>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>

<!-- Modal for View -->
<div class="modal fade" id="complaintModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" id="modalContent"></div>
  </div>
</div>

<script>
$(document).ready(function(){
    var table = $('#complaintsTable').DataTable({ "pageLength": 10, "lengthMenu": [10] });

    $('#searchInput').on('keyup', function() {
        table.search(this.value).draw();
    });

    $('#complaintsTable').on('click', '.view-btn', function(){
        var id = $(this).data('id');
        $('#modalContent').load('admin_view_complaint.php?id=' + id, function(){
            bootstrap.Modal.getOrCreateInstance(document.getElementById('complaintModal')).show();
        });
    });

    $('#complaintsTable').on('click', '.delete-btn', function(){
        var id = $(this).data('id');
        if(confirm('Are you sure to delete this complaint?')){
            $.post('admin_delete_complaint.php', {id: id, csrf_token: $('#adminCsrfToken').val()}, function(res){
                if(res.trim() == 'success'){
                    $('#complaintRow'+id).remove();
                    showToast('Complaint deleted.', 3000, 'success');
                } else {
                    showToast(res || 'Could not delete complaint.', 4000, 'error');
                }
            });
        }
    });
});
</script>
</body>
</html>
