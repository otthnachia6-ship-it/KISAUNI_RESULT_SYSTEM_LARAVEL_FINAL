<?php

namespace App\Console\Commands;

use App\Services\LegacyPasswordService;
use App\Support\Scrypt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures what verifying ONE old Flask (scrypt) password costs on THIS server,
 * so LEGACY_SCRYPT_ENABLED is only switched on with evidence.
 *
 *   php artisan auth:scrypt-benchmark
 *
 * Uses a throw-away password and salt. It reads no passwords and changes nothing.
 * It only inspects the *parameters* of legacy hashes already stored in `users`.
 */
class ScryptBenchmarkCommand extends Command
{
    protected $signature = 'auth:scrypt-benchmark
                            {--n=32768 : scrypt N (the old system used 32768)}
                            {--r=8}
                            {--p=1}
                            {--runs=2 : how many timed runs}';

    protected $description = 'Measure time and memory of one legacy scrypt password check on this server';

    public function handle(): int
    {
        $n = (int) $this->option('n');
        $r = (int) $this->option('r');
        $p = (int) $this->option('p');
        $runs = max(1, min(5, (int) $this->option('runs')));

        $this->line('PHP version:          ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
        $this->line('memory_limit:         ' . ini_get('memory_limit') . '  (web requests may differ from CLI!)');
        $this->line('max_execution_time:   ' . ini_get('max_execution_time') . 's  (CLI is usually 0 = unlimited; the web value matters)');
        $this->line(sprintf('Parameters:           N=%d r=%d p=%d, estimated need ~%.0f MB', $n, $r, $p, Scrypt::estimateMemory($n, $r, $p) / 1048576));
        $this->line('LEGACY_SCRYPT_ENABLED: ' . (LegacyPasswordService::scryptEnabled() ? 'true' : 'false'));

        $this->reportStoredHashes();

        $times = [];
        for ($i = 1; $i <= $runs; $i++) {
            $t = microtime(true);
            Scrypt::derive('benchmark-password', 'benchmark-salt-16', $n, $r, $p, 64);
            $times[] = microtime(true) - $t;
            $this->line(sprintf('Run %d: %.2f s', $i, end($times)));
        }
        $peak = memory_get_peak_usage(true) / 1048576;
        $avg = array_sum($times) / count($times);

        $this->newLine();
        $this->info(sprintf('Average %.2f s per password check, peak PHP memory %.0f MB.', $avg, $peak));

        $limit = LegacyPasswordService::iniBytes((string) ini_get('memory_limit'));
        $maxExec = (int) ini_get('max_execution_time');
        $warnings = [];
        if ($limit !== -1 && $limit < Scrypt::estimateMemory($n, $r, $p) + 16 * 1048576) {
            $warnings[] = 'memory_limit is below what a check needs (the app tries to raise it per request; if the host forbids that, login will report "old password format").';
        }
        if ($maxExec !== 0 && $maxExec < $avg * 3) {
            $warnings[] = "max_execution_time ({$maxExec}s) is too close to the check time; raise it or keep the feature off.";
        }
        if ($avg > 8) {
            $warnings[] = 'Each first login would take more than 8 seconds: prefer a controlled password reset instead.';
        }

        foreach ($warnings as $w) {
            $this->warn($w);
        }
        $this->line($warnings ? 'Verdict: NOT recommended to enable yet.' : 'Verdict: acceptable - LEGACY_SCRYPT_ENABLED=true can be considered (test with one real teacher first).');

        return self::SUCCESS;
    }

    private function reportStoredHashes(): void
    {
        try {
            $counts = [];
            foreach (DB::table('users')->pluck('password') as $hash) {
                if (!LegacyPasswordService::isLegacyHash($hash)) {
                    $counts['native'] = ($counts['native'] ?? 0) + 1;
                    continue;
                }
                $parsed = LegacyPasswordService::parse($hash);
                $key = $parsed
                    ? ($parsed['kind'] === 'scrypt' ? "scrypt N={$parsed['n']} r={$parsed['r']} p={$parsed['p']}" . (LegacyPasswordService::scryptParamsAllowed($parsed) ? '' : ' (OUTSIDE allowed limits)') : "pbkdf2 {$parsed['algo']}")
                    : 'unparsable legacy hash';
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            foreach ($counts as $k => $c) {
                $this->line("Stored password hashes:  {$c} x {$k}");
            }
        } catch (\Throwable) {
            $this->line('Stored password hashes:  (database not reachable, skipped)');
        }
    }
}
