<?php

namespace App\Support;

final readonly class VersionableClass
{
    public function __construct(
        public string $name,
        public bool $hasVersionConstant,
    ) {}
}
