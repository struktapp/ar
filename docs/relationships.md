# Relationships

Describe a relationship as a method that delegates to `hasMany()`, `hasOne()`,
or `belongsTo()`. Results are computed on the first call and memoized for the
life of the instance, so reading the same relation twice never queries twice.

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
$user->posts();                  // Post[]
$user->posts()[0]->comments();   // nested, still one query per relation
```

## The three relation types

| Type | Returns | Query on the related table |
|---|---|---|
| `hasMany($related)` | `array` | `WHERE foreign_key = ?` ordered by `id` |
| `hasOne($related)` | `Model\|null` | `WHERE foreign_key = ?`, first match |
| `belongsTo($related)` | `Model\|null` | `WHERE owner_key = ?`, first match |

`belongsTo` returns `null` when the foreign key is empty; the other two return
an empty array / null when nothing matches.

## Key derivation

Foreign and local keys are derived from the class names and can be overridden
in every call:

| Relation | Default foreign key | Default local/owner key |
|---|---|---|
| `hasMany($related)` | `{snake owner}_id` on the related table | this row's primary key |
| `hasOne($related)` | `{snake owner}_id` on the related table | this row's primary key |
| `belongsTo($related)` | `{snake related}_id` on this table | the related class's `keyName()` |

`{snake Owner}` is the short class name snake-cased, so `User` yields
`user_id`:

```php
class User extends Model
{
    public function posts(): array
    {
        return $this->hasMany(Post::class);            // posts.user_id = users.id
    }
}

class Comment extends Model
{
    public function author(): ?User
    {
        return $this->belongsTo(User::class);          // comments.user_id = users.id
    }
}
```

Override when the schema differs:

```php
return $this->hasMany(Post::class, 'owner_id');
return $this->belongsTo(User::class, 'creator_id', 'uuid');
```

## Access and memoization

Relations are resolved lazily through `__get`, so property access and method
calls both work. The result is cached per model instance keyed by the relation
and its resolved keys — reading `$user->posts()` twice returns the same array
without a second query.

An accessor that returns anything other than a model, an array of models, or
`null` throws `LogicException` — this keeps relation methods honest:

```
Relation [foo] must return a model or an array of models.
```

## Composition

Relation results are realized arrays, so they compose like any model collection:

```php
$posts = $user->posts();                     // Post[]
$recent = array_slice(array_filter(
    $posts,
    fn (Post $post): bool => $post->published && $post->created_at >= $cutoff,
), 0, 10);
```

For filtered, ordered, or aggregated reads, build the query through the model —
the relation helpers capture the common case, the query builder the rest:

```php
Post::query()->where('user_id', $user->key())->where('published', 1)
    ->orderBy('created_at', 'desc')->get();
```