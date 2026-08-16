<?php

namespace App\Providers;

use App\Support\Ai\CommitMessageGenerator;
use App\Support\Ai\LaravelAiCommitMessageGenerator;
use App\Support\Release\GitHubCliReleaseSource;
use App\Support\Release\GitHubReleaseSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CommitMessageGenerator::class, LaravelAiCommitMessageGenerator::class);
        $this->app->bind(GitHubReleaseSource::class, GitHubCliReleaseSource::class);
    }
}
