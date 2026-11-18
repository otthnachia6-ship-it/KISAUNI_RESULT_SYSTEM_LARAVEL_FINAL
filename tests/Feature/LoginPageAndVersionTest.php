<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The redesigned login page keeps its content and form, shows who developed the system,
 * and every signed-in page shows the system version at the very bottom.
 */
class LoginPageAndVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_login_page_keeps_its_content_and_form(): void
    {
        $page = $this->get(route('login'))->assertOk();

        foreach (['Result Management System',
                  'Student Records', 'Marks &amp; Approval', 'PDF Reports', 'Performance Analytics',
                  'Sign in to the System', 'Use your personal portal credentials',
                  'Need quick help?', 'Forgot Password?'] as $text) {
            $page->assertSee($text, false);
        }
        // no sales-pitch wording on the sign-in page
        $page->assertDontSee('Manage Results');
        $page->assertDontSee('Measure Progress');
        $page->assertDontSee('Drive Success');
        $page->assertDontSee('Use your Class Teacher or Headmaster account');
        $page->assertSee('name="username"', false);
        $page->assertSee('name="password"', false);
        $page->assertSee('action="' . route('login') . '"', false);
        $page->assertSee('href="' . route('forgot_password') . '"', false);
        $page->assertDontSee('Remember me');   // the system never keeps people signed in
    }

    public function test_login_page_names_the_developer_at_the_very_bottom_with_a_link(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $line = 'Developed by Ostex Global Technologies';
        $this->assertStringContainsString($line, $html);
        $this->assertStringContainsString('href="https://ostexs.com"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        // it is the last visible text of the page, after the copyright line
        $this->assertGreaterThan(strrpos($html, 'All rights reserved.'), strrpos($html, $line));
    }

    public function test_every_signed_in_page_shows_the_version_at_the_bottom(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);

        $this->assertSame('1.0', config('app.version'));

        foreach (['dashboard', 'students', 'subjects', 'examinations'] as $name) {
            $this->actingAs($hm)->get(route($name))->assertOk()
                ->assertSee('Version 1.0', false);
        }
    }

    public function test_version_follows_the_config_value(): void
    {
        config(['app.version' => '1.4']);
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);

        $this->actingAs($hm)->get(route('dashboard'))->assertOk()->assertSee('Version 1.4', false);
    }
}
