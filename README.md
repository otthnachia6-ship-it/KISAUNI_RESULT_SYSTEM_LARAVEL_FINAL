# abuudoro63 — School Result Management System
**Target: PHP 8.2+ / Laravel 12 / MySQL / Blade**  
**Destination Hosting: OSTEXS.COM (cPanel / Apache)**

This repository contains the complete production-ready migration of the `abuudoro63` school result management system from Python/Flask/SQLite/Jinja2 to PHP/Laravel/MySQL/Blade. The user interface, CSS, JS, responsive behavior, workflows, decimal grading logic, and security rules have been preserved 100% without alteration.

The application also contains an independent **Developer Control Center** (see sections 9-11): a separate
Developer account with the combined Headmaster + Class Teacher capabilities plus system monitoring, security,
maintenance and on-demand database backup/download tools.

---

## 1. System Requirements

- **PHP**: `^8.2` (tested up to PHP 8.5)
  - Required PHP Extensions: `BCMath`, `Ctype`, `cURL`, `DOM`, `Fileinfo`, `Filter`, `Hash`, `JSON`, `Mbstring`, `OpenSSL`, `PCRE`, `PDO`, `pdo_mysql`, `Session`, `Tokenizer`, `XML`, `GD` (for photo/logo processing)
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Web Server**: Apache 2.4+ (with `mod_rewrite` enabled) or Nginx
- **Composer**: Composer 2.x (if installing/updating dependencies on server)

---

## 2. Directory Structure

```text
abuudoro63_LARAVEL_FINAL/
├── app/
│   ├── Http/
│   ├── Console/Commands/      # db:backup (cron backup), developer:create, Flask import, scrypt benchmark
│   ├── Http/
│   │   ├── Controllers/       # School controllers (Auth, Dashboard, Students, Classes, Subjects, Exams, Marks, Results, etc.)
│   │   │   └── Developer/     # Developer Control Center (login, dashboard, monitoring, backups, security, maintenance)
│   │   └── Middleware/        # Auth, headmaster (Headmaster/Administrator/Developer), developer, Password Change, Idle Timeout, NoCache
│   ├── Models/                # 9 Eloquent Models (User, SchoolClass, Subject, Student, Exam, Mark, Status, Setting, AuditLog)
│   └── Services/              # SchoolService, PdfReportService, DatabaseBackupService, SystemHealthService
├── bootstrap/
│   ├── app.php
│   └── cache/
├── config/                    # Laravel & school configurations
├── database/
│   ├── migrations/            # 10 MySQL/SQLite compatible migrations
│   ├── seeders/               # DatabaseSeeder (classes, subjects, exams, headmaster)
│   └── ostexs_school_system_mysql.sql # Ready-to-import MySQL database dump
├── public/
│   ├── css/style.css          # Original CSS preserved verbatim
│   ├── js/script.js           # Original client-side JavaScript preserved
│   ├── images/logo.png        # Default school crest / logo
│   ├── index.php              # Front controller
│   └── .htaccess              # Apache rewrite rules
├── resources/
│   └── views/                 # Blade templates; developer/ holds the Developer Control Center pages
├── routes/
│   └── web.php                # School routes + /developer/* routes
├── storage/                   # App storage, logs, framework cache/sessions/views
├── tests/
│   └── Feature/               # Automated integration and parity tests
├── artisan                    # Laravel CLI tool
├── composer.json              # PHP dependencies
├── .env.example               # Safe production environment template
├── .htaccess                  # Root redirect to public/
└── README.md                  # This documentation
```

---

## 3. Environment Configuration (`.env`)

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Ensure the database settings reflect your MySQL database on OSTEXS.COM:

```env
APP_NAME="Result Management System"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://ostexs.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_ostexs_database_name
DB_USERNAME=your_ostexs_database_user
DB_PASSWORD=your_ostexs_database_password

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

DEFAULT_SCHOOL_NAME="Kisauni Primary School"
DEFAULT_ACADEMIC_YEAR="2026"
IDLE_TIMEOUT_MINUTES=10
AUDIT_LOG_RETENTION_DAYS=5          # keep at 5 (approved policy)

# Database backups (php artisan db:backup and the Developer Control Center)
BACKUP_PATH=/home/USER/kisauni_backups   # private folder OUTSIDE public_html; empty = ../kisauni_backups
BACKUP_RETENTION_DAYS=90
BACKUP_MIN_KEEP=7
APP_TIMEZONE="Africa/Dar_es_Salaam"
```

The full list of supported variables is in `.env.ostexs.example`. Never commit `.env`.

---

## 4. Database Setup on OSTEXS.COM (cPanel)

### Option A: Via phpMyAdmin (Recommended for cPanel)
1. In cPanel, navigate to **MySQL Databases** and create a new database and user. Assign **ALL PRIVILEGES**.
2. Open **phpMyAdmin**, select the newly created database.
3. Click the **Import** tab.
4. Select `database/ostexs_school_system_mysql.sql` and click **Go**.
5. Your database is now populated with all tables, initial classes (17 streams), subjects (9), examinations, and default headmaster account.

### Option B: Via SSH / Terminal
If SSH access is available on OSTEXS.COM:

```bash
php artisan migrate --force
php artisan db:seed --force
```

---

## 5. Storage Permissions & Symlink

Ensure the web server has write permissions to `storage` and `bootstrap/cache`:

```bash
chmod -R 775 storage bootstrap/cache
```

Generate the public storage symlink for uploaded logos and user photos:

```bash
php artisan storage:link
```

---

## 6. Document Root & Web Server Setup

In cPanel on OSTEXS.COM:
- **Recommended Setup**: Point your domain/subdomain **Document Root** directly to the `public/` directory (e.g. `/home/username/public_html/abuudoro63-laravel/public` or move `public/*` to `public_html` with root files placed one directory above).
- **Alternative Setup**: If placing the entire application inside `public_html`, the provided root `.htaccess` will automatically protect `.env` and route requests to `public/index.php`.

---

## 7. Production Optimization Commands

Run these commands when deploying or after updating configuration on the server:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

To clear caches during maintenance:

```bash
php artisan optimize:clear
```

---

## 8. Default Login Credentials

- **URL**: `https://ostexs.com/login` (the Developer uses `/developer/login`, see section 10)
- **Username**: `headmaster`
- **Password**: there is no fixed default password. `php artisan db:seed` prints a random temporary
  password once in the terminal (or uses `INITIAL_HEADMASTER_PASSWORD` from `.env` if you set it).
  If you import `database/ostexs_school_system_mysql.sql` instead, ask the developer for the
  temporary password and log in immediately after importing.

*The temporary password must be changed (to a different one) at first login. Run `php artisan db:seed`
only on a new, empty database.*

---

## 9. User Roles

| Account | Signs in at | What it can do |
|---|---|---|
| **Headmaster** (`role=headmaster`, title *Headmaster*) | `/login` | All school management, review/approval, users, settings, audit log |
| **Administrator** (`role=headmaster`, title *Administrator*) | `/login` | Exactly the same rights as the Headmaster (only the displayed title differs) |
| **Class Teacher** (`role=class_teacher`) | `/login` | Own class only: students, marks entry, Excel marks upload, submit results, reports after release |
| **Developer** (`role=developer`) | `/developer/login` only | Headmaster capabilities + Class Teacher capabilities for any class + the Developer Control Center |

Rules enforced on the server (middleware and controllers, not only by hiding menus):

- School accounts are refused at `/developer/login`, and the Developer is refused at `/login`
  (both answer "Incorrect username or password", so neither page reveals the other kind of account).
- `/developer/*` pages return **404** for every account except the Developer.
- The Developer account is **not listed** on the school Users page and cannot be edited, deactivated,
  deleted or have its password reset by a Headmaster/Administrator (those URLs return 404).
- The Developer uses the same protections as everybody: login throttling and lockout, idle sign-out,
  sign-out of old sessions after a password change, CSRF on forms, and audit logging.

## 10. Developer Account Setup

There is no default Developer account and no default password. Create it on the server (SSH / cPanel Terminal):

```bash
php artisan developer:create devuser --name="System Developer"
# you are asked for the password twice (it is not shown and not stored in plain text)

php artisan developer:create devuser --reset   # set a new password later
```

Then sign in at `https://YOUR-DOMAIN/developer/login`.

**No terminal on the server?** A signed-in Headmaster or Administrator can create the **first** Developer account
once at `https://YOUR-DOMAIN/developer-setup`:
choose the Developer username and password, confirm with the Headmaster's own password. The page exists only
while no Developer account exists; afterwards it returns 404 for everyone. The action is in the audit log.
Later password changes still use `php artisan developer:create USERNAME --reset`.

## 11. Developer Control Center

The Developer sees an expandable sidebar (main menus with nested submenus). School pages are the existing
pages; no school function was duplicated.

| Menu | Submenus |
|---|---|
| 1. Main | Dashboard (health summary, backup state, counts, quick actions, recent audit log) |
| 2. School Management | Students, Classes, Subjects, Examinations, Marks Entry & Upload (choose any class for Excel upload), Results Review & Approval, Reports, Analytics, Student Records |
| 3. User Management | All Users, Account Management (add account), Login Activity |
| 4. System Monitoring | Application Health, Database Monitoring, Server Status, Storage Monitoring, Error Monitoring, Performance |
| 5. Database & Backup | Database Overview, Create Backup, Download Backup, Backup History, Backup Status |
| 6. Security | Security Overview, Login Attempts, Session Monitoring |
| 7. System Activities | Audit Logs |
| 8. Maintenance | Cache Management (clear views / routes / config / application cache), System Diagnostics, Maintenance Mode |
| 9. Developer Settings | My Profile, Security Settings (change password), System Information |
| 10. Logout | |

Monitoring shows only values that PHP, Laravel and the database really report, with the states
**Healthy / Warning / Critical / Unavailable / Not configured**. When the host does not expose a metric
(for example disk space or load average on some shared hosts), it is shown as *Unavailable*, never invented.
No `.env` values, database credentials, `APP_KEY` or session ids are shown; known secrets are masked in log lines.

### Maintenance Mode
**Maintenance → Maintenance Mode** closes the system to school users while you update or repair it:

- *Switch ON* (with confirmation): Headmaster, Administrator and Class Teachers see the existing maintenance page
  (`maintenance.updating` "System update" or `maintenance.suspended` "Service suspended", English + Kiswahili).
- Only the **signed-in Developer account** keeps full access. The Developer's browser gets Laravel's signed bypass
  cookie (12 hours, also given automatically when the Developer signs in during maintenance), but every request is
  checked again after the session starts (`EnforceMaintenanceForSchoolUsers`): if the Developer signs out and a
  school user signs in on the same browser, that user still sees the maintenance page. The cookie is removed at sign-out.
- `/developer/*` (including `/developer/login`) stays reachable during maintenance, so the Developer can always sign in
  - even from another browser - and use *Give this browser full access* or *Switch OFF*. All those pages still
  require the Developer account.
- Switching on/off is written to the audit log. School data is not changed.
- From the server, `php artisan up` always opens the system again.
- Messages (English + Kiswahili): *Scheduled System Maintenance* for updates, and *Service Temporarily Suspended*
  for a pending hosting/domain renewal. Set `SUPPORT_CONTACT` in `.env` (phone or e-mail) to show a contact line
  on both pages. The page is prepared when maintenance is switched ON, so after changing `SUPPORT_CONTACT`
  (and running `php artisan config:cache` if used) switch maintenance OFF and ON again.

## 12. Backup & Disaster Recovery Procedure

All backups are made by `App\Services\DatabaseBackupService`:

- read-only dump of the live database (`mysqldump --single-transaction`, or a built-in PHP dumper as fallback);
- gzip-compressed, then **verified** (readable, contains the `users` table, ends with the completion marker);
  a file that fails verification is deleted and the attempt is reported as failed;
- written to a **private folder outside the public web root** (`BACKUP_PATH`, default `../kisauni_backups`),
  file mode 0600 / folder 0700 on Linux;
- no `.env` content, and no session/cache/token tables. User rows (with password *hashes*) are included
  so accounts can be recovered - keep backup files private.

### A. Automatic backups (cron)
In cPanel → Cron Jobs (daily):

```bash
cd /home/USER/kisauni && php artisan db:backup            # verified backup + retention (90 days, keep newest 7)
php artisan db:backup --status                             # exit code 1 if the last backup failed or is too old
```

### B. On-demand backup and download (Developer only)
1. Sign in at `/developer/login` → **Database & Backup → Create Backup** → *Create backup now*.
2. **Download Backup** lists the files; each file is verified again before it is sent.
   Downloads go through an authorised controller only (no public URL; names outside the backup naming
   pattern, `../` paths, `status.json` etc. return 404).
3. **Backup History** lists the files and the create/download actions from the audit log;
   **Backup Status** shows the last attempt (from web or cron) and any error.

Creating or downloading a backup never changes school data. Backups made from the web do not delete older
backups (retention is applied only by the cron command). Headmaster, Administrator and Class Teacher accounts
have **no** backup pages. There is **no restore and no delete from the web**.

### C. Restoring (server, by a technician)
Dumps contain no `DROP` statements, so restore into a **new, empty** database (then point `.env` to it), never
over the live one:

```bash
gunzip < kisauni_db_YYYYMMDD_HHMMSS.sql.gz | mysql -u NEW_DB_USER -p NEW_EMPTY_DATABASE
```

## 13. Audit Log

Important actions (sign-in/out, user management, student/marks/result actions, backups, cache clearing, Developer
account creation) are written to `audit_log` and **kept for 5 days** (`AUDIT_LOG_RETENTION_DAYS=5`). Older
entries are removed automatically at most once a day.

## 14. Testing

```bash
composer install
php artisan test            # or: vendor/bin/phpunit --testsuite=Feature
```

Tests use an in-memory SQLite database (see `phpunit.xml`); an `APP_KEY` must be available (from `.env` or the
environment). Developer features are covered by `tests/Feature/DeveloperAccessTest.php` and
`tests/Feature/DeveloperBackupTest.php`. Note: on Windows, one check in `DatabaseBackupTest` (file mode 0600)
fails because Windows does not support Unix file permissions; it passes on Linux hosting.

---

## 15. Grading & Calculation Parity Verification

The system maintains strict decimal-inclusive boundary rules identical to the source implementation:

- **E**: 0.0 – 20.9 (Color: `#dc3545`)
- **D**: 21.0 – 40.9 (Color: `#fd7e14`)
- **C**: 41.0 – 60.9 (Color: `#ffc107`)
- **B**: 61.0 – 80.9 (Color: `#0d6efd`)
- **A**: 81.0 – 100.0 (Color: `#198754`)

Verified boundary examples:
- `20.9` -> `E`, `20.95` -> `E`, `21.0` -> `D`
- `40.9` -> `D`, `40.95` -> `D`, `41.0` -> `C`
- `60.9` -> `C`, `60.95` -> `C`, `61.0` -> `B`
- `80.9` -> `B`, `80.95` -> `B`, `81.0` -> `A`
