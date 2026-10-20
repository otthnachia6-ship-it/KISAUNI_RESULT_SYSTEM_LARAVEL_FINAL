<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

/**
 * php artisan flask:import-users - built against a synthetic Flask database that
 * has the same schema as the real kisauni.db but DIFFERENT class ids from
 * Laravel (as in real life), to prove classes are matched by name.
 */
class FlaskImportUsersTest extends TestCase
{
    use RefreshDatabase;

    private const HASH_A = 'scrypt:1024:8:1$nkifCJZOAZyhCIOQ$7d62488a00a200e331debf00a6af1665d9bdb33c10a99dd70ceef8cafa5b08a0365a8741cf8294b8dd1247be7344a976d842e0f3a618a2c10106228574f30e7e'; // Teacher-A#2026
    private const HASH_B = 'scrypt:1024:8:1$aInKO7USgyAzVn5z$2b396f1b8a3d75b28f792736348d6c93647448887eea5c31f633915a1a82d04e08a682176aa9eaf0158d47bd8afd3479944a178fc4608fad6712852fb1031f7e'; // Teacher-B#2026
    private const HASH_C = 'scrypt:1024:8:1$3ygR589wHprnBTwg$eaab22ad837b0fa32acecafd10e2e44e1671b6c9fe4aea762747b8ff45ab26dedb90125121646f14c1ae6de417a4aa5581b75c3f8391e610369a4dc7bf1025c6'; // Kiarabu#عارف

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        config(['kisauni.legacy_scrypt.enabled' => true]);
        $this->file = sys_get_temp_dir() . '/flask_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->file . '.json');
        parent::tearDown();
    }

    /** Flask class ids deliberately do NOT match Laravel's (here: reversed order). */
    private function makeFlask(array $users, ?array $classNames = null): void
    {
        $classNames ??= SchoolClass::orderBy('id')->pluck('name')->reverse()->values()->all();
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec("CREATE TABLE classes (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, standard INTEGER, stream TEXT, sort_order INTEGER NOT NULL DEFAULT 0, teacher_id INTEGER, last_promoted_year TEXT, last_promoted_from_year TEXT)");
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, full_name TEXT NOT NULL, role TEXT NOT NULL, class_id INTEGER, active INTEGER NOT NULL DEFAULT 1, must_change_password INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, photo_path TEXT, title TEXT, failed_login_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT)");
        $ins = $pdo->prepare('INSERT INTO classes (id, name, sort_order) VALUES (?,?,?)');
        foreach ($classNames as $i => $n) {
            $ins->execute([$i + 1, $n, $i + 1]);
        }
        $u = $pdo->prepare('INSERT INTO users (id, username, password_hash, full_name, role, class_id, active, must_change_password, created_at, title) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($users as $r) {
            $u->execute([$r['id'], $r['username'], $r['hash'] ?? self::HASH_A, $r['name'] ?? "Name {$r['username']}", $r['role'] ?? 'class_teacher',
                $r['class'] ?? null, $r['active'] ?? 1, $r['mcp'] ?? 0, '2026-01-01 08:00:00', $r['title'] ?? null]);
        }
        $pdo = null;
    }

    private function flaskClassId(string $name): int
    {
        $pdo = new PDO('sqlite:' . $this->file);
        return (int) $pdo->query("SELECT id FROM classes WHERE name = " . $pdo->quote($name))->fetchColumn();
    }

    private function standard(): array
    {
        return [
            ['id' => 3, 'username' => 'damtu', 'name' => "Mtumwa Moh'd issa", 'hash' => self::HASH_A, 'class' => $this->flaskClassId('Standard Five C')],
            ['id' => 29, 'username' => 'DAMTU', 'name' => 'Mtumwa Mgeni Hamad', 'hash' => self::HASH_B, 'class' => $this->flaskClassId('Standard Seven C')],
            ['id' => 14, 'username' => 'Arif', 'name' => 'ARIF MOHAMMED MBAROUK', 'role' => 'headmaster', 'hash' => self::HASH_A, 'title' => 'Administrator'],
            ['id' => 26, 'username' => 'عارف', 'name' => 'Arif Mohammed Mbarouk', 'hash' => self::HASH_C, 'class' => $this->flaskClassId('Standard Seven B')],
            ['id' => 5, 'username' => 'khamaja', 'name' => 'Khadija Majid Jabir', 'hash' => self::HASH_B, 'class' => $this->flaskClassId('Standard Two A'), 'active' => 0],
        ];
    }

    private function seedFlask(): void
    {
        // classes first (so ids are known), then users
        $this->makeFlask([]);
        @unlink($this->file);
        $this->makeFlask([]);
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec('DELETE FROM users');
        $pdo = null;
        // rebuild with real rows now that class ids exist
        $names = SchoolClass::orderBy('id')->pluck('name')->reverse()->values()->all();
        @unlink($this->file);
        $this->makeFlask([], $names);
        $rows = $this->standard();
        @unlink($this->file);
        $this->makeFlask($rows, $names);
        // Flask says fatma... no: point Standard Five C's teacher_id at damtu (id 3), Seven B at عارف (26)
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec("UPDATE classes SET teacher_id = 3 WHERE name = 'Standard Five C'");
        $pdo->exec("UPDATE classes SET teacher_id = 26 WHERE name = 'Standard Seven B'");
        $pdo = null;
    }

    private function laravelClassName(User $u): ?string
    {
        return $u->class_id ? SchoolClass::find($u->class_id)->name : null;
    }

    // ----------------------------------------------------------------- tests

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->seedFlask();
        $before = User::count();
        $this->artisan('flask:import-users', ['database' => $this->file])->assertExitCode(0);
        $this->assertSame($before, User::count());
        $this->assertSame(0, AuditLog::where('action', 'IMPORT_FLASK_USER')->count());
    }

    public function test_apply_imports_all_accounts_with_class_matched_by_name_not_id(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);

        $damtu = User::findByUsername('damtu');
        $DAMTU = User::findByUsername('DAMTU');
        $this->assertNotNull($damtu);
        $this->assertNotNull($DAMTU);
        $this->assertNotSame($damtu->id, $DAMTU->id);
        $this->assertSame('Standard Five C', $this->laravelClassName($damtu));
        $this->assertSame('Standard Seven C', $this->laravelClassName($DAMTU));

        // the Flask ids were reversed relative to Laravel's: a raw class_id copy would have been wrong
        $this->assertNotSame($this->flaskClassId('Standard Five C'), $damtu->class_id);

        $arif = User::findByUsername('Arif');
        $arifAr = User::findByUsername('عارف');
        $this->assertNotSame($arif->id, $arifAr->id);
        $this->assertSame(['headmaster', null], [$arif->role, $arif->class_id]);
        $this->assertSame('Administrator', $arif->title);
        $this->assertSame(['class_teacher', 'Standard Seven B'], [$arifAr->role, $this->laravelClassName($arifAr)]);

        $this->assertSame(0, (int) User::findByUsername('khamaja')->active, 'inactive stays inactive');
    }

    public function test_password_hashes_are_copied_untouched_and_never_printed(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true, '--report' => $this->file . '.json'])
            ->doesntExpectOutputToContain('7d62488a00a200e331')
            ->doesntExpectOutputToContain('2b396f1b8a3d75b28f')
            ->assertExitCode(0);

        $this->assertSame(self::HASH_A, User::findByUsername('damtu')->password);
        $this->assertSame(self::HASH_B, User::findByUsername('DAMTU')->password);
        $this->assertSame(self::HASH_C, User::findByUsername('عارف')->password);

        $report = file_get_contents($this->file . '.json');
        $this->assertStringNotContainsString('scrypt:1024', $report);
        $this->assertStringNotContainsString('7d62488a00a2', $report);
        $this->assertSame(0, (int) User::findByUsername('damtu')->failed_login_attempts);
    }

    public function test_classes_teacher_id_is_filled_by_class_name_only_when_empty(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);
        $this->assertSame(User::findByUsername('damtu')->id, SchoolClass::where('name', 'Standard Five C')->first()->teacher_id);
        $this->assertSame(User::findByUsername('عارف')->id, SchoolClass::where('name', 'Standard Seven B')->first()->teacher_id);
        $this->assertNull(SchoolClass::where('name', 'Standard One B')->first()->teacher_id, 'nothing invented');
    }

    public function test_running_it_twice_creates_no_duplicates(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);
        $count = User::count();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);
        $this->assertSame($count, User::count());
        $this->assertSame(1, User::where('username', 'DAMTU')->count());
        $this->assertSame(5, AuditLog::where('action', 'IMPORT_FLASK_USER')->count());
    }

    public function test_existing_laravel_users_are_never_changed_or_deleted(): void
    {
        $this->seedFlask();
        $default = User::where('role', 'headmaster')->first();
        $snapshot = User::all()->map->getAttributes()->all();

        // Flask also has a "headmaster" with a different identity -> conflict, not overwritten
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role, class_id, active, must_change_password, created_at) VALUES (1, '{$default->username}', '" . self::HASH_B . "', 'AHMADA HAJI KHAMISI', 'headmaster', NULL, 1, 0, '2026-01-01')");
        $pdo = null;

        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])
            ->expectsOutputToContain('1 conflicts')
            ->assertExitCode(0);

        foreach ($snapshot as $row) {
            $now = User::find($row['id'])->getAttributes();
            $this->assertSame($row, $now, "user {$row['username']} was modified");
        }
        $this->assertSame('Head Master', User::find($default->id)->full_name);
    }

    public function test_adopt_lets_the_real_headmaster_take_over_the_seeded_account_only_when_asked(): void
    {
        $this->seedFlask();
        $default = User::where('role', 'headmaster')->first();
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role, class_id, active, must_change_password, created_at) VALUES (1, '{$default->username}', '" . self::HASH_B . "', 'AHMADA HAJI KHAMISI', 'headmaster', NULL, 1, 0, '2026-01-01')");
        $pdo = null;

        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true, '--adopt' => $default->username])->assertExitCode(0);

        $default->refresh();
        $this->assertSame('AHMADA HAJI KHAMISI', $default->full_name);
        $this->assertSame(self::HASH_B, $default->password);
        $this->assertSame(0, (int) $default->must_change_password);
        $this->assertSame(1, User::where('username', $default->username)->count(), 'adopted in place, not duplicated');

        // and he can log in with his old Flask password
        $this->post('/login', ['username' => $default->username, 'password' => 'Teacher-B#2026'])->assertRedirect(route('dashboard'));
        $this->assertStringStartsWith('$2y$', $default->fresh()->password);
    }

    public function test_same_identity_already_in_laravel_is_left_untouched(): void
    {
        $this->seedFlask();
        $mine = User::create([
            'username' => 'khamaja', 'password' => Hash::make('Laravel-set-this'), 'full_name' => 'Khadija Majid Jabir', 'role' => 'class_teacher',
            'class_id' => SchoolClass::where('name', 'Standard Two A')->first()->id, 'active' => 1, 'must_change_password' => 0, 'created_at' => SchoolService::nowStr(),
        ]);
        $hash = $mine->password;

        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);

        $this->assertSame(1, User::where('username', 'khamaja')->count());
        $mine->refresh();
        $this->assertSame($hash, $mine->password, 'the production password is preserved');
        $this->assertSame(1, (int) $mine->active, 'active flag is not overwritten either');
    }

    public function test_class_teacher_with_unknown_class_is_reported_and_skipped_not_invented(): void
    {
        $this->makeFlask([
            ['id' => 1, 'username' => 'ghost', 'class' => 99],
            ['id' => 2, 'username' => 'noclass'],
            ['id' => 3, 'username' => 'fine', 'class' => 1],
        ], ['Standard One A', 'Standard Seven Z']);
        $before = SchoolClass::count();

        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);

        $this->assertNull(User::findByUsername('ghost'));
        $this->assertNull(User::findByUsername('noclass'));
        $this->assertNotNull(User::findByUsername('fine'));
        $this->assertSame($before, SchoolClass::count(), 'no classes are created');
    }

    public function test_a_flask_class_that_laravel_lacks_is_skipped(): void
    {
        $this->makeFlask([['id' => 1, 'username' => 'lost', 'class' => 1]], ['Standard Eight Q']);
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(0);
        $this->assertNull(User::findByUsername('lost'));
    }

    public function test_any_error_blocks_the_whole_import_all_or_nothing(): void
    {
        $this->makeFlask([
            ['id' => 1, 'username' => 'good', 'class' => 1],
            ['id' => 2, 'username' => 'badrole', 'role' => 'janitor', 'class' => 1],
            ['id' => 3, 'username' => 'badhash', 'hash' => 'plaintext-password!', 'class' => 1],
        ], ['Standard One A']);
        $before = User::count();

        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(1);

        $this->assertSame($before, User::count());
        $this->assertNull(User::findByUsername('good'));
    }

    public function test_bad_input_files_are_rejected_clearly(): void
    {
        $this->artisan('flask:import-users', ['database' => '/no/such/file.db'])->assertExitCode(1);

        file_put_contents($this->file, 'this is not sqlite at all, sorry');
        $this->artisan('flask:import-users', ['database' => $this->file])->assertExitCode(1);

        @unlink($this->file);
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
        $pdo = null;
        $this->artisan('flask:import-users', ['database' => $this->file])->assertExitCode(1);
    }

    public function test_the_flask_file_itself_is_never_modified(): void
    {
        $this->seedFlask();
        $before = md5_file($this->file);
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);
        $this->assertSame($before, md5_file($this->file));
    }

    public function test_end_to_end_imported_teachers_can_log_in_with_their_old_flask_passwords(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);

        // damtu / DAMTU: own passwords only
        $this->post('/login', ['username' => 'damtu', 'password' => 'Teacher-B#2026']);
        $this->assertGuest();
        $this->post('/login', ['username' => 'damtu', 'password' => 'Teacher-A#2026'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::findByUsername('damtu'));
        auth()->logout();

        $this->post('/login', ['username' => 'DAMTU', 'password' => 'Teacher-B#2026'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::findByUsername('DAMTU'));
        auth()->logout();

        // Arif (headmaster) and عارف (teacher) are separate logins
        $this->post('/login', ['username' => 'عارف', 'password' => 'Kiarabu#عارف'])->assertRedirect(route('dashboard'));
        $this->assertSame('class_teacher', auth()->user()->role);
        auth()->logout();
        $this->post('/login', ['username' => 'Arif', 'password' => 'Teacher-A#2026'])->assertRedirect(route('dashboard'));
        $this->assertSame('headmaster', auth()->user()->role);

        // hashes upgraded for the ones who logged in; the others still legacy
        $this->assertStringStartsWith('$2y$', User::findByUsername('damtu')->password);
        $this->assertStringStartsWith('scrypt:', User::findByUsername('khamaja')->password);
    }

    public function test_inactive_imported_account_cannot_log_in(): void
    {
        $this->seedFlask();
        $this->artisan('flask:import-users', ['database' => $this->file, '--apply' => true])->assertExitCode(0);
        $this->post('/login', ['username' => 'khamaja', 'password' => 'Teacher-B#2026']);
        $this->assertGuest();
    }
}
