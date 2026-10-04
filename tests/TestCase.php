<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for the package.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        \Strukt\Env::reset();
        \Strukt\Ar\ConnectionManager::reset();
    }
}