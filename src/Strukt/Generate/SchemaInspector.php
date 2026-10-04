<?php

namespace Strukt\Generate;

use Strukt\Ar\Connection;

/**
 * Reads the shape of a database through a {@see Connection}.
 *
 * The inspector is driver aware; it understands SQLite (`PRAGMA table_info`),
 * MySQL, and PostgreSQL (`information_schema`), and reports tables along with
 * their columns, types, nullability, and primary keys:
 *
 * ```php
 * $inspector = new SchemaInspector(Connection::sqlite('app.db'));
 *
 * foreach ($inspector->tables() as $table) {
 *     foreach ($inspector->columns($table) as $column) {
 *         // name, type, nullable, primary
 *     }
 * }
 * ```
 */
final class SchemaInspector
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Lists the application tables.
     *
     * @return array<int, string> Table names.
     */
    public function tables(): array
    {
        $rows = match ($this->driver()) {
            'sqlite' => $this->connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            ),
            'mysql' => $this->connection->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            ),
            'pgsql' => $this->connection->select(
                "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            ),
            default => throw new \LogicException(sprintf('Unsupported driver: %s.', $this->driver())),
        };

        return array_map(fn(array $row) => (string) $row['name'], $rows);
    }

    /**
     * Describes the columns of one table.
     *
     * @param string $table Table name.
     * @return array<int, Column> Columns in definition order.
     */
    public function columns(string $table): array
    {
        $rows = match ($this->driver()) {
            'sqlite' => $this->sqliteColumns($table),
            'mysql' => $this->connection->select(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS key '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'ORDER BY ORDINAL_POSITION',
                [$table],
            ),
            'pgsql' => $this->pgsqlColumns($table),
            default => throw new \LogicException(sprintf('Unsupported driver: %s.', $this->driver())),
        };

        return array_map(
            fn(array $row) => new Column(
                (string) $row['name'],
                (string) $row['type'],
                ($row['nullable'] ?? 'YES') === 'YES',
                (bool) ($row['primary'] ?? false),
            ),
            $rows,
        );
    }

    /**
     * @return string PDO driver name.
     */
    private function driver(): string
    {
        return (string) $this->connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @return array<int, array<string, mixed>> Raw column rows.
     */
    private function sqliteColumns(string $table): array
    {
        $safe = str_replace("'", "''", $table);

        $rows = $this->connection->select(sprintf('PRAGMA table_info(%s)', "'{$safe}'"));

        return array_map(
            static fn(array $row) => [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => (int) $row['notnull'] === 0 ? 'YES' : 'NO',
                'primary' => (int) $row['pk'] > 0,
            ],
            $rows,
        );
    }

    /**
     * @return array<int, array<string, mixed>> Raw column rows.
     */
    private function pgsqlColumns(string $table): array
    {
        $rows = $this->connection->select(
            'SELECT column_name AS name, data_type AS type, is_nullable AS nullable '
            . "FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? "
            . 'ORDER BY ordinal_position',
            [$table],
        );

        $primary = $this->connection->select(
            'SELECT a.attname AS name FROM pg_index i '
            . 'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) '
            . 'WHERE i.indrelid = to_regclass($1) AND i.indisprimary',
            [$table],
        );
        $keys = array_flip(array_column($primary, 'name'));

        return array_map(
            static fn(array $row) => [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => $row['nullable'],
                'primary' => array_key_exists((string) $row['name'], $keys),
            ],
            $rows,
        );
    }
}