<?php

namespace App\Support\Quality;

use Closure;
use Symfony\Component\Process\Process;

final readonly class QualityCommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  Closure(string): void  $output
     */
    public function run(array $command, string $projectRoot, Closure $output): int
    {
        $process = new Process($command, $projectRoot);
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }
}
