# Role-Based Setup

## Database migration

From the project root, run the additive migration with XAMPP PHP:

```powershell
& 'C:\xampp\php\php.exe' admin/config/migrations.php
```

The migration is CLI-only and repeatable. Existing `admin` accounts are preserved; legacy `user` accounts become approved students. It adds faculties, faculty ownership for departments, faculty directors, student approval, teacher assignments, notifications, system settings, and audit logs without removing existing complaint records. Existing departments remain available for a faculty director to adopt from the unassigned-departments list.

If the database has no administrator, create the first one once from PowerShell. The password is requested as a secure prompt and passed temporarily through the process environment:

```powershell
$secure = Read-Host 'Initial administrator password' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:BOOTSTRAP_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    & 'C:\xampp\php\php.exe' scripts/create_admin.php
} finally {
    Remove-Item Env:BOOTSTRAP_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
}
```

The bootstrap utility is CLI-only and refuses to run if an administrator already exists.

## Account workflow

1. An administrator creates a faculty under Social Science or Natural Science, then assigns its faculty director.
2. A faculty director registers departments and assigns department heads inside that faculty.
3. A department head registers approved teachers for their assigned department.
4. A student self-registers by choosing a faculty category, major faculty, and department; their account remains pending until that department head approves them.
5. Approved students submit complaints to approved teachers in that same department.
6. Assigned teachers respond; the complaint, conversation, resolution, and student notification update together.
7. Students can send a sensitive complaint directly to their department head, or escalate an unresolved teacher complaint after seven days. Department heads can review linked complaints and teacher responses, reply to the student, or send a resolution that closes the escalation and marks its complaint resolved.

The administrator dashboard summarizes the faculty director, department head, teacher, and student dashboards. Faculty directors have a faculty-scoped audit log; administrators can review system-wide audit events under Admin → System. The administrator dashboard no longer assigns students to departments.

Department-head communication is for sensitive complaints or unresolved cases already raised with the teacher; ordinary complaints must go to the assigned teacher. Sensitive threads and complaint escalations are scoped to the student's department, and complaint escalations are checked server-side against the seven-day waiting period.

## Security and operations

Protected pages use centralized role checks, session regeneration at login, CSRF tokens on state-changing forms, prepared statements, and department/owner-scoped queries. Passwords are stored with `password_hash`; newly created accounts require at least 12 characters including uppercase, lowercase, a number, and a symbol.

Lists use ten records per page. Dashboard charts and summary counts refresh every 60 seconds. Closing the system blocks non-admin sign-in, registration, and protected pages while preserving administrator access. Audit events are available in Admin → System.
