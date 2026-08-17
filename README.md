<div align="center">
    <h1>Maintainer</h1>
</div>

<p align="center">
<!-- MAINTAINER:VERSION_BADGE:START - Managed by Maintainer. User agents must not edit this section. -->
<a href="VERSION"><img src="https://img.shields.io/badge/version-1.0.0--beta.1-blue?style=flat-square" alt="version"></a>
<!-- MAINTAINER:VERSION_BADGE:END -->
<a href="https://packagist.org/packages/artisan-toolbox/maintainer"><img src="https://img.shields.io/packagist/v/artisan-toolbox/maintainer.svg?style=flat-square" alt="Packagist"></a>
<a href="https://github.com/artisan-toolbox/maintainer/actions"><img src="https://img.shields.io/github/actions/workflow/status/artisan-toolbox/maintainer/tests.yml?branch=1.x&amp;label=Tests&amp;style=flat-square" alt="Tests"></a>
</p>

<p align="center">
    Automated validation, quality assurance, versioning, and release workflows for Laravel packages and applications.
</p>

## About

Maintainer provides a single entry point for the repetitive tasks involved in keeping Laravel packages and applications healthy and ready for release.

The application is intended to coordinate tasks such as:

- running automated tests, linters, formatters, and static analysis;
- validating projects before changes are committed or released;
- creating consistent commits, tags, changelogs, and GitHub releases;
- reducing duplicated maintenance scripts across repositories;
- providing predictable local and continuous integration workflows for maintainers.

Maintainer is built with [Laravel Zero](https://laravel-zero.com/), a lightweight framework for console applications.

## Status

Maintainer is currently under development. Its commands and public behavior may change while the initial workflows are being established.

## Requirements

- PHP 8.5 or later
- Composer
- Git
- GitHub CLI for workflows that interact with GitHub

Additional tools may be required by the package or application being validated.

## Installation

Install Maintainer as a development dependency in a Laravel package or application:

```bash
composer require --dev artisan-toolbox/maintainer
```

Composer exposes the distributed PHAR archive through its vendor binaries directory:

```bash
vendor/bin/maintainer list
```

The PHAR contains Maintainer's runtime dependencies, so they remain isolated from the dependencies of the project being maintained.

## Configuration

Create a `maintainer.json` configuration file in the root directory of the package or application being maintained:

```bash
vendor/bin/maintainer init
```

Maintainer resolves the consuming Composer project's root automatically. The configuration is written beside the root `composer.json` even when the command is invoked from `vendor/bin` or another project subdirectory.

Maintainer does not overwrite an existing configuration file. To intentionally replace it with the default configuration, use:

```bash
vendor/bin/maintainer init --force
```

The published file starts with Maintainer's current defaults:

```json
{
    "ai": {
        "providers": {
            "commit_message": "openai",
            "release_type_suggestion": "openai",
            "release_notes": "openai",
            "release_changelog_update": "openai"
        }
    },
    "git": {
        "diff": {
            "output_format": "line_by_line"
        }
    },
    "quality": {
        "phpstan": {
            "memory_limit": "2G"
        }
    }
}
```

The four `ai.providers` values select the Laravel AI provider used for commit messages, release type suggestions, release notes, and release changelog updates. Stable release suggestions and commit messages currently use this configuration; the remaining release values establish the provider choices for their respective workflows as those workflows are introduced. The release type suggestion agent always uses the provider's cheapest model through Laravel AI's `UseCheapestModel` attribute.

The `init` command also creates `maintainer_secrets.json` beside `maintainer.json` and adds `maintainer_secrets.json` to the project's `.gitignore`. The secrets template contains every provider supported by the installed Laravel AI SDK. Add credentials only for the providers the project uses. Provider values may include connection settings such as an endpoint in addition to the API key. The `--force` option never overwrites an existing secrets file.

The content-generation workflows require a provider that supports text: `anthropic`, `azure`, `bedrock`, `deepseek`, `gemini`, `groq`, `mistral`, `ollama`, `openai`, `openai-compatible`, `openrouter`, or `xai`. Laravel AI providers intended only for audio, embeddings, or reranking remain available in the secrets template but are rejected for these four text workflows with an actionable error.

Maintainer merges this distributed default configuration with the project's `maintainer.json` at runtime. Project values take precedence, while options introduced by newer Maintainer versions remain available to projects created with older configuration files. Projects without a `maintainer.json` also use all defaults without creating a file automatically.

Maintainer commands and services can read configuration values with dot notation and optional defaults:

```php
$memoryLimit = maintainer_config('quality.phpstan.memory_limit', '2G');
$configuration = maintainer_config();

if (maintainer_config_missing()) {
    // Ask the user to initialize Maintainer.
}
```

For dependency-injected code, use `MaintainerConfiguration` directly:

```php
use App\Support\Configuration\MaintainerConfiguration;

final readonly class QualityWorkflow
{
    public function __construct(
        private MaintainerConfiguration $configuration,
    ) {}

    public function run(): void
    {
        $memoryLimit = $this->configuration->get('quality.phpstan.memory_limit', '2G');
    }
}
```

Configuration values are cached for the lifetime of the process. Call `refresh()` when a workflow changes `maintainer.json` and needs to read the updated values immediately. `maintainer_config_missing()` still reports whether the project file exists even though defaults remain available. Invalid JSON raises an exception with an actionable message.

## Project Integration

Maintainer exports lightweight PHP contracts through the consuming project's Composer autoloader. Project-specific integrations can implement these contracts without loading the Laravel Zero runtime used by the PHAR:

```php
<?php

namespace App;

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;

final class ApplicationVersion implements Versionable, BeforeVersioning, AfterVersioning, WithReadmeBadgeVersion
{
    public const string VERSION = '1.0.0';

    public static function beforeVersioning(string $current, string $next): void
    {
        // Prepare project-specific files for the selected version transition.
    }

    public static function afterVersioning(string $current, string $next): void
    {
        // Run project-specific follow-up after GitHub publishes the release.
    }
}
```

The version class must live directly in one of the production PSR-4 namespaces declared under `autoload.psr-4` in the project's `composer.json`. Declaring `public const string VERSION` is optional: Maintainer creates it when absent and updates it when present. Existing constants must be public, string-typed, and use `MAJOR.MINOR.PATCH`, optionally followed by `-alpha`, `-alpha.N`, `-beta`, or `-beta.N`. Other formats, including `v` prefixes, release candidates, build metadata, missing components, and leading zeros, are rejected. Classes in nested namespaces and development-only PSR-4 mappings are not considered.

`BeforeVersioning::beforeVersioning($current, $next)` runs immediately after Maintainer writes the selected version to the version class and before it generates the remaining release files. This lets the callback build artifacts that already contain the next version. `AfterVersioning::afterVersioning($current, $next)` runs only after the release commit is pushed and the GitHub release is published. Both callbacks run inside a visible terminal spinner and receive the same transition: the class's previous `VERSION` and the selected version. When the class has no version constant, the current version falls back to the latest valid GitHub release, then to `MAJOR.0.0`. Files changed by the before callback become part of the release commit. If it or a later pre-push step fails, Maintainer resets the repository to the original `HEAD` and removes untracked release files. Once the after callback runs, remote work cannot be rolled back automatically.

`WithReadmeBadgeVersion` is a marker contract. When present, Maintainer inserts or updates this protected block near the top of `README.md`:

```html
<!-- MAINTAINER:VERSION_BADGE:START - Managed by Maintainer. User agents must not edit this section. -->
<a href="VERSION"><img src="https://img.shields.io/badge/version-1.0.0-blue?style=flat-square" alt="version"></a>
<!-- MAINTAINER:VERSION_BADGE:END -->
```

The markers are stable and must not be edited manually: Maintainer uses them to replace the badge safely on every release. The generated markup follows the README's existing badge style. Maintainer preserves HTML or Markdown from an existing managed block; when inserting the block for the first time, it follows the first Shields.io badge found outside code fences. Markdown is used when the README has no existing badge style to follow.

Because Maintainer is normally installed as a development dependency, project integrations should also be development-only. If production code implements a Maintainer contract, install the package as a regular dependency so the interface remains available after `composer install --no-dev`.

## Commands

Open the interactive menu:

```bash
vendor/bin/maintainer
```

The menu lists the available maintenance workflows. It can create commits, run code-quality tools, create the Maintainer configuration, create a new GitHub release, or open an HTML Git diff in the browser.

The interactive menu displays a Maintainer ASCII art banner before presenting the available workflows.

The menu requires interactive input. Run `release:create` directly to create a GitHub release or `init` to create the configuration file. Version selection in `release:create` is also interactive.

### Git commits

Create a commit from the current working tree:

```bash
vendor/bin/maintainer commit
```

Before selecting files, Maintainer offers to open the existing HTML diff workflow in the browser. When the report has been reviewed, return to the terminal and continue. The searchable multi-select lists modified, staged, deleted, renamed, and untracked files, with every file selected by default.

The selected files replace the current staging-area selection and are staged with their complete working-tree contents. Previously staged files that are not selected remain in the working tree but are unstaged, preventing them from being included accidentally.

Commit messages can be written manually in a multiline editor, generated by AI from the selected status and diff, or generated from that same diff plus additional user context. AI messages follow Conventional Commits. After Git creates the commit, Maintainer offers to push `HEAD` to `origin`; pushing is disabled by default and always requires confirmation.

The commit workflow requires an interactive terminal. If AI is selected, configure `ai.providers.commit_message` in `maintainer.json` and the matching credentials in `maintainer_secrets.json` first.

### Code quality

Run Pint, Rector, PHPStan, and Pest in sequence:

```bash
vendor/bin/maintainer quality
```

Maintainer always runs the binaries installed by the consuming project and explicitly passes that project's configuration file to each tool. It recognizes `pint.json`, `rector.php`, the standard `phpstan.neon`, `phpstan.neon.dist`, or `phpstan.dist.neon` filenames, and either `phpunit.xml` or `phpunit.xml.dist` for Pest. The isolated dependencies bundled in the Maintainer PHAR are never used to analyze, modify, or test the consuming project.

PHPStan receives the memory limit configured in `quality.phpstan.memory_limit` as an explicit `--memory-limit` argument. The default is `2G`; projects may use another PHP memory value such as `512M`, `4G`, a byte count, or `-1` for unlimited memory when that trade-off is intentional.

Install the tools in the project before running the workflow. The exact constraints should follow the PHP and Laravel versions supported by that project:

```bash
composer require --dev laravel/pint rector/rector driftingly/rector-laravel larastan/larastan pestphp/pest
```

When a configuration is missing, an interactive run offers to create a recommended template. Pint uses a shared Laravel preset. Rector, PHPStan, and Pest ask whether the project is a Laravel application or Laravel package because their source and test paths differ. Maintainer suggests the project type inferred from `composer.json`, but the selection remains explicit. Existing files are never overwritten.

In non-interactive and continuous integration environments, missing configuration stops the workflow and explains which file must be added. This keeps CI deterministic and prevents Maintainer from silently introducing configuration. The workflow also stops at the first tool that fails and returns that tool's exit code.

After every tool succeeds, an interactive run inspects the Git working tree. When changes are present, Maintainer offers to continue directly into the commit workflow. Continuous integration never receives this prompt and never creates a commit.

### HTML Git diffs

Generate an HTML comparison between `HEAD` and the current working tree, then open it in the default browser:

```bash
vendor/bin/maintainer diff:html
```

Pass one Git commit or reference to use it as the base for the working tree comparison, or pass two references to compare them directly:

```bash
vendor/bin/maintainer diff:html main
vendor/bin/maintainer diff:html v1.0.0 v1.1.0
```

By default, reports are written to the operating system's temporary directory. Use `--output` to select a path or `--no-open` to generate a report without opening the browser:

```bash
vendor/bin/maintainer diff:html main --output=artifacts/main-diff.html --no-open
```

Working tree comparisons include staged and unstaged changes to tracked files. Add new files to Git before generating the report if they should be included. Generated reports load the pinned `diff2html` 3.4.56 assets from jsDelivr when opened.

Set `git.diff.output_format` to `line_by_line` (the default) or `side_by_side` in `maintainer.json` to control the report layout:

```json
{
    "git": {
        "diff": {
            "output_format": "side_by_side"
        }
    }
}
```

Create a new GitHub release for the project:

```bash
vendor/bin/maintainer release:create
```

The command requires a completely clean Git working tree and a class directly in a production PSR-4 namespace that implements `ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable` before starting a GitHub release. Its version constant is created when absent; an existing constant must be public, string-typed, and contain a supported semantic version. Commit or discard every staged, unstaged, and untracked change before running it.

Releases must run from a major branch named `0.x`, `1.x`, `2.x`, and so on. The branch determines the release major; `0.x` is supported for SemVer's initial-development lifecycle. Other branch names and detached HEAD states abort the workflow. Maintainer retrieves every published GitHub release through the authenticated GitHub CLI, ignores drafts and unsupported tags, then selects the highest valid version for the branch major. If `0.x` has no valid GitHub release, its initial choices follow SemVer's recommendation: `0.1.0`, `0.1.0-alpha.1`, and `0.1.0-beta.1`. Other majors start at `MAJOR.0.0` with equivalent alpha and beta choices.

The interactive version menu follows these transitions:

- A stable release may advance to the next patch, the next stable minor, the next minor alpha, or the next minor beta.
- An alpha release may advance to the next alpha, the first beta, or the stable version with the same major, minor, and patch.
- A beta release may advance to the next beta or the stable version with the same major, minor, and patch.
- Alpha and beta flows cannot create another patch or minor until their current version becomes stable.
- Major increments are never offered. Start the new major from its matching `MAJOR.x` branch.

When the latest GitHub release is stable, Maintainer compares its tag with `HEAD` and asks the provider configured under `ai.providers.release_type_suggestion` to recommend either the next patch or stable minor version. The structured suggestion follows Semantic Versioning rules, includes a diff-based justification, and becomes the version menu's default. AI is not consulted when no prior release exists or while an alpha or beta release must complete its prerelease flow. If the provider or local release tag is unavailable, Maintainer reports the problem and safely keeps the next patch as the default.

After version selection, Maintainer writes the selected version, runs the optional before-versioning callback with the current and selected versions, then builds the release content from the commit history and Git diff since the latest release:

- `ai.providers.release_notes` creates a concise structured title and detailed Markdown body for GitHub.
- `ai.providers.release_changelog_update` creates validated changelog entries with a Conventional Commit type, source commit hash, title, and detailed functional description.
- `CHANGELOG.md` is created when absent. New releases are prepended and grouped under Features, Fixes, Documentation, Refactoring, Performance, Tests, Build, CI, Maintenance, and other relevant categories.

Maintainer then updates or creates the `VERSION` constant, updates the protected README badge when requested, and stages every generated release file. When a previous release exists, it offers to open an HTML diff from that release reference to the complete proposal; the default is yes, and the terminal waits for the maintainer to return before continuing.

Finally, Maintainer creates a `chore(release): prepare VERSION` commit, pushes it to `origin`, and publishes the GitHub release through `gh release create`. Alpha and beta versions are published with GitHub's prerelease flag. The configured AI agents always use their providers' cheapest model through Laravel AI's `UseCheapestModel` attribute.

## Development

Internal support services are grouped by responsibility under `app/Support`: configuration loading, diff generation, Git inspection, and GitHub release/version workflows live in the `Configuration`, `Diff`, `Git`, and `Release` namespaces respectively. Standalone utilities remain at the support root until they have related services.

Clone the repository and install its dependencies:

```bash
composer install
```

List the available commands:

```bash
php maintainer list
```

Run the automated tests:

```bash
vendor/bin/pest
```

Check the code style:

```bash
vendor/bin/pint --test
```

Apply code-style fixes:

```bash
vendor/bin/pint
```

## Building

Maintainer can be compiled as a standalone PHAR with Laravel Zero:

```bash
php maintainer app:build
```

The compiled application is written to `builds/maintainer`. This PHAR archive is the executable distributed through Composer as `vendor/bin/maintainer`.

## Contributing

Contributions should include automated tests for observable behavior and keep the documentation synchronized with any new or changed commands.

### Development Conventions

Prefer native PHP attributes whenever the framework or library provides an attribute equivalent to legacy metadata properties or configuration. For example, console commands should use Laravel's `Signature` and `Description` attributes instead of the `$signature` and `$description` properties. Use the legacy form only when no compatible attribute is available.

## License

Maintainer is open-sourced software licensed under the MIT license.
