<?php

namespace Tests\Fakes;

use App\Support\Release\ReleaseVersionSelector;

final class FakeReleaseVersionSelector extends ReleaseVersionSelector
{
    public ?string $selected = null;

    /**
     * @param  array<string, string>  $options
     */
    public function select(array $options, string $default): string
    {
        return $this->selected ?? $default;
    }
}
