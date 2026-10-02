# abuudoro63 — School Result Management System
**Target: PHP 8.2+ / Laravel 12 / MySQL / Blade**  
**Destination Hosting: OSTEXS.COM (cPanel / Apache)**

This repository contains the complete production-ready migration of the `abuudoro63` school result management system from Python/Flask/SQLite/Jinja2 to PHP/Laravel/MySQL/Blade. The user interface, CSS, JS, responsive behavior, workflows, decimal grading logic, and security rules have been preserved 100% without alteration.

The original Python/Flask application is safely preserved in the `flask_original/` directory as the reference implementation.

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
│   │   ├── Controllers/       # 16 Controllers (Auth, Dashboard, Students, Classes, Subjects, Exams, Marks, Results, etc.)
│   │   └── Middleware/        # Auth, Role (Headmaster), Password Change, Idle Timeout (10m), NoCache
│   ├── Models/                # 9 Eloquent Models (User, SchoolClass, Subject, Student, Exam, Mark, Status, Setting, AuditLog)
│   └── Services/              # SchoolService (Grading, remarks, stats), PdfReportService, BackupService
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
│   └── views/                 # 28+ Blade templates (100% parity with Flask Jinja2)
├── routes/
│   └── web.php                # 56 routes matching Flask route endpoints
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
APP_KEY=base64:LRsfpI2j2ksDiOpSJIjIR9yg6khP5q6aS/DCmf6vdlw=
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
BACKUP_RETENTION_DAYS=90
BACKUP_MIN_KEEP=10
APP_TIMEZONE="Africa/Dar_es_Salaam"
```

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

- **URL**: `https://ostexs.com/login`
- **Username**: `headmaster`
- **Password**: `admin123`

*Note: In accordance with the original system security policy, newly seeded accounts require a password change on first login.*

---

## 9. Backup & Disaster Recovery Procedure

### Automated / In-App Backups
1. Headmaster accounts can visit **Settings** (`/settings#backups`).
2. Click **Create Backup Now** to generate a timestamped snapshot stored in `storage/app/backups/`.
3. Backups can be downloaded off-site, restored with one click, or uploaded for disaster recovery.

### Server-Level MySQL Database Backup (`mysqldump`)
Run on server / cron job:

```bash
mysqldump -u your_user -p your_database_name > backup_$(date +\%F).sql
```

To restore a MySQL database backup:

```bash
mysql -u your_user -p your_database_name < backup_file.sql
```

---

## 10. Grading & Calculation Parity Verification

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
