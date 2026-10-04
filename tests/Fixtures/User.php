<?php

namespace Tests\Fixtures;

use Strukt\Ar\Model;

/**
 * User fixture used throughout the test suite.
 */
class User extends Model
{
    protected static bool $timestamps = true;

    protected static array $fillable = ['name', 'email', 'is_admin', 'birthday'];

    protected static array $casts = [
        'is_admin' => 'bool',
        'birthday' => 'datetime',
    ];

    protected static array $schema = [
        'name' => ['type' => 'string', 'nullable' => false],
        'email' => ['type' => 'string', 'nullable' => false, 'unique' => true],
        'is_admin' => ['type' => 'bool', 'nullable' => false, 'default' => false],
        'birthday' => ['type' => 'datetime', 'nullable' => true],
        'profile' => ['type' => 'text', 'nullable' => true],
    ];

    public function posts(): array
    {
        return $this->hasMany(Post::class);
    }
}