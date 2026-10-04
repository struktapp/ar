<?php

namespace Tests\Unit;

use Strukt\Ar\Connection;
use Strukt\Console\Runtime\Application;
use Strukt\Generate\Column;
use Strukt\Generate\ModelWriter;
use Strukt\Generate\SchemaInspector;

beforeEach(function () {
    $this->connection = Connection::sqlite(':memory:');
    $this->connection->execute('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )');
    $this->connection->execute('CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        published_at DATETIME
    )');
    $this->connection->execute('CREATE TABLE pages (
        slug TEXT PRIMARY KEY,
        title TEXT NOT NULL
    )');
});

test('inspector lists tables and columns for sqlite', function () {
    $inspector = new SchemaInspector($this->connection);

    expect($inspector->tables())->toBe(['pages', 'posts', 'users']);

    $users = $inspector->columns('users');
    expect(array_column($users, 'name'))->toBe(['id', 'name', 'is_admin', 'created_at', 'updated_at']);
    expect($users[0]->primary)->toBeTrue();
    expect($users[1]->primary)->toBeFalse();
    expect($users[1]->nullable)->toBeFalse();
    expect($users[3]->nullable)->toBeFalse();
});

test('inspector detects a non-id primary key', function () {
    $inspector = new SchemaInspector($this->connection);

    $pages = $inspector->columns('pages');

    expect($pages[0]->name)->toBe('slug');
    expect($pages[0]->primary)->toBeTrue();
});

test('writer derives PascalCase class names from tables', function () {
    $writer = new ModelWriter();

    expect($writer->className('users'))->toBe('User');
    expect($writer->className('user_posts'))->toBe('UserPost');
    expect($writer->className('blog_entries'))->toBe('BlogEntry');
});

test('writer renders an id-keyed model', function () {
    $writer = new ModelWriter();
    $code = $writer->render('users', [
        new Column('id', 'INTEGER', false, true),
        new Column('name', 'TEXT'),
        new Column('is_admin', 'TINYINT(1)', false),
        new Column('created_at', 'DATETIME'),
        new Column('updated_at', 'DATETIME'),
    ], 'App\Model');

    expect($code)->toContain('class User extends Model');
    expect($code)->toContain("protected static ?string \$table = 'users';");
    expect($code)->not->toContain('$keyName');
    expect($code)->toContain("'id' => 'int'");
    expect($code)->toContain("'is_admin' => 'bool'");
    expect($code)->not->toContain('$timestamps = false;');
    expect($code)->not->toContain("\n        'id',");
    expect($code)->not->toContain("\n        'created_at',");
    expect($code)->toContain("\n        'name',");
});

test('writer renders a non-id key and disables timestamps', function () {
    $writer = new ModelWriter();
    $code = $writer->render('pages', [
        new Column('slug', 'VARCHAR(64)', false, true),
        new Column('title', 'TEXT'),
    ], 'App\Model');

    expect($code)->toContain("protected static string \$keyName = 'slug';");
    expect($code)->toContain('protected static bool $timestamps = false;');
    expect($code)->toContain("'title',");
});

test('writer maps json and numeric casts', function () {
    $writer = new ModelWriter();
    $code = $writer->render('settings', [
        new Column('id', 'INTEGER', false, true),
        new Column('payload', 'JSON'),
        new Column('price', 'DECIMAL(10,2)'),
    ], 'App\Model');

    expect($code)->toContain("'payload' => 'array'");
    expect($code)->toContain("'price' => 'float'");
    expect($code)->toContain("'id' => 'int'");
});

test('model:generate command writes models through the console application', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    $connection = Connection::sqlite($file);
    $connection->execute('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME,
        updated_at DATETIME
    )');
    $connection->execute('CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL
    )');

    $dir = sys_get_temp_dir() . '/dbcli_models_' . uniqid();
    $root = dirname(__DIR__, 2);
    $argv = [
        'dbcli',
        'model:generate',
        '--database=' . $file,
        '--driver=sqlite',
        '--dir=' . $dir,
        '--namespace=Tests\\Generated',
    ];

    $exit = (new Application($root . '/src/Strukt/Generate/Command'))->run($argv);

    expect($exit)->toBe(0);
    expect(is_file($dir . '/User.php'))->toBeTrue();
    expect(is_file($dir . '/Post.php'))->toBeTrue();

    $user = file_get_contents($dir . '/User.php');
    expect($user)->toContain('namespace Tests\\Generated;');
    expect($user)->toContain("class User extends Model");
    expect($user)->toContain("protected static ?string \$table = 'users';");
    expect($user)->not->toContain('$timestamps = false;');

    $post = file_get_contents($dir . '/Post.php');
    expect($post)->toContain('protected static bool $timestamps = false;');

    @unlink($file);
});

test('model:generate reports a missing table', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    Connection::sqlite($file)->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');

    $options = [
        'driver' => 'sqlite',
        'database' => $file,
        'host' => '127.0.0.1',
        'port' => null,
        'user' => 'root',
        'password' => '',
        'dir' => sys_get_temp_dir(),
        'namespace' => 'App\\Model',
        'table' => 'missing',
    ];

    expect(fn() => (new \Strukt\Generate\Command\ModelGenerate())->execute([], $options))
        ->toThrow(\RuntimeException::class, 'Table does not exist');

    @unlink($file);
});

test('generated model drives the ar layer end-to-end', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    $connection = Connection::sqlite($file);
    $connection->execute('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME,
        updated_at DATETIME
    )');

    $inspector = new SchemaInspector(Connection::sqlite($file));
    $code = (new ModelWriter())->render('users', $inspector->columns('users'), 'Tests\\Generated\\Works');

    $dir = sys_get_temp_dir() . '/dbcli_models_' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/User.php', $code);

    require_once $dir . '/User.php';

    Connection::sqlite($file)->asDefault();

    $model = new \Tests\Generated\Works\User();
    $model->name = 'Ada';

    expect($model->save())->toBeTrue();
    expect(\Tests\Generated\Works\User::count())->toBe(1);

    $user = \Tests\Generated\Works\User::first();
    expect($user->name)->toBe('Ada');
    expect($user->id)->toBe(1);
    expect($user->is_admin)->toBeFalse();

    @unlink($file);
});

test('bin/dbcli generates models from the shell', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    Connection::sqlite($file)->execute('CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    )');

    $dir = sys_get_temp_dir() . '/dbcli_models_' . uniqid();
    $root = dirname(__DIR__, 2);

    $command = sprintf(
        '%s %s model:generate --database=%s --driver=sqlite --dir=%s --namespace=AppGenerated --table=posts 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/bin/dbcli'),
        escapeshellarg($file),
        escapeshellarg($dir),
    );
    exec($command, $output, $exit);

    expect($exit)->toBe(0);
    expect(implode("\n", $output))->toContain('Generated AppGenerated\Post -> ');
    expect(is_file($dir . '/Post.php'))->toBeTrue();

    @unlink($file);
});

test('db:create builds tables from a directory of models', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    $root = dirname(__DIR__, 2);

    $options = [
        'driver' => 'sqlite',
        'database' => $file,
        'host' => '127.0.0.1',
        'port' => null,
        'user' => 'root',
        'password' => '',
        'dir' => $root . '/tests/Fixtures',
        'namespace' => 'Tests\\Fixtures',
    ];

    ob_start();
    $exit = (new \Strukt\Generate\Command\DbCreate())->execute([], $options);
    $output = ob_get_clean();

    expect($exit)->toBe(0);
    expect($output)->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($output)->toContain('Created 1 table(s)');

    $connection = Connection::sqlite($file);
    $tables = array_column($connection->select("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
    expect($tables)->toContain('users');
    expect($tables)->not->toContain('posts');

    $connection->execute("INSERT INTO users (name, email) VALUES ('Sam', 'sam@strukt.test')");
    expect($connection->table('users')->count())->toBe(1);

    @unlink($file);
});

test('db:create reports when no schema models are found', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    $root = dirname(__DIR__, 2);

    $options = [
        'driver' => 'sqlite',
        'database' => $file,
        'host' => '127.0.0.1',
        'port' => null,
        'user' => 'root',
        'password' => '',
        'dir' => $root . '/tests/Fixtures',
        'namespace' => 'App\\Missing',
    ];

    ob_start();
    $exit = (new \Strukt\Generate\Command\DbCreate())->execute([], $options);
    $output = ob_get_clean();

    expect($exit)->toBe(0);
    expect($output)->toContain('No models with a declared schema');

    @unlink($file);
});

test('bin/dbcli creates the database from the shell', function () {
    $file = tempnam(sys_get_temp_dir(), 'db_');
    unlink($file);
    $root = dirname(__DIR__, 2);

    $command = sprintf(
        '%s %s db:create --database=%s --driver=sqlite --dir=%s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/bin/dbcli'),
        escapeshellarg($file),
        escapeshellarg($root . '/tests/Fixtures'),
    );
    exec($command, $output, $exit);

    expect($exit)->toBe(0);
    $output = implode("\n", $output);
    expect($output)->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($output)->toContain('Created 1 table(s)');

    $tables = array_column(Connection::sqlite($file)->select("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
    expect($tables)->toContain('users');

    @unlink($file);
});

test('db:create builds tables from the env-described connection', function () {
    $file = tempnam(sys_get_temp_dir(), 'dbebs_');
    unlink($file);
    $env = $file . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE={$file}\n");
    $root = dirname(__DIR__, 2);

    $options = [
        'database' => null,
        'env' => $env,
        'driver' => 'sqlite',
        'host' => '127.0.0.1',
        'port' => null,
        'user' => 'root',
        'password' => '',
        'dir' => $root . '/tests/Fixtures',
        'namespace' => 'Tests\\Fixtures',
    ];

    ob_start();
    $exit = (new \Strukt\Generate\Command\DbCreate())->execute([], $options);
    $output = ob_get_clean();

    expect($exit)->toBe(0);
    expect($output)->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($output)->toContain("Created 1 table(s) on [{$file}]");

    $tables = array_column(Connection::sqlite($file)->select("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
    expect($tables)->toContain('users');

    @unlink($env);
    @unlink($file);
});

test('model:generate reads the schema from the env-described connection', function () {
    $file = tempnam(sys_get_temp_dir(), 'envgen_');
    unlink($file);
    $env = $file . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE={$file}\n");
    Connection::sqlite($file)->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');

    $dir = sys_get_temp_dir() . '/dbcli_models_' . uniqid();
    $root = dirname(__DIR__, 2);

    $options = [
        'database' => null,
        'env' => $env,
        'driver' => 'sqlite',
        'host' => '127.0.0.1',
        'port' => null,
        'user' => 'root',
        'password' => '',
        'dir' => $dir,
        'namespace' => 'Tests\\Generated\\Env',
        'table' => null,
    ];

    ob_start();
    $exit = (new \Strukt\Generate\Command\ModelGenerate())->execute([], $options);
    $output = ob_get_clean();

    expect($exit)->toBe(0);
    expect($output)->toContain('Generated Tests\Generated\Env\Post ->');
    expect(is_file($dir . '/Post.php'))->toBeTrue();
    expect(file_get_contents($dir . '/Post.php'))->toContain("protected static ?string \$table = 'posts';");

    @unlink($env);
    @unlink($file);
});