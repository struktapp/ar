<?php

use Strukt\Ar\Connection;
use Strukt\Ar\ConnectionManager;

beforeEach(function () {
    ConnectionManager::reset();
});

it('returns the default connection', function () {
    $connection = Connection::sqlite(':memory:')->asDefault();

    expect(db())->toBe($connection);
});

it('returns the named connection', function () {
    $connection = Connection::sqlite(':memory:')->as('audit');

    expect(db('audit'))->toBe($connection);
});

it('fails without a default connection', function () {
    expect(fn () => db())->toThrow(LogicException::class);
});

it('fails for an unknown named connection', function () {
    Connection::sqlite(':memory:')->asDefault();

    expect(fn () => db('unknown'))->toThrow(OutOfBoundsException::class);
});

it('knows which connections exist and renames the default', function () {
    Connection::sqlite(':memory:')->as('audit');
    Connection::sqlite(':memory:')->as('primary');

    expect(ConnectionManager::has('audit'))->toBeTrue();
    expect(ConnectionManager::has('missing'))->toBeFalse();

    ConnectionManager::define('audit');

    expect(db())->toBe(db('audit'));
});

it('refuses empty connection names', function () {
    expect(fn () => ConnectionManager::register('  ', Connection::sqlite(':memory:')))->toThrow(InvalidArgumentException::class);
});

it('returns the underlying pdo instance', function () {
    withSchema();

    expect(db()->pdo())->toBeInstanceOf(PDO::class);
});