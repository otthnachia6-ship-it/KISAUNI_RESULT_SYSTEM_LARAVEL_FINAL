<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Uploaded profile photos and school logos must be real PNG/JPEG images, and the
 * /media route must never let a browser treat an upload as a web page.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private array $before = [];
    private User $hm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);

        $this->dir = storage_path('app/uploads');
        File::ensureDirectoryExists($this->dir);
        $this->before = array_map('basename', File::files($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (File::files($this->dir) as $f) {
            if (!in_array($f->getFilename(), $this->before, true)) {
                File::delete($f->getPathname());
            }
        }
        parent::tearDown();
    }

    private function newFiles(): array
    {
        return array_values(array_diff(array_map('basename', File::files($this->dir)), $this->before));
    }

    private function html(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '<html><script>alert(document.cookie)</script></html>');
    }

    public function test_html_disguised_as_a_photo_is_rejected(): void
    {
        $this->actingAs($this->hm)
            ->post('/profile', ['action' => 'upload_photo', 'photo' => $this->html('evil.jpg')])
            ->assertSessionHas('danger');

        $this->assertNull($this->hm->fresh()->photo_path);
        $this->assertSame([], $this->newFiles(), 'a disguised HTML file must not be stored');
    }

    public function test_html_disguised_as_a_logo_is_rejected(): void
    {
        $this->actingAs($this->hm)
            ->post('/settings', ['school_name' => 'Test School', 'academic_year' => '2026', 'logo' => $this->html('logo.png')])
            ->assertSessionHas('danger');

        $this->assertSame([], $this->newFiles(), 'a disguised HTML file must not be stored');
    }

    public function test_real_png_and_jpeg_photos_still_work(): void
    {
        $this->actingAs($this->hm)
            ->post('/profile', ['action' => 'upload_photo', 'photo' => UploadedFile::fake()->image('me.png', 40, 40)])
            ->assertSessionMissing('danger');
        $png = $this->hm->fresh()->photo_path;
        $this->assertMatchesRegularExpression('#^uploads/user_\d+_\d+\.png$#', (string) $png);

        // a real JPEG called .jpeg is stored with a plain .jpg extension
        $this->actingAs($this->hm)
            ->post('/profile', ['action' => 'upload_photo', 'photo' => UploadedFile::fake()->image('me.jpeg', 40, 40)])
            ->assertSessionMissing('danger');
        $this->assertMatchesRegularExpression('#^uploads/user_\d+_\d+\.jpg$#', (string) $this->hm->fresh()->photo_path);
    }

    public function test_media_serves_images_with_their_real_type_and_nosniff(): void
    {
        $name = 'zz_test_real.png';
        UploadedFile::fake()->image($name, 20, 20)->move($this->dir, $name);

        $r = $this->get('/media/' . $name)->assertOk();
        $this->assertSame('image/png', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function test_media_never_serves_html_even_if_it_is_in_the_uploads_folder(): void
    {
        File::put($this->dir . '/zz_test_evil.jpg', '<html><script>alert(1)</script></html>');
        File::put($this->dir . '/zz_test_page.html', '<html>hi</html>');

        $r = $this->get('/media/zz_test_evil.jpg')->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'), 'type comes from the extension whitelist, never from the content');
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        $this->get('/media/zz_test_page.html')->assertNotFound();
    }
}
