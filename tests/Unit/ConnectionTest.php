<?php

use Strukt\Ar\Connection;
use Strukt\Ar\ConnectionManager;
use Strukt\Ar\Query;

beforeEach(function () {
    withSchema();
});

it('opens an in-memory sqlite connection', function () {
    $connection = Connection::sqlite(':memory:');

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->pdo())->toBeInstanceOf(PDO::class);
});

it('registers itself as the default connection', function () {
    $connection = Connection::sqlite(':memory:')->asDefault();

    expect(ConnectionManager::default())->toBe($connection);
});

it('registers itself under a named connection', function () {
    $connection = Connection::sqlite(':memory:')->as('audit');

    expect(ConnectionManager::has('audit'))->toBeTrue();
    expect(ConnectionManager::get('audit'))->toBe($connection);
});

it('runs statements and returns the affected row count', function () {
    $count = db()->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);

    expect($count)->toBe(1);
    expect(db()->execute('DELETE FROM users'))->toBe(1);
});

it('selects rows keyed by column name', function () {
    db()->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);

    $rows = db()->select('SELECT name, email FROM users');

    expect($rows)->toBe([['name' => 'Sam', 'email' => 'sam@strukt.test']]);
});

it('returns the last inserted id', function () {
    db()->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);

    expect(db()->lastInsertId())->toBe('1');
});

it('commits a successful transaction', function () {
    db()->transaction(function (Connection $connection) {
        $connection->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);
        $connection->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Ada', 'ada@strukt.test']);
    });

    expect(db()->table('users')->count())->toBe(2);
});

it('rolls back a failed transaction and rethrows', function () {
    $thrown = false;

    try {
        db()->transaction(function (Connection $connection) {
            $connection->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Sam', 'sam@strukt.test']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $caught) {
        $thrown = $caught->getMessage() === 'boom';
    }

    expect($thrown)->toBeTrue();
    expect(db()->table('users')->count())->toBe(0);
});

it('starts a query builder bound to the connection', function () {
    $query = db()->table('users');

    expect($query)->toBeInstanceOf(Query::class);
    expect($query->table())->toBe('users');
});

it('opens the connection described by one environment file', function () {
    $file = tempnam(sys_get_temp_dir(), 'karrowr_');
    unlink($file);
    $env = $file . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE={$file}\n");

    $connection = Connection::fromEnv($env);

    expect(ConnectionManager::default())->toBe($connection);
    expect(db())->toBe($connection);

    $connection->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    expect(db()->table('widgets')->count())->toBe(0);

    @unlink($env);
    @unlink($file);
});

it('lets a later environment file override an earlier one', function () {
    $db1 = tempnam(sys_get_temp_dir(), 'karrowr_');
    $db2 = tempnam(sys_get_temp_dir(), 'karrowr_');
    unlink($db1);
    unlink($db2);
    $env = $db1 . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE={$db1}\n");
    file_put_contents($db1 . '.local.env', "DB_DATABASE={$db2}\n");

    $connection = Connection::fromEnv($env, $db1 . '.local.env');
    db()->execute('CREATE TABLE later (id INTEGER PRIMARY KEY AUTOINCREMENT)');

    expect(count((new PDO('sqlite:' . $db2))->query("SELECT name FROM sqlite_master WHERE type='table' AND name='later'")->fetchAll()))->toBe(1);
    expect(count((new PDO('sqlite:' . $db1))->query("SELECT name FROM sqlite_master WHERE type='table' AND name='later'")->fetchAll()))->toBe(0);

    @unlink($env);
    @unlink($db1 . '.local.env');
    @unlink($db1);
    @unlink($db2);
});

it('falls back to the process environment', function () {
    $file = tempnam(sys_get_temp_dir(), 'karrowr_');
    unlink($file);
    $env = $file . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\n");

    putenv('DB_DATABASE=' . $file);
    try {
        $connection = Connection::fromEnv($env);
    } finally {
        putenv('DB_DATABASE');
    }

    expect(ConnectionManager::default())->toBe($connection);
    $connection->execute('CREATE TABLE scratch (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    expect(count((new PDO('sqlite:' . $file))->query("SELECT name FROM sqlite_master WHERE type='table' AND name='scratch'")->fetchAll()))->toBe(1);

    @unlink($env);
    @unlink($file);
});

it('rejects an unsupported environment driver', function () {
    $env = tempnam(sys_get_temp_dir(), 'karrowr_') . '.env';
    file_put_contents($env, "DB_CONNECTION=oracle\nDB_DATABASE=app\n");

    expect(fn () => Connection::fromEnv($env))->toThrow(InvalidArgumentException::class);

    @unlink($env);
});