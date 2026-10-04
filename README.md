# Strukt Ar

A small query builder and active record ORM for SQL databases. It sits directly on
PDO, parameterizes every statement, and keeps the moving parts small enough to read in
one sitting.

A single namespace covers the data-access and active record layers:

| | `Strukt\Ar` |
|---|---|
| Style | immediate, explicit writes |
| Identity | one object per call |
| Writes | `save()`/`delete()` run at once |
| Reads | query builder per query |
| Use | low-level control |

Managed entities — an identity map, a unit of work, and result caching over the very
same models — live in the separate `strukt/orm` package; see the install note below.

## Install

```bash
composer require strukt/ar
```

The package needs at least PHP 8.2 and a PDO driver for your database. For managed
entities — identity map, unit of work, result cache layered over these very same
models and connection — install `strukt/orm` as well; its README documents how the
two layers interoperate.

## Connections

A `Strukt\Ar\Connection` wraps a PDO instance. Open one through a named constructor and
register it with the manager; the `db()` helper resolves the default connection from
then on.

```php
use Strukt\Ar\Connection;

Connection::sqlite(':memory:')->asDefault();   // this is what db() returns
Connection::sqlite('app.db')->as('archive');   // named, opt in

db();             // the default connection
db('archive');    // a named connection
```

`mysql()` and `pgsql()` take host, credentials, and similar connection options. `pdo()`
wraps an existing client. A transaction runs inside `transaction()`, which commits on
success and rolls back when the callback throws; nested calls share the outer
transaction.

```php
db()->transaction(function (Connection $connection) {
    $connection->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [25, 7]);
    $connection->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [25, 9]);
});
```

### Connections from environment files

`Connection::fromEnv()` loads `.env` file(s) through `strukt/base`'s `Env` and opens the
connection they describe, registering it as the default. Files are read in order, so a
later file overrides an earlier one — the classic `.env` plus `.env.local` pattern.

```env
DB_CONNECTION=sqlite
DB_DATABASE=app.db
```

```php
Connection::fromEnv('.env', '.env.local');   // defaults to ['.env'];
```

Keys are read with `env()`, falling back to the process environment and then to
defaults: `DB_CONNECTION` (`sqlite`), `DB_DATABASE` (`database.sqlite`),
`DB_HOST` (`127.0.0.1`), `DB_USERNAME` (`root`), `DB_PASSWORD` (empty), and
`DB_PORT` (3306 for mysql, 5432 for pgsql).

## The query builder

`table()` starts a fluent, always parameterized query against one table. Every value is
bound, never interpolated, so clauses are safe by construction.

```php
db()->table('users')
    ->where('active', 1)
    ->where('age', '>', 21)
    ->orderBy('name')
    ->limit(10)
    ->get();
```

`where()` accepts equality shorthand, an explicit operator, or a map of equalities;
`orWhere()`, `whereIn()`, `whereNotIn()`, `whereNull()`, `whereNotNull()`, and
`whereBetween()` cover the rest. `join()` and `leftJoin()` join without adding
bindings, so a query stays legible.

```php
db()->table('posts')
    ->join('users', 'users.id', 'posts.user_id')
    ->select(['users.name', 'posts.title'])
    ->groupBy('users.name')
    ->get();
```

Reading gives you rows, single values, or aggregates:

```php
db()->table('users')->where('active', 1)->count(); // 5
db()->table('users')->sum('balance');              // 412.5
db()->table('users')->pluck('name', 'id');         // [1 => 'Sam', ...]
db()->table('users')->where('id', 7)->value('name'); // 'Sam'
db()->table('users')->where('email', $email)->exists(); // true
```

The last row inside a `transaction()` insert surfaces through `insertGetId()`. Writes
are guarded the same way: `delete()` refuses to run without a `where()` clause, and
`update()`/`increment()`/`decrement()` touch only the matching rows.

Clauses accumulate on the instance. Keep a base query, then clone it for variations:

```php
$base = db()->table('users')->where('active', 1);
$admins = (clone $base)->where('admin', 1)->get();
$staff  = (clone $base)->where('admin', 0)->get();
```

`toSql()` and `bindings()` expose the compiled statement for debugging.

## Active record

Extend `Strukt\Ar\Model`. One instance is one row: create, read, update, delete, and
their relationships all run through the query builder above, so the two layers compose.

```php
class User extends Model
{
    protected static array $fillable = ['name', 'email', 'is_admin'];

    public function posts(): array
    {
        return $this->hasMany(Post::class);
    }
}

$sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
$sam->name;                 // 'Sam', via the columns of the row
$sam->is_admin = true;
$sam->save();

User::find(1);              // reloads the row
User::where('is_admin', 0)->orderBy('name')->get();
User::first();
```

### Table names and keys

The table name is derived from the class short name, pluralized and snake-cased:
`PostComment` maps to `post_comments`. Override it, or the key, when the schema differs:

```php
class User extends Model
{
    protected static ?string $table = 'people';
    protected static string $keyName = 'uuid';
}
```

### Fillable, casts, and timestamps

`fillable` guards every mass assignment through `create()`, `fill()`, and `update()`;
a column must be listed before it can be set that way. Individual columns always accept
direct assignment through the property or `setAttribute()`.

`casts` map how values enter and leave the database. Dates become `DateTimeImmutable`
on read and return to `Y-m-d H:i:s` on write; `bool`, `int`, `float`, `string`, and
`array` round trip their JSON representation automatically.

Timestamps are maintained when `$timestamps` is left at its default `true`:

```php
$sam = User::create([...]);
$sam->created_at;   // '2026-10-02 16:52:58'
$sam->updated_at;   // set again on every save()
```

### The lifecycle

```php
$sam = User::findOrFail(7);      // or types a clear ModelNotFoundException

$sam->update(['name' => 'Samantha']);
$sam->refresh();                 // reloads the row in place
$sam->delete();                  // false when the model does not exist yet

$sam->exists();                  // whether the row is in the database
$sam->key();                     // the primary key value
$sam->toArray();                 // visible, cast columns
$sam->toJson();                  // the same, as JSON
```

`save()` inserts when the model is new and updates otherwise, touching only the
columns that changed. `fresh` reads always go through the query builder, so nothing is
cached at the query layer.

### Relationships

Describe a relationship as a method that delegates to `hasMany()`, `hasOne()`, or
`belongsTo()`. Foreign keys are derived from the class names and can be overridden.
Results are computed on the first call and remembered for the life of the instance.

```php
class Post extends Model
{
    public function author(): ?User
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): array
    {
        return $this->hasMany(Comment::class);
    }
}

$post->author()->name;
$user->posts();                    // Post[]
$user->posts()[0]->comments();     // nested, still one query per relation
```

## Magical properties only

Model columns are read through `__get`/`__set`. Requesting a column that is neither an
attribute nor a declared relationship throws `OutOfBoundsException`, so a typo fails
loudly instead of returning `null`.

## Managed entities via `strukt/orm`

For an identity map, a unit of work, and result caching over these very same models,
install the `strukt/orm` package:

```bash
composer require strukt/orm
```

It layers managed-entity semantics on top of untouched `Strukt\Ar` models and the
default connection, so the two layers read and write the same rows through the same
classes:

```php
$sam = orm()->find(User::class, 7);    // one instance per row
$sam->name = 'Samantha';               // nothing written yet
orm()->commit();                       // one transaction
```

See `strukt/orm`'s README for the full contract, especially how direct Ar writes
interact with its result cache and identity map.

## Generating models from the database

The package ships a `model:generate` command that introspects a database schema and
writes `Ar\Model` subclasses. It runs through the `strukt/console` runtime, so the
same docblock drives the CLI, `--help`, and generated documentation.

```bash
php bin/dbcli model:generate --database=app.db --driver=sqlite

# restrict to one table, or pick a namespace and directory
php bin/dbcli model:generate --database=app.db --driver=sqlite \
    --table=users --namespace=App\Models --dir=src/Models
```

MySQL and PostgreSQL add host and credentials:

```bash
php bin/dbcli model:generate --driver=mysql --database=app \
    --host=127.0.0.1 --user=root --password=secret --dir=src/Models

php bin/dbcli model:generate --driver=pgsql --database=app \
    --host=127.0.0.1 --user=app --dir=src/Models
```

Omit `--database` to pick the connection from a dotenv file instead. `--env` lists
the file(s), defaults to `.env`, and accepts comma-separated values.

```bash
php bin/dbcli model:generate --dir=src/Models            # from .env
php bin/dbcli model:generate --env=.env,.env.local
```

For every table the generator derives the class name (singular, PascalCase), pins
`$table`, selects a `$keyName` when the primary key is not `id`, disables
`$timestamps` when the table has no `created_at`/`updated_at`, and fills `$casts` from
the column types and `$fillable` with everything except the key and the timestamp
columns:

```php
// blog_posts -> BlogPost; pk id; no timestamp columns
class BlogPost extends Model
{
    protected static ?string $table = 'blog_posts';
    protected static bool $timestamps = false;
    protected static array $fillable = ['author_id', 'title', 'published_at', 'views'];
    protected static array $casts = [
        'id' => 'int',
        'author_id' => 'int',
        'published_at' => 'datetime',
        'views' => 'int',
    ];
}
```

When the package is installed as a dependency, the command is available as
`vendor/bin/dbcli`. Use `list` to see the available commands.

## Creating the database from models

The reverse of `model:generate` is `db:create`: it scans a directory for models
that declare a `$schema` and builds their tables, without needing a hand-written
`CREATE TABLE`. The primary key and, when `$timestamps` is on, the
`created_at`/`updated_at` columns are generated for you.

```php
class User extends Model
{
    protected static array $schema = [
        'name'    => ['type' => 'string', 'nullable' => false],
        'email'   => ['type' => 'string', 'nullable' => false, 'unique' => true],
        'is_admin' => ['type' => 'bool',  'nullable' => false, 'default' => false],
        'birthday' => ['type' => 'datetime', 'nullable' => true],
    ];
}
```

```bash
php bin/dbcli db:create --database=app.db --driver=sqlite --dir=src

# restrict discovery to one namespace
php bin/dbcli db:create --database=app.db --driver=sqlite \
    --dir=src --namespace=App\Models

php bin/dbcli db:create --driver=mysql --database=app --user=root \
    --password=secret --dir=src/Models
```

Omit `--database` to pick the connection from a dotenv file instead (see
`--env`, which defaults to `.env`):

```bash
php bin/dbcli db:create --dir=src/Models   # from .env
```

Models without a declared `$schema` are skipped, and running the command again is
a no-op thanks to `IF NOT EXISTS`. Described types map to SQLite storage classes:
int/bool to INTEGER, float to REAL, string/text/datetime/json to TEXT, and blob
to BLOB.

## Tests

```bash
composer test
```

The suite runs against an in-memory SQLite connection with the same schema the code
examples use, and covers connections, the query builder, the active record layer, and
the `model:generate` schema generator on top of it.