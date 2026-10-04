<?php

namespace Strukt\Generate;

/**
 * Turns a table and its columns into a Strukt Ar model class.
 *
 * Given a `user_posts` table the writer derives a `UserPost` class with an
 * explicit `$table`, a `$keyName` when the primary key is something other than
 * `id`, `$casts` deduced from the column types, and a `$fillable` list that
 * excludes the primary key and the timestamp columns:
 *
 * ```php
 * $writer = new ModelWriter();
 *
 * $code = $writer->render('users', $columns, 'App\Model');
 * ```
 */
final class ModelWriter
{
    /**
     * Derives a PascalCase class name from a table name.
     *
     * @param string $table Table name.
     * @return string Model class name.
     */
    public function className(string $table): string
    {
        return (string) str(singular($table))->toCamel();
    }

    /**
     * Renders the PHP source of a model class.
     *
     * @param string $table Table name.
     * @param array<int, Column> $columns Columns in definition order.
     * @param string $namespace Namespace for the generated class.
     * @param string|null $name Class name; derived from the table when null.
     * @return string PHP source code.
     */
    public function render(string $table, array $columns, string $namespace, ?string $name = null): string
    {
        $name ??= $this->className($table);

        $primary = array_values(array_filter($columns, static fn (Column $column): bool => $column->primary));
        $key     = $primary[0]->name ?? 'id';

        $timestamps = $this->has($columns, 'created_at') && $this->has($columns, 'updated_at');

        $fillable = [];
        $casts    = [];
        foreach ($columns as $column) {
            if (!$column->primary && !in_array($column->name, ['created_at', 'updated_at'], true)) {
                $fillable[] = $column->name;
            }

            $cast = $this->castFor($column->type);
            if ($cast !== null) {
                $casts[$column->name] = $cast;
            }
        }

        $lines = [
            '<?php',
            '',
            sprintf('namespace %s;', $namespace),
            '',
            'use Strukt\Ar\Model;',
            '',
            sprintf('/** Model for the `%s` table. */', $table),
            sprintf('class %s extends Model', $name),
            '{',
            sprintf('    protected static ?string $table = %s;', $this->quote($table)),
        ];

        if ($key !== 'id') {
            $lines[] = sprintf('    protected static string $keyName = %s;', $this->quote($key));
        }

        if (!$timestamps) {
            $lines[] = '    protected static bool $timestamps = false;';
        }

        if ($fillable !== []) {
            $lines[] = '    protected static array $fillable = [';
            foreach ($fillable as $field) {
                $lines[] = sprintf('        %s,', $this->quote($field));
            }
            $lines[] = '    ];';
        }

        if ($casts !== []) {
            $lines[] = '    protected static array $casts = [';
            foreach ($casts as $field => $cast) {
                $lines[] = sprintf('        %s => %s,', $this->quote($field), $this->quote($cast));
            }
            $lines[] = '    ];';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Checks whether a column exists.
     *
     * @param array<int, Column> $columns Columns to search.
     * @param string $name Column name to find.
     * @return bool True when the column is present.
     */
    private function has(array $columns, string $name): bool
    {
        foreach ($columns as $column) {
            if ($column->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps a database type to a model cast type.
     *
     * @param string $raw Raw column type as reported by the driver.
     * @return string|null Cast type, or null when the column stays as-is.
     */
    private function castFor(string $raw): ?string
    {
        $raw = strtolower(trim($raw));

        if (preg_match('/^tinyint\(\s*1\s*\)$/', $raw)) {
            return 'bool';
        }

        $type = preg_replace('/\s*\(.*\)$/', '', $raw) ?? $raw;

        return match ($type) {
            'int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint',
            'serial', 'bigserial', 'smallserial' => 'int',
            'float', 'double', 'real', 'decimal', 'numeric', 'money' => 'float',
            'bool', 'boolean' => 'bool',
            'datetime', 'timestamp', 'date', 'time',
            'timestamp without time zone', 'timestamp with time zone' => 'datetime',
            'json', 'jsonb' => 'array',
            default => null,
        };
    }

    /**
     * Quotes a value as a single-quoted PHP literal.
     *
     * @param string $value Value to quote.
     * @return string Quoted literal.
     */
    private function quote(string $value): string
    {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }
}