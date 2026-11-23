<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Developer tool: copy uploaded files (logo, photos) that are missing after a move. */
class DeveloperRestoreUploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $dev;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        config(['kisauni.uploads_restore_host' => '203.0.113.9', 'kisauni.uploads_restore_domain' => 'kisaunips.ostexs.com']);
        $this->dir = storage_path('app/uploads');
        @mkdir($this->dir, 0755, true);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'Dev',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*test*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function referenceFiles(): array
    {
        $logo = 'logo_test_' . uniqid() . '.jpeg';
        $photo = 'user_test_' . uniqid() . '.png';
        Setting::set('logo_path', "uploads/{$logo}");
        $cls = SchoolClass::orderBy('id')->first();
        User::create(['username' => 'tch1', 'password' => Hash::make('x-12345'), 'full_name' => 'Teacher', 'role' => 'class_teacher',
            'class_id' => $cls->id, 'active' => 1, 'must_change_password' => 0, 'photo_path' => "uploads/{$photo}"]);

        return [$logo, $photo];
    }

    public function test_missing_files_are_listed_and_copied_from_the_old_server(): void
    {
        [$logo, $photo] = $this->referenceFiles();

        // The old server serves both images.
        Http::fake([
            "https://203.0.113.9/media/{$logo}" => Http::response("\xFF\xD8\xFFreal-logo-bytes-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", 200, ['Content-Type' => 'image/jpeg']),
            "https://203.0.113.9/media/{$photo}" => Http::response("\x89PNGreal-photo-bytes-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($this->dev)->get('/developer/database')->assertOk()
            ->assertSee('Missing uploaded files (2)')->assertSee('Copy missing files from the old server');

        $this->actingAs($this->dev)->post('/developer/database/copy-uploads')
            ->assertRedirect(route('developer.backups.database'))->assertSessionHas('success');

        $this->assertFileExists("{$this->dir}/{$logo}");
        $this->assertFileExists("{$this->dir}/{$photo}");
        $this->assertTrue(AuditLog::where('action', 'COPY_UPLOADS')->where('username', 'devuser')->exists());

        // Now nothing is missing.
        $this->actingAs($this->dev)->get('/developer/database')->assertOk()->assertSee('All uploaded files');
    }

    public function test_a_file_gone_from_the_old_server_has_its_reference_cleared(): void
    {
        [$logo, $photo] = $this->referenceFiles();
        Http::fake([
            "https://203.0.113.9/media/{$logo}" => Http::response('not found', 404),
            "https://203.0.113.9/media/{$photo}" => Http::response("\x89PNG" . str_repeat('ok-photo-bytes-', 6), 200, ['Content-Type' => 'image/png']),
        ]);

        // logo gone (404) -> cleared; photo present (200) -> copied; nothing unreachable -> success
        $this->actingAs($this->dev)->post('/developer/database/copy-uploads')->assertSessionHas('success');
        $this->assertFileExists("{$this->dir}/{$photo}");
        $this->assertFileDoesNotExist("{$this->dir}/{$logo}");
        // the dangling logo reference is reset to the bundled logo
        $this->assertSame('images/logo.png', Setting::get('logo_path'));
        // the teacher's photo now exists, so its reference is kept
        $this->assertSame("uploads/{$photo}", User::where('username', 'tch1')->value('photo_path'));
    }

    public function test_a_user_photo_gone_from_the_old_server_clears_that_users_photo(): void
    {
        [$logo, $photo] = $this->referenceFiles();
        Http::fake([
            "https://203.0.113.9/media/{$logo}" => Http::response("\xFF\xD8\xFF" . str_repeat('logo-bytes-', 6), 200, ['Content-Type' => 'image/jpeg']),
            "https://203.0.113.9/media/{$photo}" => Http::response('not found', 404),
        ]);

        $this->actingAs($this->dev)->post('/developer/database/copy-uploads')->assertSessionHas('success');
        $this->assertNull(User::where('username', 'tch1')->value('photo_path'));
        $this->assertFileExists("{$this->dir}/{$logo}");
    }

    public function test_non_image_response_is_rejected(): void
    {
        [$logo] = $this->referenceFiles();
        Http::fake(['*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($this->dev)->post('/developer/database/copy-uploads');
        $this->assertFileDoesNotExist("{$this->dir}/{$logo}");
    }

    public function test_school_accounts_cannot_use_it(): void
    {
        $this->referenceFiles();
        Http::fake();
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $this->actingAs($hm)->post('/developer/database/copy-uploads')->assertNotFound();
        $this->actingAs($hm)->get('/developer/database')->assertNotFound();
        Http::assertNothingSent();
    }
}
