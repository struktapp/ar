<?php

namespace Strukt\Ar;

use PDO;
use PDOStatement;
use Strukt\Env;

/**
 * A thin PDO wrapper that owns the connection for the whole package.
 *
 * A connection is created through one of the named constructors
 * (`sqlite()`, `mysql()`, `pgsql()`, or `pdo()`) and is registered with the
 * {@see ConnectionManager} so `db()` and the models can find it:
 *
 * ```php
 * Connection::sqlite(':memory:')->asDefault();
 * ```
 *
 * Every query goes through `run()`, which prepares and executes against the
 * PDO instance. PDO is configured to throw on errors, so a failed statement
 * surfaces as a `PDOException` at the call site.
 */
final class Connection
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Underlying driver connection.
     */
    private function __construct(PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->pdo = $pdo;
    }

    /**
     * Wraps an existing PDO connection.
     *
     * @param PDO $pdo Driver connection.
     * @return static New connection.
     */
    public static function wrap(PDO $pdo): static
    {
        return new static($pdo);
    }

    /**
     * Opens a SQLite database.
     *
     * @param string $file Database file, or `:memory:` for an in-memory database.
     * @return static New connection.
     */
    public static function sqlite(string $file): static
    {
        $connection = new static(new PDO('sqlite:' . $file));
        $connection->execute('PRAGMA foreign_keys = ON');

        return $connection;
    }

    /**
     * Opens a MySQL or MariaDB database.
     *
     * @param string $database Database name.
     * @param string $user User name.
     * @param string $password Password.
     * @param string $host Host name.
     * @param int $port Port number.
     * @param string $charset Connection character set.
     * @return static New connection.
     */
    public static function mysql(
        string $database,
        string $user,
        string $password = '',
        string $host = '127.0.0.1',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): static {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        return new static(new PDO($dsn, $user, $password));
    }

    /**
     * Opens a PostgreSQL database.
     *
     * @param string $database Database name.
     * @param string $user User name.
     * @param string $password Password.
     * @param string $host Host name.
     * @param int $port Port number.
     * @return static New connection.
     */
    public static function pgsql(
        string $database,
        string $user,
        string $password = '',
        string $host = '127.0.0.1',
        int $port = 5432,
    ): static {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        return new static(new PDO($dsn, $user, $password));
    }

    /**
     * Opens the connection described by environment file(s).
     *
     * Files are loaded with {@see Env::withFile()}, in order, so a later file
     * overrides an earlier one. Keys are read through `env()`, falling back to
     * PHP's process environment and then to these defaults: `DB_CONNECTION`
     * defaults to `sqlite`, `DB_DATABASE` to `database.sqlite`, `DB_HOST` to
     * `127.0.0.1`, `DB_USERNAME` to `root`, and the password to empty. The
     * port follows the driver when `DB_PORT` is absent. The connection is
     * registered as the default.
     *
     * ```env
     * DB_CONNECTION=sqlite
     * DB_DATABASE=app.db
     * ```
     *
     * ```php
     * Connection::fromEnv('.env', '.env.local');
     * ```
     *
     * @param string ...$files Environment files, last one wins.
     * @return static New default connection.
     *
     * @throws \InvalidArgumentException When a file does not exist.
     */
    public static function fromEnv(string ...$files): static
    {
        if ($files === []) {
            $files = ['.env'];
        }

        foreach ($files as $file) {
            Env::withFile($file);
        }

        $connection = match (strtolower((string) self::env('DB_CONNECTION', 'sqlite'))) {
            'sqlite' => static::sqlite((string) self::env('DB_DATABASE', 'database.sqlite')),
            'mysql' => static::mysql(
                (string) self::env('DB_DATABASE', ''),
                (string) self::env('DB_USERNAME', 'root'),
                (string) self::env('DB_PASSWORD', ''),
                (string) self::env('DB_HOST', '127.0.0.1'),
                (int) self::env('DB_PORT', 3306),
            ),
            'pgsql' => static::pgsql(
                (string) self::env('DB_DATABASE', ''),
                (string) self::env('DB_USERNAME', 'root'),
                (string) self::env('DB_PASSWORD', ''),
                (string) self::env('DB_HOST', '127.0.0.1'),
                (int) self::env('DB_PORT', 5432),
            ),
            default => throw new \InvalidArgumentException(
                sprintf('Unsupported DB_CONNECTION [%s].', self::env('DB_CONNECTION', 'sqlite'))
            ),
        };

        return $connection->asDefault();
    }

    /**
     * Reads an option through `env()`, the process environment, or a fallback.
     *
     * @param string $key Environment key.
     * @param string|int|bool $fallback Value when the key is unset.
     * @return string|int|bool Read value.
     */
    private static function env(string $key, string|int|bool $fallback): string|int|bool
    {
        try {
            return \env($key);
        } catch (\Throwable) {
            $value = getenv($key);

            return $value === false ? $fallback : $value;
        }
    }

    /**
     * Registers this connection as the default.
     *
     * @return static This connection.
     */
    public function asDefault(): static
    {
        ConnectionManager::register('default', $this);

        return $this;
    }

    /**
     * Registers this connection under a name.
     *
     * @param string $name Connection name.
     * @return static This connection.
     */
    public function as(string $name): static
    {
        ConnectionManager::register($name, $this);

        return $this;
    }

    /**
     * Returns the underlying driver connection.
     *
     * @return PDO Driver connection.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepares and executes a statement with bound values.
     *
     * @param string $sql SQL with named or positional placeholders.
     * @param array<int|string, mixed> $bindings Values bound in placeholder order.
     * @return PDOStatement Executed statement.
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * Runs a statement and returns the affected row count.
     *
     * @param string $sql SQL to run.
     * @param array<int|string, mixed> $bindings Values bound in placeholder order.
     * @return int Affected row count.
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Runs a statement and returns every resulting row.
     *
     * @param string $sql SQL to run.
     * @param array<int|string, mixed> $bindings Values bound in placeholder order.
     * @return array<int, array<string, mixed>> Rows keyed by column name.
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * Runs a callback inside a transaction.
     *
     * Nested calls share the surrounding transaction when one is already open.
     *
     * @param callable $work Receives this connection, returns any value.
     * @return mixed Callback result.
     *
     * @throws \Throwable When the callback throws; the transaction is rolled back.
     */
    public function transaction(callable $work): mixed
    {
        $nested = $this->pdo->inTransaction();

        if (!$nested) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $work($this);

            if (!$nested) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if (!$nested) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Returns the identifier of the last inserted row.
     *
     * @param string|null $name Sequence name used by some drivers.
     * @return string|false Inserted identifier.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Starts a query against a table.
     *
     * @param string $table Table name.
     * @return Query New query builder.
     */
    public function table(string $table): Query
    {
        return new Query($this, $table);
    }
}