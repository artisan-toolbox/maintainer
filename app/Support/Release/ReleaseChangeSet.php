<?php

namespace App\Support\Release;

final readonly class ReleaseChangeSet
{
    public function __construct(
        public string $diff,
        public string $commits,
    ) {}
}
