<?php

namespace App\Support\Release;

final readonly class SemanticVersionNumber
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $prerelease = null,
        public ?int $prereleaseNumber = null,
    ) {}

    public function value(): string
    {
        $version = "{$this->major}.{$this->minor}.{$this->patch}";

        if ($this->prerelease === null) {
            return $version;
        }

        $version .= '-'.$this->prerelease;

        return $this->prereleaseNumber === null
            ? $version
            : $version.'.'.$this->prereleaseNumber;
    }
}
