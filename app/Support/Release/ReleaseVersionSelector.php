<?php

namespace App\Support\Release;

use function Laravel\Prompts\select;

class ReleaseVersionSelector
{
    /**
     * @param  array<string, string>  $options
     */
    public function select(array $options, string $default): string
    {
        return (string) select(
            label: 'Which version should be released?',
            options: $options,
            default: $default,
        );
    }
}
