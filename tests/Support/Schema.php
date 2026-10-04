<?php

namespace Tests\Support;

use Strukt\Ar\Connection;

/**
 * Builds the package test schema on a fresh in-memory database.
 */
final class Schema
{
    /**
     * Opens an in-memory database, registers it as the default connection and
     * creates the users, posts, and comments tables.
     *
     * @return Connection Fresh default connection.
     */
    public static function create(): Connection
    {
        $connection = Connection::sqlite(':memory:')->asDefault();

        $connection->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            is_admin INTEGER NOT NULL DEFAULT 0,
            birthday TEXT,
            profile TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $connection->execute('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT,
            published INTEGER NOT NULL DEFAULT 0,
            votes INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users (id)
        )');

        $connection->execute('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT,
            FOREIGN KEY (post_id) REFERENCES posts (id)
        )');

        return $connection;
    }
}