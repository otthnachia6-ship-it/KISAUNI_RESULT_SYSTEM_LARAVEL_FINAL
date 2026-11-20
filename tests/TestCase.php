<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** Temporary backup folder used by every test (unless a test sets its own). */
    private ?string $testBackupDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Safety backups (e.g. before a promotion) made during tests must never land in the
        // real backup folder (../kisauni_backups), where they would show up as real backups.
        $this->testBackupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kis_test_bk_' . uniqid();
        config(['kisauni.backup.path' => $this->testBackupDir]);
    }

    protected function tearDown(): void
    {
        if ($this->testBackupDir && is_dir($this->testBackupDir)) {
            foreach (glob($this->testBackupDir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->testBackupDir);
        }
        parent::tearDown();
    }
}
