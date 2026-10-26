<?php

declare(strict_types=1);

namespace Bridge\Infrastructure\Persistence;

use PDO;
use Throwable;

/** Creates the business schema and preserves pending work during additive upgrades. */
final class SchemaMigrator
{
    public function migrate(PDO $connection): void
    {
        $connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS pairs (
                id TEXT PRIMARY KEY,
                github_id TEXT NOT NULL UNIQUE,
                zendesk_id TEXT NOT NULL UNIQUE,
                baseline TEXT,
                plan TEXT,
                conflict TEXT,
                approval TEXT,
                status TEXT NOT NULL DEFAULT 'new',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_run INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                last_success INTEGER
            );
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pair_id TEXT NOT NULL,
                type TEXT NOT NULL,
                details TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            SQL);

        $this->migrateApproval($connection);
    }

    private function migrateApproval(PDO $connection): void
    {
        if ($this->hasApproval($connection)) {
            return;
        }

        $connection->exec('BEGIN IMMEDIATE');
        try {
            // Another process may have completed the upgrade while this connection waited.
            if (!$this->hasApproval($connection)) {
                $connection->exec('ALTER TABLE pairs ADD COLUMN approval TEXT');
            }

            $connection->exec('COMMIT');
        } catch (Throwable $error) {
            $connection->exec('ROLLBACK');
            throw $error;
        }
    }

    private function hasApproval(PDO $connection): bool
    {
        $columns = $connection->query('PRAGMA table_info(pairs)')->fetchAll(PDO::FETCH_ASSOC);

        return in_array('approval', array_column($columns, 'name'), true);
    }
}
