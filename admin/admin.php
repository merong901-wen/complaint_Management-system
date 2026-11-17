<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf_or_exit();
    $action = (string) ($_POST['action'] ?? '');
    $directorId = (int) ($_POST['director_id'] ?? 0);

    try {
        if ($directorId < 1) {
            throw new RuntimeException('Choose a valid faculty director.');
        }

        if ($action === 'update_director') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || mb_strlen($fullName) > 120 || $username === '' || mb_strlen($username) > 50 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
                throw new RuntimeException('Enter a name (up to 120 characters), username (up to 50 characters), and valid email.');
            }
            if ($password !== '' && !password_is_strong($password)) {
                throw new RuntimeException('Use a 12-character password with uppercase, lowercase, number, and symbol.');
            }

            $directorCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'faculty_director' LIMIT 1");
            $directorCheck->bind_param('i', $directorId);
            $directorCheck->execute();
            if (!$directorCheck->get_result()->fetch_row()) {
                throw new RuntimeException('That faculty director no longer exists.');
            }

            $duplicateCheck = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
            $duplicateCheck->bind_param('ssi', $username, $email, $directorId);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->fetch_row()) {
                throw new RuntimeException('That username or email is already in use.');
            }

            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, password = ? WHERE id = ? AND role = 'faculty_director'");
                $update->bind_param('ssssi', $fullName, $username, $email, $passwordHash, $directorId);
            } else {
                $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ? AND role = 'faculty_director'");
                $update->bind_param('sssi', $fullName, $username, $email, $directorId);
            }
            $update->execute();
            record_audit($conn, 'faculty_director_updated', 'user', $directorId);
            $message = 'Faculty director details updated.';
        } elseif ($action === 'toggle_director') {
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['approved', 'inactive'], true)) {
                throw new RuntimeException('Choose a valid account status.');
            }

            $update = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'faculty_director'");
            $update->bind_param('si', $status, $directorId);
            $update->execute();
            if ($update->affected_rows === 0) {
                $exists = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'faculty_director' AND status = ? LIMIT 1");
                $exists->bind_param('is', $directorId, $status);
                $exists->execute();
                if (!$exists->get_result()->fetch_row()) {
                    throw new RuntimeException('That faculty director no longer exists.');
                }
            }
            record_audit($conn, $status === 'approved' ? 'faculty_director_activated' : 'faculty_director_deactivated', 'user', $directorId);
            $message = $status === 'approved'
                ? 'Faculty director account activated.'
                : 'Faculty director account deactivated.';
        } else {
            throw new RuntimeException('Choose a valid faculty director action.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The faculty director change could not be saved. Check for duplicate usernames or emails.';
        error_log('Admin faculty director management failed: ' . $exception->getMessage());
    }
}

$directors = $conn->query("SELECT u.id, u.full_name, u.username, u.email, u.status, u.created_at, f.id AS faculty_id, f.name AS faculty_name, f.category AS faculty_category
    FROM users u
    LEFT JOIN faculties f ON f.id = u.faculty_id
    WHERE u.role = 'faculty_director'
    ORDER BY f.name, u.full_name, u.username")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty directors</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content { padding: 28px; }
        .director-panel { padding: 22px; border: 1px solid var(--system-border); border-radius: 12px; background: var(--system-surface); box-shadow: 0 8px 24px rgba(20,45,75,.06); }
        .director-toolbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .director-toolbar h1 { margin-bottom: 4px; }
        .director-search { max-width: 340px; }
        .director-actions { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
        .director-status { display: inline-flex; padding: 4px 10px; border-radius: 999px; background: rgba(44,168,121,.12); color: #258260; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .director-status.inactive { background: rgba(115,121,142,.14); color: var(--system-muted); }
        .director-empty { padding: 28px; border: 1px dashed var(--system-border); border-radius: 10px; color: var(--system-muted); text-align: center; }
        @media (max-width: 760px) { .content { padding: 18px 12px; } .director-toolbar { align-items: stretch; flex-direction: column; } .director-search { max-width: none; } }
    </style>
</head>
<body>
<main class="content">
    <section class="director-panel">
        <header class="director-toolbar">
            <div>
                <h1 class="h3">Registered faculty directors</h1>
                <p class="mb-0 text-muted">Edit director accounts and control whether they can sign in and use Chat.</p>
            </div>
            <input class="form-control director-search" type="search" data-director-search placeholder="Search directors or faculties" aria-label="Search faculty directors">
        </header>

        <?php if ($message): ?><div class="alert alert-success" role="status"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>

        <?php if (!$directors): ?>
            <div class="director-empty">No faculty directors have been registered yet. Create one from Faculty setup.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Director</th>
                            <th scope="col">Faculty</th>
                            <th scope="col">Email</th>
                            <th scope="col">Status</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($directors as $director):
                        $searchText = strtolower(implode(' ', [
                            $director['full_name'],
                            $director['username'],
                            $director['email'],
                            $director['faculty_name'] ?? '',
                            $director['faculty_category'] ?? '',
                        ]));
                    ?>
                        <tr data-director-row data-search-text="<?= h($searchText) ?>">
                            <td><strong><?= h($director['full_name'] ?: $director['username']) ?></strong><small class="d-block text-muted">@<?= h($director['username']) ?></small></td>
                            <td><?= h($director['faculty_name'] ?: 'Faculty unavailable') ?><?php if (!empty($director['faculty_category'])): ?><small class="d-block text-muted"><?= h($director['faculty_category']) ?></small><?php endif; ?></td>
                            <td><?= h($director['email']) ?></td>
                            <td><span class="director-status <?= $director['status'] === 'approved' ? '' : 'inactive' ?>"><?= h($director['status']) ?></span></td>
                            <td><?= h(date('M j, Y', strtotime((string) $director['created_at']))) ?></td>
                            <td class="director-actions">
                                <button
                                    class="btn btn-outline-primary btn-sm"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#directorEditModal"
                                    data-director-id="<?= (int) $director['id'] ?>"
                                    data-director-name="<?= h($director['full_name']) ?>"
                                    data-director-username="<?= h($director['username']) ?>"
                                    data-director-email="<?= h($director['email']) ?>"
                                >Edit</button>
                                <form method="post" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_director">
                                    <input type="hidden" name="director_id" value="<?= (int) $director['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $director['status'] === 'approved' ? 'inactive' : 'approved' ?>">
                                    <button class="btn btn-sm <?= $director['status'] === 'approved' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                        <?= $director['status'] === 'approved' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-director-empty hidden><td colspan="6" class="text-center text-muted">No directors match your search.</td></tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<div class="modal fade" id="directorEditModal" tabindex="-1" aria-labelledby="directorEditModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_director">
                <input type="hidden" name="director_id" data-director-edit-id>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="directorEditModalTitle">Edit faculty director</h2>
                        <p class="mb-0 mt-1 text-muted small">Update the account details or optionally set a new password.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="director-edit-name">Full name</label>
                    <input class="form-control mb-3" id="director-edit-name" name="full_name" maxlength="120" required>
                    <label class="form-label" for="director-edit-username">Username</label>
                    <input class="form-control mb-3" id="director-edit-username" name="username" maxlength="50" required>
                    <label class="form-label" for="director-edit-email">Email</label>
                    <input class="form-control mb-3" type="email" id="director-edit-email" name="email" maxlength="100" required>
                    <label class="form-label" for="director-edit-password">New password <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="password" id="director-edit-password" name="password" minlength="12" autocomplete="new-password">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const directorEditModal = document.getElementById('directorEditModal');
directorEditModal?.addEventListener('show.bs.modal', (event) => {
    const button = event.relatedTarget;
    if (!(button instanceof HTMLElement)) return;

    directorEditModal.querySelector('[data-director-edit-id]').value = button.dataset.directorId || '';
    directorEditModal.querySelector('#director-edit-name').value = button.dataset.directorName || '';
    directorEditModal.querySelector('#director-edit-username').value = button.dataset.directorUsername || '';
    directorEditModal.querySelector('#director-edit-email').value = button.dataset.directorEmail || '';
    directorEditModal.querySelector('#director-edit-password').value = '';
});

document.querySelector('[data-director-search]')?.addEventListener('input', function () {
    const query = this.value.trim().toLocaleLowerCase();
    const rows = Array.from(document.querySelectorAll('[data-director-row]'));
    let visible = 0;
    rows.forEach((row) => {
        const matches = row.dataset.searchText.includes(query);
        row.hidden = !matches;
        if (matches) visible++;
    });
    const emptyRow = document.querySelector('[data-director-empty]');
    if (emptyRow) emptyRow.hidden = visible > 0;
});
</script>
</body>
</html>
