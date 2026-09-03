<?php

namespace App\Support\Quality;

use Closure;

use function Laravel\Prompts\confirm;

final readonly class QualityCheckPrompt
{
    /** @param  (Closure(): bool)|null  $prompt */
    public function __construct(private ?Closure $prompt = null) {}

    public function shouldRun(): bool
    {
        return $this->prompt === null
            ? confirm(
                'Would you like to run the configured code-quality checks now?',
                true,
            )
            : ($this->prompt)();
    }
}
