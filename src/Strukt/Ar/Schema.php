<?php

declare(strict_types=1);

namespace Strukt\Ar;

/**
 * Builds tables from model declarations.
 *
 * A model declares its non-conventional columns in `$schema`; the primary key
 * and — when timestamps are on — `created_at`/`updated_at` are implicit.
 * `Schema::for()` compiles one `CREATE TABLE IF NOT EXISTS` statement per
 * model and `create()` runs them on the models' connections, so a second run
 * is a no-op. `Model::schema()` is the same builder from the model side:
 *
 * ```php
 * User::schema()->create();   // CREATE TABLE IF NOT EXISTS users ...
 * Schema::for([User::class, Post::class])->create();
 * ```
 *
 * Column types map to SQLite storage classes: int/bool become INTEGER, float
 * REAL, string/text/datetime/json TEXT, and blob BLOB.
 */
final class Schema
{
    /** @var array<int, class-string<Model>> */
    private array $models;

    /**
     * @param class-string<Model>|array<int, class-string<Model>> $model Model class or classes.
     */
    public function __construct(string|array $model)
    {
        $this->models = is_array($model) ? array_values($model) : [$model];
    }

    /**
     * Opens a schema builder for one or several models.
     *
     * @param class-string<Model>|array<int, class-string<Model>> $model Model class or classes.
     * @return static This schema.
     */
    public static function for(string|array $model): static
    {
        return new static($model);
    }

    /**
     * Compiles one CREATE TABLE statement per model.
     *
     * @return array<int, string> DDL statements, in model order.
     */
    public function toSql(): array
    {
        return array_map(static fn (string $class): string => self::statement($class), $this->models);
    }

    /**
     * Creates every table on its model's connection.
     *
     * @return void
     */
    public function create(): void
    {
        foreach ($this->models as $class) {
            $class::connection()->execute(self::statement($class));
        }
    }

    /**
     * Compiles a single model into a CREATE TABLE statement.
     *
     * @param class-string<Model> $class Model class.
     * @return string DDL statement.
     */
    private static function statement(string $class): string
    {
        $columns = [$class::keyName() . ' INTEGER PRIMARY KEY AUTOINCREMENT'];

        foreach ($class::schemaMap() as $column => $definition) {
            $columns[] = self::columnSQL($class, $column, $definition);
        }

        if ($class::timestamps()) {
            $columns[] = 'created_at TEXT';
            $columns[] = 'updated_at TEXT';
        }

        return sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $class::tableName(), implode(', ', $columns));
    }

    /**
     * Compiles one declared column into an SQL fragment.
     *
     * @param class-string<Model> $class Model class.
     * @param string $column Column name.
     * @param array<string, mixed> $definition Type plus optional nullable, default, unique.
     * @return string Column SQL.
     *
     * @throws \LogicException When the type is missing or unsupported, or the
     *     column collides with a generated one.
     */
    private static function columnSQL(string $class, string $column, array $definition): string
    {
        $type = $definition['type'] ?? null;

        if ($type === null) {
            throw new \LogicException(sprintf('Column [%s] on [%s] needs a type.', $column, $class::tableName()));
        }

        if ($column === $class::keyName()) {
            throw new \LogicException(sprintf('Column [%s] on [%s] is the generated primary key.', $column, $class::tableName()));
        }

        if ($class::timestamps() && in_array($column, ['created_at', 'updated_at'], true)) {
            throw new \LogicException(sprintf('Column [%s] on [%s] is a maintained timestamp.', $column, $class::tableName()));
        }

        $fragment = self::type((string) $type);

        if (($definition['nullable'] ?? true) === false) {
            $fragment .= ' NOT NULL';
        }

        if (array_key_exists('default', $definition)) {
            $fragment .= ' DEFAULT ' . self::defaultLiteral((string) $type, $definition['default']);
        }

        if (!empty($definition['unique'])) {
            $fragment .= ' UNIQUE';
        }

        return $column . ' ' . $fragment;
    }

    /**
     * Maps a declared type to an SQLite storage class.
     *
     * @param string $type Declared type.
     * @return string SQLite storage class.
     *
     * @throws \LogicException When the type is unknown.
     */
    private static function type(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer', 'bool', 'boolean' => 'INTEGER',
            'float', 'real' => 'REAL',
            'string', 'text', 'datetime', 'date', 'json', 'array' => 'TEXT',
            'blob' => 'BLOB',
            default => throw new \LogicException(sprintf('Unsupported column type [%s].', $type)),
        };
    }

    /**
     * Renders a default value as a SQL literal.
     *
     * @param string $type Declared type.
     * @param mixed $default Default value.
     * @return string SQL literal.
     */
    private static function defaultLiteral(string $type, mixed $default): string
    {
        if ($default === null) {
            return 'NULL';
        }

        return match (strtolower($type)) {
            'bool', 'boolean' => $default ? '1' : '0',
            'int', 'integer' => (string) (int) $default,
            'float', 'real' => (string) (float) $default,
            default => "'" . str_replace("'", "''", (string) $default) . "'",
        };
    }
}