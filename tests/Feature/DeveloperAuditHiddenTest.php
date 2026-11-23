<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Developer's own actions are kept out of the Audit Log that school staff see
 * (Headmaster / Administrator / Class Teacher). Nothing is deleted: the Developer's
 * own pages still show the complete log.
 */
class DeveloperAuditHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'System Developer',
            'role' => 'developer', 'title' => 'Developer', 'active' => 1, 'must_change_password' => 0]);

        SchoolService::logAction($this->hm, 'EDIT_USER', 'headmaster edited an account');
        SchoolService::logAction($this->dev, 'CREATE_BACKUP', 'devuser made a backup');
        SchoolService::logAction($this->dev, 'EDIT_STUDENT', 'devuser corrected a student');   // a school action done BY the developer
        SchoolService::logAction(null, 'IDLE_LOGOUT', 'system cleanup entry');                  // system action (user_id NULL)
    }

    public function test_headmaster_audit_log_hides_developer_actions_but_keeps_school_and_system(): void
    {
        $html = $this->actingAs($this->hm)->get('/audit-logs')->assertOk()->getContent();

        $this->assertStringContainsString('headmaster edited an account', $html);
        $this->assertStringContainsString('system cleanup entry', $html);
        $this->assertStringNotContainsString('devuser made a backup', $html);
        $this->assertStringNotContainsString('devuser corrected a student', $html);
    }

    public function test_developer_sees_the_complete_audit_log(): void
    {
        $html = $this->actingAs($this->dev)->get('/audit-logs')->assertOk()->getContent();

        $this->assertStringContainsString('devuser made a backup', $html);
        $this->assertStringContainsString('devuser corrected a student', $html);
        $this->assertStringContainsString('headmaster edited an account', $html);
    }

    public function test_school_dashboard_recent_activity_hides_developer_actions(): void
    {
        $html = $this->actingAs($this->hm)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('devuser made a backup', $html);
        $this->assertStringNotContainsString('devuser corrected a student', $html);
    }

    public function test_scope_keeps_everything_when_no_developer_exists(): void
    {
        $this->dev->delete();
        $visible = AuditLog::schoolVisible()->pluck('details')->all();
        $this->assertContains('headmaster edited an account', $visible);
        $this->assertContains('system cleanup entry', $visible);
    }

    public function test_nothing_is_deleted_from_the_table(): void
    {
        // Every entry still exists in the database; only the school view filters them.
        $this->assertSame(4, AuditLog::whereIn('details', [
            'headmaster edited an account', 'devuser made a backup', 'devuser corrected a student', 'system cleanup entry',
        ])->count());
    }
}
