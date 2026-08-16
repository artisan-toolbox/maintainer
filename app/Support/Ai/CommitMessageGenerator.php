<?php

namespace App\Support\Ai;

interface CommitMessageGenerator
{
    public function generate(
        string $provider,
        string $status,
        string $diff,
        ?string $userContext = null,
    ): string;
}
