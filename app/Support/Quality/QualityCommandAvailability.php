<?php

namespace App\Support\Quality;

final readonly class QualityCommandAvailability
{
    private function __construct(
        public bool $available,
        public ?string $reason = null,
    ) {}

    public static function available(): self
    {
        return new self(true);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason);
    }
}
