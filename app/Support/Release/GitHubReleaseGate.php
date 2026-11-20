<?php

namespace App\Support\Release;

use Closure;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class GitHubReleaseGate
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private const int PROGRESS_INTERVAL = 30;

    private const int JOB_REFRESH_INTERVAL = 15;

    /**
     * @param  (Closure(list<string>, string): Process)|null  $processFactory
     * @param  (Closure(): (int|float))|null  $clock
     */
    public function __construct(
        private ?Closure $processFactory = null,
        private ?Closure $clock = null,
    ) {}

    /**
     * @param  non-empty-list<non-empty-string>  $selectors
     * @return non-empty-list<GitHubReleaseWorkflow>
     */
    public function resolveWorkflows(string $projectRoot, string $repository, array $selectors): array
    {
        [$host, $name] = explode('/', $repository, 2);
        $process = $this->process([
            'gh', 'api', "repos/{$name}/actions/workflows?per_page=100",
            '--hostname', $host, '--paginate', '--slurp',
        ], $projectRoot);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Could not load GitHub Actions workflows: '.trim($process->getErrorOutput()));
        }
        $catalog = $this->workflowCatalog($process->getOutput());
        $workflows = [];
        $ids = [];

        foreach ($selectors as $selector) {
            $matches = array_values(array_filter($catalog, static function (array $workflow) use ($selector): bool {
                if (ctype_digit($selector)) {
                    return (int) $selector === $workflow['id'];
                }

                if (preg_match('/\.ya?ml$/iD', $selector) === 1) {
                    return $selector === basename($workflow['path']);
                }

                return $workflow['state'] === 'active'
                    && mb_convert_case($selector, MB_CASE_FOLD, 'UTF-8') === mb_convert_case($workflow['name'], MB_CASE_FOLD, 'UTF-8');
            }));

            throw_unless(count($matches) === 1, RuntimeException::class, "Could not resolve a unique GitHub Actions workflow for {$selector}. Use its workflow filename.");
            $workflow = $matches[0];
            throw_if($workflow['state'] !== 'active', RuntimeException::class, "Required workflow {$selector} is disabled.");
            if (isset($ids[$workflow['id']])) {
                throw new RuntimeException("Duplicate release.workflow entry: {$selector} refers to the same workflow as {$ids[$workflow['id']]}.");
            }

            $ids[$workflow['id']] = $selector;
            $workflows[] = new GitHubReleaseWorkflow($selector, $workflow['id']);
        }

        return $workflows;
    }

    /** @param (Closure(GitHubWorkflowProgress): void)|null $onProgress */
    public function waitForSuccess(
        string $projectRoot,
        PreparedRelease $release,
        int $timeoutSeconds = 900,
        ?Closure $onProgress = null,
    ): void {
        $startedAt = $this->now();
        $deadline = $startedAt + $timeoutSeconds * self::NANOS_PER_SECOND;
        $watches = array_map(static fn (GitHubReleaseWorkflow $workflow): GitHubWorkflowWait => new GitHubWorkflowWait($workflow), $release->workflows);

        do {
            foreach ($watches as $watch) {
                if ($timeoutSeconds > 0 && $this->now() >= $deadline) {
                    break 2;
                }

                $this->poll($projectRoot, $release, $watch, $startedAt, $deadline, $onProgress);
            }

            if (array_all($watches, static fn (GitHubWorkflowWait $watch): bool => $watch->passed)
                && ($timeoutSeconds === 0 || $this->now() < $deadline)) {
                return;
            }

            $remaining = $deadline - $this->now();

            if ($remaining > 0) {
                Sleep::usleep((int) min(5_000_000, ceil($remaining / 1000)));
            }
        } while ($this->now() < $deadline);

        $pending = [];
        foreach ($watches as $watch) {
            if ($watch->passed) {
                continue;
            }

            $pending[] = $watch->workflow->selector.($watch->url === null
                ? ' (no matching push run was found)'
                : " (run still pending: {$watch->url})");
        }

        $detail = $pending === [] ? 'all required workflows' : implode(', ', $pending);

        throw new RuntimeException("Timed out waiting for {$detail} on {$release->commit}.");
    }

    /**
     * @param  (Closure(GitHubWorkflowProgress): void)|null  $onProgress
     */
    private function poll(
        string $projectRoot,
        PreparedRelease $release,
        GitHubWorkflowWait $watch,
        int|float $startedAt,
        int|float $deadline,
        ?Closure $onProgress,
    ): void {
        $workflow = $watch->workflow;
        $selector = $workflow->id === null ? $workflow->selector : (string) $workflow->id;
        $command = [
            'gh', 'run', 'list', '--repo', $release->repository,
            '--workflow', $selector, '--event', 'push',
            '--branch', $release->branch, '--commit', $release->commit,
            '--limit', '1', '--json', 'headSha,headBranch,event,status,conclusion,url,databaseId,attempt,workflowDatabaseId',
        ];
        $process = $this->process($command, $projectRoot);
        try {
            $process->setTimeout(min(30, max(1, ($deadline - $this->now()) / self::NANOS_PER_SECOND)));
            $process->run();
        } catch (ProcessException $exception) {
            throw new RuntimeException("Could not read workflow {$workflow->selector}: ".$exception->getMessage(), previous: $exception);
        }

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'GitHub CLI could not list the required workflow runs.';

            throw new RuntimeException("Could not read workflow {$workflow->selector}: {$message}");
        }

        $run = $this->runForRelease($process->getOutput(), $release, $workflow);
        $watch->url = $run['url'] ?? null;

        if ($onProgress !== null) {
            $this->reportProgress($projectRoot, $release, $watch, $run, $startedAt, $deadline, $onProgress);
        }

        throw_if(
            ($run['status'] ?? null) === 'completed' && $run['conclusion'] !== 'success',
            RuntimeException::class,
            "Required workflow {$workflow->selector} concluded ".($run['conclusion'] ?? 'without success')." for {$release->commit}. {$watch->url}",
        );
        $watch->passed = ($run['status'] ?? null) === 'completed';
    }

    /**
     * @param  array{status: string, conclusion: string, url: string, id: int|null, attempt: int|null}|null  $run
     * @param  Closure(GitHubWorkflowProgress): void  $onProgress
     */
    private function reportProgress(
        string $projectRoot,
        PreparedRelease $release,
        GitHubWorkflowWait $watch,
        ?array $run,
        int|float $startedAt,
        int|float $deadline,
        Closure $onProgress,
    ): void {
        $now = $this->now();
        $identity = [$watch->url, $run['id'] ?? null, $run['attempt'] ?? null];
        $identityChanged = $identity !== $watch->runIdentity;
        $status = $run['status'] ?? null;

        if ($identityChanged || $status !== 'in_progress') {
            $watch->runIdentity = $identity;
            $watch->activeJobs = [];
            $watch->nextJobRefresh = $now;
        }

        if ($status === 'in_progress' && $run['id'] !== null && $run['attempt'] !== null
            && $now >= $watch->nextJobRefresh && $deadline - $now >= self::NANOS_PER_SECOND) {
            $watch->activeJobs = $this->activeJobs(
                $projectRoot,
                $release,
                $run['id'],
                $run['attempt'],
                min(2.0, ($deadline - $now) / self::NANOS_PER_SECOND),
            );
            $watch->nextJobRefresh = $now + self::JOB_REFRESH_INTERVAL * self::NANOS_PER_SECOND;
            $now = $this->now();
        }

        $conclusion = $run['conclusion'] ?? null;
        $last = $watch->lastProgress;

        if ($last === null || $identityChanged || $status !== $last->status || $conclusion !== $last->conclusion
            || $watch->activeJobs !== $last->activeJobs
            || ($status !== 'completed' && $now - $watch->lastReportedAt >= self::PROGRESS_INTERVAL * self::NANOS_PER_SECOND)) {
            $progress = new GitHubWorkflowProgress(
                $watch->workflow->selector,
                $status,
                $conclusion,
                $watch->url,
                (int) (($now - $startedAt) / self::NANOS_PER_SECOND),
                $watch->activeJobs,
                $run['attempt'] ?? null,
            );
            $onProgress($progress);
            $watch->lastProgress = $progress;
            $watch->lastReportedAt = $now;
        }
    }

    /** @return array{status: string, conclusion: string, url: string, id: int|null, attempt: int|null}|null */
    private function runForRelease(string $output, PreparedRelease $release, GitHubReleaseWorkflow $workflow): ?array
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
                && ($workflow->id === null || ($run['workflowDatabaseId'] ?? null) === $workflow->id)
                && is_string($run['status'] ?? null)
                && is_string($run['conclusion'] ?? null)
                && is_string($run['url'] ?? null),
            RuntimeException::class,
            "GitHub CLI did not return a push workflow run for the prepared release commit and workflow {$workflow->selector}.",
        );

        return [
            'status' => $run['status'],
            'conclusion' => $run['conclusion'],
            'url' => $run['url'],
            'id' => $this->positiveInteger($run['databaseId'] ?? null),
            'attempt' => $this->positiveInteger($run['attempt'] ?? null),
        ];
    }

    /** @return list<array{id: positive-int, name: string, path: string, state: string}> */
    private function workflowCatalog(string $output): array
    {
        try {
            $pages = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub CLI returned invalid workflow catalog data.', previous: $exception);
        }

        throw_unless(is_array($pages) && array_is_list($pages), RuntimeException::class, 'GitHub CLI returned invalid workflow catalog data.');
        $catalog = [];

        foreach ($pages as $page) {
            $workflows = is_array($page) ? ($page['workflows'] ?? null) : null;
            throw_unless(is_array($workflows) && array_is_list($workflows), RuntimeException::class, 'GitHub CLI returned invalid workflow catalog data.');

            foreach ($workflows as $workflow) {
                throw_unless(
                    is_array($workflow)
                        && is_int($workflow['id'] ?? null) && $workflow['id'] > 0
                        && is_string($workflow['name'] ?? null)
                        && is_string($workflow['path'] ?? null)
                        && is_string($workflow['state'] ?? null),
                    RuntimeException::class,
                    'GitHub CLI returned invalid workflow catalog data.',
                );
                $catalog[] = [
                    'id' => $workflow['id'],
                    'name' => $workflow['name'],
                    'path' => $workflow['path'],
                    'state' => $workflow['state'],
                ];
            }
        }

        return $catalog;
    }

    /** @return list<string> */
    private function activeJobs(string $projectRoot, PreparedRelease $release, int $runId, int $attempt, float $timeout): array
    {
        try {
            $process = $this->process([
                'gh', 'run', 'view', (string) $runId, '--attempt', (string) $attempt,
                '--repo', $release->repository, '--json', 'databaseId,attempt,headSha,jobs',
            ], $projectRoot);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            $details = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (ProcessException|JsonException) {
            return [];
        }

        if (! is_array($details)
            || ($details['databaseId'] ?? null) !== $runId
            || ($details['attempt'] ?? null) !== $attempt
            || ($details['headSha'] ?? null) !== $release->commit
            || ! is_array($details['jobs'] ?? null)) {
            return [];
        }

        $active = [];

        foreach ($details['jobs'] as $job) {
            if (! is_array($job) || ($job['status'] ?? null) !== 'in_progress' || ! is_string($job['name'] ?? null)) {
                continue;
            }

            $label = $job['name'];
            $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];

            foreach ($steps as $step) {
                if (is_array($step) && ($step['status'] ?? null) === 'in_progress' && is_string($step['name'] ?? null)) {
                    $label .= ' / '.$step['name'];

                    break;
                }
            }

            $active[] = $label;
        }

        return $active;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @param list<string> $command */
    private function process(array $command, string $projectRoot): Process
    {
        return $this->processFactory === null
            ? new Process($command, $projectRoot)
            : ($this->processFactory)($command, $projectRoot);
    }

    private function now(): int|float
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }
}
