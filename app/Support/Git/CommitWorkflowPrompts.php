<?php

namespace App\Support\Git;

use Closure;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

final readonly class CommitWorkflowPrompts
{
    /**
     * @param  (Closure(): bool)|null  $reviewDiff
     * @param  (Closure(): CommitMessageMode)|null  $messageMode
     * @param  (Closure(): bool)|null  $pushCommit
     */
    public function __construct(
        private ?Closure $reviewDiff = null,
        private ?Closure $messageMode = null,
        private ?Closure $pushCommit = null,
    ) {}

    public function shouldReviewDiff(): bool
    {
        return $this->reviewDiff === null
            ? confirm('Would you like to review the complete Git diff in your browser before selecting files?', true)
            : ($this->reviewDiff)();
    }

    public function messageMode(): CommitMessageMode
    {
        if ($this->messageMode !== null) {
            return ($this->messageMode)();
        }

        return CommitMessageMode::from(select(
            label: 'How should the commit message be created?',
            options: [
                CommitMessageMode::Manual->value => 'Write it manually',
                CommitMessageMode::Ai->value => 'Generate it with AI',
                CommitMessageMode::AiWithContext->value => 'Generate it with AI and additional context',
            ],
            default: CommitMessageMode::Ai->value,
        ));
    }

    public function shouldPushCommit(): bool
    {
        return $this->pushCommit === null
            ? confirm('Push this commit to origin?', false)
            : ($this->pushCommit)();
    }
}
