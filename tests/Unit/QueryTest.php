<?php

use Tests\Fixtures\User;

beforeEach(function () {
    withSchema();
});

it('inserts a single row and returns the affected count', function () {
    $affected = db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect($affected)->toBe(1);
    expect(db()->table('users')->count())->toBe(1);
});

it('inserts several rows at once', function () {
    $affected = db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test'],
    ]);

    expect($affected)->toBe(2);
    expect(db()->table('users')->count())->toBe(2);
});

it('rejects malformed multi-row inserts', function () {
    expect(fn () => db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test'],
        ['name' => 'Ada'],
    ]))->toThrow(InvalidArgumentException::class);
});

it('inserts a row and returns its identifier', function () {
    $id = db()->table('users')->insertGetId(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect($id)->toBe('1');
    expect(db()->table('users')->where('email', 'sam@strukt.test')->value('name'))->toBe('Sam');
});

it('filters with the full where operator set', function () {
    db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test', 'is_admin' => 1, 'birthday' => null],
        ['name' => 'Ada', 'email' => 'ada@strukt.test', 'is_admin' => 0, 'birthday' => null],
        ['name' => 'Ivy', 'email' => 'ivy@strukt.test', 'is_admin' => 0, 'birthday' => '1995-04-20'],
    ]);

    expect(db()->table('users')->where('name', 'Sam')->count())->toBe(1);
    expect(db()->table('users')->where('name', '!=', 'Sam')->count())->toBe(2);
    expect(db()->table('users')->where('is_admin', '>', 0)->count())->toBe(1);
    expect(db()->table('users')->where('is_admin', 0)->orWhere('name', 'Sam')->count())->toBe(3);
    expect(db()->table('users')->where(['is_admin' => 0, 'name' => 'Ivy'])->count())->toBe(1);
    expect(db()->table('users')->whereIn('name', ['Sam', 'Ivy'])->count())->toBe(2);
    expect(db()->table('users')->whereNotIn('name', ['Sam', 'Ivy'])->count())->toBe(1);
    expect(db()->table('users')->whereNull('birthday')->count())->toBe(2);
    expect(db()->table('users')->whereNotNull('birthday')->count())->toBe(1);
    expect(db()->table('users')->whereBetween('id', [1, 2])->count())->toBe(2);
});

it('orders, limits and offsets the results', function () {
    foreach (['Sam', 'Ada', 'Ivy', 'Eve'] as $index => $name) {
        db()->table('users')->insert(['name' => $name, 'email' => strtolower($name) . '@strukt.test']);
    }

    $names = db()->table('users')->orderBy('name')->limit(2)->pluck('name');

    expect($names)->toBe(['Ada', 'Eve']);

    $paged = db()->table('users')->orderBy('name')->limit(2)->offset(1)->pluck('name');

    expect($paged)->toBe(['Eve', 'Ivy']);
});

it('composes multiple order clauses into one ORDER BY', function () {
    foreach ([
        ['name' => 'Sam', 'email' => 'sam@strukt.test', 'created_at' => '2026-01-02'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test', 'created_at' => '2026-01-01'],
        ['name' => 'Sam', 'email' => 'sam2@strukt.test', 'created_at' => '2026-01-03'],
    ] as $row) {
        db()->table('users')->insert($row);
    }

    $names = db()->table('users')
        ->orderBy('name', 'desc')
        ->orderBy('created_at', 'desc')
        ->pluck('name');

    expect($names)->toBe(['Sam', 'Sam', 'Ada']);
});

it('replaces prior ordering with a single order clause', function () {
    foreach ([
        ['name' => 'Sam', 'email' => 'sam@strukt.test', 'created_at' => '2026-01-02'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test', 'created_at' => '2026-01-01'],
        ['name' => 'Ivy', 'email' => 'ivy@strukt.test', 'created_at' => '2026-01-03'],
    ] as $row) {
        db()->table('users')->insert($row);
    }

    $names = db()->table('users')->orderBy('name')->reorder('created_at', 'desc')->pluck('name');

    expect($names)->toBe(['Ivy', 'Sam', 'Ada']);

    $unsorted = db()->table('users')->orderBy('name')->reorder()->pluck('name');

    expect($unsorted[0])->toBe('Sam');
});

it('collects distinct values', function () {
    db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'a@strukt.test'],
        ['name' => 'Sam', 'email' => 'b@strukt.test'],
    ]);

    expect(db()->table('users')->distinct()->pluck('name'))->toBe(['Sam']);
});

it('updates matching rows', function () {
    db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $affected = db()->table('users')->where('id', 1)->update(['name' => 'Samantha']);

    expect($affected)->toBe(1);
    expect(db()->table('users')->value('name'))->toBe('Samantha');
});

it('increments and decrements columns', function () {
    db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    db()->table('users')->where('id', 1)->increment('is_admin');
    expect(db()->table('users')->value('is_admin'))->toBe(1);

    db()->table('users')->where('id', 1)->increment('is_admin', 2);
    expect(db()->table('users')->value('is_admin'))->toBe(3);

    db()->table('users')->where('id', 1)->decrement('is_admin');
    expect(db()->table('users')->value('is_admin'))->toBe(2);
});

it('deletes only rows matched by a where clause', function () {
    db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test'],
    ]);

    expect(db()->table('users')->where('name', 'Ada')->delete())->toBe(1);
    expect(db()->table('users')->count())->toBe(1);
});

it('refuses to delete without a where clause', function () {
    db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(fn () => db()->table('users')->delete())->toThrow(LogicException::class);
});

it('computes aggregates', function () {
    db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test'],
    ]);
    db()->table('posts')->insert([
        ['user_id' => 1, 'title' => 'First', 'votes' => 5],
        ['user_id' => 1, 'title' => 'Second', 'votes' => 9],
        ['user_id' => 2, 'title' => 'Third', 'votes' => 1],
    ]);

    expect(db()->table('posts')->count())->toBe(3);
    expect(db()->table('posts')->sum('votes'))->toBe(15);
    expect(db()->table('posts')->avg('votes'))->toBe(5.0);
    expect(db()->table('posts')->min('votes'))->toBe(1);
    expect(db()->table('posts')->max('votes'))->toBe(9);
});

it('checks for matches and reads single values', function () {
    db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(db()->table('users')->where('name', 'Sam')->exists())->toBeTrue();
    expect(db()->table('users')->where('name', 'Nope')->exists())->toBeFalse();
    expect(db()->table('users')->value('name'))->toBe('Sam');
    expect(db()->table('users')->where('name', 'Nope')->value('name', 'fallback'))->toBe('fallback');
    expect(db()->table('users')->pluck('name'))->toBe(['Sam']);
    expect(db()->table('users')->pluck('name', 'id'))->toBe([1 => 'Sam']);
});

it('joins and groups results', function () {
    db()->table('users')->insert([
        ['name' => 'Sam', 'email' => 'sam@strukt.test'],
        ['name' => 'Ada', 'email' => 'ada@strukt.test'],
    ]);
    db()->table('posts')->insert([
        ['user_id' => 1, 'title' => 'First'],
        ['user_id' => 1, 'title' => 'Second'],
        ['user_id' => 2, 'title' => 'Third'],
    ]);

    $rows = db()->table('posts')
        ->join('users', 'users.id', 'posts.user_id')
        ->select(['users.name', 'posts.title'])
        ->orderBy('posts.id')
        ->get();

    expect($rows)->toHaveCount(3);
    expect($rows[0]['name'])->toBe('Sam');

    $counts = db()->table('posts')
        ->join('users', 'users.id', 'posts.user_id')
        ->select(['users.name', 'COUNT(posts.id) AS total'])
        ->groupBy('users.name')
        ->orderBy('users.name')
        ->get();

    expect($counts)->toBe([
        ['name' => 'Ada', 'total' => 1],
        ['name' => 'Sam', 'total' => 2],
    ]);
});

it('compiles sql and binds values', function () {
    $query = db()->table('users')
        ->where('is_admin', 1)
        ->where('name', 'Sam')
        ->limit(5)
        ->offset(10);

    expect($query->toSql())->toBe('SELECT * FROM users WHERE (is_admin = ?) AND (name = ?) LIMIT ? OFFSET ?');
    expect($query->bindings())->toBe([1, 'Sam', 5, 10]);
});

it('hydrates selected rows into models', function () {
    db()->table('users')->insert(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $users = db()->table('users')->model(User::class)->get();

    expect($users)->toHaveCount(1);
    expect($users[0])->toBeInstanceOf(User::class);
    expect($users[0]->name)->toBe('Sam');
});