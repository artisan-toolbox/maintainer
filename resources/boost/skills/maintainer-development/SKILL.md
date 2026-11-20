---
name: maintainer-development
description: Use Artisan Toolbox Maintainer in a Composer project. Consult this skill when Maintainer is installed and you add or change tests, quality checks, Composer or npm test scripts, or GitHub Actions workflows. It also covers Maintainer configuration and versioning contracts. Apply to release and deployment workflows only when the user requests Maintainer for that task.
license: MIT
---

# Use Maintainer in a Composer project

Start in the existing project root with PHP 8.5 and Composer. Check `composer show artisan-toolbox/maintainer` and inspect the installed configuration before choosing a workflow.

## Install and verify

Install Maintainer as a development dependency when it only runs project tooling:

```bash
composer require --dev artisan-toolbox/maintainer
php vendor/bin/maintainer list
```

The command list includes `config:publish` and `quality:check`. Maintainer ships its CLI as a PHAR. Run it through Composer's `vendor/bin` entry.

If production code implements a Maintainer contract such as `Versionable`, require Maintainer as a regular Composer dependency. Declare the integration class under `autoload.psr-4` or `autoload.classmap` so Maintainer can discover it and production can load it after `composer install --no-dev`.

## Run project checks

Inspect `config/maintainer.php`. If the project needs a configuration file, run `php vendor/bin/maintainer config:publish` and review the interactive choices. Keep existing settings and secrets intact.

Install the tools selected by the project's configuration before running its checks. In a frontend project that consumes Composer-delivered packages, install Composer dependencies before running `npm ci`.

```bash
php vendor/bin/maintainer quality:check --no-interaction
```

Read the result for each configured tool. An unavailable tool can be reported as skipped, so verify that the expected checks ran. Use `--tool=pest` to select only Pest when the project has installed it. Review `quality:fix` separately when edits are requested.

When changing tests or a workflow, check `composer.json` for `artisan-toolbox/maintainer` and read `quality.test` in `config/maintainer.php`. Use Maintainer as the required GitHub Actions entry point for every tool already delegated to it. Call the same configured check locally and in Actions so both use the project's configuration files and package scripts. Keep separate steps for project checks that Maintainer does not run.

Use `--strict` for required checks when the installed version supports it. The `1.x` source preview includes this option. For a PHPStan-only job, configure `RunsPhpStanCheck`, install PHPStan, and supply its configuration. Run this command from the project root:

```bash
php vendor/bin/maintainer quality:check --no-interaction --strict --tool=phpstan
```

The command succeeds when PHPStan runs and passes. A missing requirement or empty selection fails the strict check. Check `quality:check --help` before using `--strict` on an installed release.

The GitHub.com CI helpers that use the root action are a source preview. Pin either helper to a full Maintainer commit SHA containing the root `action.yml`. For a job that only runs the configured quality checks, call `artisan-toolbox/maintainer/.github/workflows/ci.yml@<commit-sha>`. For an existing job with services, custom dependency resolution, or required check names, call `artisan-toolbox/maintainer@<commit-sha>` after checkout.

The CLI installed in the consuming project must support the flags used in the check. Disable `composer-cache` for private Composer downloads and leave `npm-cache` off for private npm downloads. See the [GitHub Actions guide](https://atb.dev.br/docs/maintainer/ci#github-actions) for inputs and examples.

To verify a workflow change, lint the caller's workflow with `actionlint` and run the same project checks locally. On GitHub, inspect the run for the changed commit and confirm the intended tools ran. After changing the cache, also confirm a later run with the same inputs restores the cache and still installs dependencies. See [Verify a CI run](https://atb.dev.br/docs/maintainer/ci#verify-ci-run).

For parallel Pest runs in the source preview, set `quality.pest.parallel` to `true`. Set `quality.pest.processes` to a positive integer to choose the worker count, or omit it to use Pest's default count.

After a successful interactive check with Git changes, confirm the commit suggestion. Choose **Commit**, the default, or **Commit and release**. The second choice starts the existing release workflow after the work commit succeeds. Use `php vendor/bin/maintainer commit --release` to enter that sequence directly and select all pending changes required for a clean release worktree.

## Require CI before publishing

The published `config/maintainer.php` sets `release.workflow` to `null`. To require CI before publication, use a workflow filename such as `tests.yml`. In the `1.x` source preview, use `['tests.yml', 'coverage.yml']` to require two separate workflows. Every required workflow must run on pushes to the release branch and pass on the exact commit Maintainer will tag. If coverage is a job inside `tests.yml`, use the single filename and make the workflow require a successful result from that job.

Maintainer reports the workflow state and run link while waiting. If waiting or publishing fails, stay on the prepared commit and reopen **Versioning › GitHub releases**. Confirm the prompt to resume the displayed version. For direct invocation, use `php vendor/bin/maintainer release:create --resume`.

After a successful interactive release, Maintainer offers to deploy the published tag when a local Deployer recipe is present. The default is no. On confirmation, the existing deploy command uses the detected recipe and the published tag. A deployment failure leaves the GitHub release published.

When `release:create` offers to sync a tracked `package.json`, confirm to set the top-level `version` to the selected release version. The `1.x` source preview puts a new `version` first and updates an existing one in place. Set `maintainer.syncVersion` to the JSON boolean `true` in `package.json` to synchronize automatically.

## Configure AI

The `1.x` source preview defaults OpenAI text tasks to `gpt-6.2-sol`. Override a task under `ai.models` in `config/maintainer.php` using its key. The keys are `commit_message`, `release_type_suggestion`, `release_notes`, and `release_changelog_update`. Models for other providers use their supported IDs. An omitted override keeps that provider's Laravel AI model selection. Newer models can become available after a Maintainer release. Check your provider's current API catalog, account access, and structured output support before choosing an ID.

Use the provider and model shown beside each AI request in the terminal to verify which configuration the command resolved.
During release recommendations and diff summaries, use the fragment counter to see how many requests are left after the current one.

The release picker explains the suggested version and the other offered versions. Treat routine docs, workflow, and development-environment changes as maintenance when they leave public behavior unchanged. Set `ai.local_feedback_locale` to a locale such as `pt_BR` for the reasons in the terminal, or leave it `null` to detect the language. The source preview uses the first preferred macOS language when the terminal does not specify a language, including when it reports `C.UTF-8`. Set `ai.project_locale` for AI-generated commit messages, release notes, and changelog text. It defaults to `en`.

Maintainer builds Conventional Commit messages from a structured type, scope, title, and changes. It renders release notes from a title and sections of changes. Review the generated commit message and the release title at the prompts. Changelog entries use validated types and Git commit hashes.

## Choose a workflow

- [Install and verify the CLI](https://atb.dev.br/docs/maintainer/installation)
- [Publish project configuration](https://atb.dev.br/docs/maintainer/configuration/publishing)
- [Run quality checks](https://atb.dev.br/docs/maintainer/ci/quality)
- [Create releases with required workflows](https://atb.dev.br/docs/maintainer/versioning/releases)
- [Integrate version and README metadata](https://atb.dev.br/docs/maintainer/versioning/project-integration)
- [Look up command options](https://atb.dev.br/docs/maintainer/commands)

Check the exact command help before preparing a release or deploying. Keep publication, remote deployment, credentials, and target selection within the user's requested scope.
