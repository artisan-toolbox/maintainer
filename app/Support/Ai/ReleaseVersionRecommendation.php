<?php

namespace App\Support\Ai;

final readonly class ReleaseVersionRecommendation
{
    public function __construct(
        public ReleaseIncrement $increment,
        public string $justification,
    ) {}
}
