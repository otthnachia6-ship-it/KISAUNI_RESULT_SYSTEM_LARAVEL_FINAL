<?php

namespace App\Services;

use App\Support\Scrypt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies passwords stored by the old Flask system (Werkzeug hashes):
 *
 *   scrypt:32768:8:1$<salt>$<128 hex chars>      (what the real accounts use)
 *   pbkdf2:sha256:<iterations>$<salt>$<hex>      (older Werkzeug default)
 *
 * After a successful login the caller re-hashes the password with Laravel's own
 * hasher, so each teacher pays the (one-off) scrypt cost once.
 *
 * Safety:
 *  - scrypt verification is OFF unless LEGACY_SCRYPT_ENABLED=true, because it is
 *    CPU/memory heavy and must be proven on the real hosting first
 *    (php artisan auth:scrypt-benchmark).
 *  - Only one scrypt verification runs at a time (cache lock) so a burst of
 *    logins cannot exhaust the shared-hosting memory.
 *  - Parameters found in a stored hash are range-checked before use.
 *  - Neither the password nor the hash is ever logged.
 */
class LegacyPasswordService
{
    public const OK = 'ok';
    public const WRONG = 'wrong';
    /** Hash is a legacy one but this server cannot / must not verify it (disabled, too heavy, unknown variant). */
    public const UNVERIFIABLE = 'unverifiable';
    /** Another scrypt check is running and did not finish in time - not a wrong password. */
    public const BUSY = 'busy';

    public static function isLegacyHash(?string $hash): bool
    {
        return is_string($hash) && (str_starts_with($hash, 'scrypt:') || str_starts_with($hash, 'pbkdf2:'));
    }

    /** True for hashes PHP's own password API understands (bcrypt/argon2). */
    public static function isNativeHash(?string $hash): bool
    {
        return is_string($hash) && (password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown';
    }

    /**
     * Parse "method$salt$hex" without verifying anything.
     *
     * @return array{kind:string,n?:int,r?:int,p?:int,algo?:string,iterations?:int,salt:string,hex:string}|null
     */
    public static function parse(string $hash): ?array
    {
        $parts = explode('$', $hash);
        if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '' || !ctype_xdigit($parts[2]) || strlen($parts[2]) % 2 !== 0) {
            return null;
        }
        [$method, $salt, $hex] = $parts;
        $bits = explode(':', $method);

        if (($bits[0] ?? '') === 'scrypt' && count($bits) === 4 && ctype_digit($bits[1] . $bits[2] . $bits[3])) {
            return ['kind' => 'scrypt', 'n' => (int) $bits[1], 'r' => (int) $bits[2], 'p' => (int) $bits[3], 'salt' => $salt, 'hex' => strtolower($hex)];
        }
        if (($bits[0] ?? '') === 'pbkdf2' && count($bits) === 3 && ctype_digit($bits[2])) {
            return ['kind' => 'pbkdf2', 'algo' => strtolower($bits[1]), 'iterations' => (int) $bits[2], 'salt' => $salt, 'hex' => strtolower($hex)];
        }
        return null;
    }

    public static function scryptEnabled(): bool
    {
        return (bool) config('kisauni.legacy_scrypt.enabled', false);
    }

    /** Are the stored parameters inside the limits this server is willing to run? */
    public static function scryptParamsAllowed(array $parsed): bool
    {
        $n = $parsed['n'];
        $r = $parsed['r'];
        $p = $parsed['p'];
        if ($n < 2 || ($n & ($n - 1)) !== 0 || $n > (int) config('kisauni.legacy_scrypt.max_n', 65536)) {
            return false;
        }
        if ($r < 1 || $r > 16 || $p < 1 || $p > 4) {
            return false;
        }
        $limit = (int) config('kisauni.legacy_scrypt.max_memory_mb', 128) * 1048576;
        return Scrypt::estimateMemory($n, $r, $p) <= $limit;
    }

    /** @return string one of the class constants */
    public static function verify(string $password, string $hash): string
    {
        $parsed = static::parse($hash);
        if (!$parsed) {
            return self::UNVERIFIABLE;
        }

        if ($parsed['kind'] === 'pbkdf2') {
            return static::verifyPbkdf2($password, $parsed);
        }

        return static::verifyScrypt($password, $parsed);
    }

    private static function verifyPbkdf2(string $password, array $p): string
    {
        if (!in_array($p['algo'], hash_algos(), true) || $p['iterations'] < 1 || $p['iterations'] > 5_000_000) {
            return self::UNVERIFIABLE;
        }
        $computed = hash_pbkdf2($p['algo'], $password, $p['salt'], $p['iterations'], strlen($p['hex']), false);
        return hash_equals($p['hex'], strtolower($computed)) ? self::OK : self::WRONG;
    }

    private static function verifyScrypt(string $password, array $p): string
    {
        if (!static::scryptEnabled()) {
            return self::UNVERIFIABLE;
        }
        if (!static::scryptParamsAllowed($p)) {
            Log::warning('Legacy scrypt hash skipped: parameters outside the allowed limits.', ['n' => $p['n'], 'r' => $p['r'], 'p' => $p['p']]);
            return self::UNVERIFIABLE;
        }

        $lock = Cache::lock('legacy-scrypt-verify', 120);
        try {
            $lock->block((int) config('kisauni.legacy_scrypt.wait_seconds', 30));
        } catch (LockTimeoutException) {
            return self::BUSY;
        }

        try {
            if (!static::ensureMemory(Scrypt::estimateMemory($p['n'], $p['r'], $p['p']))) {
                Log::error('Legacy scrypt verification needs more PHP memory_limit than this server allows.');
                return self::UNVERIFIABLE;
            }
            @set_time_limit(max(60, (int) config('kisauni.legacy_scrypt.time_limit_seconds', 60)));

            $dk = Scrypt::derive($password, $p['salt'], $p['n'], $p['r'], $p['p'], intdiv(strlen($p['hex']), 2));
            return hash_equals($p['hex'], bin2hex($dk)) ? self::OK : self::WRONG;
        } catch (Throwable $e) {
            Log::error('Legacy scrypt verification failed unexpectedly: ' . get_class($e));
            return self::UNVERIFIABLE;
        } finally {
            optional($lock)->release();
        }
    }

    /** Raise memory_limit for this request only, when it is too low and PHP allows it. */
    private static function ensureMemory(int $neededBytes): bool
    {
        $current = static::iniBytes((string) ini_get('memory_limit'));
        if ($current === -1) {
            return true;
        }
        $needed = $neededBytes + memory_get_usage(true) + 16 * 1048576;
        if ($current >= $needed) {
            return true;
        }
        @ini_set('memory_limit', (string) $needed);
        return static::iniBytes((string) ini_get('memory_limit')) >= $needed;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $unit = strtolower(substr($v, -1));
        $n = (int) $v;
        return match ($unit) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
