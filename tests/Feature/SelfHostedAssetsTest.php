<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The system must not depend on other websites to look and work right
 * (Bootstrap, icons, fonts and charts are served from /public/vendor).
 */
class SelfHostedAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const EXTERNAL_HOSTS = [
        'cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com',
        'unpkg.com', 'cdnjs.cloudflare.com', 'code.jquery.com', 'stackpath.bootstrapcdn.com',
    ];

    private function ownFiles(): array
    {
        $files = [];
        foreach (File::allFiles(resource_path('views')) as $f) {
            $files[] = $f->getPathname();
        }
        foreach (['css', 'js'] as $dir) {
            foreach (File::files(public_path($dir)) as $f) {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }

    public function test_no_view_stylesheet_or_script_points_to_another_website(): void
    {
        $found = [];
        foreach ($this->ownFiles() as $path) {
            $text = File::get($path);
            foreach (self::EXTERNAL_HOSTS as $host) {
                if (str_contains($text, $host)) {
                    $found[] = basename($path) . ' -> ' . $host;
                }
            }
        }
        $this->assertSame([], $found, 'these files still load something from another website');
    }

    public function test_every_vendor_file_used_by_the_views_exists(): void
    {
        $missing = [];
        $seen = 0;
        foreach (File::allFiles(resource_path('views')) as $f) {
            preg_match_all("#asset\('(vendor/[^']+)'\)#", File::get($f->getPathname()), $m);
            foreach ($m[1] as $rel) {
                $seen++;
                if (!is_file(public_path($rel))) {
                    $missing[] = $rel;
                }
            }
        }
        $this->assertGreaterThan(0, $seen, 'the views should reference files in public/vendor');
        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_font_files_named_inside_the_vendor_stylesheets_exist(): void
    {
        $missing = [];
        foreach (['vendor/inter/5.3.0/inter.css', 'vendor/bootstrap-icons/1.11.3/bootstrap-icons.css'] as $css) {
            $this->assertFileExists(public_path($css));
            preg_match_all('#url\(\s*["\']?([^)"\'?]+\.woff2?)#', File::get(public_path($css)), $m);
            foreach ($m[1] as $url) {
                if (!is_file(public_path(dirname($css) . '/' . $url))) {
                    $missing[] = $css . ' -> ' . $url;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_login_page_uses_local_bootstrap_icons_and_font(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('/vendor/bootstrap/5.3.3/css/bootstrap.min.css', $html);
        $this->assertStringContainsString('/vendor/bootstrap-icons/1.11.3/bootstrap-icons.css', $html);
        $this->assertStringContainsString('/vendor/inter/5.3.0/inter.css', $html);
        $this->assertStringContainsString('/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js', $html);
        foreach (self::EXTERNAL_HOSTS as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
    }
}
