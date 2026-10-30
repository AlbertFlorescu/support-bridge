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
            CREATE TABLE IF NOT EXISTS escalations (
                ticket_id TEXT PRIMARY KEY,
                pair_id TEXT NOT NULL UNIQUE,
                marker TEXT NOT NULL UNIQUE,
                snapshot TEXT NOT NULL,
                github_id TEXT,
                status TEXT NOT NULL DEFAULT 'prepared',
                attempted_at INTEGER,
                next_run INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS comment_deliveries (
                pair_id TEXT NOT NULL REFERENCES pairs(id),
                comment_id TEXT NOT NULL,
                note TEXT NOT NULL,
                marker TEXT NOT NULL UNIQUE,
                source_version TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempted_at INTEGER,
                destination_version TEXT,
                zendesk_comment_id TEXT,
                next_run INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(pair_id,comment_id)
            );
            CREATE TABLE IF NOT EXISTS comment_streams (
                pair_id TEXT PRIMARY KEY REFERENCES pairs(id),
                last_scan INTEGER NOT NULL DEFAULT 0,
                next_run INTEGER NOT NULL DEFAULT 0,
                error TEXT
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
