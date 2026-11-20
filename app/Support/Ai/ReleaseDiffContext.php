<?php

namespace App\Support\Ai;

final readonly class ReleaseDiffContext
{
    /**
     * @param  list<string>  $chunks
     * @param  list<string|null>  $paths  File path for each chunk, including continuations of a large file.
     * @param  list<string>  $omittedFiles
     */
    public function __construct(
        public array $chunks,
        public array $omittedFiles,
        public bool $truncated,
        public bool $hasAnalyzableChanges,
        public array $paths,
    ) {}
}
