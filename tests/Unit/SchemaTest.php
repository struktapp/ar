<?php

use Strukt\Ar\Connection;
use Strukt\Ar\Model;
use Strukt\Ar\Schema;
use Tests\Fixtures\User;

class SchemaBrokenType extends Model
{
    protected static array $schema = ['tags' => ['type' => 'binary']];
}

class SchemaMissingType extends Model
{
    protected static array $schema = ['tags' => ['default' => 'x']];
}

class SchemaKeyClash extends Model
{
    protected static array $schema = ['id' => ['type' => 'string']];
}

it('compiles a CREATE TABLE from the model schema', function () {
    [$sql] = User::schema()->toSql();

    expect($sql)->toStartWith('CREATE TABLE IF NOT EXISTS users (')
        ->and($sql)->toContain('id INTEGER PRIMARY KEY AUTOINCREMENT')
        ->and($sql)->toContain('name TEXT NOT NULL')
        ->and($sql)->toContain('email TEXT NOT NULL UNIQUE')
        ->and($sql)->toContain('is_admin INTEGER NOT NULL DEFAULT 0')
        ->and($sql)->toContain('birthday TEXT')
        ->and($sql)->toContain('profile TEXT')
        ->and($sql)->toContain('created_at TEXT')
        ->and($sql)->toContain('updated_at TEXT')
        ->and($sql)->toEndWith(')');
});

it('creates the table from the model schema', function () {
    Connection::sqlite(':memory:')->asDefault();

    User::schema()->create();
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(db()->table('users')->count())->toBe(1);
    expect(User::find(1)->name)->toBe('Sam');

    User::schema()->create();
});

it('enforces NOT NULL and unique columns', function () {
    Connection::sqlite(':memory:')->asDefault();

    User::schema()->create();
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(fn () => db()->table('users')->insert(['name' => 'Bart']))
        ->toThrow(PDOException::class);

    expect(fn () => User::create(['name' => 'Bart', 'email' => 'sam@strukt.test']))
        ->toThrow(PDOException::class);
});

it('compiles several models in model order', function () {
    $statements = Schema::for([User::class, Tests\Fixtures\Post::class])->toSql();

    expect($statements)->toHaveCount(2);
    expect($statements[0])->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($statements[1])->toContain('CREATE TABLE IF NOT EXISTS posts');
});

it('rejects unknown or missing column types', function () {
    Connection::sqlite(':memory:')->asDefault();

    expect(fn () => Schema::for(SchemaBrokenType::class)->create())->toThrow(LogicException::class);
    expect(fn () => Schema::for(SchemaMissingType::class)->create())->toThrow(LogicException::class);
});

it('rejects columns that collide with generated ones', function () {
    Connection::sqlite(':memory:')->asDefault();

    expect(fn () => Schema::for(SchemaKeyClash::class)->create())->toThrow(LogicException::class);
    expect(fn () => User::schema()->toSql())->not->toThrow(LogicException::class);
});