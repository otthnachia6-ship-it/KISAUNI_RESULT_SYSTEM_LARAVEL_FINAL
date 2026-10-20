<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes users.username an exact-match (case-sensitive) column on MySQL/MariaDB.
 *
 * Why: the old Flask/SQLite system treated "damtu" and "DAMTU" as two different
 * teachers, but the default utf8mb4_unicode_ci collation treats them as the same
 * value, so the unique index would reject the second one and login lookups
 * could hit the wrong account.
 *
 * Safety:
 *  - Metadata/index change only: no rows are modified, deleted or re-inserted.
 *  - utf8mb4_bin is stricter than utf8mb4_unicode_ci, so it can never create a
 *    duplicate that did not already exist.
 *  - SQLite (used by tests) is already case-sensitive: nothing to do.
 *  - down() refuses to revert while two usernames differ only by case, because
 *    reverting would then fail or force renames.
 */
return new class extends Migration
{
    private function isMySql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        DB::statement('ALTER TABLE `users` MODIFY `username` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        $clashes = DB::table('users')
            ->select(DB::raw('LOWER(username) AS lowered'), DB::raw('COUNT(*) AS c'))
            ->groupBy(DB::raw('LOWER(username)'))
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($clashes > 0) {
            throw new RuntimeException(
                'Cannot revert: some usernames differ only by upper/lower case (for example damtu and DAMTU). '
                . 'Reverting to a case-insensitive collation would break them.'
            );
        }

        DB::statement('ALTER TABLE `users` MODIFY `username` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
