<?php

namespace Tests\Fixtures;

use Strukt\Ar\Model;

/**
 * Post fixture used throughout the test suite.
 */
class Post extends Model
{
    protected static bool $timestamps = true;

    protected static ?string $table = 'posts';

    protected static array $fillable = ['user_id', 'title', 'body', 'published'];

    protected static array $casts = [
        'published' => 'bool',
    ];

    public function author(): ?User
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): array
    {
        return $this->hasMany(Comment::class);
    }
}