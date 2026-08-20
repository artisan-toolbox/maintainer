<?php

namespace App\Providers;

use App\Support\Ai\CommitMessageGenerator;
use App\Support\Ai\LaravelAiCommitMessageGenerator;
use App\Support\Ai\LaravelAiReleaseChangeAnalyzer;
use App\Support\Ai\LaravelAiReleaseChangelogGenerator;
use App\Support\Ai\LaravelAiReleaseNotesGenerator;
use App\Support\Ai\LaravelAiReleaseVersionRecommender;
use App\Support\Ai\ReleaseChangeAnalyzer;
use App\Support\Ai\ReleaseChangelogGenerator;
use App\Support\Ai\ReleaseNotesGenerator;
use App\Support\Ai\ReleaseVersionRecommender;
use App\Support\Configuration\ConfigurationFilePublisher;
use App\Support\Configuration\MaintainerSecrets;
use App\Support\Configuration\MaintainerSecretsTemplate;
use App\Support\Configuration\UserConfigurationPath;
use App\Support\Release\GitCliReleaseRepository;
use App\Support\Release\GitHubCliReleasePublisher;
use App\Support\Release\GitHubCliReleaseSource;
use App\Support\Release\GitHubReleasePublisher;
use App\Support\Release\GitHubReleaseSource;
use App\Support\Release\ReleaseGitRepository;
use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
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
        $this->app->bind(
            ConfigurationFilePublisher::class,
            fn (Application $application): ConfigurationFilePublisher => new ConfigurationFilePublisher(
                $application->make(Filesystem::class),
                $application->make(UserConfigurationPath::class),
                maintainerSecretsTemplate: $application->make(MaintainerSecretsTemplate::class),
            ),
        );
        $this->app->bind(
            MaintainerSshKeys::class,
            fn (Application $application): MaintainerSshKeys => MaintainerSshKeys::fromResolvers(
                fn (): StringEncrypter => $application->make(StringEncrypter::class),
                fn (): string => $application->make(MaintainerSecrets::class)->rsaKey(),
            ),
        );
        $this->app->bind(CommitMessageGenerator::class, LaravelAiCommitMessageGenerator::class);
        $this->app->bind(ReleaseChangeAnalyzer::class, LaravelAiReleaseChangeAnalyzer::class);
        $this->app->bind(ReleaseChangelogGenerator::class, LaravelAiReleaseChangelogGenerator::class);
        $this->app->bind(ReleaseNotesGenerator::class, LaravelAiReleaseNotesGenerator::class);
        $this->app->bind(ReleaseVersionRecommender::class, LaravelAiReleaseVersionRecommender::class);
        $this->app->bind(ReleaseGitRepository::class, GitCliReleaseRepository::class);
        $this->app->bind(GitHubReleasePublisher::class, GitHubCliReleasePublisher::class);
        $this->app->bind(GitHubReleaseSource::class, GitHubCliReleaseSource::class);
    }
}
