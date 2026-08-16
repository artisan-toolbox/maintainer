<?php

namespace App\Support\Release;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\pause;

class ReleaseDiffReviewer
{
    public function shouldReview(): bool
    {
        return confirm(
            'Would you like to review the proposed release diff in your browser before continuing?',
            true,
        );
    }

    public function waitForReturn(): void
    {
        pause('Return to this terminal and press enter to continue the release...');
    }
}
