<?php

namespace App\Support\Quality;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

use function Illuminate\Filesystem\join_paths;

final readonly class QualityToolRunner
{
    /**
     * Run a quality tool installed by the consuming project.
     *
     * @param  Closure(string): void  $output
     * @param  list<string>  $additionalArguments
     */
    public function run(
        QualityTool $tool,
        string $projectRoot,
        string $configurationPath,
        Closure $output,
        array $additionalArguments = [],
    ): int {
        $binary = join_paths($projectRoot, 'vendor', 'bin', $tool->binaryFilename());

        if (PHP_OS_FAMILY === 'Windows' && is_file($binary.'.bat')) {
            $binary .= '.bat';
        }

        throw_unless(
            is_file($binary),
            RuntimeException::class,
            "{$tool->label()} is not installed in the project. Install its Composer development dependency first.",
        );

        $process = new Process(
            $tool->command($binary, $configurationPath, $additionalArguments),
            $projectRoot,
        );
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }
}
