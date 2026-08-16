<?php

namespace App\Support\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitCommitRepository
{
    /**
     * @return list<GitChangedFile>
     */
    public function changes(string $projectRoot): array
    {
        $output = $this->run([
            'git',
            'status',
            '--porcelain=v1',
            '-z',
            '--untracked-files=all',
        ], $projectRoot);

        if ($output === '') {
            return [];
        }

        $records = explode("\0", rtrim($output, "\0"));
        $changes = [];

        for ($index = 0; $index < count($records); $index++) {
            $record = $records[$index];
            $status = substr($record, 0, 2);
            $path = substr($record, 3);
            $renamed = str_contains($status, 'R') || str_contains($status, 'C');

            if ($renamed) {
                $source = $records[++$index] ?? throw new RuntimeException('Git returned an incomplete rename status.');
                $changes[] = new GitChangedFile($status, "{$status} {$source} -> {$path}", [$source, $path]);

                continue;
            }

            $changes[] = new GitChangedFile($status, "{$status} {$path}", [$path]);
        }

        return $changes;
    }

    /**
     * Replace the index with the complete contents of the selected files.
     *
     * @param  list<GitChangedFile>  $files
     */
    public function stageOnly(string $projectRoot, array $files): void
    {
        throw_if($files === [], RuntimeException::class, 'At least one changed file must be selected.');

        $this->run(['git', 'reset', '--quiet', '--', '.'], $projectRoot);

        $paths = array_values(array_unique(array_merge(...array_map(
            static fn (GitChangedFile $file): array => $file->paths,
            $files,
        ))));

        $this->run(['git', 'add', '--all', '--', ...$paths], $projectRoot);
    }

    public function stagedStatus(string $projectRoot): string
    {
        return trim($this->run([
            'git',
            'diff',
            '--cached',
            '--name-status',
            '--find-renames',
        ], $projectRoot));
    }

    public function stagedDiff(string $projectRoot): string
    {
        return $this->run([
            'git',
            'diff',
            '--cached',
            '--no-ext-diff',
            '--no-color',
            '--find-renames',
            '--',
            '.',
        ], $projectRoot);
    }

    public function commit(string $projectRoot, string $message): string
    {
        return trim($this->run(['git', 'commit', '--message', $message], $projectRoot));
    }

    public function pushToOrigin(string $projectRoot): string
    {
        return trim($this->run(['git', 'push', 'origin', 'HEAD'], $projectRoot));
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $projectRoot): string
    {
        $process = new Process($command, $projectRoot);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($message !== '' ? $message : 'Git could not complete the requested operation.');
        }

        return $process->getOutput();
    }
}
