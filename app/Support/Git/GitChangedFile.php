<?php

namespace App\Support\Git;

final readonly class GitChangedFile
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public string $status,
        public string $label,
        public array $paths,
    ) {}
}
