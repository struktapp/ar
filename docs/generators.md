# Generators

The package ships two console commands — `model:generate` and `db:create` —
backed by a driver-aware inspector and a model writer. They run through the
`strukt/console` runtime, so the docblock drives the CLI, `--help`, and
generated documentation.

```bash
php bin/dbcli list            # available commands
php bin/dbcli --help          # command options, from the docblocks
```

When the package is installed as a dependency, the commands are available as
`vendor/bin/dbcli`.

## SchemaInspector

`Strukt\Generate\SchemaInspector` reads the shape of a database through a
connection. It is driver aware — SQLite (`PRAGMA table_info`), MySQL, and
PostgreSQL (`information_schema`) — and reports tables along with their
columns, types, nullability, and primary keys.

```php
use Strukt\Generate\SchemaInspector;

$inspector = new SchemaInspector(Connection::sqlite('app.db'));

$inspector->tables();                 // ['pages', 'posts', 'users']
$inspector->columns('users');         // Column[]
```

Columns are `Strukt\Generate\Column` value objects (`name`, `type`, `nullable`,
`primary`). A non-`id` primary key — a `slug` key, say — is detected as such.

## ModelWriter

`Strukt\Generate\ModelWriter` turns a table and its columns into a model class.
`className()` derives the PascalCase class name from a singularized, camel-cased
table name; `render()` emits the PHP source.

```php
use Strukt\Generate\ModelWriter;

$writer = new ModelWriter();

$writer->className('user_posts');    // 'UserPost'
$writer->render('users', $inspector->columns('users'), 'App\Model');
```

For every table the writer pins `$table`, selects a `$keyName` when the primary
key is not `id`, disables `$timestamps` when the table has no
`created_at`/`updated_at`, and fills `$casts` from the column types and
`$fillable` with everything except the key and the timestamp columns:

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

Column types map to casts: integer families to `int`, decimals and floats to
`float`, booleans (including `TINYINT(1)`) to `bool`, date/time families to
`datetime`, and `json`/`jsonb` to `array`.

## model:generate

Introspects a database schema and writes `Ar\Model` subclasses:

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

Omit `--database` to pick the connection from a dotenv file instead. `--env`
lists the file(s), defaults to `.env`, and accepts comma-separated values:

```bash
php bin/dbcli model:generate --dir=src/Models            # from .env
php bin/dbcli model:generate --env=.env,.env.local
```

With no tables the command prints `No tables found.`; a `--table` that does not
exist throws `RuntimeException`.

### Options

| Option | Default | Notes |
|---|---|---|
| `--database` | — | Database name, file, or `:memory:`. Omit for the env-described connection. |
| `--env` | `.env` | Comma-separated file(s) used when `--database` is omitted. |
| `--driver` | `sqlite` | `sqlite`, `mysql`, or `pgsql`. |
| `--host` | `127.0.0.1` | mysql and pgsql. |
| `--port` | — | `3306` for mysql, `5432` for pgsql. |
| `--user` | `root` | mysql and pgsql. |
| `--password` | `""` | mysql and pgsql. |
| `--dir` | `build/Models` | Directory to write generated models into. |
| `--namespace` | `App\Model` | Namespace for the generated models. |
| `--table` | — | Restrict generation to a single table. |

## db:create

The reverse of `model:generate`: scans a directory for models that declare a
`$schema` and builds their tables — the primary key and, when `$timestamps` is
on, the timestamps are generated for you. See [schema](schema.md) for the
declaration language.

```bash
php bin/dbcli db:create --database=app.db --driver=sqlite --dir=src

# restrict discovery to one namespace
php bin/dbcli db:create --database=app.db --driver=sqlite \
    --dir=src --namespace=App\Models

php bin/dbcli db:create --driver=mysql --database=app --user=root \
    --password=secret --dir=src/Models
```

Omit `--database` to pick the connection from a dotenv file (see `--env`,
which defaults to `.env`):

```bash
php bin/dbcli db:create --dir=src/Models   # from .env
```

The command prints the compiled DDL, then a summary:

```
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, ...);
Created 1 table(s) on [app.db].
```

Models without a declared `$schema` are skipped; when none are found it prints
`No models with a declared schema were found in [src].` and returns 0. Running
the command again is a no-op thanks to `IF NOT EXISTS`.

### Discovery

Files are scanned with the PHP tokenizer rather than loaded, so discovery is
deterministic regardless of what the process already knows. Classes are then
filtered to `Strukt\Ar\Model` subclasses whose `schemaMap()` is non-empty, with
an optional namespace prefix filter.

### Options

`db:create` shares the connection options with `model:generate` (`--database`,
`--env`, `--driver`, `--host`, `--port`, `--user`, `--password`), plus:

| Option | Default | Notes |
|---|---|---|
| `--dir` | `src` | Directory to scan for models with a declared schema. |
| `--namespace` | — | Restrict creation to models inside this namespace. |

The summary names the database — `Env::has('DB_DATABASE')` when `.env` declared
one, otherwise `env`.