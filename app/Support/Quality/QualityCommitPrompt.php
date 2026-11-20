<?php

namespace App\Support\Quality;

use Closure;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

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

    public function shouldRelease(): bool
    {
        return select(
            label: 'What would you like to do after creating the commit?',
            options: [
                'commit' => 'Commit',
                'release' => 'Commit and release',
            ],
            default: 'commit',
            hint: 'Commit and release starts the regular release workflow.',
        ) === 'release';
    }
}
