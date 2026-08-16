<?php

namespace Tests\Fakes;

use App\Support\BrowserLauncher;

final class FakeBrowserLauncher extends BrowserLauncher
{
    public ?string $opened = null;

    public function open(string $path): void
    {
        $this->opened = $path;
    }
}
