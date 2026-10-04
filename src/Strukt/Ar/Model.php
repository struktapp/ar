<?php

namespace Strukt\Ar;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Describes a row that could not be found.
 */
final class ModelNotFoundException extends \RuntimeException
{
    /**
     * @param class-string<Model> $model Model class that was searched.
     * @param mixed $key Value of the searched key.
     */
    public function __construct(public readonly string $model, public readonly mixed $key)
    {
        parent::__construct(sprintf('No [%s] was found for key [%s].', $this->model, var_export($this->key, true)));
    }
}

/**
 * The active record base: one instance maps to one table row.
 *
 * A model derives its table name from the class short name, pluralized and
 * snake-cased (`PostComment` becomes `post_comments`), and can be wired to a
 * specific connection with `connect()`; otherwise the default connection is
 * used. Rows hydrate into models through the static query entry points:
 *
 * ```php
 * $user = User::find(1);
 * $user->name('Sam')->save();
 *
 * User::where('active', 1)->with...->get();
 * ```
 *
 * Columns are exposed as properties through the magic `__get`/`__set`
 * accessors. Declared `casts` shape how values enter and leave the database,
 * and `fillable` guards mass assignment through `fill()`, `create()` and
 * `update()`.
 */
abstract class Model
{
    /** @var Connection|null Per-class connection override. */
    protected static ?Connection $connection = null;

    /** @var string|null Table name override; derived from the class name when null. */
    protected static ?string $table = null;

    /** @var string Primary key column. */
    protected static string $keyName = 'id';

    /** @var bool Whether created_at/updated_at are maintained. */
    protected static bool $timestamps = true;

    /** @var array<int, string> Columns allowed through mass assignment. */
    protected static array $fillable = [];

    /** @var array<string, string> Attribute to cast-type map. */
    protected static array $casts = [];

    /**
     * @var array<string, array<string, mixed>> Column to definition map.
     *
     * The primary key and the timestamp columns are implicit; only declare
     * what is not conventional. A definition holds at most `type`, `nullable`,
     * `default` and `unique`:
     *
     * ```php
     * protected static array $schema = [
     *     'name'     => ['type' => 'string', 'nullable' => false],
     *     'email'    => ['type' => 'string', 'unique' => true],
     *     'votes'    => ['type' => 'int',    'default' => 0],
     *     'birthday' => ['type' => 'datetime', 'nullable' => true],
     * ];
     * ```
     */
    protected static array $schema = [];

    /** @var array<int, string> Columns hidden from toArray()/toJson(). */
    protected static array $hiddens = [];

    /** @var array<string, mixed> Column values in storage form. */
    protected array $attributes = [];

    /** @var array<string, mixed> Column values as last loaded from the database. */
    protected array $original = [];

    /** @var bool Whether the row exists in the database. */
    protected bool $exists = false;

    /** @var array<string, array<string, mixed>> Memoized relation results. */
    protected array $related = [];

    /**
     * Returns the connection this model reads and writes through.
     *
     * @return Connection Model connection.
     */
    public static function connection(): Connection
    {
        return static::$connection ?? ConnectionManager::default();
    }

    /**
     * Points this model class at a concrete connection.
     *
     * @param Connection $connection Connection to use.
     * @return void
     */
    public static function connect(Connection $connection): void
    {
        static::$connection = $connection;
    }

    /**
     * Returns the table name the model maps to.
     *
     * @return string Table name.
     */
    public static function tableName(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }

        $short = (new \ReflectionClass(static::class))->getShortName();

        return plural(str($short)->toSnake());
    }

    /**
     * Returns the primary key column name.
     *
     * @return string Primary key column.
     */
    public static function keyName(): string
    {
        return static::$keyName;
    }

    /**
     * Opens a schema builder for this model's table.
     *
     * @return Schema Declared-column schema.
     */
    public static function schema(): Schema
    {
        return Schema::for(static::class);
    }

    /**
     * Returns the declared column definitions.
     *
     * @return array<string, array<string, mixed>> Column to definition map.
     */
    public static function schemaMap(): array
    {
        return static::$schema;
    }

    /**
     * Whether created_at and updated_at columns are maintained.
     *
     * @return bool True when timestamps are on.
     */
    public static function timestamps(): bool
    {
        return static::$timestamps;
    }

    /**
     * Starts a query against the model's table that hydrates into models.
     *
     * @return Query Model-bound query.
     */
    public static function query(): Query
    {
        return static::connection()->table(static::tableName())->model(static::class);
    }

    /**
     * Returns every row as models.
     *
     * @return array<int, static> Model instances.
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Finds a row by its primary key.
     *
     * @param mixed $key Primary key value.
     * @return static|null Model, or null when not found.
     */
    public static function find(mixed $key): ?static
    {
        return static::query()->where(static::keyName(), $key)->first();
    }

    /**
     * Finds a row by its primary key or throws.
     *
     * @param mixed $key Primary key value.
     * @return static Model.
     *
     * @throws ModelNotFoundException When no row matches.
     */
    public static function findOrFail(mixed $key): static
    {
        $model = static::find($key);

        if ($model === null) {
            throw new ModelNotFoundException(static::class, $key);
        }

        return $model;
    }

    /**
     * Creates and persists a model from column values.
     *
     * @param array<string, mixed> $attributes Column values.
     * @return static Persisted model.
     */
    public static function create(array $attributes): static
    {
        $model = (new static())->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Adds a where clause to a fresh model query.
     *
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @return Query Model-bound query.
     */
    public static function where(string|array $column, mixed $operatorOrValue = null, mixed $value = null): Query
    {
        if (func_num_args() === 2) {
            return static::query()->where($column, $operatorOrValue);
        }

        return static::query()->where($column, $operatorOrValue, $value);
    }

    /**
     * Returns the first matching row as a model.
     *
     * @return static|null Model, or null when no rows match.
     */
    public static function first(): ?static
    {
        return static::query()->first();
    }

    /**
     * Counts matching rows.
     *
     * @return int Row count.
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Builds a model from a database row.
     *
     * @param array<string, mixed> $row Column values in storage form.
     * @return static Hydrated model.
     */
    public static function hydrate(array $row): static
    {
        $model = new static();

        if ($row !== []) {
            foreach ($row as $column => $value) {
                $model->attributes[$column] = $value;
            }
            $model->original = $model->attributes;
            $model->exists = true;
        }

        return $model;
    }

    /**
     * Hydrates several models from rows.
     *
     * @param array<int, array<string, mixed>> $rows Column values in storage form.
     * @return array<int, static> Hydrated models.
     */
    public function hydrateMany(array $rows): array
    {
        return array_map(static fn (array $row): static => static::hydrate($row), $rows);
    }

    /**
     * Returns the primary key value of this row.
     *
     * @return mixed Primary key value, or null when not set.
     */
    public function key(): mixed
    {
        return $this->attributes[static::keyName()] ?? null;
    }

    /**
     * Whether this model maps to an existing row.
     *
     * @return bool True when the row exists in the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Fills mass-assignable columns from values.
     *
     * @param array<string, mixed> $attributes Column values.
     * @return static This model.
     */
    public function fill(array $attributes): static
    {
        $allowed = static::$fillable;

        foreach ($attributes as $column => $value) {
            if ($allowed !== [] && !in_array($column, $allowed, true)) {
                continue;
            }

            $this->setAttribute($column, $value);
        }

        return $this;
    }

    /**
     * Returns every column value, cast for reading.
     *
     * @return array<string, mixed> Column values in read form.
     */
    public function attributes(): array
    {
        $values = [];

        foreach (array_keys($this->attributes) as $column) {
            $values[$column] = $this->getAttribute($column);
        }

        return $this->withoutHidden($values);
    }

    /**
     * Returns the read form of one column.
     *
     * @param string $column Column name.
     * @return mixed Cast value, or null when absent.
     */
    public function getAttribute(string $column): mixed
    {
        return array_key_exists($column, $this->attributes)
            ? $this->castRead($this->attributes[$column], $column)
            : null;
    }

    /**
     * Sets one column to a value.
     *
     * @param string $column Column name.
     * @param mixed $value Column value.
     * @return mixed The stored value, in storage form.
     */
    public function setAttribute(string $column, mixed $value): mixed
    {
        return $this->attributes[$column] = $this->castWrite($value, $column);
    }

    /**
     * Whether a column is set.
     *
     * @param string $column Column name.
     * @return bool True when the column holds a value.
     */
    public function has(string $column): bool
    {
        return array_key_exists($column, $this->attributes);
    }

    /**
     * Stores the current values, inserting or updating as needed.
     *
     * @return bool True on success; errors surface as exceptions.
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->persistUpdate();
        }

        $this->touchOnCreate();
        $this->connection()->table(static::tableName())->insert($this->attributes);

        if ($this->key() === null) {
            $id = $this->connection()->lastInsertId();
            $this->attributes[static::keyName()] = is_numeric($id) ? (int) $id : $id;
        }

        $this->original = $this->attributes;
        $this->exists = true;

        return true;
    }

    /**
     * Persists the filled columns of an existing row.
     *
     * @param array<string, mixed> $attributes Column values.
     * @return bool True on success; errors surface as exceptions.
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);

        return $this->save();
    }

    /**
     * Removes the row from the database.
     *
     * @return bool True when a row was removed.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $affected = $this->connection()
            ->table(static::tableName())
            ->where(static::keyName(), $this->key())
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
        }

        return $affected > 0;
    }

    /**
     * Reloads the current row from the database.
     *
     * @return static This model.
     *
     * @throws ModelNotFoundException When the row no longer exists.
     */
    public function refresh(): static
    {
        $row = $this->connection()
            ->table(static::tableName())
            ->where(static::keyName(), $this->key())
            ->first();

        if ($row === null) {
            throw new ModelNotFoundException(static::class, $this->key());
        }

        foreach ($row as $column => $value) {
            $this->attributes[$column] = $value;
        }
        $this->original = $this->attributes;
        $this->exists = true;

        return $this;
    }

    /**
     * Reads a column, or a memoized relation when the name matches none.
     *
     * @param string $name Column or relation name.
     * @return mixed Column value or relation result.
     *
     * @throws \OutOfBoundsException When the name is neither a column nor relation.
     */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->getAttribute($name);
        }

        $relation = $this->resolveRelation($name);

        if ($relation !== null) {
            return $relation;
        }

        throw new \OutOfBoundsException(sprintf('Column or relation [%s] does not exist on [%s].', $name, static::class));
    }

    /**
     * Sets a column through property syntax.
     *
     * @param string $name Column name.
     * @param mixed $value Column value.
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Whether a column or relation exists.
     *
     * @param string $name Column or relation name.
     * @return bool True when the column holds a value or the relation resolves.
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes) || $this->resolveRelation($name) !== null;
    }

    /**
     * Returns the attributes without their hidden columns.
     *
     * @return array<string, mixed> Read form, hidden columns removed.
     */
    public function toArray(): array
    {
        return $this->attributes();
    }

    /**
     * Encodes the visible attributes as JSON.
     *
     * @param int $flags JSON encoding flags.
     * @return string JSON document.
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Renders the model as its JSON document.
     *
     * @return string JSON document.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Defines a `has-many` relation in a concrete model:
     *
     * ```php
     * public function posts(): array
     * {
     *     return $this->hasMany(Post::class);
     * }
     * ```
     *
     * @param class-string<Model> $related Related model class.
     * @param string|null $foreignKey Column on the related table; derived when null.
     * @param string|null $localKey Column on this table; `keyName()` by default.
     * @return array<int, Model> Related models.
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): array
    {
        $foreignKey ??= str($this->shortName(static::class))->toSnake() . '_id';
        $localKey ??= $this->key();

        return $this->memoize($related, $foreignKey, $localKey, fn () => $related::query()
            ->where($foreignKey, $localKey)
            ->orderBy('id')
            ->get());
    }

    /**
     * Defines a `has-one` relation in a concrete model.
     *
     * @param class-string<Model> $related Related model class.
     * @param string|null $foreignKey Column on the related table; derived when null.
     * @param string|null $localKey Column on this table; `keyName()` by default.
     * @return Model|null Related model, or null when none match.
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        $foreignKey ??= str($this->shortName(static::class))->toSnake() . '_id';
        $localKey ??= $this->key();

        return $this->memoize($related, $foreignKey, $localKey, fn () => $related::query()
            ->where($foreignKey, $localKey)
            ->first());
    }

    /**
     * Defines a `belongs-to` relation in a concrete model.
     *
     * @param class-string<Model> $related Related model class.
     * @param string|null $foreignKey Column on this table; derived when null.
     * @param string|null $ownerKey Column on the related table; `keyName()` by default.
     * @return Model|null Related model, or null when none match or the key is empty.
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?Model
    {
        $ownerKey ??= $related::keyName();
        $foreignKey ??= str($this->shortName($related))->toSnake() . '_id';
        $value = $this->getAttribute($foreignKey);

        if ($value === null) {
            return null;
        }

        return $this->memoize($related, $foreignKey, $ownerKey, fn () => $related::query()
            ->where($ownerKey, $value)
            ->first());
    }

    /**
     * Finds a relation accessor by name.
     *
     * @param string $name Relation method name.
     * @return mixed Relation result, or null when no such accessor exists.
     *
     * @throws \LogicException When the accessor returns a type relations do not.
     */
    private function resolveRelation(string $name): mixed
    {
        if (!method_exists($this, $name)) {
            return null;
        }

        $result = $this->{$name}();

        if ($result instanceof Model || $result === null || is_array($result)) {
            return $result;
        }

        throw new \LogicException(sprintf('Relation [%s] must return a model or an array of models.', $name));
    }

    /**
     * Runs a relation once and caches the result per model instance.
     *
     * @param string $related Related model class.
     * @param string $foreignKey Foreign key column.
     * @param string $localKey Local key column.
     * @param callable $resolve Relation query.
     * @return mixed Relation result.
     */
    private function memoize(string $related, string $foreignKey, string $localKey, callable $resolve): mixed
    {
        $name = $related . ':' . $foreignKey . ':' . $localKey;

        if (array_key_exists($name, $this->related)) {
            return $this->related[$name];
        }

        return $this->related[$name] = $resolve();
    }

    /**
     * Updates an existing row with the columns that changed.
     *
     * @return bool True on success; errors surface as exceptions.
     */
    private function persistUpdate(): bool
    {
        $this->touchOnUpdate();
        $changes = [];

        foreach ($this->attributes as $column => $value) {
            if (!array_key_exists($column, $this->original) || $this->original[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        if ($changes !== []) {
            $this->connection()
                ->table(static::tableName())
                ->where(static::keyName(), $this->key())
                ->update($changes);
        }

        $this->original = $this->attributes;

        return true;
    }

    /**
     * Sets created_at/updated_at on a fresh row when timestamps are enabled.
     *
     * @return void
     */
    private function touchOnCreate(): void
    {
        if (static::$timestamps) {
            $this->attributes['created_at'] ??= self::sqlTimestamp();
            $this->attributes['updated_at'] ??= self::sqlTimestamp();
        }
    }

    /**
     * Sets updated_at on an existing row when timestamps are enabled.
     *
     * @return void
     */
    private function touchOnUpdate(): void
    {
        if (static::$timestamps) {
            $this->attributes['updated_at'] = self::sqlTimestamp();
        }
    }

    /**
     * Current timestamp in the storage format.
     *
     * @return string Formatted timestamp.
     */
    private static function sqlTimestamp(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    /**
     * Converts a stored value into its read form.
     *
     * @param mixed $value Stored value.
     * @param string $column Column name.
     * @return mixed Read value.
     */
    private function castRead(mixed $value, string $column): mixed
    {
        $type = static::$casts[$column] ?? null;

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'datetime' => $value === null ? null : new DateTimeImmutable($value),
            'array', 'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => $value,
        };
    }

    /**
     * Converts a value into its storage form.
     *
     * @param mixed $value Read value.
     * @param string $column Column name.
     * @return mixed Stored value.
     */
    private function castWrite(mixed $value, string $column): mixed
    {
        $type = static::$casts[$column] ?? null;

        return match ($type) {
            'datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            'array', 'json' => is_array($value) ? json_encode($value) : $value,
            default => $value,
        };
    }

    /**
     * Removes hidden columns from a set of values.
     *
     * @param array<string, mixed> $values Column values.
     * @return array<string, mixed> Values without hidden columns.
     */
    private function withoutHidden(array $values): array
    {
        if (static::$hiddens === []) {
            return $values;
        }

        return array_diff_key($values, array_flip(static::$hiddens));
    }

    /**
     * Short class name of a model class.
     *
     * @param class-string<Model> $class Model class.
     * @return string Short class name.
     */
    private function shortName(string $class): string
    {
        return (new \ReflectionClass($class))->getShortName();
    }
}