<?php

declare(strict_types=1);

use Strukt\Ar\Connection;
use Strukt\Ar\ConnectionManager;

if (!function_exists('db')) {
    /**
     * Returns the default database connection, or the one registered under a name.
     *
     * ```php
     * Connection::sqlite(':memory:')->asDefault();
     *
     * $name = db()->table('users')->value('name'); // default
     * $audit = db('audit');                       // named connection
     * ```
     *
     * @param string|null $name Connection name; the default when omitted.
     * @return Connection Connection instance.
     */
    function db(?string $name = null): Connection
    {
        return $name === null ? ConnectionManager::default() : ConnectionManager::get($name);
    }
}