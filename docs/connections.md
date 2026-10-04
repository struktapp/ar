# Connections

A `Strukt\Ar\Connection` is a thin PDO wrapper that owns the connection for the
whole package. PDO is configured to throw on errors, fetch by column name, and
never emulate prepares — a failed statement surfaces as a `PDOException` at the
call site.

## Opening a connection

Open one through a named constructor and register it with the connection
manager; the `db()` helper resolves the default from then on:

```php
use Strukt\Ar\Connection;

Connection::sqlite(':memory:')->asDefault();   // this is what db() returns
Connection::sqlite('app.db')->as('archive');   // named, opt in

db();             // the default connection
db('archive');    // a named connection
```

Available constructors:

| Constructor | Purpose |
|---|---|
| `sqlite($file)` | A SQLite database file, or `:memory:`. Enables `PRAGMA foreign_keys = ON`. |
| `mysql($database, $user, $password = '', $host = '127.0.0.1', $port = 3306, $charset = 'utf8mb4')` | MySQL or MariaDB. |
| `pgsql($database, $user, $password = '', $host = '127.0.0.1', $port = 5432)` | PostgreSQL. |
| `wrap(PDO $pdo)` | Wraps an existing PDO connection. |

`asDefault()` registers the connection under the special `default` name;
`as($name)` registers it under any other name.

## The connection manager

`Strukt\Ar\ConnectionManager` is the registry of named connections:

```php
use Strukt\Ar\ConnectionManager;

ConnectionManager::has('audit');     // whether a name is registered
ConnectionManager::get('audit');     // the connection, or OutOfBoundsException
ConnectionManager::default();        // the default, or LogicException when unset
ConnectionManager::define('audit');  // make 'audit' the default name
ConnectionManager::reset();          // drop every connection (tests)
```

An empty name is rejected with `InvalidArgumentException`. Without a registered
default, `db()` and the models throw a `LogicException` that explains how to
register one.

## The `db()` helper

```php
db();              // ConnectionManager::default()
db('audit');       // ConnectionManager::get('audit')
```

## Running statements

Every query goes through `run()`, which prepares and executes against PDO:

```php
db()->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);
// 1 (affected rows)

db()->select('SELECT name, email FROM users');
// [['name' => 'Sam', 'email' => 'sam@strukt.test']]

db()->lastInsertId();   // the last inserted identifier
```

`run($sql, $bindings)` returns the executed `PDOStatement`; `execute()` returns
the affected row count; `select()` returns every row keyed by column name.

## Transactions

`transaction($work)` runs a callback inside a transaction, committing on
success and rolling back when the callback throws. Nested calls share the
surrounding transaction:

```php
db()->transaction(function (Connection $connection) {
    $connection->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [25, 7]);
    $connection->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [25, 9]);
});
```

## The query builder entry point

`table($table)` starts a fluent query against one table:

```php
$query = db()->table('users');
```

See the [query builder](query-builder.md) topic.

## Connections from environment files

`Connection::fromEnv()` loads `.env` file(s) through `strukt/base`'s `Env` and
opens the connection they describe, registering it as the default. Files are
read in order, so a later file overrides an earlier one — the classic `.env`
plus `.env.local` pattern:

```env
DB_CONNECTION=sqlite
DB_DATABASE=app.db
```

```php
Connection::fromEnv('.env', '.env.local');   // defaults to ['.env']
```

### Keys and defaults

Keys are read with `env()`, falling back to the process environment and then to
these defaults:

| Key | Default |
|---|---|
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | `database.sqlite` |
| `DB_HOST` | `127.0.0.1` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | empty |
| `DB_PORT` | `3306` for mysql, `5432` for pgsql |

The driver name is matched case-insensitively; an unknown `DB_CONNECTION`
throws `InvalidArgumentException`:

```php
Connection::fromEnv('.env');   // DB_CONNECTION=oracle
// InvalidArgumentException: Unsupported DB_CONNECTION [oracle].
```

### Precedence

Files override each other in argument order. With `.env` + `.env.local`, values
from `.env.local` win:

```php
# .env
DB_CONNECTION=sqlite
DB_DATABASE=app.db

# .env.local
DB_DATABASE=local.db

Connection::fromEnv('.env', '.env.local');   // opens local.db
```

A value missing from every file falls back to the process environment first,
then to the defaults above.