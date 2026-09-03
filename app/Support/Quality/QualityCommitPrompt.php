<?php

namespace App\Support\Quality;

use Closure;

use function Laravel\Prompts\confirm;

final readonly class QualityCommitPrompt
{
    /** @param  (Closure(): bool)|null  $prompt */
    public function __construct(private ?Closure $prompt = null) {}

    public function shouldCommit(): bool
    {
        return $this->prompt === null
            ? confirm(
                'The project has changes. Would you like to create a commit now?',
                true,
            )
            : ($this->prompt)();
    }
}
