<?php

declare(strict_types=1);

namespace Plugins\Push\Tests\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * An in-memory stand-in for the two tables this plugin owns.
 *
 * Not a SQL engine — it recognises the specific statements the repositories
 * issue and keeps rows in arrays. That is enough to test the behaviour that
 * matters, and the behaviour that matters most is the UNIQUE constraint on
 * `push_devices.token`: {@see self::upsert()} enforces it, because without that
 * the headline test (a token reassigned rather than duplicated) would pass
 * against a store that simply appended and the real defence would be untested.
 *
 * It uses SQLite where the SQL is worth executing for real.
 */
final class ArrayDatabase implements DatabasePort
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // The real schema, reduced to what SQLite needs — the unique index on
        // `token` included, because it is the control under test.
        $this->pdo->exec(
            'CREATE TABLE push_devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                public_id TEXT NOT NULL,
                token TEXT NOT NULL,
                fid TEXT NOT NULL DEFAULT \'\',
                platform TEXT NOT NULL,
                provider TEXT NOT NULL,
                environment TEXT NOT NULL DEFAULT \'production\',
                user_id TEXT NOT NULL DEFAULT \'\',
                label TEXT NOT NULL DEFAULT \'\',
                failure_count INTEGER NOT NULL DEFAULT 0,
                last_seen_at TEXT NULL,
                last_delivered_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
        );
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_push_device_token ON push_devices (token)');

        $this->pdo->exec(
            'CREATE TABLE push_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                public_id TEXT NOT NULL,
                token TEXT NOT NULL,
                fid TEXT NOT NULL DEFAULT \'\',
                platform TEXT NOT NULL,
                provider TEXT NOT NULL,
                environment TEXT NOT NULL DEFAULT \'production\',
                user_id TEXT NOT NULL DEFAULT \'\',
                notification TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TEXT NOT NULL,
                delivered_at TEXT NULL,
                last_reason TEXT NOT NULL DEFAULT \'\',
                last_error TEXT NOT NULL DEFAULT \'\',
                provider_message_id TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);

        return $rows === [] ? null : $rows[0];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * SQLite's own upsert, so the unique index is enforced by the engine.
     */
    public function upsert(
        string $table,
        array $values,
        array $conflictColumns,
        ?array $updateColumns = null,
    ): int {
        $columns      = array_keys($values);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $update = $updateColumns ?? array_values(array_diff($columns, $conflictColumns));

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $conflictColumns),
            $update === []
                ? 'NOTHING'
                : 'UPDATE SET ' . implode(', ', array_map(
                    static fn (string $c): string => $c . ' = :' . $c,
                    $update,
                )),
        );

        return $this->execute($sql, $values);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Test helper: every row of a table.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table): array
    {
        return $this->query('SELECT * FROM ' . $table . ' ORDER BY id ASC');
    }
}
