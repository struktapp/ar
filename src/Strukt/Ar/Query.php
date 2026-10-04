<?php

namespace Strukt\Ar;

use Countable;

/**
 * A fluent parameterized query builder for a single table.
 *
 * Queries are created through {@see Connection::table()}; every value is
 * bound to a prepared statement, so clauses never reach SQL by string
 * interpolation. Clauses accumulate on the instance, so chain in one
 * expression or clone the query when the same base is reused:
 *
 * ```php
 * $base = db()->table('users')->where('active', 1);
 * $admins = (clone $base)->where('admin', 1)->get();
 * ```
 *
 * When a model class is attached with `model()`, `get()` and `first()` return
 * hydrated model instances instead of plain rows.
 */
final class Query
{
    /** @var array<int, string> Columns selected. */
    private array $columns = ['*'];

    private bool $distinct = false;

    /** @var array<int, array<string, mixed>> Where clauses. */
    private array $wheres = [];

    /** @var array<int, array<string, string>> Join clauses. */
    private array $joins = [];

    /** @var array<int, array{0: string, 1: string}> Order clauses. */
    private array $orders = [];

    /** @var array<int, string> Group clauses. */
    private array $groups = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var class-string<Model>|null Model class used for hydration. */
    private ?string $model = null;

    /**
     * @param Connection $connection Owning connection.
     * @param string $table Table name.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    /**
     * Returns the table the query reads from.
     *
     * @return string Table name.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Selects specific columns.
     *
     * @param string|array<int, string> $columns Columns to select.
     * @return static This query.
     */
    public function select(string|array $columns): static
    {
        $this->columns = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    /**
     * Adds a `DISTINCT` marker to the select.
     *
     * @return static This query.
     */
    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Attaches a model class so rows hydrate into model instances.
     *
     * @param class-string<Model> $class Model class.
     * @return static This query.
     */
    public function model(string $class): static
    {
        $this->model = $class;

        return $this;
    }

    /**
     * Adds a where clause.
     *
     * Three shapes are supported:
     *
     * ```php
     * ->where('name', 'Sam')              // name = ?
     * ->where('age', '>', 21)             // age > ?
     * ->where(['a' => 1, 'b' => 2])       // a = ? AND b = ?
     * ```
     *
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @param string $boolean `and` or `or`.
     * @return static This query.
     */
    public function where(
        string|array $column,
        mixed $operatorOrValue = null,
        mixed $value = null,
        string $boolean = 'and',
    ): static {
        if (is_array($column)) {
            foreach ($column as $key => $item) {
                $this->where($key, '=', $item, $boolean);
            }

            return $this;
        }

        if (func_num_args() === 2) {
            return $this->basic($column, '=', $operatorOrValue, $boolean);
        }

        return $this->basic($column, $operatorOrValue, $value, $boolean);
    }

    /**
     * Adds an `OR` where clause.
     *
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @return static This query.
     */
    public function orWhere(string|array $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $item) {
                $this->basic($key, '=', $item, 'or');
            }

            return $this;
        }

        if (func_num_args() === 2) {
            return $this->basic($column, '=', $operatorOrValue, 'or');
        }

        return $this->basic($column, $operatorOrValue, $value, 'or');
    }

    /**
     * Adds a `column IN (...)` clause.
     *
     * @param string $column Column name.
     * @param array<int, mixed> $values Candidate values.
     * @return static This query.
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => 'and'];

        return $this;
    }

    /**
     * Adds a `column NOT IN (...)` clause.
     *
     * @param string $column Column name.
     * @param array<int, mixed> $values Excluded values.
     * @return static This query.
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'notIn', 'column' => $column, 'values' => $values, 'boolean' => 'and'];

        return $this;
    }

    /**
     * Adds a `column IS NULL` clause.
     *
     * @param string $column Column name.
     * @return static This query.
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'boolean' => 'and'];

        return $this;
    }

    /**
     * Adds a `column IS NOT NULL` clause.
     *
     * @param string $column Column name.
     * @return static This query.
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'notNull', 'column' => $column, 'boolean' => 'and'];

        return $this;
    }

    /**
     * Adds a `column BETWEEN min AND max` clause.
     *
     * @param string $column Column name.
     * @param array{0: mixed, 1: mixed} $range Inclusive lower and upper bounds.
     * @return static This query.
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->wheres[] = ['type' => 'between', 'column' => $column, 'range' => $range, 'boolean' => 'and'];

        return $this;
    }

    /**
     * Adds a join clause.
     *
     * Two shapes are supported:
     *
     * ```php
     * ->join('posts', 'posts.user_id', 'users.id')
     * ->join('posts', 'posts.user_id', '=', 'users.id')
     * ```
     *
     * @param string $table Joined table name.
     * @param string $first Left column.
     * @param string $operator Operator (`=` by default) or the right column when no operator is given.
     * @param string|null $second Right column.
     * @param string $type Join type such as `inner` or `left`.
     * @return static This query.
     */
    public function join(
        string $table,
        string $first,
        string $operator = '=',
        ?string $second = null,
        string $type = 'inner',
    ): static {
        if (func_num_args() === 3) {
            $second = $operator;
            $operator = '=';
        }

        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second ?? $first,
        ];

        return $this;
    }

    /**
     * Adds a `LEFT JOIN` clause.
     *
     * @param string $table Joined table name.
     * @param string $first Left column.
     * @param string $operator Operator (`=` by default) or the right column when no operator is given.
     * @param string|null $second Right column.
     * @return static This query.
     */
    public function leftJoin(string $table, string $first, string $operator = '=', ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Orders the results.
     *
     * @param string $column Column to order by.
     * @param string $direction `asc` or `desc`.
     * @return static This query.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = [$column, strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'];

        return $this;
    }

    /**
     * Replaces any prior ordering, optionally with one new order column.
     *
     * @param string|null $column Column to order by; null drops the ordering.
     * @param string $direction `asc` or `desc`.
     * @return static This query.
     */
    public function reorder(?string $column = null, string $direction = 'asc'): static
    {
        $this->orders = [];

        return $column === null ? $this : $this->orderBy($column, $direction);
    }

    /**
     * Groups the results.
     *
     * @param string|array<int, string> $columns Columns to group by.
     * @return static This query.
     */
    public function groupBy(string|array $columns): static
    {
        foreach ((array) $columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * Limits the number of results.
     *
     * @param int $limit Maximum rows.
     * @return static This query.
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Skips the first rows.
     *
     * @param int $offset Rows to skip.
     * @return static This query.
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Runs the select and returns every result.
     *
     * @return array<int, array<string, mixed>|Model> Rows, or models when a model is attached.
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        $rows = $this->connection->select($sql, $bindings);

        if ($this->model === null) {
            return $rows;
        }

        return (new $this->model())->hydrateMany($rows);
    }

    /**
     * Runs the select and returns the first result.
     *
     * @return array<string, mixed>|Model|null First row, model, or null when empty.
     */
    public function first(): array|Model|null
    {
        $query = (clone $this)->limit(1);
        $rows = $query->get();

        return $rows[0] ?? null;
    }

    /**
     * Returns a single column value from the first result.
     *
     * @param string $column Column to read.
     * @param mixed $default Value when the result is empty.
     * @return mixed Column value, or the default.
     */
    public function value(string $column, mixed $default = null): mixed
    {
        $row = $this->select($column)->first();

        return $row[$column] ?? $default;
    }

    /**
     * Collects a column into a list.
     *
     * @param string $column Column to collect.
     * @param string|null $key Optional column used as the array keys.
     * @return array<array-key, mixed> Column values.
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->select([$key ?? $column, $column])->get();
        $result = [];

        foreach ($rows as $row) {
            if ($key !== null) {
                $result[$row[$key]] = $row[$column];
            } else {
                $result[] = $row[$column];
            }
        }

        return $result;
    }

    /**
     * Counts matching rows.
     *
     * @param string $column Column to count, `*` by default.
     * @return int Row count.
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    /**
     * Checks whether any row matches.
     *
     * @return bool True when at least one row matches.
     */
    public function exists(): bool
    {
        $statement = (clone $this)->select('1')->limit(1);

        return $statement->first() !== null;
    }

    /**
     * Computes an aggregate over the matching rows.
     *
     * @param string $function Aggregate such as `SUM`, `AVG`, `MIN`, or `MAX`.
     * @param string $column Column to aggregate.
     * @return mixed Aggregate value.
     */
    public function aggregate(string $function, string $column): mixed
    {
        $alias = sprintf('%s(%s)', strtoupper($function), $column);
        $row = (clone $this)->select($alias)->first();

        if (is_array($row)) {
            return $row[$alias] ?? null;
        }

        return $row?->getAttribute($alias) ?? null;
    }

    /**
     * Sums a column over the matching rows.
     *
     * @param string $column Column to sum.
     * @return int|float|null Total, or null when no rows match.
     */
    public function sum(string $column): int|float|null
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Averages a column over the matching rows.
     *
     * @param string $column Column to average.
     * @return int|float|null Average, or null when no rows match.
     */
    public function avg(string $column): int|float|null
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Returns the smallest value of a column.
     *
     * @param string $column Column to inspect.
     * @return mixed Minimum value, or null when no rows match.
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Returns the largest value of a column.
     *
     * @param string $column Column to inspect.
     * @return mixed Maximum value, or null when no rows match.
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Inserts rows and returns the number affected.
     *
     * A single associative array is one row; a list of associative arrays is
     * several. Every row must share the same columns.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $rows Row or rows.
     * @return int Rows inserted.
     *
     * @throws \InvalidArgumentException When `$rows` is empty or columns differ.
     */
    public function insert(array $rows): int
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('Query::insert() requires at least one row.');
        }

        [$columns, $list] = $this->normalizeRows($rows);

        $marks = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($list), $marks));
        $bindings = [];

        foreach ($list as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Inserts one row and returns its identifier.
     *
     * @param array<string, mixed> $row Row to insert.
     * @return int|string Inserted identifier.
     */
    public function insertGetId(array $row): int|string
    {
        $this->insert($row);

        return $this->connection->lastInsertId();
    }

    /**
     * Updates the matching rows and returns the number affected.
     *
     * @param array<string, mixed> $values Column values to set.
     * @return int Rows updated.
     *
     * @throws \InvalidArgumentException When no values are given.
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Query::update() requires at least one value.');
        }

        $sets = array_map(static fn (string $column): string => "{$column} = ?", array_keys($values));

        [$where, $bindings] = $this->compileWhere();
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ($where === '' ? '' : ' WHERE ' . $where);

        return $this->connection->execute($sql, [...array_values($values), ...$bindings]);
    }

    /**
     * Deletes the matching rows and returns the number affected.
     *
     * @return int Rows deleted.
     *
     * @throws \LogicException When no where clause is present.
     */
    public function delete(): int
    {
        [$where, $bindings] = $this->compileWhere();

        if ($where === '') {
            throw new \LogicException('Query::delete() without a where clause is not allowed.');
        }

        return $this->connection->execute('DELETE FROM ' . $this->table . ' WHERE ' . $where, $bindings);
    }

    /**
     * Increments a column on the matching rows.
     *
     * @param string $column Column to increment.
     * @param int|float $by Amount to add.
     * @return int Rows updated.
     */
    public function increment(string $column, int|float $by = 1): int
    {
        return $this->adjust($column, $by, '+');
    }

    /**
     * Decrements a column on the matching rows.
     *
     * @param string $column Column to decrement.
     * @param int|float $by Amount to subtract.
     * @return int Rows updated.
     */
    public function decrement(string $column, int|float $by = 1): int
    {
        return $this->adjust($column, $by, '-');
    }

    /**
     * Compiles the select statement for inspection or reuse.
     *
     * @return string Compiled SQL.
     */
    public function toSql(): string
    {
        [$sql] = $this->compileSelect();

        return $sql;
    }

    /**
     * Compiled bindings for the select statement.
     *
     * @return array<int, mixed> Bound values in placeholder order.
     */
    public function bindings(): array
    {
        [, $where] = $this->compileWhere();
        $bindings = [...$where];

        if ($this->limit !== null) {
            $bindings[] = $this->limit;
        }

        if ($this->offset !== null) {
            $bindings[] = $this->offset;
        }

        return $bindings;
    }

    /**
     * @return array{string, array<int, mixed>} Compiled SQL and its bindings.
     */
    private function compileSelect(): array
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $this->columns) . ' FROM ' . $this->table;

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $join['table'] . ' ON ' . $join['first'] . ' ' . $join['operator'] . ' ' . $join['second'];
        }

        [$where, $bindings] = $this->compileWhere();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->orders !== []) {
            $orders = array_map(
                fn (array $order): string => $order[0] . ' ' . $order[1],
                $this->orders,
            );
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $bindings[] = $this->limit;
            $sql .= ' LIMIT ?';
        }

        if ($this->offset !== null) {
            $bindings[] = $this->offset;
            $sql .= ' OFFSET ?';
        }

        return [$sql, $bindings];
    }

    /**
     * Appends a basic where clause.
     *
     * @param string $column Column name.
     * @param mixed $operator Comparison operator.
     * @param mixed $value Bound value.
     * @param string $boolean `and` or `or`.
     * @return static This query.
     */
    private function basic(string $column, mixed $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @return array{string, array<int, mixed>} Compiled where SQL and bindings.
     */
    private function compileWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $sql = '';
        $bindings = [];
        $first = true;

        foreach ($this->wheres as $where) {
            $boolean = $first ? '' : ($where['boolean'] === 'or' ? 'OR ' : 'AND ');
            $first = false;

            [$clause, $values] = $this->compileClause($where);
            $sql .= $boolean . '(' . $clause . ') ';
            $bindings = [...$bindings, ...$values];
        }

        return [rtrim($sql), $bindings];
    }

    /**
     * Compiles a single where clause.
     *
     * @param array<string, mixed> $where Clause descriptor.
     * @return array{string, array<int, mixed>} Clause SQL and bindings.
     *
     * @throws \LogicException When the clause type is unknown.
     */
    private function compileClause(array $where): array
    {
        return match ($where['type']) {
            'basic' => [
                $where['column'] . ' ' . $where['operator'] . ' ?',
                [$where['value']],
            ],
            'in' => [
                $where['column'] . ' IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
                array_values($where['values']),
            ],
            'notIn' => [
                $where['column'] . ' NOT IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
                array_values($where['values']),
            ],
            'null' => [$where['column'] . ' IS NULL', []],
            'notNull' => [$where['column'] . ' IS NOT NULL', []],
            'between' => [
                $where['column'] . ' BETWEEN ? AND ?',
                array_values($where['range']),
            ],
            default => throw new \LogicException(sprintf('Unknown where clause type [%s].', $where['type'])),
        };
    }

    /**
     * Updates a column by a fixed amount on the matching rows.
     *
     * @param string $column Column to adjust.
     * @param int|float $by Amount.
     * @param string $operator `+` or `-`.
     * @return int Rows updated.
     */
    private function adjust(string $column, int|float $by, string $operator): int
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = 'UPDATE ' . $this->table . ' SET ' . $column . ' = ' . $column . ' ' . $operator . ' ?' . ($where === '' ? '' : ' WHERE ' . $where);

        return $this->connection->execute($sql, [$by, ...$bindings]);
    }

    /**
     * Splits the insert argument into a column list and uniform rows.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $rows Insert argument.
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>} Columns and rows.
     *
     * @throws \InvalidArgumentException When a row is missing or has extra columns.
     */
    private function normalizeRows(array $rows): array
    {
        $single = !array_is_list($rows) || !is_array($rows[0] ?? null);

        if ($single) {
            $rows = [$rows];
        }

        $columns = array_keys($rows[0]);

        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                throw new \InvalidArgumentException('Every inserted row must share the same columns.');
            }
        }

        return [$columns, $rows];
    }
}