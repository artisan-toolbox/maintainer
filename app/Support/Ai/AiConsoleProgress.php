<?php

namespace App\Support\Ai;

use Closure;
use Illuminate\Console\View\Components\Factory;
use Laravel\Ai\Events\PromptingAgent;

final class AiConsoleProgress
{
    /** @var (Closure(string): void)|null */
    private ?Closure $report = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Factory $components, string $activity, Closure $operation): mixed
    {
        $previous = $this->report;
        $this->report = static function (string $target) use ($components, $activity): void {
            $components->twoColumnDetail($activity, $target);
        };

        try {
            return $operation();
        } finally {
            $this->report = $previous;
        }
    }

    public function handle(PromptingAgent $event): void
    {
        if ($this->report === null) {
            return;
        }

        $provider = $event->prompt->provider->name();
        $target = preg_replace('/[[:cntrl:]]/', '', "{$provider} / {$event->prompt->model}");

        ($this->report)($target ?? '');
    }
}
