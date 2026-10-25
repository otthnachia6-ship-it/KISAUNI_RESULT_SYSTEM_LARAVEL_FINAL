<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notices such as "Student X registered successfully" contain text typed by users.
 * It must be shown as plain text, never as HTML.
 */
class FlashMessageEscapingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_name_with_html_is_shown_as_text_in_the_notice(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class = SchoolClass::orderBy('sort_order')->first();

        $payload = '<img src=x onerror=alert(1)>';
        $this->actingAs($hm)->post('/students/add', [
            'reg_no' => 'XSS1', 'full_name' => $payload, 'gender' => 'Male', 'class_id' => $class->id,
        ])->assertRedirect(route('students'));

        $page = $this->get('/students');
        $page->assertSee('registered successfully');
        $page->assertDontSee($payload, false);          // raw HTML must not reach the browser
        $page->assertSee('&lt;img src=x onerror=alert(1)&gt;', false); // it is shown as harmless text
    }
}
