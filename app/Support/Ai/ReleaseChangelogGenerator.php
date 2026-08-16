<?php

namespace App\Support\Ai;

use App\Support\Release\ReleaseChangeSet;

interface ReleaseChangelogGenerator
{
    /** @return list<ChangelogEntry> */
    public function generate(string $provider, string $version, ReleaseChangeSet $changes): array;
}
