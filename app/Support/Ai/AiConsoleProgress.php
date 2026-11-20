<?php

namespace App\Support\Ai;

use Closure;
use Illuminate\Console\View\Components\Factory;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Prompts\spin;

final class AiConsoleProgress
{
    /** @var (Closure(string): void)|null */
    private ?Closure $report = null;

    /** @var (Closure(string, int, int, Closure(): AgentResponse): AgentResponse)|null */
    private ?Closure $reportFragment = null;

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

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function runFragments(Factory $components, string $activity, bool $animate, Closure $operation): mixed
    {
        $previous = $this->reportFragment;
        $canAnimate = $animate && function_exists('pcntl_fork') && function_exists('posix_kill');
        $this->reportFragment = static function (string $target, int $position, int $total, Closure $request) use ($components, $activity, $canAnimate): AgentResponse {
            $remaining = $total - $position;
            $label = "{$activity} ({$position}/{$total}, {$remaining} left)";

            if ($canAnimate) {
                return spin(static fn (): AgentResponse => $request(), "{$label} · {$target}");
            }

            $components->twoColumnDetail($label, $target);

            return $request();
        };

        try {
            return $operation();
        } finally {
            $this->reportFragment = $previous;
        }
    }

    /** @return Closure(AgentPrompt, Closure(AgentPrompt): AgentResponse): AgentResponse */
    public function fragment(int $position, int $total): Closure
    {
        return function (AgentPrompt $prompt, Closure $next) use ($position, $total): AgentResponse {
            return $this->reportFragment === null
                ? $next($prompt)
                : ($this->reportFragment)($this->target($prompt), $position, $total, static fn (): AgentResponse => $next($prompt));
        };
    }

    public function handle(PromptingAgent $event): void
    {
        if ($this->report === null) {
            return;
        }

        ($this->report)($this->target($event->prompt));
    }

    private function target(AgentPrompt $prompt): string
    {
        $provider = $prompt->provider->name();

        return preg_replace('/[[:cntrl:]]/', '', "{$provider} / {$prompt->model}") ?? '';
    }
}
