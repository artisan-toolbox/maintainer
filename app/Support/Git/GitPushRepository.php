<?php

namespace App\Support\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitPushRepository
{
    /** @return list<PendingGitPush> */
    public function pending(string $projectRoot, ?string $branch = null): array
    {
        $head = trim($this->run(['git', 'symbolic-ref', '--quiet', 'HEAD'], $projectRoot));
        throw_if($head === '', RuntimeException::class, 'Check out a local branch before pushing commits.');

        $fetchUrls = $this->lines($this->run(['git', 'remote', 'get-url', '--all', 'origin'], $projectRoot));
        $pushUrls = $this->lines($this->run(['git', 'remote', 'get-url', '--push', '--all', 'origin'], $projectRoot));
        throw_if(count($fetchUrls) !== 1 || $fetchUrls !== $pushUrls, RuntimeException::class,
            'Origin must have one matching fetch and push URL so the reviewed destination is the pushed destination.');
        $remoteUrl = $fetchUrls[0];

        $remoteRefs = [];
        foreach ($this->lines($this->run(['git', 'ls-remote', '--upload-pack=git-upload-pack', '--heads', '--', 'origin'], $projectRoot)) as $line) {
            [$sha, $ref] = explode("\t", $line, 2);
            $remoteRefs[$ref] = $sha;
        }

        $branches = $this->lines($this->run([
            'git', 'for-each-ref', '--format=%(refname)%00%(upstream:remotename)%00%(upstream:remoteref)%00%(objectname)', 'refs/heads/',
        ], $projectRoot));
        $pushes = [];
        $found = false;

        foreach ($branches as $record) {
            [$ref, $upstreamRemote, $upstreamRef, $localTip] = explode("\0", $record);
            $name = substr($ref, strlen('refs/heads/'));

            if ($branch !== null && $name !== $branch) {
                continue;
            }

            $found = true;
            $targetRef = $upstreamRemote === 'origin' && str_starts_with($upstreamRef, 'refs/heads/')
                ? $upstreamRef : $ref;
            $remoteTip = $remoteRefs[$targetRef] ?? null;
            $problem = $upstreamRemote !== '' && $upstreamRemote !== 'origin'
                ? 'This branch tracks another remote. Configure an origin upstream before pushing it with Maintainer.' : null;

            if ($upstreamRemote === 'origin' && ! str_starts_with($upstreamRef, 'refs/heads/')) {
                $problem = 'This branch does not track an origin branch. Configure a branch upstream before pushing it with Maintainer.';
            }

            if ($remoteTip !== null) {
                $this->run([
                    'git', 'fetch', '--upload-pack=git-upload-pack', '--refmap=', '--no-prune', '--no-tags', '--no-write-fetch-head', '--no-recurse-submodules', '--', 'origin', $remoteTip,
                ], $projectRoot);
                $counts = explode("\t", trim($this->run([
                    'git', 'rev-list', '--left-right', '--count', "{$remoteTip}...{$localTip}", '--',
                ], $projectRoot)));

                if ((int) $counts[0] > 0) {
                    $problem = 'The remote branch has commits missing locally. Integrate its changes before pushing.';
                }
            }

            $commits = $this->lines($this->run([
                'git', 'log', '--reverse', '--format=%h %s', $remoteTip === null ? $localTip : "{$remoteTip}..{$localTip}", '--',
            ], $projectRoot));
            $commits = array_map(static fn (string $summary): string => preg_replace('/[\x00-\x1F\x7F]/', ' ', $summary) ?? '', $commits);

            if ($commits !== [] || $branch !== null) {
                $pushes[] = new PendingGitPush($name, $targetRef, $localTip, $remoteTip, $remoteUrl, $commits, $problem);
            }
        }

        throw_if($branch !== null && ! $found, RuntimeException::class, 'The requested local branch does not exist.');

        return $pushes;
    }

    public function push(string $projectRoot, PendingGitPush $reviewed): void
    {
        $current = $this->pending($projectRoot, $reviewed->branch)[0];
        throw_if($current != $reviewed, RuntimeException::class, 'The local branch, remote branch, or destination changed after review. Run push again to review the updated commits.');
        throw_if($current->problem !== null, RuntimeException::class, $current->problem ?? 'The branch cannot be pushed.');
        throw_if($current->commits === [], RuntimeException::class, 'There are no pending commits on this branch.');

        $this->run([
            'git', '-c', 'push.followTags=false', '-c', 'remote.origin.mirror=false', 'push', '--receive-pack=git-receive-pack', '--no-force', '--no-follow-tags', '--recurse-submodules=no',
            '--force-with-lease='.$current->targetRef.':'.($current->remoteTip ?? ''),
            '--', 'origin', "{$current->localTip}:{$current->targetRef}",
        ], $projectRoot);
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        return $output === '' ? [] : explode("\n", rtrim($output, "\r\n"));
    }

    /** @param list<string> $command */
    private function run(array $command, string $projectRoot): string
    {
        array_splice($command, 1, 0, ['--no-replace-objects', '-c', 'advice.graftFileDeprecated=false']);
        $process = new Process($command, $projectRoot, [
            'GIT_GRAFT_FILE' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($message !== '' ? $message : 'Git could not inspect the repository. Check out a local branch and configure origin.');
        }

        return $process->getOutput();
    }
}
