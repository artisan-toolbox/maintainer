<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class BrowserLauncher
{
    /**
     * Open a local file in the operating system's default browser.
     */
    public function open(string $path): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $path],
            'Windows' => ['cmd', '/c', 'start', '', $path],
            default => ['xdg-open', $path],
        };

        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'The default browser could not be opened.';

            throw new RuntimeException($message);
        }
    }
}
