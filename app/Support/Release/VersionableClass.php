<?php

namespace App\Support\Release;

final readonly class VersionableClass
{
    /**
     * @param  list<string>  $interfaces
     */
    public function __construct(
        public string $name,
        public string $file,
        public bool $hasVersionConstant,
        public ?string $version,
        public array $interfaces = [],
    ) {}

    public function implements(string $interface): bool
    {
        return in_array($interface, $this->interfaces, true);
    }
}
