<?php

namespace Strukt\Ar;

/**
 * Registry of named {@see Connection} instances.
 *
 * Connections are registered with a name so helpers and models can resolve
 * the right database without passing one around. The `default` name is
 * special: `db()` with no arguments resolves it.
 */
final class ConnectionManager
{
    /** @var array<string, Connection> Named connections. */
    private static array $connections = [];

    /** @var string Name of the default connection. */
    private static string $default = 'default';

    /**
     * Registers a connection under a name.
     *
     * @param string $name Connection name.
     * @param Connection $connection Connection to register.
     * @return void
     *
     * @throws \InvalidArgumentException When the name is empty.
     */
    public static function register(string $name, Connection $connection): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A connection name cannot be empty.');
        }

        self::$connections[$name] = $connection;
    }

    /**
     * Checks whether a connection is registered.
     *
     * @param string $name Connection name.
     * @return bool True when the name is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$connections[$name]);
    }

    /**
     * Returns the connection registered under a name.
     *
     * @param string $name Connection name.
     * @return Connection Registered connection.
     *
     * @throws \OutOfBoundsException When the name is not registered.
     */
    public static function get(string $name): Connection
    {
        if (!isset(self::$connections[$name])) {
            throw new \OutOfBoundsException(sprintf('Connection [%s] was not registered.', $name));
        }

        return self::$connections[$name];
    }

    /**
     * Returns the default connection.
     *
     * @return Connection Default connection.
     *
     * @throws \LogicException When no default has been registered.
     */
    public static function default(): Connection
    {
        if (!isset(self::$connections[self::$default])) {
            throw new \LogicException(
                'No default database connection has been registered. '
                . 'Register one with Connection::sqlite(...)->asDefault() first.'
            );
        }

        return self::$connections[self::$default];
    }

    /**
     * Names the default connection.
     *
     * @param string $name Connection name to use as the default.
     * @return void
     */
    public static function define(string $name): void
    {
        self::$default = $name;
    }

    /**
     * Removes every registered connection.
     *
     * Used by the test suite to isolate cases.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connections = [];
        self::$default = 'default';
    }
}