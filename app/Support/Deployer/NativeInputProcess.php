<?php

namespace App\Support\Deployer;

use Closure;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class NativeInputProcess
{
    /**
     * Inherit input directly so Windows never reads it in the output polling loop.
     *
     * @param  list<string>  $command
     * @param  array<string, string|false>  $environment
     * @param  resource  $input
     * @param  Closure(string, string): void  $output
     */
    public function run(array $command, string $directory, array $environment, mixed $input, Closure $output): int
    {
        $process = null;
        $handles = [];

        try {
            $descriptors = [$input];
            $readers = [];
            $offsets = [];

            foreach ([Process::OUT, Process::ERR] as $type) {
                $writer = tmpfile();

                throw_if($writer === false, RuntimeException::class, 'A temporary file could not be opened for Deployer output.');

                $handles[] = $writer;
                $path = stream_get_meta_data($writer)['uri'] ?? throw new RuntimeException('The Deployer output file path is unavailable.');
                $reader = fopen($path, 'rb');

                throw_if($reader === false, RuntimeException::class, 'A temporary file could not be read for Deployer output.');

                $handles[] = $reader;
                $descriptors[] = $writer;
                $readers[$type] = $reader;
                $offsets[$type] = 0;
            }

            $process = @proc_open($command, $descriptors, $pipes, $directory, $this->environment($environment), [
                'bypass_shell' => true,
                'suppress_errors' => true,
            ]);

            throw_if($process === false, RuntimeException::class, 'The Deployer process could not be started.');

            do {
                $status = proc_get_status($process);

                foreach ($readers as $type => $reader) {
                    $buffer = stream_get_contents($reader, -1, $offsets[$type]);

                    throw_if($buffer === false, RuntimeException::class, 'The Deployer process output could not be read.');

                    if ($buffer !== '') {
                        $offsets[$type] += strlen($buffer);
                        $output($type, $buffer);
                    }
                }

                if (! $status['running']) {
                    return $status['exitcode'];
                }

                Sleep::usleep(10000);
            } while (true);
        } finally {
            try {
                if (is_resource($process)) {
                    try {
                        $status = proc_get_status($process);

                        if ($status['running']) {
                            try {
                                if (PHP_OS_FAMILY === 'Windows') {
                                    new Process(['taskkill', '/F', '/T', '/PID', (string) $status['pid']], timeout: 5)->run();
                                }
                            } catch (Throwable) {
                                // Preserve the output exception if process-tree cleanup fails.
                            } finally {
                                proc_terminate($process, 9);
                            }
                        }
                    } finally {
                        proc_close($process);
                    }
                }
            } finally {
                foreach (array_reverse($handles) as $handle) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * @param  array<string, string|false>  $overrides
     * @return array<string, string>
     */
    private function environment(array $overrides): array
    {
        $environment = getenv();
        $environment = (PHP_OS_FAMILY === 'Windows'
            ? array_intersect_ukey($environment, $_SERVER, 'strcasecmp')
            : array_intersect_key($environment, $_SERVER)) ?: $environment;
        $environment = $_ENV + (PHP_OS_FAMILY === 'Windows'
            ? array_diff_ukey($environment, $_ENV, 'strcasecmp')
            : $environment);
        $environment = $overrides + (PHP_OS_FAMILY === 'Windows'
            ? array_diff_ukey($environment, $overrides, 'strcasecmp')
            : $environment);
        $result = [];

        if (isset($environment['SYMFONY_DOTENV_VARS'])) {
            $variables = explode(',', (string) $environment['SYMFONY_DOTENV_VARS']);
            $variables = PHP_OS_FAMILY === 'Windows'
                ? array_udiff($variables, array_keys($overrides), 'strcasecmp')
                : array_diff($variables, array_keys($overrides));
            $environment['SYMFONY_DOTENV_VARS'] = implode(',', $variables);
        }

        foreach ($environment as $name => $value) {
            if (is_string($name) && $name !== '' && ! str_contains($name, '=') && ! str_contains($name, "\0") && is_scalar($value) && $value !== false) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }
}
