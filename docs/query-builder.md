# Query builder

`Strukt\Ar\Query` is a fluent, parameterized query builder for a single table.
Create one through `Connection::table()`; every value is bound to a prepared
statement, so clauses never reach SQL by string interpolation.

```php
db()->table('users')
    ->where('active', 1)
    ->where('age', '>', 21)
    ->orderBy('name')
    ->limit(10)
    ->get();
```

Clauses accumulate on the instance. Keep a base query, then clone it for
variations:

```php
$base = db()->table('users')->where('active', 1);
$admins = (clone $base)->where('admin', 1)->get();
$staff  = (clone $base)->where('admin', 0)->get();
```

## Selecting

```php
db()->table('users')->select(['name', 'email'])->get();
db()->table('users')->distinct()->get();
```

The default is `*`. `model($class)` attaches a model class so `get()` and
`first()` return hydrated model instances instead of plain rows — the active
record layer uses this internally.

## Where clauses

```php
->where('name', 'Sam')              // name = ?
->where('age', '>', 21)             // age > ?
->where(['a' => 1, 'b' => 2])       // a = ? AND b = ?
->orWhere('name', 'Sam')
```

The operators `where` and `orWhere` accept equality shorthand, an explicit
operator, or a map of equalities. The rest are dedicated clause types:

```php
->whereIn('id', [1, 2, 3])
->whereNotIn('id', [4, 5])
->whereNull('deleted_at')
->whereNotNull('email')
->whereBetween('age', [21, 65])
```

Each clause compiles to parenthesized SQL, so `or` groups bind as expected.

## Joins

`join()` accepts two shapes; bindings are never added for join conditions:

```php
->join('posts', 'posts.user_id', 'users.id')
->join('posts', 'posts.user_id', '=', 'users.id')
->leftJoin('posts', 'posts.user_id', 'users.id')
```

## Ordering, grouping, limits

```php
->orderBy('name', 'desc')
->reorder()                // drop any prior ordering
->reorder('created_at', 'desc')
->groupBy(['status', 'year'])
->limit(10)
->offset(20)
```

## Reading

```php
db()->table('users')->where('active', 1)->get();     // array of rows
db()->table('users')->where('id', 7)->first();       // first row or null
db()->table('users')->where('email', $email)->value('name');  // one column
db()->table('users')->pluck('name', 'id');           // [1 => 'Sam', ...]
```

## Aggregates

```php
db()->table('users')->where('active', 1)->count();   // 5
db()->table('users')->sum('balance');                // 412.5
db()->table('users')->avg('balance');
db()->table('users')->min('age');
db()->table('users')->max('age');
db()->table('users')->where('email', $email)->exists();   // true
```

`aggregate($function, $column)` is the underlying helper for `SUM`, `AVG`,
`MIN`, and `MAX`.

## Writing

`insert()` takes one associative array or a list of arrays sharing the same
columns, and returns the number of rows affected:

```php
db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);
db()->table('users')->insert([
    ['name' => 'Sam', 'email' => 'sam@strukt.test'],
    ['name' => 'Ada', 'email' => 'ada@strukt.test'],
]);

db()->table('users')->insertGetId($row);   // the inserted identifier
```

`update()`, `increment()`, and `decrement()` touch only the matching rows:

```php
db()->table('users')->where('id', 7)->update(['name' => 'Samantha']);
db()->table('users')->where('id', 7)->increment('votes');
db()->table('users')->where('id', 7)->decrement('votes', 2);
```

`delete()` refuses to run without a `where()` clause — a `LogicException`
protects against emptying a table by accident.

## Inspecting the compiled statement

`toSql()` and `bindings()` expose the compiled select for debugging or reuse:

```php
$query = db()->table('users')->where('active', 1)->where('age', '>', 21);

$query->toSql();
// SELECT * FROM users WHERE (active = ?) AND (age > ?)

$query->bindings();
// [1, 21]
```

## Guards

- Every value is bound to a prepared statement — never interpolated.
- `delete()` requires a where clause.
- `insert()` requires at least one row, and every row must share the same
  columns.