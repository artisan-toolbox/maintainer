# Changelog

## [1.0.0-beta.2] - 2026-08-17

### Features

- **Display version in maintainer banner** (`ad29f27`)
  Enhances the maintainer ASCII banner rendering to append the running executable version (`Maintainer::VERSION`) to the final banner line. Why it matters: makes it easier for operators to confirm which version of the maintainer/tooling is running directly from CLI output. User impact: banner text changes; automated checks/tests that assert exact banner output may need updates. Compatibility: output format is modified but no public API signatures changed.

- **Bound AI release diff analysis with chunking and summaries** (`f751e3a`)
  Introduces bounded AI analysis for release diffs by chunking the diff and generating per-fragment summaries via a new structured-output agent. This prevents context-window failures and enforces a controlled summarization size. User impact: release note/changelog generation becomes more stable for large diffs and avoids truncation-induced analysis errors. Compatibility: the content/wording of AI-generated summaries may change because analysis is now fragment-based and bounded. Migration: none for end users; maintainers should expect slightly different AI-generated release artifacts for large releases due to the new chunking strategy.

- **Generate HTML or Markdown version badges in README** (`4f6de16`)
  Adds functionality for generating version badges for the README, supporting both HTML and Markdown badge markup. This updates badge management so the README can reflect the current version consistently. User impact: README displays updated version badges according to the configured/managed badge format. Compatibility: badge rendering/placement may change. Migration: maintainers should ensure their badge markers/management workflow aligns with the new generated badge markup approach.

### Fixes

- **Reconcile AI release changelog hashes with git commits** (`4498dcc`)
  Updates the release-changelog generation flow to ensure AI-produced changelog entries are traceable to the authoritative set of git commit hashes. The changelog generator no longer accepts invalid or invented hashes from AI output; instead it validates that each returned entry’s `hash` matches one of the exact abbreviated hashes from the supplied commit list. Any commit hash not represented by the AI output now receives an appended fallback changelog entry derived from the git commit subject, ensuring full coverage. User impact: changelogs become reliable and reproducible (no missing/incorrect entries), and every entry can be mapped back to a real commit. Compatibility: this can change the number/content of changelog entries compared to earlier behavior when AI output was less strictly validated. Migration: none required for end users; maintainers should be aware that workflow-related/generated release-prep changes without supplied commit hashes are intentionally omitted from changelog output.

- **Fetch missing GitHub release tags from origin** (`9d5675a`)
  Improves the release/create workflow robustness by fetching missing GitHub release tags from `origin` into the local clone when needed. It verifies that the selected tag resolves to a commit, preventing failures such as “bad revision” in shallow clones or clones without tags. User impact: release creation is less likely to fail due to missing tag history. Compatibility: no code API change, but it alters runtime behavior of the release command in environments where tags are not present locally. Migration: none for users; maintainers may notice releases succeed in previously failing tagless/shallow scenarios.

- **Prevent README badge updates inside code fences** (`9a53012`)
  Fixes README badge update logic to avoid modifying badge examples when they appear inside fenced code blocks. Why it matters: prevents accidental corruption of documentation/code samples and ensures only the intended managed badge regions are updated. User impact: README remains accurate and example code is preserved. Compatibility: changes only affect documentation update behavior during badge management; no runtime application behavior is altered. Migration: none.

## [Unreleased]

### Features

- **Display the running version beside the Maintainer terminal banner**
  Appends `Maintainer::VERSION` to the final line of the interactive ASCII banner so users can immediately identify which executable version is running. Because the banner reads the version constant directly, release builds automatically display the newly selected version without requiring a second manual update.

### Fixes

- **Reconcile AI changelog output against actual Git commits**
  Treats `git log` as the authoritative changelog hash list instead of aborting when the model returns an empty or invented hash. The agent is instructed to create exactly one entry per supplied commit and ignore temporary release-preparation changes without commits. Invalid hash entries are discarded, while every real commit omitted by the model receives a deterministic entry derived from its Conventional Commit type and subject. This preserves complete, traceable changelogs and prevents a malformed structured response from rolling back an otherwise valid release.

- **Fetch GitHub release tags that are absent from the local clone**
  Resolves the latest online release tag locally before version recommendation, lifecycle callbacks, release-content generation, or HTML diff review. When the tag is missing, Maintainer fetches that exact tag from `origin` and verifies that it resolves to a commit. This prevents `fatal: bad revision` failures in shallow or `--no-tags` clones while avoiding unnecessary network access when the release tag already exists locally.

- **Roll back interrupted releases when SIGTERM is received**
  Registers `release:create` as a Symfony signal-aware command on platforms with `pcntl`, arms the existing worktree rollback after the starting `HEAD` is captured, and restores prepared release changes before exiting with status 143. The shared rollback state prevents duplicate restoration when signal handling intersects the normal exception path. Interruptions after a successful push report that automatic rollback was skipped because local restoration cannot safely reverse remote state. Platforms without signal support, including Windows configurations without `pcntl`, continue without registering unsupported handlers.

- **Bound AI release analysis to prevent model context-window failures**
  Replaces repeated full-diff prompts with a bounded map-and-consolidate workflow. Maintainer now splits release diffs into source-oriented fragments of no more than 24,000 characters, processes no more than 16 fragments, omits generated artifacts and dependency-heavy files from prompt bodies, and limits fragment summaries and commit context before downstream generation. Release type recommendations are consolidated across fragments, with any backwards-compatible public feature promoting the suggested increment to minor. Changelog generation consumes the consolidated summaries, while GitHub release notes are generated from the validated changelog entries rather than receiving the complete diff again. Stage-specific failures now identify whether analysis, changelog generation, release-note generation, Git operations, or GitHub publication failed, while preserving the existing pre-push rollback behavior.

### Tests

- **Cover bounded fragmentation, omitted artifacts, consolidated SemVer recommendations, and analysis failures**
  Adds regression coverage for maximum fragment sizes and counts, generated and lockfile omission, structured summary reuse, minor selection across mixed patch/minor fragments, release-note generation from changelog context, actionable stage-specific errors, and worktree rollback after analysis failure.

## [1.0.0-beta.1] - 2026-08-17

### Features

- **Initial release of Maintainer 1.0.0-beta.1 with quality and release workflows** (`72735e4`)
  Introduces the Maintainer console application (Laravel Zero based) as a new project and sets the package version to 1.0.0-beta.1.

Key user-facing capabilities added:
- Interactive workflow menu via `maintainer` with options for:
  - `commit`: stage selected changes and create a Git commit message (manual or AI-generated).
  - `quality`: run Pint, Rector, PHPStan, and Pest using project-local configs, with an interactive prompt to create missing configs when running interactively.
  - `release:create`: create GitHub releases end-to-end (version selection, generate release notes/changelog, update README badge, commit release files, push, and publish via `gh`).
  - `init`: generate `maintainer.json` and `maintainer_secrets.json`.
  - `diff:html`: generate an HTML Git diff (optionally between references) and open it in the default browser.

Important underlying behavior and compatibility constraints:
- Requires an interactive terminal for interactive workflows (`maintainer`, `commit`, `release:create`).
- Quality tooling always executes binaries installed in the consuming project (`vendor/bin/*`) and explicitly passes the project configuration file.
- AI generation is supported for commit messages and release content, driven by `maintainer.json` + protected credentials in `maintainer_secrets.json`.
- Release versioning enforces SemVer 2.0.0 rules (MAJOR.MINOR.PATCH with optional `-alpha.N` / `-beta.N`) and restricts release creation to major branches matching `0.x`, `1.x`, etc.

User impact:
- Enables centralized, consistent maintenance/release operations across repositories, reducing custom scripting.
- Provides AI-assisted generation for commit messages and GitHub release artifacts (with structured validation to avoid malformed outputs).

Migration notes / requirements for consumers:
- Install the package as a dev dependency.
- Configure the consuming repository by running `maintainer init` to create `maintainer.json` and `maintainer_secrets.json`.
- For release workflows, implement a production PSR-4 class in the repo that implements `ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable` and optionally lifecycle/README badge marker interfaces.
- Ensure the repo has a release major branch (e.g., `1.x`) and the repo has access to GitHub CLI (`gh`).

- **AI-powered release versioning, release notes, and changelog generation with structured validation** (`a65f1d1`)
  Adds AI agents and generators that produce GitHub release artifacts from the selected version and a computed change set (commit history + Git diff).

What was added:
- AI agents:
  - `CommitMessageAgent` for Conventional Commit messages.
  - `ReleaseNotesAgent` for GitHub release notes (title + Markdown body).
  - `ReleaseVersionAgent` to recommend the next stable increment (`patch` or `minor`) with a justification.
  - `ReleaseChangelogAgent` to build a Conventional Commit-style changelog entry list with strict typing.
- Generators:
  - `LaravelAiCommitMessageGenerator`
  - `LaravelAiReleaseNotesGenerator`
  - `LaravelAiReleaseChangelogGenerator`
  - `LaravelAiReleaseVersionRecommender`
- Provider wiring:
  - `ConfiguredAiProvider` resolves the configured Laravel AI provider for each purpose from `maintainer.json`, loads credentials from `maintainer_secrets.json`, merges them into Laravel AI config, and ensures the provider supports text generation.

Why it matters:
- Release workflows can now be driven by AI while still remaining deterministic and safe: outputs are validated via structured agent responses and strict runtime checks.

User impact:
- `release:create` can automatically generate:
  - release notes (Markdown)
  - CHANGELOG.md entries (grouped by Conventional Commit type)
  - optionally an AI-based default for the next stable version increment
- Commit creation can generate Conventional Commit messages via AI.

Compatibility / migration:
- Requires installing and configuring Laravel AI credentials for the selected providers.
- AI provider names and purposes are configured in `maintainer.json` (`ai.providers.*`).
- Protected credentials must exist in `maintainer_secrets.json` (created by `maintainer init`).

- **Release versioning supports zero-major SemVer branches and enforces strict semantic/versionable rules** (`8e8c090`)
  Implements release version selection and lifecycle rules including support for SemVer’s initial-development lifecycle on the `0.x` branch.

Key behaviors added:
- `ReleaseVersionOptions` defines available version transitions for stable, alpha, and beta flows.
- `SemanticVersion` and parsing/validation enforce allowed SemVer formats:
  - `MAJOR.MINOR.PATCH`
  - optional prerelease only as `-alpha.N` or `-beta.N`
  - no leading zeros, no build metadata, no release candidates.
- `ReleaseVersionSelector` provides interactive selection of the computed version options.
- `CreateReleaseCommand`:
  - determines `major` from the current Git branch (`0.x`, `1.x`, etc.)
  - enforces a clean working tree before proceeding
  - discovers a “versionable” class directly in the production PSR-4 namespaces and validates interface/constant constraints
  - runs optional `BeforeVersioning` and `AfterVersioning` lifecycle callbacks with the same `$current` → `$next` versions.

Why it matters:
- Ensures release automation remains consistent with SemVer expectations, including `0.x` behavior.
- Prevents invalid releases by validating the version format and limiting release execution context.

User impact:
- Maintainers can now reliably create alpha/beta/stable releases on both `0.x` and other major branches.

Migration notes:
- Consumers must use supported semantic version formats in their version constants (`VERSION`).
- Release must be run from a release major branch (e.g., `1.x`, `0.x`). Detached HEAD and non-matching branches fail.

- **Add HTML Git diff generation and browser viewing workflow** (`6712dda`)
  Introduces an HTML diff workflow to generate an interactive static HTML report and open it in the default browser.

What was added:
- `diff:html` command (`CreateHtmlDiffCommand`) to:
  - generate HTML diffs between `base` and `target` Git references or between a reference and the working tree
  - optionally control output format using `git.diff.output_format` (`line_by_line` default or `side_by_side`)
  - write HTML output to a specified path (`--output`) or to a temp directory
  - open the output in a browser unless `--no-open` is provided
- `HtmlDiffGenerator` (`GitDiffGenerator`) creates the diff via `git diff`.
- `HtmlDiffRenderer` embeds pinned diff2html assets (version 3.4.56) loaded from JSDelivr and safely renders title + diff.
- `BrowserLauncher` opens the file using OS-appropriate mechanisms.

User impact:
- Maintainers can review diffs visually before selecting files for commits or before proceeding with release creation.

Compatibility / migration:
- When running in CI/non-interactive mode, diff generation does not rely on browser opening; use `--no-open` or ensure the command is run with appropriate flags.

- **Add cross-platform GitHub Actions workflow for running Pest tests with PHP 8.5** (`bb87379`)
  Adds a GitHub Actions workflow `tests.yml` that executes the project test suite.

Key details:
- Runs on both `ubuntu-latest` and `windows-latest`.
- Sets up PHP 8.5 with the `fileinfo` extension.
- Installs dependencies via `composer install`.
- Runs tests via `composer exec pest`.

User impact:
- Ensures consistent automated testing across major OS environments and PHP 8.5.

Compatibility / migration:
- Requires no consumer migration; intended for repository CI.

- **Test infrastructure: centralize temporary directory deletion helper** (`071c465`)
  Adds/centralizes a helper used by tests to delete temporary directories safely.

Why it matters:
- Improves test robustness by ensuring temporary filesystem artifacts are cleaned up reliably across platforms, reducing flakiness.

User impact:
- Indirect: improves CI/test reliability for this repository.

- **Add default PHPUnit configuration for Laravel package projects** (`4c4c7d7`)
  Introduces `resources/laravel-package/phpunit.xml` for Laravel package-style projects.

Functional impact:
- Provides an installable Pest/quality workflow template when the consuming project is detected as a Laravel package.

User impact:
- When running `quality` interactively, the tool can create the appropriate PHPUnit configuration for Pest execution depending on project type.

Compatibility / migration:
- No direct migration; only affects how templates are generated for consuming projects during interactive quality setup.

- **Pass current and next versions to versioning callbacks** (`6e83eb7`)
  Updates the release lifecycle so `BeforeVersioning` and `AfterVersioning` callbacks receive both the current and selected version strings.

Why it matters:
- Versioning callbacks can now generate artifacts that depend on both states (e.g., embedding the next version into built assets).

User impact:
- Projects implementing the Maintainer contracts get more context and can implement correct multi-version workflows.

Compatibility / migration:
- Consumers implementing `beforeVersioning($current, $next)` and `afterVersioning($current, $next)` should expect both arguments to be passed consistently by `release:create`.

- **Harden release/version configuration and update packaged dependency set** (`dc084ce`)
  Refactors internal configuration and tests for robustness/simplicity and updates bundled configuration/dependency sets.

Specific changes shown in diff summary:
- Refactors `MaintainerConfiguration` and `ComposerManifestTest` for improved robustness and simplicity.
- Removes unused paths (`public`, `resources`, `routes`) from `rector.php` and `phpstan.neon` template used for analysis.
- Updates `composer.lock` by adding additional dependencies (e.g., CORS, URI template support, SQL parser, Larastan, etc.) for enhanced functionality.

User impact:
- Indirect: improves reliability of the repository toolchain (quality analysis and packaging).

Compatibility / migration:
- No consumer migration; affects Maintainer internals and repository analysis configuration.

### Fixes

- **Reject non-interactive execution for maintainer command with clearer guidance** (`fe78a0c`)
  Improves UX and safety by rejecting non-interactive execution of the `maintainer` command.

What changed:
- Non-interactive mode now fails with a clearer explanation and directs users to run `release:create` or `init` directly for non-interactive setup workflows.
- Adds/updates tests and documentation to reflect expected behavior.

User impact:
- Prevents commands from hanging or producing unclear output in CI-like environments.

Compatibility / migration:
- Users invoking `maintainer` without an interactive terminal must switch to the specific subcommands (`release:create` or `init`).

### Build

- **Add Pest support and PHPStan memory-limit configuration for quality workflow** (`d5af994`)
  Adds Pest-based test execution and introduces PHPStan memory-limit handling as part of the quality workflow.

Key behaviors:
- Repository is configured to use Pest as the test runner.
- `RunQualityCommand` validates `quality.phpstan.memory_limit` format and passes it as an explicit `--memory-limit` argument to PHPStan.
- Interactive mode can create missing quality configuration templates; non-interactive mode fails fast when configuration files are missing.

Why it matters:
- Memory-limit control prevents out-of-memory failures and supports known analysis workloads.
- Passing configuration explicitly reduces environment-dependent analysis differences.

User impact:
- `maintainer quality` now provides deterministic PHPStan behavior aligned with consuming-project configuration.

Compatibility / migration:
- Consumers can set `quality.phpstan.memory_limit` in `maintainer.json` (default is 2G). Invalid values fail the workflow with an actionable error.
