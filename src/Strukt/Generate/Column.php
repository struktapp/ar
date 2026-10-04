<?php

namespace Strukt\Generate;

/**
 * Describes a single column of a database table.
 */
final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly bool $primary = false,
    ) {
    }
}