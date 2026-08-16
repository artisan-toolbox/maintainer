<?php

namespace Tests\Fakes;

use App\Support\Release\ReleaseDiffReviewer;

final class FakeReleaseDiffReviewer extends ReleaseDiffReviewer
{
    public bool $review = false;

    public bool $waited = false;

    public function shouldReview(): bool
    {
        return $this->review;
    }

    public function waitForReturn(): void
    {
        $this->waited = true;
    }
}
