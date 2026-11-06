<?php

use App\Support\Git\GitPushRepository;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\GitPushFixture;

beforeEach(function () {
    $this->fixture = new GitPushFixture;
    $this->repository = new GitPushRepository;
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('reviews actual origin state and pushes exactly the selected branch while preserving local changes', function () {
    $fixture = $this->fixture;
    $mainTip = $fixture->commit('First pending commit');
    $fixture->git(['checkout', '-b', 'feature']);
    $featureTip = $fixture->commit('Second pending commit');
    $fixture->git(['checkout', 'main']);
    file_put_contents($fixture->local.'/staged.txt', "staged\n");
    $fixture->git(['add', 'staged.txt']);
    file_put_contents($fixture->local.'/example.txt', "unstaged\n", FILE_APPEND);
    file_put_contents($fixture->local.'/untracked.txt', "untracked\n");
    $status = $fixture->git(['status', '--porcelain=v1']);
    $index = file_get_contents($fixture->local.'/.git/index');
    $fixture->git(['config', 'push.default', 'matching']);
    $fixture->git(['config', 'push.followTags', 'true']);
    $fixture->git(['tag', '-a', 'v1.0.0', '-m', 'Do not push this tag']);
    $fixture->git(['config', 'remote.origin.push', 'refs/heads/feature:refs/heads/unwanted']);
    $fixture->git(['config', 'remote.origin.mirror', 'true']);

    $pushes = $this->repository->pending($fixture->local);
    $main = collect($pushes)->firstWhere('branch', 'main');

    expect($main->commits)->toHaveCount(1)
        ->and($main->commits[0])->toContain('First pending commit')
        ->and($main->localTip)->toBe($mainTip)
        ->and($main->targetRef)->toBe('refs/heads/main');

    $this->repository->push($fixture->local, $main);

    expect($fixture->git(['rev-parse', 'refs/heads/main'], $fixture->remote))->toBe($mainTip)
        ->and($fixture->git(['show-ref'], $fixture->remote))->not->toContain('refs/heads/feature', 'refs/heads/unwanted', 'refs/tags/')
        ->and($fixture->git(['rev-parse', 'feature']))->toBe($featureTip)
        ->and($fixture->git(['status', '--porcelain=v1']))->toBe($status)
        ->and(file_get_contents($fixture->local.'/.git/index'))->toBe($index)
        ->and($this->repository->pending($fixture->local, 'main')[0]->commits)->toBe([]);
});

it('creates an origin branch without changing the checked out branch or configuring upstreams', function () {
    $fixture = $this->fixture;
    $fixture->git(['branch', 'feature/nested']);
    $current = $fixture->git(['symbolic-ref', 'HEAD']);
    $push = $this->repository->pending($fixture->local, 'feature/nested')[0];

    expect($push->remoteTip)->toBeNull()
        ->and($push->commits)->toHaveCount(1);

    $this->repository->push($fixture->local, $push);

    expect($fixture->git(['rev-parse', 'refs/heads/feature/nested'], $fixture->remote))->toBe($push->localTip)
        ->and($fixture->git(['symbolic-ref', 'HEAD']))->toBe($current)
        ->and($fixture->git(['for-each-ref', '--format=%(upstream)', 'refs/heads/feature/nested']))->toBe('');
});

it('uses the configured origin upstream name rather than assuming branch names match', function () {
    $fixture = $this->fixture;
    $fixture->git(['branch', '-m', 'local-main']);
    $fixture->commit('Pending on renamed local branch');
    $push = $this->repository->pending($fixture->local, 'local-main')[0];

    expect($push->targetRef)->toBe('refs/heads/main');
    $this->repository->push($fixture->local, $push);
    expect($fixture->git(['rev-parse', 'refs/heads/main'], $fixture->remote))->toBe($push->localTip)
        ->and($fixture->git(['show-ref'], $fixture->remote))->not->toContain('refs/heads/local-main');
});

it('resolves origin URL rewrites once without sending commits to a second rewritten destination', function () {
    $fixture = $this->fixture;
    $unexpected = $fixture->directory.'/unexpected.git';
    mkdir($unexpected);
    $fixture->git(['init', '--bare', '--initial-branch=main'], $unexpected);
    $fixture->git(['push', $unexpected, 'main']);
    $original = $fixture->git(['rev-parse', 'main'], $unexpected);
    $tip = $fixture->commit('Reviewed destination only');
    $fixture->git(['remote', 'set-url', 'origin', 'maintainer-test-alias:']);
    $fixture->git(['config', 'url.'.$fixture->remote.'.insteadOf', 'maintainer-test-alias:']);
    $fixture->git(['config', 'url.'.$unexpected.'.pushInsteadOf', $fixture->remote]);

    $push = $this->repository->pending($fixture->local, 'main')[0];
    expect($push->remoteUrl)->toBe($fixture->remote);
    $this->repository->push($fixture->local, $push);

    expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($tip)
        ->and($fixture->git(['rev-parse', 'main'], $unexpected))->toBe($original);
});

it('rejects a remote rewind that happens after review and before the push connection', function () {
    $fixture = $this->fixture;
    $original = $fixture->git(['rev-parse', 'main']);
    $fixture->commit('Already published commit');
    $fixture->git(['push', 'origin', 'main']);
    $fixture->commit('Reviewed outgoing commit');
    $push = $this->repository->pending($fixture->local, 'main')[0];
    $realGit = (new ExecutableFinder)->find('git');
    expect($realGit)->not->toBeNull();
    $wrapperDirectory = $fixture->directory.'/wrapper';
    mkdir($wrapperDirectory);
    $wrapper = <<<'PHP'
    <?php
    require AUTOLOAD_PATH;
    $arguments = array_slice($argv, 1);
    if (in_array('push', $arguments, true)) {
        (new Symfony\Component\Process\Process([REAL_GIT, '--git-dir='.REMOTE_PATH, 'update-ref', 'refs/heads/main', ORIGINAL_TIP]))->mustRun();
    }
    exit((new Symfony\Component\Process\Process([REAL_GIT, ...$arguments]))->run(static function (string $type, string $buffer): void {
        fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
    }));
    PHP;
    $wrapper = strtr($wrapper, [
        'AUTOLOAD_PATH' => var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
        'REAL_GIT' => var_export($realGit, true),
        'REMOTE_PATH' => var_export($fixture->remote, true),
        'ORIGINAL_TIP' => var_export($original, true),
    ]);
    file_put_contents($wrapperDirectory.'/git.php', $wrapper);

    if (PHP_OS_FAMILY === 'Windows') {
        file_put_contents($wrapperDirectory.'/git.bat', '@"'.PHP_BINARY.'" "'.$wrapperDirectory.'/git.php" %*'."\r\n");
    } else {
        file_put_contents($wrapperDirectory.'/git', "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($wrapperDirectory.'/git.php').' "$@"'."\n");
        chmod($wrapperDirectory.'/git', 0755);
    }

    $originalPath = getenv('PATH');
    putenv('PATH='.$wrapperDirectory.PATH_SEPARATOR.$originalPath);

    try {
        expect(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class);
    } finally {
        $originalPath === false ? putenv('PATH') : putenv('PATH='.$originalPath);
    }

    expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($original);
});

it('detects divergence from actual remote state even when tracking refs are stale', function () {
    $fixture = $this->fixture;
    $fixture->git(['checkout', '-b', 'other']);
    $remoteTip = $fixture->commit('Remote change');
    $fixture->git(['push', $fixture->remote, 'HEAD:refs/heads/main']);
    $fixture->git(['checkout', 'main']);
    $fixture->commit('Local change');
    $push = $this->repository->pending($fixture->local, 'main')[0];

    expect($push->remoteTip)->toBe($remoteTip)
        ->and($push->commits)->toHaveCount(1)
        ->and($push->problem)->toContain('missing locally')
        ->and(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class, 'missing locally')
        ->and($fixture->git(['rev-parse', 'refs/heads/main'], $fixture->remote))->toBe($remoteTip);
});

it('rejects local commits added after review', function () {
    $fixture = $this->fixture;
    $original = $fixture->git(['rev-parse', 'main'], $fixture->remote);
    $fixture->commit('Reviewed commit');
    $push = $this->repository->pending($fixture->local, 'main')[0];
    $fixture->commit('Unreviewed commit');

    expect(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class, 'changed after review')
        ->and($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($original);
});

it('rejects remote changes after review', function () {
    $fixture = $this->fixture;
    $fixture->commit('Reviewed commit');
    $push = $this->repository->pending($fixture->local, 'main')[0];
    $fixture->git(['push', $fixture->remote, 'HEAD:refs/heads/main']);

    expect(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class, 'changed after review');
});

it('fetches remote objects without running configured fetch refspecs or updating tracking branches', function () {
    $fixture = $this->fixture;
    $trackingTip = $fixture->git(['rev-parse', 'origin/main']);
    $other = $fixture->directory.'/other';
    $fixture->git(['clone', $fixture->remote, $other]);
    $fixture->git(['config', 'user.name', 'Other Contributor'], $other);
    $fixture->git(['config', 'user.email', 'other@example.com'], $other);
    file_put_contents($other.'/remote-only.txt', "remote only\n");
    $fixture->git(['add', '.'], $other);
    $fixture->git(['commit', '-m', 'Remote object unavailable locally'], $other);
    $remoteTip = $fixture->git(['rev-parse', 'HEAD'], $other);
    $fixture->git(['push', 'origin', 'main'], $other);
    $fixture->commit('Local pending change');
    $fixture->git(['config', 'remote.origin.fetch', '+refs/heads/*:refs/heads/*']);
    $localTip = $fixture->git(['rev-parse', 'main']);

    $push = $this->repository->pending($fixture->local, 'main')[0];

    expect($push->remoteTip)->toBe($remoteTip)
        ->and($push->problem)->toContain('missing locally')
        ->and($fixture->git(['rev-parse', 'main']))->toBe($localTip)
        ->and($fixture->git(['rev-parse', 'refs/remotes/origin/main']))->toBe($trackingTip);
});

it('reviews the actual commit graph when local replacement objects exist', function () {
    $fixture = $this->fixture;
    $original = $fixture->git(['rev-parse', 'HEAD']);
    $tip = $fixture->commit('Actual pending commit');
    $fixture->git(['replace', $tip, $original]);
    $push = $this->repository->pending($fixture->local, 'main')[0];

    expect($push->commits)->toHaveCount(1)
        ->and($push->commits[0])->toContain('Actual pending commit');

    $this->repository->push($fixture->local, $push);

    expect($fixture->git(['log', '-1', '--format=%s'], $fixture->remote))->toBe('Actual pending commit');
});

it('rejects unrelated history even when local legacy grafts claim a remote ancestor', function () {
    $fixture = $this->fixture;
    $original = $fixture->git(['rev-parse', 'main'], $fixture->remote);
    $fixture->git(['checkout', '--orphan', 'unrelated']);
    $tip = $fixture->commit('Unrelated root');
    $fixture->git(['branch', '--set-upstream-to=origin/main', 'unrelated']);
    file_put_contents($fixture->local.'/.git/info/grafts', "{$tip} {$original}\n");

    $push = $this->repository->pending($fixture->local, 'unrelated')[0];
    expect($push->problem)->not->toBeNull();
    expect(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class);
    expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($original);
});

it('passes a valid branch containing shell punctuation as a literal ref', function () {
    $fixture = $this->fixture;
    $fixture->git(['branch', 'feature;touch-injected']);
    $push = $this->repository->pending($fixture->local, 'feature;touch-injected')[0];

    $this->repository->push($fixture->local, $push);

    expect($fixture->git(['rev-parse', 'refs/heads/feature;touch-injected'], $fixture->remote))->toBe($push->localTip)
        ->and(file_exists($fixture->local.'/touch-injected'))->toBeFalse();
});

it('rejects a different or multiple push destination', function () {
    $fixture = $this->fixture;
    $fixture->git(['remote', 'set-url', '--push', 'origin', $fixture->directory.'/unexpected.git']);

    expect(fn () => $this->repository->pending($fixture->local))->toThrow(RuntimeException::class, 'matching fetch and push URL');
});

it('rejects branches tracking a different remote', function () {
    $fixture = $this->fixture;
    $fixture->git(['remote', 'add', 'other', $fixture->remote]);
    $fixture->git(['config', 'branch.main.remote', 'other']);
    $fixture->commit('Pending commit');
    $push = $this->repository->pending($fixture->local, 'main')[0];

    expect($push->problem)->toContain('another remote')
        ->and(fn () => $this->repository->push($fixture->local, $push))->toThrow(RuntimeException::class, 'another remote');
});

it('rejects detached HEAD and a missing origin without pushing', function () {
    $fixture = $this->fixture;
    $fixture->git(['checkout', '--detach']);

    expect(fn () => $this->repository->pending($fixture->local))->toThrow(RuntimeException::class, 'Check out a local branch');

    $fixture->git(['checkout', 'main']);
    $fixture->git(['remote', 'remove', 'origin']);

    expect(fn () => $this->repository->pending($fixture->local))->toThrow(RuntimeException::class, 'origin');
});

it('treats branch arguments as exact names and never as revisions or command options', function (string $branch) {
    expect(fn () => $this->repository->pending($this->fixture->local, $branch))
        ->toThrow(RuntimeException::class, 'local branch does not exist');
})->with(['--all', 'main:refs/heads/injected', 'main; touch injected', '../main', 'HEAD']);
