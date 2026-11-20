<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use App\Services\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Developer account: independent sign-in, combined Headmaster + Class Teacher
 * capabilities, Developer-only Control Center, and no change for school roles.
 */
class DeveloperAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $dev;
    private User $hm;
    private User $admin;
    private User $teacher;
    private SchoolClass $cls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0, 'password' => Hash::make('Head-pass-123')]);
        $this->admin = User::create(['username' => 'admin1', 'password' => Hash::make('Admin-pass-123'), 'full_name' => 'Office Admin',
            'role' => 'headmaster', 'title' => 'Administrator', 'active' => 1, 'must_change_password' => 0]);
        $this->cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->teacher = User::create(['username' => 'tch', 'password' => Hash::make('Teach-pass-123'), 'full_name' => 'Teacher One',
            'role' => 'class_teacher', 'class_id' => $this->cls->id, 'active' => 1, 'must_change_password' => 0]);
        SchoolService::assignClassTeacher($this->cls->id, $this->teacher->id);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'System Developer',
            'role' => 'developer', 'title' => 'Developer', 'active' => 1, 'must_change_password' => 0]);
    }

    // ------------------------------------------------------------ authentication

    public function test_create_command_makes_a_developer_account_without_default_password(): void
    {
        $this->artisan('developer:create', ['username' => 'newdev', '--name' => 'New Dev'])
            ->expectsQuestion('Password for the Developer account', 'Strong-pass-99')
            ->expectsQuestion('Type the password again', 'Strong-pass-99')
            ->assertExitCode(0);

        $u = User::findByUsername('newdev');
        $this->assertTrue($u->isDeveloper());
        $this->assertFalse($u->isHeadmaster());
        $this->assertTrue(Hash::check('Strong-pass-99', $u->password));
        $this->assertTrue(AuditLog::where('action', 'CREATE_DEVELOPER')->exists());
    }

    public function test_create_command_refuses_a_school_username(): void
    {
        $this->artisan('developer:create', ['username' => 'headmaster'])->assertExitCode(1);
        $this->assertSame('headmaster', User::findByUsername('headmaster')->role);
    }

    public function test_developer_signs_in_only_at_the_developer_login(): void
    {
        $this->post('/login', ['username' => 'devuser', 'password' => 'Dev-pass-1234'])
            ->assertSessionHas('danger', 'Incorrect username or password.');
        $this->assertGuest();

        $this->post('/developer/login', ['username' => 'devuser', 'password' => 'Dev-pass-1234'])
            ->assertRedirect(route('developer.dashboard'));
        $this->assertAuthenticatedAs($this->dev);
        $this->assertTrue(AuditLog::where('action', 'LOGIN')->where('username', 'devuser')->exists());
    }

    public function test_school_accounts_cannot_sign_in_at_the_developer_login(): void
    {
        foreach ([['headmaster', 'Head-pass-123'], ['admin1', 'Admin-pass-123'], ['tch', 'Teach-pass-123']] as [$u, $p]) {
            $this->post('/developer/login', ['username' => $u, 'password' => $p])
                ->assertSessionHas('danger', 'Incorrect username or password.');
            $this->assertGuest();
        }
        // and the normal school login still works for them
        $this->post('/login', ['username' => 'headmaster', 'password' => 'Head-pass-123'])->assertRedirect(route('dashboard'));
    }

    public function test_developer_logout_is_audited_and_returns_to_developer_login(): void
    {
        $this->actingAs($this->dev)->post('/logout')->assertRedirect(route('developer.login'));
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'LOGOUT')->where('username', 'devuser')->exists());
    }

    // ------------------------------------------------------------ authorization

    public function test_developer_control_center_is_developer_only(): void
    {
        $this->get('/developer')->assertRedirect(route('login'));
        foreach ([$this->hm, $this->admin, $this->teacher] as $u) {
            foreach (['/developer', '/developer/backups/create', '/developer/monitoring/health', '/developer/security', '/developer/maintenance/cache', '/developer/system-information'] as $uri) {
                $this->actingAs($u)->get($uri)->assertNotFound();
            }
            $this->actingAs($u)->post('/developer/maintenance/cache', ['action' => 'views'])->assertNotFound();
        }
    }

    public function test_every_developer_page_opens_for_the_developer(): void
    {
        $this->actingAs($this->dev);
        $this->get('/dashboard')->assertRedirect(route('developer.dashboard'));
        $html = $this->get('/developer')->assertOk()->getContent();
        foreach (['School Management', 'User Management', 'System Monitoring', 'Database &amp; Backup', 'Security', 'System Activities', 'Maintenance', 'Developer Settings', 'Logout'] as $menu) {
            $this->assertStringContainsString($menu, $html);
        }
        foreach (['health', 'database', 'server', 'storage', 'errors', 'performance'] as $s) {
            $this->get("/developer/monitoring/{$s}")->assertOk();
        }
        $this->get('/developer/monitoring/secrets')->assertNotFound();
        foreach (['/developer/security', '/developer/security/login-attempts', '/developer/security/sessions', '/developer/security/login-activity',
            '/developer/maintenance/cache', '/developer/maintenance/diagnostics', '/developer/system-information', '/developer/database'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_developer_pages_never_show_secrets(): void
    {
        config(['database.connections.sqlite.password' => 'TopSecretDbPw']);
        $this->actingAs($this->dev);
        foreach (['/developer/system-information', '/developer/monitoring/health', '/developer/monitoring/server', '/developer/monitoring/errors', '/developer/maintenance/diagnostics'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString((string) config('app.key'), $html, $uri);
            $this->assertStringNotContainsString('TopSecretDbPw', $html, $uri);
        }
    }

    public function test_developer_has_headmaster_capabilities(): void
    {
        $this->actingAs($this->dev);
        foreach (['/students', '/classes', '/subjects', '/examinations', '/marks', '/results', '/headmaster/overview', '/reports', '/analytics',
            '/records', '/users', '/users/add', '/settings', '/audit-logs', '/promote', "/classes/{$this->cls->id}/tools", '/students/upload'] as $uri) {
            $this->get($uri)->assertOk();
        }

        // headmaster-only actions really work
        $this->post('/classes/add', ['name' => 'Standard Seven Z', 'standard' => 7, 'stream' => 'Z'])->assertRedirect();
        $this->assertTrue(SchoolClass::where('name', 'Standard Seven Z')->exists());
        $this->post('/examinations', ['exam_type' => 'MID TERM'])->assertRedirect();
    }

    public function test_developer_has_class_teacher_capabilities_for_any_class(): void
    {
        $exam = Examination::orderBy('id')->first();
        $maths = Subject::where('name', 'Mathematics')->first();
        Student::create(['reg_no' => 'A1', 'full_name' => 'Pupil One', 'gender' => 'Male', 'class_id' => $this->cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $this->actingAs($this->dev);

        // Excel marks upload: the Developer picks the class
        $this->get('/marks/upload')->assertOk()->assertSee('-- Select Class --');
        $this->get("/marks/upload?class={$this->cls->id}&exam={$exam->id}&subject={$maths->id}")->assertOk()->assertSee('Download template');
        $r = $this->get("/marks/template/{$exam->id}/{$maths->id}?class={$this->cls->id}")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($path, $r->getContent());
        $sheets = Xlsx::read($path);
        $this->assertSame((string) $this->cls->id, $sheets['Meta'][1][1]);

        // upload -> preview -> confirm saves the marks
        $file = $this->tmpXlsx([
            ['name' => 'Marks', 'rows' => [['t'], [], [], [], [], [], [], ['No', 'Reg No', 'Full Name', 'Marks'], [1, 'A1', 'Pupil One', 66]]],
            ['name' => 'Meta', 'hidden' => true, 'rows' => [['class_id', $this->cls->id], ['exam_id', $exam->id], ['subject_id', $maths->id]]],
        ]);
        $this->post('/marks/upload', ['exam_id' => $exam->id, 'subject_id' => $maths->id, 'class_id' => $this->cls->id, 'file' => $file])
            ->assertRedirect('/marks/upload/preview');
        $this->post('/marks/upload/confirm')->assertRedirect("/marks/{$exam->id}/{$this->cls->id}");
        $this->assertDatabaseHas('marks', ['exam_id' => $exam->id, 'subject_id' => $maths->id, 'score' => 66]);

        // marks entry page for any class
        $this->get("/marks/{$exam->id}/{$this->cls->id}")->assertOk();
    }

    public function test_class_teacher_cannot_use_the_developer_class_choice(): void
    {
        $other = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $exam = Examination::orderBy('id')->first();
        $maths = Subject::where('name', 'Mathematics')->first();
        $r = $this->actingAs($this->teacher)->get("/marks/template/{$exam->id}/{$maths->id}?class={$other->id}")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($path, $r->getContent());
        // still the teacher's own class, never the requested one
        $this->assertSame((string) $this->cls->id, Xlsx::read($path)['Meta'][1][1]);
        $this->actingAs($this->teacher)->get('/marks/upload')->assertOk()->assertDontSee('-- Select Class --');
    }

    // ------------------------------------------------------------ school roles unchanged

    public function test_school_users_cannot_see_or_manage_the_developer_account(): void
    {
        $this->actingAs($this->hm);
        $this->get('/users')->assertOk()->assertDontSee('devuser');
        $this->get("/users/edit/{$this->dev->id}")->assertNotFound();
        $this->post("/users/edit/{$this->dev->id}", ['username' => 'x', 'full_name' => 'x', 'role' => 'class_teacher', 'password' => 'New-pass-123'])->assertNotFound();
        $this->post("/users/toggle/{$this->dev->id}")->assertNotFound();
        $this->post("/users/delete/{$this->dev->id}")->assertNotFound();
        $this->dev->refresh();
        $this->assertSame('developer', $this->dev->role);
        $this->assertSame(1, $this->dev->active);
        $this->assertTrue(Hash::check('Dev-pass-1234', $this->dev->password));
    }

    public function test_school_roles_keep_their_existing_access(): void
    {
        $this->assertTrue($this->hm->isHeadmaster());
        $this->assertTrue($this->admin->isHeadmaster());
        $this->assertFalse($this->hm->isDeveloper());

        $this->actingAs($this->admin)->get('/users')->assertOk();
        $this->actingAs($this->hm)->get('/dashboard')->assertOk();
        $this->actingAs($this->teacher)->get('/dashboard')->assertOk();
        $this->actingAs($this->teacher)->get('/users')->assertForbidden();
        $this->actingAs($this->teacher)->get('/results')->assertForbidden();
        $this->actingAs($this->teacher)->get('/classes')->assertForbidden();

        // the school sidebar is unchanged (no Developer menu)
        $this->actingAs($this->hm)->get('/dashboard')->assertDontSee('System Monitoring')->assertDontSee('Developer Dashboard');
    }

    public function test_audit_retention_is_still_five_days(): void
    {
        $this->assertSame(5, SchoolService::auditLogRetentionDays());
        AuditLog::create(['user_id' => null, 'username' => 'old', 'action' => 'LOGIN', 'details' => '', 'created_at' => SchoolService::now()->subDays(6)->format('Y-m-d H:i:s')]);
        AuditLog::create(['user_id' => null, 'username' => 'new', 'action' => 'LOGIN', 'details' => '', 'created_at' => SchoolService::now()->subDays(4)->format('Y-m-d H:i:s')]);
        SchoolService::purgeOldAuditLogs();
        $this->assertFalse(AuditLog::where('username', 'old')->exists());
        $this->assertTrue(AuditLog::where('username', 'new')->exists());
    }

    public function test_cache_clear_is_audited(): void
    {
        $this->actingAs($this->dev)->post('/developer/maintenance/cache', ['action' => 'views'])
            ->assertRedirect(route('developer.maintenance.cache'))->assertSessionHas('success');
        $this->assertTrue(AuditLog::where('action', 'CLEAR_CACHE')->where('username', 'devuser')->exists());
        $this->actingAs($this->dev)->post('/developer/maintenance/cache', ['action' => 'rm -rf'])->assertSessionHas('danger');
    }

    private function tmpXlsx(array $sheets)
    {
        $path = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        file_put_contents($path, Xlsx::build($sheets));

        return new \Illuminate\Http\UploadedFile($path, 'file.xlsx', null, null, true);
    }
}
