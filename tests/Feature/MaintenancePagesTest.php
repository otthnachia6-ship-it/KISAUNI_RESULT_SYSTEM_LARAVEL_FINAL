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

    public function test_pages_embed_the_real_uploaded_school_logo(): void
    {
        // The live school logo is an upload (uploads/logo_<time>.jpeg) served by the app,
        // which is down during maintenance: the page must carry the image inside itself.
        $dir = storage_path('app/uploads');
        @mkdir($dir, 0755, true);
        $name = 'logo_test_' . uniqid() . '.jpeg';
        $bytes = "\xFF\xD8\xFF\xE0" . str_repeat('kisauni-logo-bytes', 20) . "\xFF\xD9";
        file_put_contents("{$dir}/{$name}", $bytes);
        \App\Models\Setting::set('logo_path', "uploads/{$name}");

        try {
            foreach (['maintenance.updating', 'maintenance.suspended'] as $view) {
                $html = view($view)->render();
                $this->assertStringContainsString('src="data:image/jpeg;base64,' . base64_encode($bytes) . '"', $html, $view);
                $this->assertStringNotContainsString('/media/', $html, $view);
            }
        } finally {
            @unlink("{$dir}/{$name}");
        }

        // Missing upload: falls back to the bundled logo, still embedded.
        \App\Models\Setting::set('logo_path', 'uploads/does-not-exist.png');
        $this->assertStringContainsString('src="data:image/png;base64,', view('maintenance.updating')->render());
    }

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

    public function test_support_contact_is_shown_only_when_configured(): void
    {
        foreach (['maintenance.updating', 'maintenance.suspended'] as $name) {
            config(['kisauni.support_contact' => null]);
            $this->assertStringNotContainsString('Mawasiliano', view($name)->render());

            config(['kisauni.support_contact' => '+255 700 000 000']);
            $html = view($name)->render();
            $this->assertStringContainsString('Mawasiliano', $html);
            $this->assertStringContainsString('+255 700 000 000', $html);
        }
        $this->assertStringContainsString('hosting', view('maintenance.suspended')->render());
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
