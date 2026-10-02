<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Pure-PHP scrypt (RFC 7914), used ONLY to verify passwords that were hashed by
 * the old Flask system (Werkzeug "scrypt:N:r:p$salt$hex"). PHP has no built-in
 * scrypt that accepts those parameters, and shared hosting cannot install one.
 *
 * Memory is kept low on purpose: the big scratch table V is stored as packed
 * binary strings (128*r bytes per entry) instead of PHP integer arrays, which
 * is roughly 8x smaller (about 35 MB for N=32768, r=8).
 *
 * It is slow by design (that is what scrypt is for); callers must serialise and
 * gate it - see App\Services\LegacyPasswordService.
 */
final class Scrypt
{
    /** Approximate peak memory in bytes for the given parameters. */
    public static function estimateMemory(int $n, int $r, int $p = 1): int
    {
        return (int) ($n * (128 * $r + 320) + $p * 128 * $r * 8 + 4 * 1024 * 1024);
    }

    /**
     * @return string raw binary key of $dkLen bytes
     */
    public static function derive(string $password, string $salt, int $n, int $r, int $p, int $dkLen): string
    {
        if ($n < 2 || ($n & ($n - 1)) !== 0 || $r < 1 || $p < 1 || $dkLen < 1) {
            throw new InvalidArgumentException('Invalid scrypt parameters.');
        }

        $blocks = hash_pbkdf2('sha256', $password, $salt, 1, $p * 128 * $r, true);
        $out = '';
        $len = 128 * $r;
        for ($i = 0; $i < $p; $i++) {
            $out .= self::roMix(substr($blocks, $i * $len, $len), $n, $r);
        }

        return hash_pbkdf2('sha256', $password, $out, 1, $dkLen, true);
    }

    private static function roMix(string $blockBytes, int $n, int $r): string
    {
        $x = array_values(unpack('V*', $blockBytes));
        $v = [];
        $last = (2 * $r - 1) * 16;
        $mask = $n - 1;

        for ($i = 0; $i < $n; $i++) {
            $v[$i] = pack('V*', ...$x);
            $x = self::blockMix($x, $r);
        }
        for ($i = 0; $i < $n; $i++) {
            $j = $x[$last] & $mask;
            $vj = unpack('V*', $v[$j]);
            $k = 0;
            foreach ($vj as $w) {
                $x[$k++] ^= $w;
            }
            $x = self::blockMix($x, $r);
        }

        return pack('V*', ...$x);
    }

    /** @param int[] $b 32*r little-endian words */
    private static function blockMix(array $b, int $r): array
    {
        $two = 2 * $r;
        // X = last 64-byte block
        $x = array_slice($b, ($two - 1) * 16, 16);
        $even = [];
        $odd = [];

        for ($i = 0; $i < $two; $i++) {
            $o = $i * 16;
            // salsa20/8 on (X xor B[i]) with all 16 words in local variables
            $j0 = $x[0] ^ $b[$o];       $j1 = $x[1] ^ $b[$o + 1];   $j2 = $x[2] ^ $b[$o + 2];   $j3 = $x[3] ^ $b[$o + 3];
            $j4 = $x[4] ^ $b[$o + 4];   $j5 = $x[5] ^ $b[$o + 5];   $j6 = $x[6] ^ $b[$o + 6];   $j7 = $x[7] ^ $b[$o + 7];
            $j8 = $x[8] ^ $b[$o + 8];   $j9 = $x[9] ^ $b[$o + 9];   $j10 = $x[10] ^ $b[$o + 10]; $j11 = $x[11] ^ $b[$o + 11];
            $j12 = $x[12] ^ $b[$o + 12]; $j13 = $x[13] ^ $b[$o + 13]; $j14 = $x[14] ^ $b[$o + 14]; $j15 = $x[15] ^ $b[$o + 15];

            $x0 = $j0; $x1 = $j1; $x2 = $j2; $x3 = $j3; $x4 = $j4; $x5 = $j5; $x6 = $j6; $x7 = $j7;
            $x8 = $j8; $x9 = $j9; $x10 = $j10; $x11 = $j11; $x12 = $j12; $x13 = $j13; $x14 = $j14; $x15 = $j15;

            for ($round = 0; $round < 4; $round++) {
                // column round
                $t = ($x0 + $x12) & 0xFFFFFFFF;  $x4 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x4 + $x0) & 0xFFFFFFFF;   $x8 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x8 + $x4) & 0xFFFFFFFF;   $x12 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x12 + $x8) & 0xFFFFFFFF;  $x0 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x5 + $x1) & 0xFFFFFFFF;   $x9 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x9 + $x5) & 0xFFFFFFFF;   $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x13 + $x9) & 0xFFFFFFFF;  $x1 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x1 + $x13) & 0xFFFFFFFF;  $x5 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x10 + $x6) & 0xFFFFFFFF;  $x14 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x14 + $x10) & 0xFFFFFFFF; $x2 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x2 + $x14) & 0xFFFFFFFF;  $x6 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x6 + $x2) & 0xFFFFFFFF;   $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x15 + $x11) & 0xFFFFFFFF; $x3 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x3 + $x15) & 0xFFFFFFFF;  $x7 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x7 + $x3) & 0xFFFFFFFF;   $x11 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x11 + $x7) & 0xFFFFFFFF;  $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                // row round
                $t = ($x0 + $x3) & 0xFFFFFFFF;   $x1 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x1 + $x0) & 0xFFFFFFFF;   $x2 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x2 + $x1) & 0xFFFFFFFF;   $x3 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x3 + $x2) & 0xFFFFFFFF;   $x0 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x5 + $x4) & 0xFFFFFFFF;   $x6 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x6 + $x5) & 0xFFFFFFFF;   $x7 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x7 + $x6) & 0xFFFFFFFF;   $x4 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x4 + $x7) & 0xFFFFFFFF;   $x5 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x10 + $x9) & 0xFFFFFFFF;  $x11 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x11 + $x10) & 0xFFFFFFFF; $x8 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x8 + $x11) & 0xFFFFFFFF;  $x9 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x9 + $x8) & 0xFFFFFFFF;   $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x15 + $x14) & 0xFFFFFFFF; $x12 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x12 + $x15) & 0xFFFFFFFF; $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x13 + $x12) & 0xFFFFFFFF; $x14 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x14 + $x13) & 0xFFFFFFFF; $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
            }

            $x = [
                ($x0 + $j0) & 0xFFFFFFFF, ($x1 + $j1) & 0xFFFFFFFF, ($x2 + $j2) & 0xFFFFFFFF, ($x3 + $j3) & 0xFFFFFFFF,
                ($x4 + $j4) & 0xFFFFFFFF, ($x5 + $j5) & 0xFFFFFFFF, ($x6 + $j6) & 0xFFFFFFFF, ($x7 + $j7) & 0xFFFFFFFF,
                ($x8 + $j8) & 0xFFFFFFFF, ($x9 + $j9) & 0xFFFFFFFF, ($x10 + $j10) & 0xFFFFFFFF, ($x11 + $j11) & 0xFFFFFFFF,
                ($x12 + $j12) & 0xFFFFFFFF, ($x13 + $j13) & 0xFFFFFFFF, ($x14 + $j14) & 0xFFFFFFFF, ($x15 + $j15) & 0xFFFFFFFF,
            ];

            if (($i & 1) === 0) {
                array_push($even, ...$x);
            } else {
                array_push($odd, ...$x);
            }
        }

        return array_merge($even, $odd);
    }
}
