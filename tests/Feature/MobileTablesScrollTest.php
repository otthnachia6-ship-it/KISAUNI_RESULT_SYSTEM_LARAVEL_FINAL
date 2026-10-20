<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * On phones a table wider than the screen must scroll inside its own box (.table-responsive).
 * Without it the table spills out of its card, the page becomes wider than the screen and the
 * browser zooms out, leaving blank space and a squeezed layout.
 */
class MobileTablesScrollTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
    }

    /** @return string[] descriptions of tables that are not inside a .table-responsive box */
    private function unscrollableTables(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $bad = [];
        foreach ($xp->query('//main//table') as $i => $table) {
            $wrapped = $xp->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " table-responsive ")]', $table)->length > 0;
            if (!$wrapped) {
                $bad[] = 'table #' . ($i + 1) . ' class="' . $table->getAttribute('class') . '"';
            }
        }
        return $bad;
    }

    public function test_every_table_on_the_main_pages_scrolls_inside_its_own_box(): void
    {
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $exam = Examination::orderBy('id')->first();
        $sid = DB::table('students')->insertGetId([
            'reg_no' => 'M1', 'full_name' => 'Mobile Pupil', 'gender' => 'Female',
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        foreach (DB::table('class_subjects')->where('class_id', $cls->id)->pluck('subject_id') as $sub) {
            DB::table('marks')->insert([
                'student_id' => $sid, 'exam_id' => $exam->id, 'subject_id' => $sub,
                'class_id' => $cls->id, 'score' => 60, 'updated_at' => SchoolService::nowStr(),
            ]);
        }
        DB::table('exam_class_status')->updateOrInsert(
            ['exam_id' => $exam->id, 'class_id' => $cls->id],
            ['status' => 'submitted']
        );

        $urls = [
            '/users', '/audit-logs', '/examinations', '/results', '/settings', '/profile',
            '/dashboard', '/classes', '/subjects', '/students', '/analytics',
            "/students/{$sid}/history", "/reports/student/{$sid}/{$exam->id}",
        ];

        $problems = [];
        $checked = 0;
        foreach ($urls as $url) {
            $res = $this->actingAs($this->hm)->get($url);
            if ($res->getStatusCode() !== 200) {
                continue; // a redirect/other status is not what this test is about
            }
            $checked++;
            foreach ($this->unscrollableTables($res->getContent()) as $bad) {
                $problems[] = "{$url}: {$bad}";
            }
        }
        $this->assertGreaterThan(8, $checked, 'too few pages were actually rendered');
        $this->assertSame([], $problems, "Tables that can overflow a phone screen:\n" . implode("\n", $problems));
    }
}
