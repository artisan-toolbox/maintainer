<?php

namespace App\Support\Deployer;

use RuntimeException;

final class DeployerArguments
{
    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $selectors
     * @param  list<string>  $taskOptions
     * @return list<string>
     */
    public static function build(string $task, array $options, array $selectors, bool $interactive, array $taskOptions = []): array
    {
        $arguments = [];
        $file = $options['file'] ?? null;

        if (is_string($file) && $file !== '') {
            $arguments[] = "--file={$file}";
        }

        $arguments[] = $task;

        foreach (['tag', 'revision', 'branch', 'limit', 'start-from', 'log', 'profile'] as $name) {
            $value = $options[$name] ?? null;

            if (is_string($value) && $value !== '') {
                $arguments[] = "--{$name}={$value}";
            }
        }

        $overrides = $options['option'] ?? [];
        throw_unless(is_array($overrides), RuntimeException::class, 'A Deployer configuration override is invalid.');

        foreach ($overrides as $override) {
            throw_unless(is_string($override) && $override !== '', RuntimeException::class, 'A Deployer configuration override is invalid.');

            $arguments[] = "--option={$override}";
        }

        foreach (['no-hooks', 'plan'] as $name) {
            if (($options[$name] ?? false) === true) {
                $arguments[] = "--{$name}";
            }
        }

        if (! $interactive) {
            $arguments[] = '--no-interaction';
        }

        array_push($arguments, ...$taskOptions);

        foreach ($selectors as $selector) {
            throw_if($selector === '' || str_starts_with($selector, '-') || str_contains($selector, "\0"), RuntimeException::class, 'A Deployer host selector must not be empty or start with a dash.');
        }

        if ($selectors !== []) {
            $arguments[] = '--';
            array_push($arguments, ...$selectors);
        }

        return $arguments;
    }
}
