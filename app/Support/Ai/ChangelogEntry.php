<?php

namespace App\Support\Ai;

final readonly class ChangelogEntry
{
    public function __construct(
        public string $type,
        public string $hash,
        public string $title,
        public string $description,
    ) {}
}
