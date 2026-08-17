<?php

namespace App\Support\Ai;

use App\Support\Release\ReleaseChangeSet;

interface ReleaseChangeAnalyzer
{
    public function analyze(string $provider, ReleaseChangeSet $changes): ReleaseChangeSet;
}
