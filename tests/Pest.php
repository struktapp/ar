<?php

use Strukt\Ar\Connection;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

/**
 * Opens a fresh in-memory database with the package schema and registers it
 * as the default connection.
 *
 * @return Connection Fresh connection.
 */
function withSchema(): Connection
{
    Tests\Support\Schema::create();

    return db();
}