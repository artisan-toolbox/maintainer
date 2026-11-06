<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GitPushFixture
{
    public string $directory;

    public string $local;

    public string $remote;

    public function __construct(?string $local = null)
    {
        $this->directory = temporaryTestDirectory('maintainer-push-');
        $this->local = $local ?? $this->directory.'/local';
        $this->remote = $this->directory.'/origin.git';
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->local);
        $files->ensureDirectoryExists($this->remote);
        $this->git(['init', '--bare', '--initial-branch=main'], $this->remote);
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Maintainer Tests']);
        $this->git(['config', 'user.email', 'maintainer@example.com']);
        $this->commit('Initial commit');
        $this->git(['remote', 'add', 'origin', $this->remote]);
        $this->git(['push', '-u', 'origin', 'main']);
    }

    public function commit(string $message): string
    {
        file_put_contents($this->local.'/example.txt', $message."\n", FILE_APPEND);
        $this->git(['add', 'example.txt']);
        $this->git(['commit', '-m', $message]);

        return $this->git(['rev-parse', 'HEAD']);
    }

    /** @param list<string> $arguments */
    public function git(array $arguments, ?string $directory = null): string
    {
        return trim(new Process(['git', ...$arguments], $directory ?? $this->local)->mustRun()->getOutput());
    }

    public function cleanup(): void
    {
        deleteTemporaryDirectory($this->directory);
    }
}
