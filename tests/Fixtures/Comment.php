<?php

namespace Tests\Fixtures;

use Strukt\Ar\Model;

/**
 * Comment fixture used throughout the test suite.
 */
class Comment extends Model
{
    protected static bool $timestamps = true;

    protected static ?string $table = 'comments';

    protected static array $fillable = ['post_id', 'body'];

    public function post(): ?Post
    {
        return $this->belongsTo(Post::class);
    }
}