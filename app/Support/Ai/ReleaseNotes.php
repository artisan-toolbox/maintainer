<?php

namespace App\Support\Ai;

final readonly class ReleaseNotes
{
    public function __construct(
        public string $title,
        public string $body,
    ) {}
}
