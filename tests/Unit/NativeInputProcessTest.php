<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * @return array{Process, InputStream}
 */
function nativeInputProcessFixture(string $directory, Filesystem $files, string $script): array
{
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    $files->put($directory.'/runner.php', "<?php\nrequire {$autoload};\n".$script);
    $input = new InputStream;

    return [new Process([PHP_BINARY, $directory.'/runner.php'], $directory, input: $input, timeout: 5), $input];
}

it('collects both output streams and the exit code while inherited input remains open', function (int $exitCode) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($exitCode) {
        $files->put($directory.'/child.php', '<?php fwrite(STDOUT, "ready"); fwrite(STDERR, "diagnostic"); exit('.$exitCode.');');
        [$process, $input] = nativeInputProcessFixture($directory, $files, <<<'PHP'
$output = [];
$blocked = stream_get_meta_data(STDIN)['blocked'];
$exitCode = (new \App\Support\Deployer\NativeInputProcess)->run(
    [PHP_BINARY, getcwd().'/child.php'], getcwd(), [], STDIN,
    static function (string $type, string $buffer) use (&$output): void {
        $output[$type] = ($output[$type] ?? '').$buffer;
    },
);
echo json_encode(['exitCode' => $exitCode, 'output' => $output, 'blocked' => stream_get_meta_data(STDIN)['blocked'] === $blocked]);
PHP);

        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            expect($result)->toBe([
                'exitCode' => $exitCode,
                'output' => ['out' => 'ready', 'err' => 'diagnostic'],
                'blocked' => true,
            ]);
        } finally {
            $input->close();
            $process->stop(0);
        }
    });
})->with([0, 42]);

it('delivers input written after the child has started without delaying its output', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/child.php', <<<'PHP'
<?php
echo "Ready for input.\n";
echo fgets(STDIN);
PHP);
        [$process, $input] = nativeInputProcessFixture($directory, $files, <<<'PHP'
$exitCode = (new \App\Support\Deployer\NativeInputProcess)->run(
    [PHP_BINARY, getcwd().'/child.php'], getcwd(), [], STDIN,
    static function (string $type, string $buffer): void { echo $buffer; },
);
exit($exitCode);
PHP);

        try {
            $process->start();
            expect($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'Ready for input.')))->toBeTrue();
            $input->write("Delayed input arrived.\n");

            expect($process->wait())->toBe(0)
                ->and($process->getOutput())->toBe("Ready for input.\nDelayed input arrived.\n");
        } finally {
            $input->close();
            $process->stop(0);
        }
    });
});

it('terminates the child when output handling fails and preserves the callback exception', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/child.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/child.pid', (string) getmypid());
echo 'Ready.';
sleep(30);
PHP);
        [$process, $input] = nativeInputProcessFixture($directory, $files, <<<'PHP'
$callbackCalls = 0;
try {
    (new \App\Support\Deployer\NativeInputProcess)->run(
        [PHP_BINARY, getcwd().'/child.php'], getcwd(), [], STDIN,
        static function (string $type, string $buffer) use (&$callbackCalls): void {
            $callbackCalls++;
            throw new \RuntimeException('Output handling failed.');
        },
    );
} catch (\RuntimeException $exception) {
    echo json_encode(['message' => $exception->getMessage(), 'callbackCalls' => $callbackCalls]);
}
PHP);

        try {
            $process->mustRun();

            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'message' => 'Output handling failed.',
                'callbackCalls' => 1,
            ]);

            $pid = trim($files->get($directory.'/child.pid'));
            $status = PHP_OS_FAMILY === 'Windows'
                ? new Process(['tasklist', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH'])
                : new Process(['ps', '-p', $pid, '-o', 'pid=']);
            $status->run();

            if (PHP_OS_FAMILY === 'Windows') {
                expect($status->getOutput())->not->toContain('","'.$pid.'","');
            } else {
                expect(trim($status->getOutput()))->toBe('');
            }
        } finally {
            $input->close();
            $process->stop(0);
        }
    });
});

it('inherits the process environment and applies additions and removals', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        [$process, $input] = nativeInputProcessFixture($directory, $files, <<<'PHP'
putenv('MAINTAINER_NATIVE_PROCESS_REMOVED=original');
putenv('MAINTAINER_NATIVE_PROCESS_INHERITED=inherited');
putenv('MAINTAINER_NATIVE_PROCESS_ENV=original');
$_ENV['MAINTAINER_NATIVE_PROCESS_ENV'] = 'from-env';
$_ENV['MAINTAINER_NATIVE_PROCESS_CASE'] = 'original';
$output = '';
$overrideName = PHP_OS_FAMILY === 'Windows' ? 'maintainer_native_process_case' : 'MAINTAINER_NATIVE_PROCESS_CASE';
$script = <<<'CHILD'
echo json_encode([
    getenv('MAINTAINER_NATIVE_PROCESS_REMOVED'),
    getenv('MAINTAINER_NATIVE_PROCESS_ADDED'),
    getenv('MAINTAINER_NATIVE_PROCESS_INHERITED'),
    getenv('MAINTAINER_NATIVE_PROCESS_ENV'),
    getenv('MAINTAINER_NATIVE_PROCESS_CASE'),
]);
CHILD;
$exitCode = (new \App\Support\Deployer\NativeInputProcess)->run(
    [PHP_BINARY, '-r', $script], getcwd(), [
        'MAINTAINER_NATIVE_PROCESS_REMOVED' => false,
        'MAINTAINER_NATIVE_PROCESS_ADDED' => 'added',
        $overrideName => 'case-override',
    ], STDIN,
    static function (string $type, string $buffer) use (&$output): void { $output .= $buffer; },
);
echo $output;
exit($exitCode);
PHP);

        try {
            $process->mustRun();

            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                false, 'added', 'inherited', 'from-env', 'case-override',
            ]);
        } finally {
            $input->close();
            $process->stop(0);
        }
    });
});
