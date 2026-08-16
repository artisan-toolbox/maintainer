<?php

namespace App\Support\Ai;

use App\Support\Release\ReleaseChangeSet;

interface ReleaseNotesGenerator
{
    public function generate(string $provider, string $version, ReleaseChangeSet $changes): ReleaseNotes;
}
