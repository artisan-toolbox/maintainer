<?php

namespace App\Support\Ai;

final readonly class ReleaseDiffContext
{
    /**
     * @param  list<string>  $chunks
     * @param  list<string>  $omittedFiles
     */
    public function __construct(
        public array $chunks,
        public array $omittedFiles,
        public bool $truncated,
    ) {}
}
