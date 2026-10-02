<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LegacyPasswordService as L;
use App\Services\SchoolService;
use App\Support\Scrypt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Old Flask (Werkzeug) passwords: verify at login, then re-hash to Laravel's hasher.
 * The hashes below were produced by the real Python werkzeug library with
 * throw-away passwords (they are NOT real accounts).
 */
class LegacyPasswordMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SCRYPT_A = ['pw' => 'Teacher-A#2026', 'hash' => 'scrypt:1024:8:1$nkifCJZOAZyhCIOQ$7d62488a00a200e331debf00a6af1665d9bdb33c10a99dd70ceef8cafa5b08a0365a8741cf8294b8dd1247be7344a976d842e0f3a618a2c10106228574f30e7e'];
    private const SCRYPT_B = ['pw' => 'Teacher-B#2026', 'hash' => 'scrypt:1024:8:1$aInKO7USgyAzVn5z$2b396f1b8a3d75b28f792736348d6c93647448887eea5c31f633915a1a82d04e08a682176aa9eaf0158d47bd8afd3479944a178fc4608fad6712852fb1031f7e'];
    private const SCRYPT_UNI = ['pw' => 'Kiarabu#عارف', 'hash' => 'scrypt:1024:8:1$3ygR589wHprnBTwg$eaab22ad837b0fa32acecafd10e2e44e1671b6c9fe4aea762747b8ff45ab26dedb90125121646f14c1ae6de417a4aa5581b75c3f8391e610369a4dc7bf1025c6'];
    /** Same shape/strength as the real accounts: scrypt:32768:8:1, 16-char salt, 128 hex chars (162 chars). */
    private const SCRYPT_FULL = ['pw' => 'Full#Strength1', 'hash' => 'scrypt:32768:8:1$zVvsBUFUlfxZVbim$6d34e6ff187ef74566a87eb0d282db3e453571017b4414738c4f82c80c36e6f8fde897fb847e682fb8c164d5cb17ea941ca95d9c722209ee7461820caf5083bd'];
    private const PBKDF2 = ['pw' => 'Old#Pbkdf2-Pw', 'hash' => 'pbkdf2:sha256:600000$N6yieAjQJO1zPuHV$0c2089e652279e9ca5a0eefc3a55e00aae7c82aff83778eb84125496ef51ee66'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        config(['kisauni.legacy_scrypt.enabled' => true, 'kisauni.legacy_scrypt.wait_seconds' => 1]);
    }

    private function teacher(string $username, string $hash, int $standard = 5): User
    {
        $class = SchoolClass::where('standard', $standard)->orderBy('id')->first();
        return User::create([
            'username' => $username, 'password' => $hash, 'full_name' => "T {$username}", 'role' => 'class_teacher',
            'class_id' => $class->id, 'active' => 1, 'must_change_password' => 0, 'created_at' => SchoolService::nowStr(),
        ]);
    }

    // ---------------------------------------------------------- primitives

    public function test_scrypt_matches_the_rfc7914_test_vectors(): void
    {
        $this->assertSame(
            '77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906',
            bin2hex(Scrypt::derive('', '', 16, 1, 1, 64))
        );
        $this->assertSame(
            'fdbabe1c9d3472007856e7190d01e9fe7c6ad7cbc8237830e77376634b3731622eaf30d92e22a3886ff109279d9830dac727afb94a83ee6d8360cbdfa2cc0640',
            bin2hex(Scrypt::derive('password', 'NaCl', 1024, 8, 16, 64))
        );
    }

    public function test_werkzeug_scrypt_hashes_verify_correctly(): void
    {
        $this->assertSame(L::OK, L::verify(self::SCRYPT_A['pw'], self::SCRYPT_A['hash']));
        $this->assertSame(L::WRONG, L::verify('wrong-password', self::SCRYPT_A['hash']));
        $this->assertSame(L::WRONG, L::verify(self::SCRYPT_B['pw'], self::SCRYPT_A['hash']), 'another teacher\'s password must not verify');
        $this->assertSame(L::OK, L::verify(self::SCRYPT_UNI['pw'], self::SCRYPT_UNI['hash']), 'non-ASCII passwords');
    }

    public function test_full_strength_scrypt_32768_8_1_verifies_and_stays_within_memory_budget(): void
    {
        $before = memory_get_peak_usage(true);
        $this->assertSame(L::OK, L::verify(self::SCRYPT_FULL['pw'], self::SCRYPT_FULL['hash']));
        $extra = (memory_get_peak_usage(true) - $before) / 1048576;
        $this->assertLessThan(80, $extra, "peak extra memory {$extra} MB");
        $this->assertSame(162, strlen(self::SCRYPT_FULL['hash']), 'same length as the 18 real Flask hashes');
    }

    public function test_pbkdf2_werkzeug_hashes_still_work(): void
    {
        $this->assertSame(L::OK, L::verify(self::PBKDF2['pw'], self::PBKDF2['hash']));
        $this->assertSame(L::WRONG, L::verify('nope', self::PBKDF2['hash']));
    }

    public function test_parameters_are_range_checked_before_any_work_is_done(): void
    {
        $evil = 'scrypt:1048576:8:1$salt$' . str_repeat('ab', 64);
        $t = microtime(true);
        $this->assertSame(L::UNVERIFIABLE, L::verify('x', $evil));
        $this->assertLessThan(1.0, microtime(true) - $t);
        $this->assertSame(L::UNVERIFIABLE, L::verify('x', 'scrypt:1000:8:1$salt$abcd'), 'N not a power of two');
        $this->assertSame(L::UNVERIFIABLE, L::verify('x', 'scrypt:1024:8:99$salt$abcd'), 'p too large');
        $this->assertSame(L::UNVERIFIABLE, L::verify('x', 'scrypt:garbage'));
        $this->assertSame(L::UNVERIFIABLE, L::verify('x', 'pbkdf2:md99:10$salt$abcd'));
    }

    public function test_parse_reads_the_real_flask_hash_layout(): void
    {
        $p = L::parse(self::SCRYPT_FULL['hash']);
        $this->assertSame(['scrypt', 32768, 8, 1, 16, 128], [$p['kind'], $p['n'], $p['r'], $p['p'], strlen($p['salt']), strlen($p['hex'])]);
        $this->assertTrue(L::scryptParamsAllowed($p));
    }

    // -------------------------------------------------------------- login

    public function test_login_with_old_flask_password_succeeds_and_rehashes_to_laravel_native(): void
    {
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);

        $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u->fresh());

        $u->refresh();
        $this->assertStringStartsWith('$2y$', $u->password, 'stored hash must now be Laravel bcrypt');
        $this->assertTrue(Hash::check(self::SCRYPT_A['pw'], $u->password), 'same password keeps working');
        $this->assertFalse(L::isLegacyHash($u->password));

        // next login uses the native hash (no scrypt involved, even if disabled)
        auth()->logout();
        config(['kisauni.legacy_scrypt.enabled' => false]);
        $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u->fresh());
    }

    public function test_role_class_and_flags_are_untouched_by_the_rehash(): void
    {
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);
        $before = $u->only(['role', 'class_id', 'active', 'must_change_password', 'full_name', 'username']);
        $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']]);
        $this->assertSame($before, $u->fresh()->only(['role', 'class_id', 'active', 'must_change_password', 'full_name', 'username']));
    }

    public function test_wrong_password_is_rejected_counted_and_the_old_hash_is_kept(): void
    {
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);
        $this->post('/login', ['username' => 'thamra', 'password' => 'not-it'])->assertSessionHas('danger');
        $this->assertGuest();
        $u->refresh();
        $this->assertSame(self::SCRYPT_A['hash'], $u->password, 'a failed login must never change the stored hash');
        $this->assertSame(1, $u->failed_login_attempts);
    }

    public function test_lockout_applies_to_legacy_accounts_after_repeated_failures(): void
    {
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'thamra', 'password' => 'bad' . $i]);
        }
        $this->assertNotNull($u->fresh()->locked_until);
        // once locked, even the right password is refused and no scrypt work is done
        $t = microtime(true);
        $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']])->assertSessionHas('danger');
        $this->assertGuest();
        $this->assertLessThan(0.5, microtime(true) - $t);
    }

    public function test_when_the_feature_is_off_teachers_get_the_reset_message_and_nothing_is_changed(): void
    {
        config(['kisauni.legacy_scrypt.enabled' => false]);
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);

        $r = $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']]);
        $r->assertSessionHas('danger', fn ($m) => str_contains($m, 'old password format'));
        $this->assertGuest();
        $u->refresh();
        $this->assertSame(self::SCRYPT_A['hash'], $u->password);
        $this->assertSame(0, $u->failed_login_attempts, 'an unverifiable hash is not a wrong-password attempt');
    }

    public function test_busy_server_is_reported_without_counting_a_failed_attempt(): void
    {
        $u = $this->teacher('thamra', self::SCRYPT_A['hash']);
        $held = Cache::lock('legacy-scrypt-verify', 30);
        $this->assertTrue($held->get());
        try {
            $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']])
                ->assertSessionHas('danger', fn ($m) => str_contains($m, 'busy'));
        } finally {
            $held->release();
        }
        $this->assertGuest();
        $this->assertSame(0, $u->fresh()->failed_login_attempts);
        $this->assertSame(self::SCRYPT_A['hash'], $u->fresh()->password);
    }

    public function test_pbkdf2_accounts_also_migrate_at_login(): void
    {
        $u = $this->teacher('oldpb', self::PBKDF2['hash']);
        $this->post('/login', ['username' => 'oldpb', 'password' => self::PBKDF2['pw']])->assertRedirect(route('dashboard'));
        $this->assertStringStartsWith('$2y$', $u->fresh()->password);
    }

    public function test_damtu_and_DAMTU_keep_separate_legacy_passwords(): void
    {
        $lower = $this->teacher('damtu', self::SCRYPT_A['hash'], 5);
        $upper = $this->teacher('DAMTU', self::SCRYPT_B['hash'], 7);

        // damtu's password must not open DAMTU and vice versa
        $this->post('/login', ['username' => 'DAMTU', 'password' => self::SCRYPT_A['pw']]);
        $this->assertGuest();
        $this->post('/login', ['username' => 'damtu', 'password' => self::SCRYPT_B['pw']]);
        $this->assertGuest();

        $this->post('/login', ['username' => 'damtu', 'password' => self::SCRYPT_A['pw']])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($lower->fresh());
        $this->assertStringStartsWith('$2y$', $lower->fresh()->password);
        $this->assertSame(self::SCRYPT_B['hash'], $upper->fresh()->password, 'the other teacher\'s hash is untouched');
        auth()->logout();

        $this->post('/login', ['username' => 'DAMTU', 'password' => self::SCRYPT_B['pw']])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($upper->fresh());
    }

    public function test_no_password_or_hash_is_written_to_the_audit_log(): void
    {
        $this->teacher('thamra', self::SCRYPT_A['hash']);
        $this->post('/login', ['username' => 'thamra', 'password' => self::SCRYPT_A['pw']]);
        $this->assertTrue(AuditLog::where('action', 'PASSWORD_HASH_UPGRADED')->exists());
        foreach (AuditLog::all() as $row) {
            $blob = json_encode($row->toArray());
            $this->assertStringNotContainsString(self::SCRYPT_A['pw'], $blob);
            $this->assertStringNotContainsString('scrypt:', $blob);
            $this->assertStringNotContainsString('$2y$', $blob);
        }
    }

    public function test_existing_laravel_bcrypt_accounts_still_log_in(): void
    {
        $u = $this->teacher('newuser', Hash::make('Laravel#Native1'));
        $this->post('/login', ['username' => 'newuser', 'password' => 'Laravel#Native1'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u->fresh());
        auth()->logout();
        $this->post('/login', ['username' => 'newuser', 'password' => 'wrong'])->assertSessionHas('danger');
        $this->assertGuest();
    }
}
