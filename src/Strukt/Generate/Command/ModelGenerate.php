<?php

namespace Strukt\Generate\Command;

use RuntimeException;
use Strukt\Ar\Connection;
use Strukt\Console\Runtime\CommandInterface;
use Strukt\Generate\ModelWriter;
use Strukt\Generate\SchemaInspector;

/**
 * @command model:generate
 * @description Generates Strukt Ar model classes from the database schema.
 * @usage model:generate [--database=<db>] [options]
 *
 * @option database
 * @type string
 * @description Database name, file, or `:memory:`. Omit to pick the connection from the `.env` file(s).
 *
 * @option env
 * @type string
 * @default .env
 * @description Comma-separated environment file(s) used when `--database` is omitted.
 *
 * @option driver
 * @type string
 * @default sqlite
 * @enum sqlite,mysql,pgsql
 * @description Database driver.
 *
 * @option host
 * @type string
 * @default 127.0.0.1
 * @description Database host. Used by mysql and pgsql.
 *
 * @option port
 * @type int
 * @description Database port. Defaults to 3306 for mysql and 5432 for pgsql.
 *
 * @option user
 * @type string
 * @default root
 * @description Database user. Used by mysql and pgsql.
 *
 * @option password
 * @type string
 * @default ""
 * @description Database password. Used by mysql and pgsql.
 *
 * @option dir
 * @type string
 * @default build/Models
 * @description Directory to write generated models into.
 *
 * @option namespace
 * @type string
 * @default App\Model
 * @description Namespace for the generated models.
 *
 * @option table
 * @type string
 * @description Restrict generation to a single table.
 */
final class ModelGenerate implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $driver    = (string) $options['driver'];
        $database  = $options['database'] === null ? null : (string) $options['database'];
        $envFiles  = self::envFiles($options['env'] ?? null);
        $host      = (string) $options['host'];
        $port      = $options['port'] ?? null;
        $user      = (string) $options['user'];
        $password  = (string) $options['password'];
        $dir       = rtrim((string) $options['dir'], '/');
        $namespace = (string) $options['namespace'];
        $only      = $options['table'] === null ? null : (string) $options['table'];

        $connection = $database === null
            ? Connection::fromEnv(...$envFiles)
            : match ($driver) {
                'sqlite' => Connection::sqlite($database),
                'mysql' => Connection::mysql($database, $user, $password, $host, $port ?? 3306),
                'pgsql' => Connection::pgsql($database, $user, $password, $host, $port ?? 5432),
            };

        $inspector = new SchemaInspector($connection);
        $writer    = new ModelWriter();

        $tables = $inspector->tables();
        if ($only !== null) {
            if (!in_array($only, $tables, true)) {
                throw new RuntimeException(sprintf('Table does not exist: [%s].', $only));
            }

            $tables = [$only];
        }

        if ($tables === []) {
            echo "No tables found.\n";
            return 0;
        }

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory: [%s].', $dir));
        }

        foreach ($tables as $table) {
            $name = $writer->className($table);
            $file = $dir . '/' . $name . '.php';

            file_put_contents($file, $writer->render($table, $inspector->columns($table), $namespace, $name));

            echo sprintf("Generated %s\\%s -> %s\n", $namespace, $name, $file);
        }

        return 0;
    }

    /**
     * Splits the `env` option into a file list.
     *
     * @param mixed $value Raw option value.
     * @return array<int, string> Environment files, empty when unset.
     */
    private static function envFiles(mixed $value): array
    {
        $value = $value === null ? '' : (string) $value;

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}