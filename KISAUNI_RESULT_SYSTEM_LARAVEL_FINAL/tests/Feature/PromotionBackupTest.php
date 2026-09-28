<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\BackupService;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two flows the main FullWorkflowTest does not exercise:
 * year-end promotion (moving real students between classes) and the
 * Settings > Backup feature (create / list / download).
 */
class PromotionBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_promotion_moves_students_to_target_class(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class1 = SchoolClass::where('standard', 1)->orderBy('id')->first();
        $class2 = SchoolClass::where('standard', 2)->orderBy('id')->first();

        $student = Student::create([
            'reg_no' => 'PROMO-001', 'full_name' => 'Amina Promo', 'gender' => 'Female',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        $r = $this->actingAs($hm)->post('/promote', [
            'new_academic_year' => '',
            'target_' . $class1->id => $class2->id,
        ]);
        $r->assertStatus(302);

        $student->refresh();
        $this->assertEquals($class2->id, $student->class_id, 'student should have moved to the target class');
    }

    public function test_backup_create_list_and_download(): void
    {
        // BackupService's sqlite path copies the real database file, so this
        // specific test needs a file-backed sqlite connection rather than
        // ':memory:'. It is skipped (not failed) when only :memory: is
        // configured, e.g. in phpunit.xml's default test environment.
        if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->markTestSkipped('Backup-file test needs a file-backed sqlite or a real mysql connection, not :memory:.');
        }

        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);

        $r = $this->actingAs($hm)->post('/settings/backup/create');
        $r->assertStatus(302);

        $backups = BackupService::listBackups();
        $this->assertNotEmpty($backups, 'a backup file should exist after create-backup');

        $fname = $backups[0]['filename'];
        $r = $this->get('/settings/backup/download/' . urlencode($fname));
        $r->assertStatus(200);
    }
}
