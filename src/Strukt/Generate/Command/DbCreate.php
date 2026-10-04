<?php

namespace Strukt\Generate\Command;

use RuntimeException;
use Strukt\Ar\Connection;
use Strukt\Ar\Model;
use Strukt\Ar\Schema;
use Strukt\Console\Runtime\CommandInterface;
use Strukt\Env;

/**
 * @command db:create
 * @description Creates database tables from the models' declared schemas.
 * @usage db:create [--database=<db>] [options]
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
 * @default src
 * @description Directory to scan for models with a declared schema.
 *
 * @option namespace
 * @type string
 * @description Restrict creation to models inside this namespace.
 */
final class DbCreate implements CommandInterface
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
        $namespace = $options['namespace'] === null ? '' : rtrim((string) $options['namespace'], '\\');

        $models = $this->discover($dir, $namespace);

        if ($models === []) {
            echo "No models with a declared schema were found in [{$dir}].\n";
            return 0;
        }

        if ($database === null) {
            Connection::fromEnv(...$envFiles);
        } else {
            $connection = match ($driver) {
                'sqlite' => Connection::sqlite($database),
                'mysql' => Connection::mysql($database, $user, $password, $host, $port ?? 3306),
                'pgsql' => Connection::pgsql($database, $user, $password, $host, $port ?? 5432),
            };
            $connection->asDefault();
        }

        $schema = Schema::for($models);

        foreach ($schema->toSql() as $sql) {
            echo $sql . ";\n";
        }

        $schema->create();

        $label = $database ?? self::databaseLabel();

        echo sprintf("Created %d table(s) on [%s].\n", count($models), $label);

        return 0;
    }

    /**
     * Names the newly created database for the summary line.
     *
     * @return string Database name when the `.env` declared one, otherwise `env`.
     */
    private static function databaseLabel(): string
    {
        return Env::has('DB_DATABASE') ? (string) Env::get('DB_DATABASE') : 'env';
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

    /**
     * Finds the models with a declared schema inside a directory.
     *
     * Files are inspected with the tokenizer rather than loaded, so the scan
     * is deterministic regardless of what the process already knows.
     *
     * @param string $dir Directory to scan.
     * @param string $namespace Optional namespace prefix.
     * @return array<int, class-string<Model>> Schema-bearing models.
     */
    private function discover(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Directory does not exist: [%s].', $dir));
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $classes = array_merge($classes, self::classNames($file->getPathname()));
            }
        }

        $classes = array_unique($classes);
        sort($classes);

        if ($namespace !== '') {
            $classes = array_filter($classes, fn (string $class): bool => str_starts_with($class, $namespace));
        }

        return array_values(array_filter(
            $classes,
            fn (string $class): bool => is_subclass_of($class, Model::class) && $class::schemaMap() !== []
        ));
    }

    /**
     * Extracts the class names a file declares, using the tokenizer.
     *
     * @param string $file PHP file.
     * @return array<int, string> Fully-qualified class names.
     */
    private static function classNames(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens    = token_get_all($source);
        $namespace = '';
        $classes   = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = trim(self::segment($tokens, $i + 1), " \t");
                continue;
            }

            if ($tokens[$i][0] === T_CLASS && ($i === 0 || $tokens[$i - 1][0] !== T_DOUBLE_COLON)) {
                $name = self::className($tokens, $i + 1);
                if ($name !== null) {
                    $classes[] = $namespace === '' ? $name : $namespace . '\\' . $name;
                }
            }
        }

        return $classes;
    }

    /**
     * Reads the name of a namespace or the class following the `class` keyword.
     *
     * @param array<int, mixed> $tokens Token stream.
     * @param int $start Token offset to begin at.
     * @return string Namespace or class name.
     */
    private static function segment(array $tokens, int $start): string
    {
        $out = '';
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === '{') {
                break;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Returns the first string token, i.e. the class name after `class`.
     *
     * @param array<int, mixed> $tokens Token stream.
     * @param int $start Token offset to begin at.
     * @return string|null Class name, or null when no name is found.
     */
    private static function className(array $tokens, int $start): ?string
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }
}