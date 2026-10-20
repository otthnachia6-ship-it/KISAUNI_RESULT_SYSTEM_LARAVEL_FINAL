<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every response carries the basic browser-hardening headers: no framing by other
 * sites (clickjacking), no content-type guessing, and no full URL in the Referer.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertHardened($response): void
    {
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_login_page_has_the_security_headers(): void
    {
        $this->assertHardened($this->get('/login')->assertOk());
    }

    public function test_signed_in_pages_and_redirects_have_the_security_headers(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);

        $this->assertHardened($this->actingAs($hm)->get(route('dashboard'))->assertOk());
        auth()->logout();
        $this->assertHardened($this->get('/students')->assertRedirect());   // guest redirect
    }

    public function test_error_pages_have_the_security_headers(): void
    {
        $this->assertHardened($this->get('/no-such-page-here')->assertNotFound());
    }
}
