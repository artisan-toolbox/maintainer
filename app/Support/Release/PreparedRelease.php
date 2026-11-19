<?php

namespace App\Support\Release;

final readonly class PreparedRelease
{
    public function __construct(
        public string $repository,
        public string $workflow,
        public string $branch,
        public string $commit,
        public string $currentVersion,
        public string $version,
        public string $title,
        public string $body,
    ) {}
}
