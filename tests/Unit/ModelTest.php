<?php

use Strukt\Ar\Model;
use Strukt\Ar\ModelNotFoundException;
use Tests\Fixtures\Comment;
use Tests\Fixtures\Post;
use Tests\Fixtures\User;

beforeEach(function () {
    withSchema();
});

it('derives the table name from the class name', function () {
    expect(User::tableName())->toBe('users');
});

it('creates a row and finds it back', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect($sam->exists())->toBeTrue();
    expect($sam->key())->toBe(1);

    $found = User::find(1);

    expect($found)->toBeInstanceOf(User::class);
    expect($found->name)->toBe('Sam');
    expect($found->email)->toBe('sam@strukt.test');
});

it('throws when a row cannot be found', function () {
    expect(fn () => User::findOrFail(999))->toThrow(ModelNotFoundException::class);
});

it('guards mass assignment with fillable', function () {
    expect(fn () => User::create([
        'name' => 'Sam',
        'email' => 'sam@strukt.test',
        'id' => 99,
        'profile' => 'injected',
    ]))->not->toThrow(Throwable::class);

    $sam = User::find(1);

    expect($sam->id)->toBe(1);
    expect($sam->profile)->toBeNull();

    $sam->setAttribute('profile', 'direct');
    expect($sam->profile)->toBe('direct');
});

it('casts attributes for reading and writing', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test', 'is_admin' => 1, 'birthday' => '1995-04-20']);

    $sam = User::find(1);

    expect($sam->is_admin)->toBeTrue();
    expect($sam->birthday)->toBeInstanceOf(DateTimeImmutable::class);
    expect($sam->birthday->format('Y-m-d'))->toBe('1995-04-20');

    $sam->birthday = new DateTimeImmutable('2000-01-02 03:04:05');
    $sam->save();

    expect(db()->table('users')->value('birthday'))->toBe('2000-01-02 03:04:05');
    expect(User::find(1)->birthday->format('Y-m-d H:i:s'))->toBe('2000-01-02 03:04:05');
});

it('maintains timestamps on create and update', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $createdAt = $sam->created_at;

    sleep(1);
    $sam->update(['name' => 'Samantha']);

    $fresh = User::find($sam->key());

    expect($fresh->name)->toBe('Samantha');
    expect($fresh->created_at)->toBe($createdAt);
    expect($fresh->updated_at)->not->toBe($createdAt);
});

it('updates only the columns that changed', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $before = db()->table('users')->orderBy('id')->get();

    $sam->update(['name' => 'Samantha']);

    $after = db()->table('users')->orderBy('id')->get();

    expect($after[0]['name'])->toBe('Samantha');
    expect($after[0]['created_at'])->toBe($before[0]['created_at']);
});

it('reloads a row with refresh', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    db()->table('users')->where('id', $sam->key())->update(['name' => 'Samantha']);

    expect($sam->name)->toBe('Sam');
    $sam->refresh();

    expect($sam->name)->toBe('Samantha');
});

it('deletes an existing row', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(User::count())->toBe(1);
    expect($sam->delete())->toBeTrue();
    expect($sam->exists())->toBeFalse();
    expect(User::count())->toBe(0);
});

it('never deletes an unsaved model', function () {
    $sam = new User();

    expect($sam->delete())->toBeFalse();
});

it('exposes attributes as properties', function () {
    $sam = new User();
    $sam->setAttribute('name', 'Sam');

    expect(isset($sam->name))->toBeTrue();
    expect($sam->has('name'))->toBeTrue();
    expect($sam->name)->toBe('Sam');

    $sam->name = 'Samantha';
    expect($sam->name)->toBe('Samantha');
});

it('rejects unknown attributes with a clear message', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(fn () => $sam->missing)->toThrow(OutOfBoundsException::class);
});

it('serializes attributes to array and json', function () {
    $hidden = new class extends Model {
        protected static ?string $table = 'users';
        protected static array $hiddens = ['email'];
    };

    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $array = $sam->toArray();

    expect($array['name'])->toBe('Sam');
    expect($array['created_at'])->not->toBeNull();
    expect($sam->toJson(JSON_THROW_ON_ERROR))->toBeJson();

    $limited = $hidden->hydrate(['id' => 1, 'email' => 'secret@strukt.test']);
    expect($limited->toArray())->toBe(['id' => 1]);
});

it('returns the primary key value', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect($sam->key())->toBe(1);
    expect(User::keyName())->toBe('id');
});

it('queries with the static where entry point', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    $rows = User::where('is_admin', 0)->orderBy('name')->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBeInstanceOf(User::class);
    expect(User::first()->name)->toBe('Sam');
});

it('loads has-many relations lazily and memoizes them', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $ada = User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    Post::create(['user_id' => $sam->key(), 'title' => 'First']);
    Post::create(['user_id' => $sam->key(), 'title' => 'Second']);
    Post::create(['user_id' => $ada->key(), 'title' => 'Third']);

    $posts = $sam->posts();

    expect($posts)->toHaveCount(2);
    expect($posts[0])->toBeInstanceOf(Post::class);
    expect($posts[0]->title)->toBe('First');

    expect($ada->posts())->toHaveCount(1);
    expect($sam->posts())->toBe($posts);
});

it('loads belongs-to relations lazily', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $post = Post::create(['user_id' => $sam->key(), 'title' => 'First']);

    expect($post->author())->toBeInstanceOf(User::class);
    expect($post->author()->name)->toBe('Sam');

    $orphan = new Post();
    $orphan->setAttribute('user_id', null);

    expect($orphan->author())->toBeNull();
});

it('loads nested relations across models', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $post = Post::create(['user_id' => $sam->key(), 'title' => 'First']);
    Comment::create(['post_id' => $post->key(), 'body' => 'Nice work']);

    $comments = $sam->posts()[0]->comments();

    expect($comments)->toHaveCount(1);
    expect($comments[0]->body)->toBe('Nice work');
    expect($comments[0]->post()->title)->toBe('First');
});

it('reads when a model query produces no results', function () {
    expect(User::find(1))->toBeNull();
    expect(User::first())->toBeNull();
    expect(User::all())->toBe([]);
    expect(User::count())->toBe(0);
    expect(fn () => User::findOrFail(1))->toThrow(ModelNotFoundException::class);
});