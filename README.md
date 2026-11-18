# Student Complaint and Resolution System

A PHP and MySQL web application for managing student complaints from submission through review, response, escalation, and resolution. The system supports student accounts and a role-based staff workflow across teachers, department heads, faculty directors, and administrators.

## Contents

- [Features](#features)
- [Roles and workflow](#roles-and-workflow)
- [Technology](#technology)
- [Project layout](#project-layout)
- [Requirements](#requirements)
- [Installation](#installation)
- [Account setup](#account-setup)
- [Security and operational behavior](#security-and-operational-behavior)
- [Troubleshooting](#troubleshooting)
- [Known implementation notes](#known-implementation-notes)

## Features

- Student registration, sign-in, profile management, complaint submission, editing, and complaint history.
- Complaint assignment to an approved teacher in the student's department, with status, priority, category, resolution, and timestamps.
- Teacher dashboards for assigned complaints, responses, and resolutions.
- Department-head workflows for department, teacher, and student approval management, plus sensitive complaint conversations and eligible escalations.
- Faculty director tools for faculty-scoped department management, communications, registrations, and audit review.
- Administrator tools for system-wide dashboards, account and registration management, faculties, system settings, communications, impersonation, and audit logs.
- Comments and threaded messages, notifications, paginated lists, dashboard summaries, and periodic dashboard refresh.
- Role-scoped direct chat with unread counts, recent-message previews, online status, file attachments, and recorded voice messages.
- CLI-only, additive database migrations intended to preserve existing complaint records.

## Roles and workflow

| Role | Main responsibilities |
| --- | --- |
| Student | Registers, waits for department approval, submits complaints to an assigned teacher, tracks progress, comments, and may contact the department head for a sensitive issue or an eligible escalation. |
| Teacher | Reviews complaints assigned to them and records responses or resolutions. |
| Department head | Manages the department's teachers and student approvals; handles sensitive conversations and escalations for that department. |
| Faculty director | Manages departments and staff within their faculty and reviews faculty-scoped activity. |
| Administrator | Manages the system, faculties, system-wide registrations and settings, and reviews audit activity. |

Typical setup proceeds from administrator to faculty director to department head and teacher. Students register against a faculty and department and remain pending until approved by their department head. A complaint is routed to an approved teacher in that department. Students can raise a sensitive issue with the department head directly; an unresolved teacher complaint can be escalated after the configured seven-day eligibility period. The department head can reply or resolve the escalation.

## Technology

- PHP (strict typing is used in shared application helpers; no Composer dependency manifest is present)
- MySQL or MariaDB, accessed through PHP MySQLi
- HTML, CSS, JavaScript, Bootstrap/jQuery assets used by the pages
- XAMPP is the documented local development environment

## Project layout

```text
.
├── index.php                         Public landing page
├── login.php / register.php          Sign-in and student registration
├── logout.php                        Sign-out
├── bootstrap.php                     Legacy bootstrap entry point
├── complaint_modal.php               Shared complaint modal markup
├── admin/
│   ├── admin_dashboard.php            Administrator dashboard
│   ├── admin_complaints.php           Complaint management list
│   ├── admin_view_complaint.php       Complaint details and management
│   ├── admin_registrations.php        Account registration management
│   ├── faculty_setup.php              Faculty and department setup
│   ├── system.php                     System settings and audit tools
│   └── config/
│       ├── config.php                 MySQL connection settings
│       └── migrations.php             CLI-only schema creation/upgrade
├── department_head/                   Department head dashboards, approvals,
│                                      teacher administration, and messages
├── faculty_director/                  Faculty-scoped dashboards, registrations,
│                                      messages, and audit views
├── teacher/                           Teacher dashboards, complaints, resolution
├── user/                              Student complaint, profile, and message pages
├── includes/app.php                   Shared session, security, role, and helper code
├── scripts/create_admin.php           One-time CLI administrator bootstrap
├── docs/ROLE_SETUP.md                 Role setup and operational details
└── public/assets/                     CSS, JavaScript, and screenshot assets
```

`complaint_Management-system/` is a nested legacy folder and is not the active application root. Run the app from the repository root containing `login.php` and `admin/`.

## Requirements

- Windows with XAMPP (or equivalent Apache, PHP, and MySQL/MariaDB setup).
- PHP with `mysqli` enabled. Use a PHP version supported by the installed XAMPP release; the code uses PHP 8 features such as `mixed`, `never`, and `str_starts_with`.
- MySQL/MariaDB database server and permission to create/alter tables in the application database.
- A modern browser with JavaScript enabled.
- Voice recording requires microphone permission in a secure browser context (HTTPS or localhost). PHP upload limits should allow files up to 16 MB and requests up to 32 MB; the Docker image configures these limits.

## Installation

1. Place or clone this project into the web server document root. With the default XAMPP layout, this repository is `C:\xampp\htdocs\complaint-resolution`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Create a database named `complaint_system` in phpMyAdmin or from the MySQL command line. The configured database user must be able to create and alter tables.
4. Review `admin/config/config.php`. Its local defaults are host `127.0.0.1`, user `root`, an empty password, and database `complaint_system`. Change these values to match your local database; do not use the default credentials in a production deployment.
5. From PowerShell in the project root, run the schema migration with the XAMPP PHP executable:

   ```powershell
   & 'C:\xampp\php\php.exe' admin/config/migrations.php
   ```

   The migration is CLI-only and can be rerun to add missing schema elements. It creates the application tables and indexes and performs a few legacy account/data conversions. Back up any existing database before applying migrations to an existing installation.

6. Create the initial administrator as described in [Account setup](#account-setup).
7. Open `http://localhost/complaint-resolution/` in a browser. Sign in at `/login.php`.

If the project is hosted under a different URL path or virtual host, use that base URL in place of `/complaint-resolution/`. The application currently expects its PHP files and assets to be served from the project root structure.

### Deploy to Render

The application requires MySQL or MariaDB; a database running at `127.0.0.1` inside the web container is not available. Create or use an external MySQL-compatible database that allows connections from the Render service, then add these environment variables to the Render web service:

| Variable | Value |
| --- | --- |
| `DB_HOST` | Database server hostname (not `127.0.0.1` or `localhost`) |
| `DB_PORT` | Database server port, usually `3306` |
| `DB_NAME` | Application database name |
| `DB_USER` | Database username |
| `DB_PASSWORD` | Database password (`DB_PASS` is also accepted) |

After deploying with those variables set, run `php admin/config/migrations.php` from the service's Shell to create/update the schema. The web service will return HTTP 503 and log the connection detail if it cannot connect; it cannot start the application until the database is reachable and the schema is initialized.

## Account setup

### Create the first administrator

The bootstrap script is CLI-only and refuses to create an administrator if one already exists. It reads the password from `BOOTSTRAP_ADMIN_PASSWORD`; full name, username, and email can be entered interactively or supplied through environment variables. In PowerShell, the documented secure prompt flow is:

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

Choose a password of at least 12 characters with uppercase and lowercase letters, a number, and a symbol. The same password policy is enforced for new accounts. Never commit credentials or leave the bootstrap password in the environment longer than required.

### Configure the organization

1. Sign in as administrator and create a faculty under the supported faculty category.
2. Assign a faculty director to the faculty.
3. The faculty director creates departments and assigns department heads.
4. Department heads register or approve teachers for their departments.
5. Students register and select their faculty and department. Their accounts require department-head approval before normal access.

See [docs/ROLE_SETUP.md](docs/ROLE_SETUP.md) for the fuller role and escalation workflow.

## Security and operational behavior

- Passwords are stored with PHP `password_hash` and checked with `password_verify`.
- Shared helpers in `includes/app.php` configure HTTP-only, SameSite=Lax, strict-mode session cookies; state-changing forms use CSRF tokens.
- Protected pages use role checks; account and data queries are scoped by role and organizational ownership in the application flows.
- Prepared MySQLi statements are used for user-supplied values in application queries.
- Non-admin accounts must be approved and assigned to the required faculty or department. The administrator remains available to manage the system.
- The `system_open` setting controls access for non-admin users. An administrator can close access from system settings; protected non-admin pages return a temporary-unavailable response while closed.
- Audit events are stored in `audit_logs`; administrators can review system-wide events and faculty directors have a faculty-scoped audit view.
- The migration file is blocked from web requests and must be invoked through PHP CLI.

For a production deployment, configure a non-default database account, HTTPS, PHP error logging, secure backups, and web-server protections for configuration and maintenance files. Keep PHP and the database server patched.

## Troubleshooting

- **Database connection fails:** For local use, confirm MySQL is running and `complaint_system` exists. On Render, confirm the `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` variables point to a reachable MySQL/MariaDB server; `localhost` refers to the web container, not your database.
- **Tables are missing:** Run the migration from the project root with the correct PHP CLI executable. Check the CLI output and PHP error log.
- **A student or staff account cannot enter:** Check its approval status and required department/faculty assignment. A pending student must be approved by the department head.
- **Non-admin users see a temporary-unavailable page:** An administrator may have closed the system in system settings; check `system_open`.
- **Initial administrator creation is refused:** The script only works when no `admin` account exists. Use an existing administrator account once one has been created.
- **Assets or redirects fail under another URL:** Ensure the app is served from the expected project-root path and check the web-server document root.

## Known implementation notes

- Database settings come from environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`/`DB_PASS`), with local XAMPP defaults; there is no `.env` loader in the current code.
- `admin/config/migrations.php` is the authoritative schema definition. Tables include `users`, `complaints`, `comments`, `departments`, `faculties`, `department_head_conversations`, `department_head_messages`, `faculty_director_messages`, `notifications`, `system_settings`, and `audit_logs`.
- The migration also supports some older installations by converting legacy `user` roles to `student` and setting eligible legacy accounts to approved. Review and back up existing data before migration.
- There is no automated test suite or dependency installation command defined in the repository at this time.
