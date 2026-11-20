<?php

namespace App\Support\Ai;

final readonly class ReleaseVersionRecommendation
{
    /** @param array<string, string> $reasons */
    public function __construct(
        public ?string $version,
        public array $reasons,
        public bool $truncated = false,
    ) {}
}
