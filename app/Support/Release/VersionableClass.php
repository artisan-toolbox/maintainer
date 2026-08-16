<?php

namespace App\Support\Release;

final readonly class VersionableClass
{
    public function __construct(
        public string $name,
        public string $file,
        public bool $hasVersionConstant,
        public ?string $version,
    ) {}
}
