<?php

namespace App\Console\Commands;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LegacyPasswordService;
use App\Services\SchoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Brings the teacher / headmaster accounts of the old Flask system (kisauni.db)
 * into Laravel - repeatable, additive, and dry-run by default.
 *
 *   php artisan flask:import-users /path/to/kisauni.db            (report only)
 *   php artisan flask:import-users /path/to/kisauni.db --apply    (write)
 *
 * Rules:
 *  - Reads the SQLite file only (query_only). Never modifies it.
 *  - Never deletes or overwrites an existing Laravel user. The one exception is
 *    an explicit --adopt=username (see below).
 *  - Accounts are identified by the EXACT username: damtu and DAMTU are two
 *    people; Arif and عارف are two accounts. Nothing is ever merged or renamed.
 *  - Classes are matched by NAME, never by Flask class id (the ids differ).
 *  - Password hashes are copied as they are (Werkzeug scrypt/pbkdf2); they are
 *    upgraded to Laravel's hash on the user's first successful login. Plaintext
 *    is never known, stored or printed; hashes are never printed.
 *  - All-or-nothing: with --apply everything runs in one transaction.
 *  - Students, marks, exams are NOT touched by this command.
 */
class FlaskImportUsersCommand extends Command
{
    protected $signature = 'flask:import-users
                            {database : Path to the old Flask SQLite file (kisauni.db)}
                            {--apply : Actually write. Without it nothing is changed (dry run)}
                            {--adopt= : Comma-separated usernames that ALREADY exist in Laravel with a different identity and must take over the Flask password/name (explicit opt-in)}
                            {--report= : Also write a JSON report (no password hashes) to this file}';

    protected $description = 'Import Flask teacher/headmaster accounts into Laravel (dry-run by default)';

    /** @var array<int,array<string,mixed>> */
    private array $plan = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $adopt = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('adopt')))));

        try {
            $pdo = $this->openFlask((string) $this->argument('database'));
            [$flaskUsers, $flaskClasses] = $this->readFlask($pdo);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info(($apply ? 'APPLY' : 'DRY RUN (nothing will be written)') . ' - Flask users: ' . count($flaskUsers) . ', Flask classes: ' . count($flaskClasses));

        $laravelClasses = $this->laravelClassIndex();
        $caseSensitive = $this->usernameColumnIsCaseSensitive();
        $existing = User::orderBy('id')->get();

        $this->plan = [];
        foreach ($flaskUsers as $fu) {
            $this->plan[] = $this->decide($fu, $flaskClasses, $laravelClasses, $existing, $flaskUsers, $caseSensitive, $adopt);
        }
        $teacherNotes = $this->checkFlaskAssignments($flaskUsers, $flaskClasses);

        $this->renderPlan();
        foreach ($teacherNotes as $n) {
            $this->warn($n);
        }

        $counts = array_count_values(array_column($this->plan, 'action'));
        $errors = ($counts['ERROR'] ?? 0);

        if ($apply) {
            if ($errors > 0) {
                $this->error("Refusing to write: {$errors} account(s) have errors. Fix them (or the data) and run again. Nothing was changed.");
                $this->writeReport($teacherNotes);
                return self::FAILURE;
            }
            try {
                DB::transaction(fn () => $this->applyPlan($flaskUsers, $flaskClasses, $laravelClasses));
            } catch (Throwable $e) {
                $this->error('Import failed and was rolled back, nothing changed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s: %d to import, %d already present, %d conflicts, %d skipped (class), %d adopted, %d errors.',
            $apply ? 'Result' : 'Would do',
            $counts['IMPORT'] ?? 0, $counts['EXISTS'] ?? 0, $counts['CONFLICT'] ?? 0, $counts['SKIP_CLASS'] ?? 0, $counts['ADOPT'] ?? 0, $errors
        ));
        if (!$apply) {
            $this->line('Run again with --apply to write the IMPORT/ADOPT rows above.');
        }
        $this->writeReport($teacherNotes);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ------------------------------------------------------------- reading

    private function openFlask(string $path): PDO
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read the Flask database file: {$path}");
        }
        $header = (string) file_get_contents($path, false, null, 0, 16);
        if (!str_starts_with($header, 'SQLite format 3')) {
            throw new \RuntimeException('That file is not a SQLite database.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA query_only = ON');
        return $pdo;
    }

    /** @return array{0:array<int,array>,1:array<int,array>} users, classes keyed by flask id */
    private function readFlask(PDO $pdo): array
    {
        $cols = fn (string $t) => array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(), 'name');
        $uc = $cols('users');
        $cc = $cols('classes');
        foreach (['id', 'username', 'password_hash', 'full_name', 'role', 'class_id', 'active'] as $c) {
            if (!in_array($c, $uc, true)) {
                throw new \RuntimeException("Flask users table has no '{$c}' column - is this the right file?");
            }
        }
        foreach (['id', 'name'] as $c) {
            if (!in_array($c, $cc, true)) {
                throw new \RuntimeException("Flask classes table has no '{$c}' column - is this the right file?");
            }
        }

        $users = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
        $classes = [];
        foreach ($pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll() as $c) {
            $classes[(int) $c['id']] = $c;
        }
        return [$users, $classes];
    }

    /** normalised class name => Laravel class (null when ambiguous) */
    private function laravelClassIndex(): array
    {
        $idx = [];
        foreach (SchoolClass::orderBy('id')->get() as $c) {
            $key = $this->norm($c->name);
            $idx[$key] = array_key_exists($key, $idx) ? null : $c;
        }
        return $idx;
    }

    private function norm(?string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    }

    private function usernameColumnIsCaseSensitive(): bool
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return true; // SQLite compares exactly
        }
        $row = DB::selectOne(
            'SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'username']
        );
        return $row && str_ends_with((string) $row->c, '_bin');
    }

    // ------------------------------------------------------------ deciding

    private function decide(array $fu, array $flaskClasses, array $laravelClasses, $existing, array $allFlask, bool $caseSensitive, array $adopt): array
    {
        $username = (string) $fu['username'];
        $row = [
            'flask_id' => (int) $fu['id'], 'username' => $username, 'full_name' => (string) $fu['full_name'],
            'role' => (string) $fu['role'], 'class_name' => null, 'laravel_class_id' => null,
            'active' => (int) $fu['active'], 'hash_kind' => '?', 'action' => '', 'reason' => '',
        ];

        // role
        if (!in_array($row['role'], [SchoolService::ROLE_HEADMASTER, SchoolService::ROLE_CLASS_TEACHER], true)) {
            return $row + ['action' => 'ERROR', 'reason' => "unknown role '{$row['role']}' - not mapped"];
        }
        // password hash
        $parsed = LegacyPasswordService::parse((string) $fu['password_hash']);
        if (!$parsed) {
            return array_merge($row, ['action' => 'ERROR', 'reason' => 'password hash is not a recognised Werkzeug scrypt/pbkdf2 hash']);
        }
        $row['hash_kind'] = $parsed['kind'] === 'scrypt' ? "scrypt {$parsed['n']}/{$parsed['r']}/{$parsed['p']}" : "pbkdf2 {$parsed['algo']}";

        // class (by NAME)
        if ($row['role'] === SchoolService::ROLE_CLASS_TEACHER) {
            $fc = $fu['class_id'] !== null ? ($flaskClasses[(int) $fu['class_id']] ?? null) : null;
            if (!$fc) {
                return array_merge($row, ['action' => 'SKIP_CLASS', 'reason' => 'class teacher has no (valid) class in Flask - not inventing one']);
            }
            $row['class_name'] = (string) $fc['name'];
            $lc = $laravelClasses[$this->norm($fc['name'])] ?? null;
            if (!$lc) {
                return array_merge($row, ['action' => 'SKIP_CLASS', 'reason' => "class '{$fc['name']}' does not exist (or is ambiguous) in Laravel"]);
            }
            $row['laravel_class_id'] = $lc->id;
        }

        // username: exact match only
        $sameExact = $existing->first(fn ($u) => $u->username === $username);
        if ($sameExact) {
            if ($this->norm($sameExact->full_name) === $this->norm($row['full_name']) && $sameExact->role === $row['role']) {
                return array_merge($row, ['action' => 'EXISTS', 'reason' => 'already in Laravel (same username, name and role) - left untouched', 'laravel_user_id' => $sameExact->id]);
            }
            if (in_array($username, $adopt, true) && $sameExact->role === $row['role']) {
                return array_merge($row, ['action' => 'ADOPT', 'reason' => "explicit --adopt: existing account '{$sameExact->full_name}' takes the Flask password and name", 'laravel_user_id' => $sameExact->id]);
            }
            return array_merge($row, ['action' => 'CONFLICT', 'reason' => "username taken in Laravel by a different identity ('{$sameExact->full_name}', {$sameExact->role}) - not changed"]);
        }

        // case-variants need a case-sensitive column
        if (!$caseSensitive) {
            $lower = mb_strtolower($username);
            $clash = $existing->contains(fn ($u) => mb_strtolower($u->username) === $lower)
                || collect($allFlask)->contains(fn ($o) => (int) $o['id'] !== (int) $fu['id'] && mb_strtolower((string) $o['username']) === $lower);
            if ($clash) {
                return array_merge($row, ['action' => 'ERROR', 'reason' => 'differs only by upper/lower case from another account, but users.username is not case-sensitive yet - run the pending migration first']);
            }
        }

        return array_merge($row, ['action' => 'IMPORT', 'reason' => $row['role'] === SchoolService::ROLE_HEADMASTER ? 'headmaster account' : "class {$row['class_name']}"]);
    }

    /** Sanity checks on Flask's own users.class_id vs classes.teacher_id. */
    private function checkFlaskAssignments(array $flaskUsers, array $flaskClasses): array
    {
        $notes = [];
        $byId = [];
        foreach ($flaskUsers as $u) {
            $byId[(int) $u['id']] = $u;
        }
        $perClass = [];
        foreach ($flaskUsers as $u) {
            if ($u['role'] === SchoolService::ROLE_CLASS_TEACHER && $u['class_id'] !== null) {
                $perClass[(int) $u['class_id']][] = $u['username'];
            }
        }
        foreach ($perClass as $cid => $names) {
            if (count($names) > 1) {
                $notes[] = "Note: Flask class '" . ($flaskClasses[$cid]['name'] ?? $cid) . "' has " . count($names) . ' class teachers (' . implode(', ', $names) . '); all keep that class.';
            }
        }
        foreach ($flaskClasses as $cid => $c) {
            $tid = $c['teacher_id'] ?? null;
            if ($tid === null) {
                $notes[] = "Note: Flask class '{$c['name']}' has no class teacher assigned.";
            } elseif (!isset($byId[(int) $tid])) {
                $notes[] = "Note: Flask class '{$c['name']}' points to a teacher id ({$tid}) that no longer exists.";
            } elseif ((int) ($byId[(int) $tid]['class_id'] ?? 0) !== $cid) {
                $notes[] = "Note: Flask class '{$c['name']}' lists '{$byId[(int) $tid]['username']}' as its teacher, but that user's own class is different.";
            }
        }
        return $notes;
    }

    // ------------------------------------------------------------- writing

    private function applyPlan(array $flaskUsers, array $flaskClasses, array $laravelClasses): void
    {
        $flaskById = [];
        foreach ($flaskUsers as $u) {
            $flaskById[(int) $u['id']] = $u;
        }
        $map = []; // flask user id => laravel user id

        foreach ($this->plan as $row) {
            $fu = $flaskById[$row['flask_id']];
            if ($row['action'] === 'EXISTS') {
                $map[$row['flask_id']] = $row['laravel_user_id'];
            } elseif ($row['action'] === 'IMPORT') {
                $created = User::create([
                    'username' => $row['username'],
                    'password' => (string) $fu['password_hash'],          // legacy hash, upgraded at first login
                    'full_name' => $row['full_name'],
                    'role' => $row['role'],
                    'class_id' => $row['laravel_class_id'],
                    'active' => $row['active'] ? 1 : 0,
                    'must_change_password' => (int) ($fu['must_change_password'] ?? 0),
                    'photo_path' => null,                                // Flask photo files do not exist here
                    'title' => $fu['title'] ?? null,
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                    'created_at' => $fu['created_at'] ?? SchoolService::nowStr(),
                ]);
                $map[$row['flask_id']] = $created->id;
                SchoolService::logAction(null, 'IMPORT_FLASK_USER', "{$row['username']} ({$row['role']}" . ($row['class_name'] ? ", {$row['class_name']}" : '') . ')');
            } elseif ($row['action'] === 'ADOPT') {
                $u = User::findOrFail($row['laravel_user_id']);
                $u->update([
                    'password' => (string) $fu['password_hash'],
                    'full_name' => $row['full_name'],
                    'title' => $fu['title'] ?? $u->title,
                    'must_change_password' => (int) ($fu['must_change_password'] ?? 0),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ]);
                $map[$row['flask_id']] = $u->id;
                SchoolService::logAction(null, 'IMPORT_FLASK_USER', "{$row['username']} adopted Flask credentials (explicit --adopt)");
            }
        }

        // classes.teacher_id: only fill EMPTY slots, matched by class name
        foreach ($flaskClasses as $c) {
            $tid = $c['teacher_id'] ?? null;
            $lc = $laravelClasses[$this->norm($c['name'])] ?? null;
            if ($tid === null || !$lc || !isset($map[(int) $tid])) {
                continue;
            }
            $fresh = SchoolClass::find($lc->id);
            if ($fresh && $fresh->teacher_id === null) {
                $fresh->update(['teacher_id' => $map[(int) $tid]]);
            } elseif ($fresh && (int) $fresh->teacher_id !== (int) $map[(int) $tid]) {
                $this->warn("Class '{$fresh->name}' already has a different teacher in Laravel - kept.");
            }
        }
    }

    // ----------------------------------------------------------- reporting

    private function renderPlan(): void
    {
        $this->table(
            ['Flask id', 'Username', 'Full name', 'Role', 'Class (by name)', 'Active', 'Hash', 'Action', 'Why'],
            array_map(fn ($r) => [$r['flask_id'], $r['username'], $r['full_name'], $r['role'], $r['class_name'] ?? '-', $r['active'], $r['hash_kind'], $r['action'], $r['reason']], $this->plan)
        );
    }

    private function writeReport(array $notes): void
    {
        $path = (string) $this->option('report');
        if ($path === '') {
            return;
        }
        $rows = array_map(function ($r) {
            unset($r['laravel_user_id']);
            return $r;
        }, $this->plan);
        file_put_contents($path, json_encode(['generated_at' => SchoolService::nowStr(), 'applied' => (bool) $this->option('apply'), 'users' => $rows, 'notes' => $notes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
        $this->line("Report written to {$path} (no password hashes inside).");
    }
}
