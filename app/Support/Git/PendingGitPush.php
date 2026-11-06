<?php

namespace App\Support\Git;

final readonly class PendingGitPush
{
    /** @param list<string> $commits */
    public function __construct(
        public string $branch,
        public string $targetRef,
        public string $localTip,
        public ?string $remoteTip,
        public string $remoteUrl,
        public array $commits,
        public ?string $problem = null,
    ) {}
}
