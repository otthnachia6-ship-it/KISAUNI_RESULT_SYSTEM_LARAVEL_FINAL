<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two pages shown while the site is switched off with `artisan down`.
 * Their own markup is fully static. Every view in this app passes through the
 * global view composer in AppServiceProvider, which reads the settings table,
 * so the tests need the test database (RefreshDatabase). On the live site the
 * page is prerendered once by `artisan down --render=...` and then served from
 * a file, so visitors never touch the database while it is switched off.
 */
class MaintenancePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_page_renders_in_english_and_swahili(): void
    {
        $html = view('maintenance.updating')->render();

        $this->assertStringContainsString('under maintenance', $html);
        $this->assertStringContainsString('matengenezo', $html);
        $this->assertStringContainsString('KISAUNI PRIMARY SCHOOL', $html);
    }

    public function test_suspended_page_renders_in_english_and_swahili(): void
    {
        $html = view('maintenance.suspended')->render();

        $this->assertStringContainsString('temporarily suspended', $html);
        $this->assertStringContainsString('imesimamishwa', $html);
        $this->assertStringContainsString('school data is safe', $html);
    }

    public function test_pages_contain_no_form_or_template_leftovers(): void
    {
        foreach (['maintenance.updating', 'maintenance.suspended'] as $name) {
            $html = view($name)->render();
            $this->assertStringNotContainsString('csrf', strtolower($html));
            $this->assertStringNotContainsString('{{', $html);
        }
    }
}
