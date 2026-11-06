<?php

namespace App\Support\Deployer;

use RuntimeException;

final readonly class DeployerTask
{
    /**
     * @param  array<string, array{accept_value: bool, is_value_required: bool, is_multiple: bool}>  $options
     */
    public function __construct(
        public string $name,
        public string $description,
        private array $options,
    ) {}

    /**
     * Split custom task options from selectors without allowing global option overrides.
     *
     * @param  list<string>  $tokens
     * @return array{selectors: list<string>, options: list<string>}
     */
    public function arguments(array $tokens): array
    {
        $selectors = [];
        $options = [];
        $seen = [];
        $reserved = ['file', 'tag', 'revision', 'branch', 'option', 'limit', 'start-from', 'log', 'profile', 'no-hooks', 'plan', 'help', 'silent', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'];

        foreach ($tokens as $token) {
            if (! str_starts_with($token, '--')) {
                throw_if(str_starts_with($token, '-'), RuntimeException::class, 'Task options must use their full name after --.');
                $selectors[] = $token;

                continue;
            }

            [$name] = explode('=', substr($token, 2), 2);
            $definition = $this->options[$name] ?? null;

            throw_if(in_array($name, $reserved, true) || $definition === null, RuntimeException::class, "The task option [--{$name}] is not a custom option declared by this recipe.");

            $hasValue = str_contains($token, '=');
            throw_if($definition['is_value_required'] && ! $hasValue, RuntimeException::class, "The task option [--{$name}] requires the --{$name}=value form.");
            throw_if(! $definition['accept_value'] && $hasValue, RuntimeException::class, "The task option [--{$name}] does not accept a value.");
            throw_if(isset($seen[$name]) && ! $definition['is_multiple'], RuntimeException::class, "The task option [--{$name}] cannot be repeated.");

            $seen[$name] = true;
            $options[] = $token;
        }

        return ['selectors' => $selectors, 'options' => $options];
    }
}
