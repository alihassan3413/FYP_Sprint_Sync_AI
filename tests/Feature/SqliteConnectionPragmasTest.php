<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SqliteConnectionPragmasTest extends TestCase
{
    public function test_sqlite_pragmas_are_environment_driven(): void
    {
        $this->assertSame(env('DB_JOURNAL_MODE'), config('database.connections.sqlite.journal_mode'));
        $this->assertSame(env('DB_BUSY_TIMEOUT'), config('database.connections.sqlite.busy_timeout'));
        $this->assertSame(env('DB_SYNCHRONOUS'), config('database.connections.sqlite.synchronous'));
    }

    public function test_wal_journal_mode_can_be_applied_to_a_sqlite_connection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlite-pragma-').'.sqlite';
        touch($path);

        config()->set('database.connections.pragma_probe', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ]);

        try {
            $connection = app('db')->connection('pragma_probe');

            $this->assertSame('wal', strtolower((string) $connection->select('PRAGMA journal_mode')[0]->journal_mode));
            $this->assertSame(10000, (int) $connection->select('PRAGMA busy_timeout')[0]->timeout);

            $connection->disconnect();
        } finally {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
