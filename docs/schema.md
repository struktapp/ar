# Schema

`Strukt\Ar\Schema` builds tables from model declarations — the reverse of
[generating models](generators.md). A model declares its non-conventional
columns in `$schema`; the primary key and — when timestamps are on —
`created_at`/`updated_at` are implied.

```php
class User extends Model
{
    protected static array $schema = [
        'name'     => ['type' => 'string',  'nullable' => false],
        'email'    => ['type' => 'string',  'nullable' => false, 'unique' => true],
        'is_admin' => ['type' => 'bool',    'nullable' => false, 'default' => false],
        'birthday' => ['type' => 'datetime', 'nullable' => true],
        'profile'  => ['type' => 'text',    'nullable' => true],
    ];
}
```

## The builder

`Model::schema()` is the same builder as `Schema::for()`, from the model side:

```php
User::schema()->create();                     // CREATE TABLE IF NOT EXISTS users (...)
Schema::for([User::class, Post::class])->create();
```

`Schema::for()` accepts one model class or a list; `toSql()` compiles one
`CREATE TABLE IF NOT EXISTS` statement per model, in model order, and `create()`
runs them on the models' connections.

```php
[$sql] = User::schema()->toSql();

// CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,
//   name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
//   is_admin INTEGER NOT NULL DEFAULT 0, birthday TEXT, profile TEXT,
//   created_at TEXT, updated_at TEXT)
```

Because the statement is `IF NOT EXISTS`, running `create()` again is a no-op.

## The generated columns

- `{keyName} INTEGER PRIMARY KEY AUTOINCREMENT` — `id` unless overridden.
- `created_at TEXT` and `updated_at TEXT` when `$timestamps` is left at `true`.

Declaring a column that collides with a generated one throws `LogicException`.

## Declared columns

A definition holds at most `type`, `nullable`, `default`, and `unique`:

| Key | Default | Behavior |
|---|---|---|
| `type` | — | Required. Maps to a storage class (below). |
| `nullable` | `true` | `false` emits `NOT NULL`. |
| `default` | — | Emits `DEFAULT <literal>`. |
| `unique` | `false` | Emits `UNIQUE`. |

A missing or unsupported type throws `LogicException`.

### Type map

Described types map to SQLite storage classes:

| Declared type | Storage class |
|---|---|
| `int`, `integer`, `bool`, `boolean` | `INTEGER` |
| `float`, `real` | `REAL` |
| `string`, `text`, `datetime`, `date`, `json`, `array` | `TEXT` |
| `blob` | `BLOB` |
| anything else | `LogicException` |

### Defaults as literals

`default` is rendered per type: booleans become `1`/`0`, integers and floats
their numeric literal, and everything else a single-quoted string with quotes
doubled. `null` becomes `NULL`.

```php
['type' => 'bool', 'default' => false]   // is_admin INTEGER NOT NULL DEFAULT 0
['type' => 'int',  'default' => 7]       // votes INTEGER NOT NULL DEFAULT 7
['type' => 'text', 'default' => "it's"]  // note TEXT DEFAULT 'it''s'
```

## Enforcing constraints

The database enforces what the schema declares. A missing `NOT NULL` column
fails on insert, and a duplicate `UNIQUE` value throws `PDOException`:

```php
User::schema()->create();
User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

db()->table('users')->insert(['name' => 'Bart']);                 // NOT NULL fails
User::create(['name' => 'Bart', 'email' => 'sam@strukt.test']);   // UNIQUE fails
```

## Errors

`LogicException` is thrown when a declaration is malformed:

- a column has no `type`;
- the type is unsupported;
- a column is the generated primary key;
- a column is a maintained timestamp.

## Multiple models

Pass several classes to compile them together, in model order:

```php
$statements = Schema::for([User::class, Post::class])->toSql();

$statements[0];   // CREATE TABLE IF NOT EXISTS users ...
$statements[1];   // CREATE TABLE IF NOT EXISTS posts ...
```

`create()` executes each statement on its own model's connection.