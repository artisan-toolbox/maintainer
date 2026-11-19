<?php

namespace App\Support\Release;

use Closure;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class GitHubReleaseGate
{
    /**
     * @param  (Closure(list<string>, string): Process)|null  $processFactory
     */
    public function __construct(private ?Closure $processFactory = null) {}

    public function waitForSuccess(string $projectRoot, PreparedRelease $release, int $timeoutSeconds = 900): void
    {
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $command = [
            'gh', 'run', 'list', '--repo', $release->repository,
            '--workflow', $release->workflow, '--event', 'push',
            '--branch', $release->branch, '--commit', $release->commit,
            '--limit', '1', '--json', 'headSha,headBranch,event,status,conclusion,url',
        ];
        $runUrl = null;

        do {
            $process = $this->processFactory === null
                ? new Process($command, $projectRoot)
                : ($this->processFactory)($command, $projectRoot);
            $process->setTimeout(min(30, max(1, ($deadline - hrtime(true)) / 1_000_000_000)));
            $process->run();

            if (! $process->isSuccessful()) {
                $message = trim($process->getErrorOutput()) ?: 'GitHub CLI could not list the required workflow runs.';

                throw new RuntimeException($message);
            }

            $run = $this->runForRelease($process->getOutput(), $release);

            if ($run !== null) {
                $runUrl = $run['url'];

                if ($run['status'] === 'completed') {
                    throw_unless(
                        $run['conclusion'] === 'success',
                        RuntimeException::class,
                        "Required workflow {$release->workflow} concluded {$run['conclusion']} for {$release->commit}. {$runUrl}",
                    );

                    return;
                }
            }

            $remaining = $deadline - hrtime(true);

            if ($remaining > 0) {
                Sleep::usleep((int) min(5_000_000, ceil($remaining / 1000)));
            }
        } while (hrtime(true) < $deadline);

        $detail = $runUrl === null ? 'No matching push run was found.' : "The workflow run is still pending. {$runUrl}";

        throw new RuntimeException("Timed out waiting for {$release->workflow} on {$release->commit}. {$detail}");
    }

    /** @return array{status: string, conclusion: string, url: string}|null */
    private function runForRelease(string $output, PreparedRelease $release): ?array
    {
        try {
            $runs = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub CLI returned invalid workflow run data.', previous: $exception);
        }

        if ($runs === []) {
            return null;
        }

        $run = is_array($runs) && array_is_list($runs) && count($runs) === 1 ? $runs[0] : null;

        throw_unless(
            is_array($run)
                && ($run['headSha'] ?? null) === $release->commit
                && ($run['headBranch'] ?? null) === $release->branch
                && ($run['event'] ?? null) === 'push'
                && is_string($run['status'] ?? null)
                && is_string($run['conclusion'] ?? null)
                && is_string($run['url'] ?? null),
            RuntimeException::class,
            'GitHub CLI did not return a push workflow run for the prepared release commit.',
        );

        return [
            'status' => $run['status'],
            'conclusion' => $run['conclusion'],
            'url' => $run['url'],
        ];
    }
}
