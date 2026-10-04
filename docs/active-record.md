# Active record

Extend `Strukt\Ar\Model`. One instance is one row: create, read, update, delete,
and their relationships all run through the [query builder](query-builder.md),
so the two layers compose.

```php
use Strukt\Ar\Model;

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

## Table names and keys

The table name is derived from the class short name, pluralized and snake-cased:
`PostComment` maps to `post_comments`. Override it, or the key, when the schema
differs:

```php
class User extends Model
{
    protected static ?string $table = 'people';
    protected static string $keyName = 'uuid';
}
```

By default the primary key column is `id`. `tableName()` and `keyName()` return
the resolved values.

## Connecting

A model reads and writes through the default connection unless pointed
elsewhere:

```php
User::connect(Connection::sqlite('audit.db')->as('audit'));
User::connection();   // returns the pinned connection
```

`connect()` pins the class to a concrete connection; otherwise
`Connection::default()` is used.

## Reading

```php
User::all();             // every row as models
User::find(7);           // by primary key, or null
User::findOrFail(7);     // or ModelNotFoundException
User::first();           // first row or null
User::where('active', 1)->orderBy('name')->get();
User::count();           // matching rows, live
```

`query()` starts a model-bound query; `where()` is a shortcut that opens a fresh
one. Rows hydrate through `Model::hydrate()`.

`ModelNotFoundException` carries the searched model and key:

```
No [App\User] was found for key [7].
```

## Fillable, casts, and timestamps

### Fillable

`$fillable` guards every mass assignment through `create()`, `fill()`, and
`update()` — a column must be listed before it can be set that way. An empty
`$fillable` allows everything. Columns always accept direct assignment through
the property or `setAttribute()`.

### Casts

`$casts` maps how values enter and leave the database:

```php
protected static array $casts = [
    'is_admin' => 'bool',
    'birthday' => 'datetime',
    'profile'  => 'json',
];
```

Dates become `DateTimeImmutable` on read and return to `Y-m-d H:i:s` on write;
`bool`, `int`, `float`, `string`, and `array`/`json` round trip their JSON
representation automatically.

### Timestamps

`created_at`/`updated_at` are maintained when `$timestamps` is left at its
default `true`:

```php
$sam = User::create([...]);
$sam->created_at;   // '2026-10-02 16:52:58'
$sam->updated_at;   // set again on every save()
```

A new row gets both columns (when not already set); an update re-stamps
`updated_at` only.

## The lifecycle

```php
$sam = User::findOrFail(7);

$sam->update(['name' => 'Samantha']);
$sam->refresh();                 // reloads the row in place
$sam->delete();                  // false when the model does not exist yet

$sam->exists();                  // whether the row is in the database
$sam->key();                     // the primary key value
$sam->toArray();                 // visible, cast columns
$sam->toJson();                  // the same, as JSON
```

- `save()` inserts when the model is new and updates otherwise, touching only
  the columns that changed. Errors surface as `PDOException`.
- `delete()` removes the row and returns whether a row was removed.
- `refresh()` reloads the current row in place, or throws when it is gone.

## Attributes and magic accessors

Columns are exposed as properties through `__get`/`__set`. Requesting a column
that is neither an attribute nor a declared relationship throws
`OutOfBoundsException`, so a typo fails loudly instead of returning `null`.

```php
$sam->name;                 // magic read
$sam->name = 'Samantha';    // magic write
$sam->getAttribute('name');
$sam->setAttribute('name', 'Sam');
$sam->has('name');          // whether the column is set
```

`attributes()` returns every column value in read form, minus hidden columns;
`toArray()` and `toJson()` expose the same. `$hiddens` lists columns kept out of
those disclosures (for example an API token).

## Model-bound queries

Static entry points are only a sample of what a `Query` can do; for the full
surface — joins, in-clauses, aggregates, chunking patterns — see the
[query builder](query-builder.md):

```php
User::query()
    ->join('posts', 'posts.user_id', 'users.id')
    ->where('is_admin', 1)
    ->groupBy('users.name')
    ->get();
```

When a query is created through a model it hydrates into model instances. Fresh
reads always go through the query builder, so nothing is cached at the query
layer.

## Hidden columns

```php
class User extends Model
{
    protected static array $hiddens = ['api_token'];
}
```

Hidden columns stay readable as properties but drop out of `attributes()`,
`toArray()`, and `toJson()`.