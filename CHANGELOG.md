# Changelog

## [1.5.1] - 2026-09-04

### Fixes

- **Preserve template indentation/layout in packaged PHAR** (`827e661`)
  Updated the PHAR distribution process so that publishable templates keep their exact indentation and layout when shipped inside the PHAR. This matters because prior packaging behavior could alter whitespace/formatting, making configuration/template contents harder to diff, validate, or consume reliably.

Concretely, the release/publishing pipeline was adjusted to package `config/maintainer.php` and `config/maintainer_secrets.php` as unmodified template files via a dedicated `files-bin` group in `box.json`, rather than through the broader `files` set. This change helps ensure JSON and other project templates remain packaged without modification.

To prevent regressions, unit test coverage in `tests/Unit/ComposerManifestTest.php` was refined and expanded to verify:
- `box.json` no longer includes `directories` for `config`/`resources` (ensuring configuration separation is explicit).
- Config files are split correctly between compacted `boxManifest['files']` entries (e.g., `config/ai.php`, `config/app.php`, `config/commands.php`) and unmodified template entries in `boxManifest['files-bin']` (e.g., `config/maintainer.php`, `config/maintainer_secrets.php`).
- Built PHAR contents preserve byte-for-byte template formatting by asserting `getContent()` for `config/maintainer.php`, `config/maintainer_secrets.php`, and `resources/pint.json` matches the repository-root files exactly.

User impact: shipped PHAR distributions should now retain exact template formatting for the affected configuration/templates, improving consistency and reducing risk of whitespace/layout-related issues at runtime.

Compatibility/Migration: no source-level migration is required for consumers; this is a packaging/publishing behavior fix. If you rely on non-standard assumptions about how these template files were previously formatted when installed from the PHAR, re-validate those assumptions.

## [Unreleased]

### Fixes

- Preserve the indentation and layout of the published `config/maintainer.php` and `config/maintainer_secrets.php` arrays in the distributed PHAR. JSON and other project templates remain packaged without modification.

## [1.5.0] - 2026-09-03

### Features

- **Introduce contract-based code-quality workflows and fix** (`c993c64`)
  This release introduces a contract-based approach to the code-quality workflows and “fix/check” command wiring, and adjusts how quality tooling is exposed to consumers.

Key changes:
- Replaces the previous quality workflow exports with explicit consumer integration surfaces built around quality “contract” FQCNs (e.g., `RunsPintFix` / `RunsPintCheck`, plus related SSH/encryption and Deployer/maintainer surfaces). This matters because it narrows what is publicly exportable/consumable, reducing accidental coupling to internal classes.
- Updates quality tool selection and discovery around contract implementations rather than older console command classes. As a result, consumers should migrate any references from legacy `App\Support\Quality\Commands\...` entries used by `quality.fix` and `quality.test` to the corresponding `Runs...` contract FQCNs.
- Improves Versionable class discovery by using both the production Composer `autoload.classmap` and direct production PSR-4 namespaces so that exact-file exports remain correct without expanding broader namespaces.
- Refactors CI/menu/workflow UX by splitting the former single `quality` flow into two separate workflows and menu entries:
  - `quality:fix` (defaults include Pint, Rector, and the package script invoking the fix workflow)
  - `quality:check`
  This changes how users select and run quality tooling (the prior multiselect/CI menu for individual tools is removed; users instead select `quality:fix` or `quality:check` and optionally narrow with `--tool=*`).

Command and workflow behavior updates:
- Deletes the old LaravelZero console command `app/Commands/CI/RunQualityCommand.php`, including its `quality {--tool=*}` interactive behavior (tool config creation, extra-arg derivation from maintainer config, and optional git commit prompting). Any external consumers invoking that command/behavior will break because it no longer exists.
- Adds a new command `quality:check` (`app/Commands/CodeQuality/RunCheckCommand.php`) extending shared workflow logic; it is wired to `quality.test` configuration and targets CI code-quality checks.
- Adds a new command `quality:fix` (`app/Commands/CodeQuality/RunFixCommand.php`) extending the shared workflow logic; it is wired to `quality.fix`, labeled appropriately, and indicates it can both create a commit and run checks. It supports `--tool=*` to run only selected configured tools.
- Introduces a shared abstract workflow command `RunCodeQualityWorkflowCommand` implementing the core execution flow: validates project root, loads a list of configured `QualityCommand` contract implementations from the maintainer configuration provided by subclasses, supports interactive tool selection, checks tool availability per project root (skipping with warnings if unavailable), and resolves/uses tool-specific configuration when declared by each tool.

Release and service integration changes:
- Release creation now runs `quality:fix` non-interactively (failing the release command if it fails) and conditionally runs `quality:check` non-interactively based on `shouldRun()`. This is done with a helper that preserves prompt/input interactivity after `--no-interaction => true`.
- The maintainer version constant is bumped to `1.5.0`.
- Adds new container bindings for the quality fix/test contract types so the framework can resolve `RunsPintFix`, `RunsRectorFix`, `RunsPestCheck`, `RunsPintCheck`, `RunsPhpStanCheck`, `RunsVitePlusCheck`, `RunsVitePlusTest`, and `RunsVueTscCheck` to their concrete command implementations.

Quality contract surface additions (new types):
- Adds new quality contract marker interfaces under `App\Quality\Contracts` for code-quality typing/organization, including (at minimum) `RunsPestCheck`, `RunsPhpStanCheck`, `RunsPintCheck`, `RunsPintFix`, `RunsRectorFix`, and `RunsVitePlusCheck`. These interfaces are empty/marker-only.

Migration / compatibility notes:
- You must migrate any `quality.fix` / `quality.test` configuration entries that pointed to `App\Support\Quality\Commands\...` into the new `Runs...` contract FQCNs.
- If you used or automated the removed `quality` command behavior (`app/Commands/CI/RunQualityCommand.php`), update scripts to use `quality:fix` and/or `quality:check` instead.
- Package publishing behavior is adjusted to skip Maintainer SSH key email generation for Laravel package project types (no application encryption key dependency).

Overall impact:
- User-facing CLI workflow names and menu structure change (CI “quality” is split into `quality:fix` and `quality:check`).
- External integrations relying on the deleted `quality {--tool=*}` command must be updated.
- Release automation is strengthened by enforcing non-interactive quality runs during release creation.

## [1.4.0] - 2026-08-28

### Features

- **Review generated commit messages before committing** (`e465810`)
  Adds a new interactive step that reviews generated commit messages before they are finalized and committed. Generated commit messages are now always routed through a reviewer UI (a textarea prompt) where users can edit the suggested message; manual messages also use the same editing flow. Implementation-wise, the interactive flow in the commit command is refactored to use injected workflow/UX services: `CommitWorkflowPrompts` (for diff review, message mode selection, and push-to-origin decisions) and `CommitMessageReviewer` (for editing/validating commit message text). For non-manual AI modes, commit-message generation still happens (including optional additional context for `AiWithContext`), but the resulting suggested message is then presented for user editing rather than being used directly. User impact: commits no longer become immutable immediately after AI generation; users get an explicit “Review the generated commit message” editing step and validation (non-empty trimmed message). Compatibility/migration: no public API/CLI signature changes are indicated; however, users will see an additional interactive prompt when generating commit messages (AI modes), and release title editing is similarly handled in the release flow (see related changes in this release).

### Refactoring

- **Inject commit workflow prompts for deterministic tests** (`a12af19`)
  Refactors the commit/release title generation and commit-message editing flows used during tests to remove platform-dependent prompt fallback behavior. This makes interactive review/generation-mode/push decisions deterministic on Windows by explicitly injecting the prompt behavior (review/generation-mode/push decisions) rather than relying on OS-specific prompt resolution. User impact: interactive CLI prompting behavior becomes more predictable in test and CI environments, without changing the CLI surface area. Compatibility/migration: no migration required; this is internal test determinism/behavioral consistency work.

## [1.3.0] - 2026-08-28

### Features

- **Opt-in Pest parallel execution and normalize AI release titles** (`f37d602`)
  Introduces opt-in parallel Pest execution controlled by `quality.pest.parallel` (default `false`) and the `MAINTAINER_PEST_PARALLEL` environment variable; when enabled, the quality workflow forwards Pest’s `--parallel` flag to speed up CI test runs. Updates AI-generated GitHub release title handling by prefixing titles with the exact selected tag in `TAG - <compact outcome>` form, normalizing AI output to avoid duplicating an already-present version/tag, and limiting the outcome text length for cleaner release listings. Refines SemVer release increment recommendations based on Laravel project type: reusable packages still consider developer-facing AI/MCP/development AI instructions, while Laravel applications omit conventional development-AI evidence; if an application’s diff contains only omitted development AI/support/dependency-related changes, the recommender deterministically defaults to a patch (and does not prompt AI for increment rationale). Expands quality CLI argument routing so Pest/PhpStan receive their tool-specific options via a `match` structure, and adds validation that `quality.pest.parallel` must be a boolean—CI now throws a runtime exception with `quality.pest.parallel must be true or false.` on invalid configuration. Updates AI release-notes generation and diff-chunking to support these title-format and recommendation-context changes (including new `ReleaseDiffChunker` omission controls and added diff-context state for whether any analyzable changes remain), and bumps the maintainer version constant to `1.3.0`. Adds/updates tests to cover the new title payload (GitHub release publishing includes `title`), the new configuration default and env override for `quality.pest.parallel`, quality command success/failure behavior (including `--parallel` and invalid-value handling), and improved release title normalization behavior when the AI agent includes the version/tag in its suggested title. Compatibility impact: downstream consumers expecting AI agent titles to include version/tag inside the `title` field may observe different content (the version/tag is now handled deterministically by the caller/generator); CI behavior changes include stricter boolean validation for `quality.pest.parallel` and potentially different release increment recommendations for Laravel applications where only development-AI artifacts changed. Migration: set `MAINTAINER_PEST_PARALLEL=true` (or configure `quality.pest.parallel` to a real boolean) only if parallel execution is desired, and ensure any maintainer configuration sets `quality.pest.parallel` to `true`/`false` (not strings/other values) to avoid CI failures.

## [1.2.0] - 2026-08-28

### Fixes

- **Scope project .env variables during maintainer configuration evaluation** (`d126185`)
  This release fixes an environment-leakage issue during Maintainer’s evaluation of a consuming project’s configuration. Maintainer now scopes values loaded from the consuming project’s `.env` so they are only used while evaluating maintainer configuration, and are prevented from persisting into subsequent quality-tool and deployment subprocess environments.

Why it matters: previously, `.env`-loaded values could bleed into later subprocesses, which could cause quality tools to run with the wrong environment. A concrete impact mentioned is PHPStan causing a subsequent Pest run to inherit the app’s local environment instead of the PHPUnit/test environment.

In addition, the fix includes related execution and packaging hardening:
- Quality binaries are now executed with the same PHP interpreter that started Maintainer on POSIX systems, avoiding interpreter mismatches that could occur when using `env php` shebang resolution.
- PHAR packaging now includes only an explicit allowlist of distributed configuration files; local development config files (e.g., `config/dev_maintainer.php`, `config/dev_maintainer_secrets.php`) are no longer bundled. The build is also checked for local config path/credential signatures to prevent accidental inclusion.
- Maintainer SSH identity handling is mentioned as part of the broader fix set.

User impact: users running Maintainer in projects with `.env` files should see fewer unexpected side effects where later tools (tests/quality/deployment) inherit the wrong environment variables.

Compatibility/migration: no configuration keys or public APIs are described as changed; this is primarily a behavioral correction. If any workflows implicitly relied on the old (leaky) behavior, they may need adjustment to explicitly pass required environment variables to the subprocesses.

## [1.1.0] - 2026-08-20

### Features

- **Add deploy:unlock command, contrib deploy recipe, and SSH key wrapper** (`f56f015`)
  Introduces new deployment ergonomics by adding (1) a `deploy:unlock` command and (2) a Deployer contribution recipe under `app/Deployer/contrib.php` to wire maintainer SSH identity handling into Deployer. Also adds/introduces an SSH key wrapper via console tooling so keys can be decrypted/obtained as needed for deployments. Functionally, this enables unlocking failed deployments and standardizes identity usage for Deployer tasks while keeping SSH identity lifecycle explicit and controlled. Compatibility impact: adds new CLI surface area (new commands/recipes); existing workflows should continue to work.

- **Add deploy workflow delegating to consuming Deployer binary** (`0d6443b`)
  Adds a new `deploy` command/workflow that delegates execution to the consuming project’s Deployer binary. The command resolves the consuming project root via `ProjectPath`, then builds Deployer CLI arguments from provided options (e.g., file/tag/revision/branch/overrides/limits/no-hooks/plan/start-from/log/profile) and streams Deployer output to the console. It includes explicit error handling and preserves the Deployer exit code on failure, enabling predictable automation behavior. Compatibility impact: introduces a new CLI command; scripts should be updated to use `deploy` (delegation wrapper) if they previously invoked Deployer directly in a different way.

- **Add env config support and encrypted secrets keys** (`4df96b9`)
  Enhances maintainer configuration by adding support for environment-based configuration and adding encrypted secrets key handling. This matters because it allows deployments to use secrets safely (encrypted at rest) while enabling configuration to be provided via environment variables. Compatibility impact: users may need to ensure the new encrypted secrets key configuration (e.g., required environment/app key or maintainer secrets key) is set so encryption/decryption can succeed.

- **Add config publish workflow and migrate Maintainer config** (`224bde0`)
  Introduces an interactive `config:publish` workflow that lets users select which configuration templates to publish, optionally choose a project type (Application/Package) for project-specific templates, and decide whether to add published destinations to `.gitignore` (default yes). It also changes overwrite behavior to be conservative by default (preserve), requiring explicit confirmation for overwriting existing files (default no for existing destinations). For maintainer secrets, it prompts for an email and passes it through `sshKeyEmail()` (with email validation via `filter_var`). Additionally, it migrates Maintainer configuration flow to align with the new menu/commands architecture. Compatibility impact: replaces/obsoletes the old `init`-driven setup process; users should migrate to `config:publish` and follow new prompts for overwrite and `.gitignore` updates.

### Fixes

- **Restrict PHAR package to explicit config allowlist** (`5c7541c`)
  Tightens PHAR packaging/repackaging rules to only include PHAR content that matches an explicit configuration allowlist. This reduces the risk of unintentionally shipping unwanted files into the PHAR distribution and improves security/supply-chain hygiene. Compatibility impact: deployments that relied on implicitly included files will need to ensure those files are present in the allowlist configuration.

### Refactoring

- **Centralize consuming project root paths via project_path()** (`d3e908c`)
  Refactors code to centralize consuming project path resolution through a shared `project_path()` helper. This standardizes how paths are derived across deployment/command code paths, reducing drift and path-handling inconsistencies. User impact is primarily stability/maintenance; compatibility impact is limited unless external integrations depended on prior ad-hoc path construction quirks.

- **Restructure Maintainer console menus into CI/Configuration/Deployment/Versioning** (`aadfb7f`)
  Reworks the interactive `MaintainerCommand` UI from a single flat “which workflow” prompt into a hierarchical menu with dedicated sections (CI, Configuration, Deployment, Versioning, Exit). Adds submenu flows for configuration (`config:publish`, `ssh:key`, `ssh:public`), deployment (`deploy`, `deploy:unlock`), and CI quality tool selection. Also updates non-interactive guidance to reference `config:publish` instead of the removed `init` flow and implements submenu cancellation behavior so Ctrl+C returns to the main menu rather than terminating the entire command. Compatibility impact: users/scripts relying on older menu text or prompt order will change; integrations that expected `init` behavior must migrate.

- **Remove init workflow and migrate SSH key naming** (`3eae4c7`)
  Removes the `init` console workflow and updates related SSH key naming/usage so configuration/secrets and keys align with the new publish/unlock/ssh tooling. Compatibility impact is significant: the `init` command is no longer available, so any user flow that depended on it to generate `maintainer.json` / `maintainer_secrets.json` and update `.gitignore` must be migrated to the new `config:publish` and related commands.

- **Centralize consuming project root paths via project_path()** (`d3e908c`)
  Refactors code to centralize how the consuming project’s filesystem paths are resolved using a shared `project_path()` helper. This eliminates inconsistencies across commands (notably deployment-related ones) and ensures related operations derive roots consistently. User impact is mainly improved stability and fewer environment-specific edge cases; migration is not expected unless external code relied on non-standard path derivation.

### Tests

- **Normalize PHAR scanner entry paths for distributed archives** (`4b7648b`)
  Updates the distributed PHAR scanning logic to normalize PHAR entry paths. This makes scanner behavior consistent across environments/packaging layouts where path formats can differ (e.g., differing prefixes or separators), reducing missed/incorrect matches during scanning. User impact is indirect but important for reliability of scanning in packaged/distributed artifacts; no CLI/API changes are implied.

## [Unreleased]

### Features

- Export only explicit consumer integration surfaces through Composer: readable code-quality contracts, versioning contracts, SSH helpers, encryption support, Deployer integration, and the root `Maintainer` version class. New directories under `app/` remain private unless deliberately added to the production autoloader. Published and distributed quality configuration now uses stable identifiers such as `RunsPintFix` and `RunsPintCheck`, while Maintainer resolves them to private implementations internally. Migration: replace every `App\Support\Quality\Commands\...` entry in `quality.fix` and `quality.test` with its corresponding `Runs...` contract.
- Discover versionable classes from production Composer `autoload.classmap` entries as well as direct production PSR-4 namespaces. This keeps exact-file public exports usable without opening a broader package namespace.
- Replace the single `quality` workflow and **CI** menu with **Code Quality**, split into `quality:fix` and `quality:check`. Fix defaults to Pint, Rector, and the package script that invokes `vp check --fix`; CI Check defaults to Pest, Pint test mode, package scripts that invoke `vp check`, `vp test`, and `vue-tsc --noEmit`, then PHPStan. The ordered `quality.fix` and `quality.test` configuration lists contain public contract FQCNs and drive the default interactive multi-selection. PHP commands now require both their `vendor/bin` executable and recognized configuration file; frontend commands require both their `node_modules/.bin` executable and a matching `package.json` script, whose name is discovered from its command contents. Missing requirements emit a `Skipped` warning without failing the remaining workflow. Migration: replace automation that invokes `quality` with `quality:fix` or `quality:check`, and customize the new contract lists when the distributed selection is not appropriate.
- Present generated commit messages and GitHub release titles in editable Laravel Prompts textareas before using them. Manual commit messages use the same editor, while edited release titles must preserve the exact `TAG - compact outcome` format.
- Add opt-in parallel Pest execution through `quality.pest.parallel` or `MAINTAINER_PEST_PARALLEL`. The default remains `false`; when enabled, the quality workflow passes Pest's native `--parallel` flag.
- Prefix every generated GitHub release title with the exact selected tag using `TAG - compact outcome`. AI output is normalized so an already-present version is not duplicated, and the outcome portion is bounded for concise release listings.
- Scope SemVer recommendations by Laravel project type. Package recommendations continue to consider development AI instructions and MCP configuration, while Laravel applications omit conventional AI-assistant rules, prompts, skills, and MCP files because they do not change the delivered application. An application release containing only omitted development-AI or generated/dependency changes now defaults deterministically to patch without prompting AI.
- Add a `deploy:unlock` command and Deployment submenu workflow that invoke Deployer's native `deploy:unlock` task with host selectors, alternative recipes, configuration overrides, execution limits, hooks, plans, logs, and profiles. The command uses the same temporary Maintainer SSH identity lifecycle and returns Deployer's original exit code.
- Group the interactive menu into CI, Configuration, Deployment, and Versioning submenus. `Ctrl+C` returns from a submenu to the main menu, where Exit closes Maintainer. Compatible terminals are cleared on each navigation before the banner and current breadcrumb are redrawn, with a non-clearing fallback for unsupported terminals. CI uses a multi-select to run any tool combination in one workflow, backed by the repeatable `quality --tool=<tool>` option; omitting the option preserves the complete quality sequence.
- Add an interactive `config:publish` workflow with granular template selection for Maintainer settings and secrets, Pint, Rector, PHPStan, Pest/PHPUnit, and Deployer. Selected files can be added to `.gitignore` by default without duplicate entries. Every existing destination requires an explicit per-file overwrite confirmation that defaults to preserving the file.
- Add a package-managed Deployer contribution recipe. The `deploy` command passes its absolute path through `MAINTAINER_CONTRIB`, allowing the published `deploy.php` to import it after the Laravel and npm recipes while keeping project configuration, hosts, and hooks in the consuming project.
- Add a `deploy` command and interactive workflow that delegate deployments to the consuming project's `vendor/bin/dep`, stream Deployer output, preserve interactive terminal access, forward supported deployment options and selectors, and return Deployer's exit code. When Maintainer secrets contain an encrypted `ssh_key`, the wrapper decrypts it into a restricted temporary identity file, supplies only that file's path to Deployer, and deletes it after the process finishes, including failed deployments. Projects without a generated key keep Deployer's normal SSH configuration.
- Add an opt-in `repository:tag` Deployer task that queries remote tags without peeled-reference duplicates, sorts semantic versions newest-first, filters by the configured `N.x` branch major, limits the interactive choices to 10 tags by default, and uses the selected tag as the deployment target. Configure `repository_tag_limit` to change the number of choices; an explicit `--tag` skips remote discovery and prompting.
- Add an authoritative `pm2:config` Deployer task for ecosystem configuration changes. The task validates `pm2_config_file`, `release_path`, the ecosystem file, and the PM2 executable; inspects `pm2 jlist` as JSON; deletes existing processes only when the list is non-empty; starts the configured ecosystem with updated environment values; and saves the resulting PM2 state. It remains opt-in and can be hooked into a deployment or invoked from another task.
- Move project configuration from root-level JSON files to `config/maintainer.php` and `config/maintainer_secrets.php`. Both files return arrays and project values are recursively merged over the latest distributed PHP defaults. `config:publish` is the single configuration creation workflow, while legacy JSON remains readable for manual migration. Source development uses the configurable `dev_` prefix to keep local overrides separate from packaged defaults; Laravel Zero's production build environment disables that prefix in the PHAR.
- Remove the redundant `init` command and workflow menu entry. Configuration creation now always uses the selective, overwrite-protected `config:publish` workflow.
- Support Laravel-style `env()` calls in Maintainer configuration files. Distributed and published templates now expose environment-backed defaults, and Maintainer loads the consuming Composer project's `.env` before evaluating its configuration while preserving operating-system environment precedence.
- Add Laravel's authenticated encryption layer through `Crypt`, `encrypt()`, and `decrypt()`. The lazily resolved encrypter requires `maintainer_secrets.key`, whose distributed default is `env('APP_KEY')`, and throws `MissingAppKeyException` only when encryption is requested without an available key.
- Generate an OpenSSH Ed25519 key when publishing Maintainer secrets. Only the Laravel-encrypted private key is stored under `ssh_key`; `ssh:key` decrypts it, `ssh:public` derives the public key on demand, and consumer-side `maintainer_ssh_key()` and `maintainer_ssh_public_key()` helpers call the same public key service directly without starting a subprocess. Published files receive normalized line endings, trailing whitespace, and final newlines. Key generation uses phpseclib without requiring the Sodium extension or an external `ssh-keygen` binary.

### Fixes

- Offer to run `quality:check` after every successful interactive `quality:fix` workflow and then explicitly ask before entering the commit workflow. Nested non-interactive checks now restore the parent prompt state, preventing the commit confirmation from silently accepting its default and jumping directly to diff review. The release workflow runs fixes without offering a separate quality commit, presents the check offer before staging, and retains its existing rollback when a fixer or accepted check fails.
- Skip SSH identity generation when publishing Maintainer secrets for a Laravel package. Package projects no longer need an application `.env` file or `APP_KEY` just to run `config:publish`; the secrets file is published with `ssh_key` set to `null`, while Laravel applications retain encrypted key generation and validation.
- Scope values loaded from a consuming project's `.env` to Maintainer configuration evaluation. Project variables no longer leak into later quality-tool or deployment subprocesses, preventing PHPStan from causing a following Pest run to inherit the application's local environment instead of PHPUnit's testing environment. Existing operating-system and CI variables keep their precedence.
- Run project quality binaries with the same PHP interpreter that started Maintainer on POSIX systems. This prevents Pint, Rector, PHPStan, or Pest from selecting a different or broken PHP installation through their `env php` shebang. No configuration or migration is required.
- Package only an explicit allowlist of distributed configuration files in the PHAR. Local `config/dev_maintainer.php` and `config/dev_maintainer_secrets.php` files are no longer collected by Box, and the committed build is checked for local configuration paths and common credential signatures.
- Initialize the temporary Maintainer SSH identity silently while importing the shared Deployer contribution recipe. Every remote task in that invocation now receives the identity, including direct unlock, lock inspection, rollback, release listing, log, push, granular task, `--no-hooks`, and `--start-from` flows. The hidden hooks only report the selected identity after Deployer output is available.
- Preserve comments and indentation in every published project template from the PHAR. The `resources` directory is now packaged without Box compactors, preventing PHP templates such as `deploy.php` and `rector.php` from being stripped before `config:publish` reads them.
- Configure Deployer's temporary SSH `identity_file` without writing to its output service while the shared recipe is being imported. Deployer initializes console output only after loading recipes, so this prevents `Uninitialized "output" in Deployer container` before a deployment can start.

### Tests

- Keep the editable commit-message workflow regression test deterministic on Windows by injecting its review, generation-mode, and push decisions instead of relying on platform-specific prompt fallback behavior.
- Keep the Maintainer test suite portable across Windows and POSIX runners. Deployer task fixtures now match Deployer's Bash execution environment, command assertions ignore Windows-only serialization quotes, path assertions use the host directory separator, and the PHAR credential scanner normalizes archive entry paths before excluding vendored dependencies.

### Refactoring

- Remove abandoned code from the previous quality workflow: the development-only `maintainer_config()` and `maintainer_config_missing()` helpers, automatic template installation through `QualityConfigurationManager`, and unused quality-tool presentation methods. Configuration publishing remains exclusively owned by `config:publish`, while quality workflows only locate existing project configuration.
- Organize console command classes into CI, Configuration, Deployment, and Versioning namespaces that mirror the interactive menu structure.
- Centralize consuming-project path resolution in the Laravel-style `project_path()` helper. Relative paths accept either slash style and are normalized for the host operating system, while Maintainer-owned paths use Laravel's `base_path()`, `config_path()`, and `resource_path()` helpers.

## [1.0.0] - 2026-08-17

### Features

- **Display version in maintainer banner** (`ad29f27`)
  Enhances the maintainer CLI banner to append `Maintainer::VERSION`, making it easier for users to verify which installed version they are running. This is user-facing but non-breaking: it changes only display output. Compatibility: no API changes. Migration: none.

- **Bound AI release diff analysis with chunking and summaries** (`f751e3a`)
  Limits AI analysis of release diffs by chunking the diff and producing per-fragment summaries, reducing the chance of context-window failures and improving robustness for larger diffs. Why it matters: AI-based release tooling must scale to real-world repository diffs. User impact: more consistent generation of changelog/release artifacts for large change sets; output quality should improve due to guided summarization. Compatibility/migration: no manual migration, but the structure/content of AI-generated summaries may differ from prior behavior.

- **Pass current and next versions to versioning callbacks** (`6e83eb7`)
  Updates the versioning lifecycle so that callbacks receive both the current version and the next target version. This matters because callbacks often need to compute behavior based on an explicit version transition (e.g., updating files, validating constraints, or customizing changelog sections). User impact: improved callback capability/expressiveness. Compatibility: existing callback signatures may need adjustment depending on how the callback interface is defined in the codebase. Migration: update any custom `BeforeVersioning`/`AfterVersioning` implementations to accept/use the new parameters as applicable.

- **Normalize directory paths and log content handling in RunQualityCommandTest** (`cb57143`)
  Improves test robustness by normalizing directory paths and log content handling in `RunQualityCommandTest`. This helps avoid platform-specific failures and makes assertions less brittle. User impact: none directly (test-only). Compatibility/migration: none.

- **Add ReleaseDiffReviewer with browser review and terminal pause support** (`fea4324`)
  Introduces `ReleaseDiffReviewer`, adding a review step that can render diffs for browser inspection and optionally pause in the terminal for user confirmation. Why it matters: release workflows benefit from an explicit human review loop, especially when AI-generated changelog/release notes are involved. User impact: more controlled release creation workflow with improved review ergonomics. Compatibility: no API breaking changes implied, but release flow UI/interaction changes (an additional review step/pause) may affect automation scripts if they rely on fully non-interactive execution.

- **Support zero-major SemVer branches and improve platform compatibility** (`8e8c090`)
  Enhances SemVer handling for zero-major branches and improves platform compatibility, along with refinements to content handling. This matters for repositories whose branching/version scheme starts with `0.x` and for ensuring consistent behavior across environments/OSes. User impact: more reliable release/version behavior in early-stage versioning and fewer cross-platform issues. Compatibility/migration: behavior changes in version inference/content handling; migration typically not required but automation expectations may need validation.

- **Introduce AI-powered release versioning and changelog generation** (`a65f1d1`)
  Adds AI-powered tooling to recommend release version increments and generate changelog entries as part of the release workflow. Why it matters: reduces manual effort and improves speed/consistency in release notes. User impact: `release:create` can now use AI to draft changelog and version increment recommendations. Compatibility: introduces new behavior and potential new dependencies/configuration for AI operations. Migration: ensure AI configuration/environment is set up if you enable AI features; otherwise the non-AI path should remain functional depending on existing defaults.

- **Add Git commit workflow with AI-generated message support** (`feb74be`)
  Adds a Git commit workflow that supports AI-generated commit messages. Why it matters: streamlines developer commit creation and enforces Conventional Commit structure. User impact: new interactive/assisted commit flow, potentially changing developer experience. Compatibility: introduces new CLI command/workflow; no breaking changes expected for existing git usage. Migration: none unless you want to adopt the new commit command.

- **Add HTML Git diff generation and browser viewing workflow** (`6712dda`)
  Adds functionality/workflow to generate HTML Git diffs and view them in a browser. This supports review of changes, particularly useful for AI-assisted release workflows. User impact: new option to visually inspect diffs. Compatibility: adds a command/path; no breaking changes expected. Migration: none.

- **Add release:create and maintainer commands; replace InspireCommand** (`9d8f6a9`)
  Implements `release:create` and `maintainer` commands, replaces `InspireCommand`, and updates documentation/tests accordingly. Why it matters: establishes the core CLI entry points for release and interactive maintenance tasks. User impact: command set changes; users should use the new commands. Compatibility: if you used the old `InspireCommand`/CLI entry points, they may no longer exist. Migration: update your command usage to `maintainer` and `release:create`.

- **Generate HTML or Markdown version badges in README** (`4f6de16`)
  Includes the changes introduced by commit 4f6de16: feat: generate HTML or Markdown version badges in README.

### Fixes

- **Reconcile AI release changelog hashes with git commits** (`4498dcc`)
  Fixes AI-generated release changelog entries so that they reconcile against actual git commits. This matters because deterministic traceability is critical for changelogs: entries must map to real commit hashes. User impact is improved reliability/accuracy of generated changelog output. Compatibility and migration: no breaking compatibility changes, but generated changelog history for the affected release can differ from previously generated outputs.

- **Fetch missing GitHub release tags from origin** (`9d5675a`)
  Improves the release flow by fetching missing GitHub release tags from `origin` when they are not present locally. This prevents the release process from missing context about the latest release state, which can otherwise lead to incorrect version selection and changelog/release note generation. User impact: more reliable `release:create` behavior when local tag state is incomplete. Migration: none required.

- **Reject non-interactive execution for maintainer command** (`fe78a0c`)
  Ensures the `maintainer` command is rejected when run in non-interactive mode, and updates error messaging, tests, and documentation with workflow instructions. Why it matters: interactive CLI commands can behave incorrectly or hang in CI/non-interactive environments. User impact: clearer failure behavior when automation tries to run interactive commands. Compatibility: scripts that previously invoked `maintainer` non-interactively may now fail. Migration: run `maintainer` in interactive contexts, or adjust scripts to use non-interactive-friendly alternatives if provided.

- **Prevent README badge updates inside code fences** (`9a53012`)
  Includes the changes introduced by commit 9a53012: fix: prevent README badge updates inside code fences.

### Documentation

- **Clarify README and documentation links** (`d5e9844`)
  Updates README and other documentation links for improved clarity and easier navigation. This affects where users click to learn about usage and configuration, but it does not change runtime behavior or APIs. Compatibility impact is limited to documentation consumers; no migration is required.

- **Update README badges to reference 1.x branch workflows** (`0e99316`)
  Updates README badges so they point to 1.x branch workflow references. This helps users find the correct CI status indicators. User impact: documentation-only; no behavior changes. Migration: none.

### Code Style

- **Update branding and requirements** (`17167cf`)
  Updates branding and requirements. This likely affects visible naming/branding text and documented constraints. User impact: documentation/branding changes. Compatibility: if requirements change (e.g., PHP/library minimums), this affects installation. Migration: ensure your environment meets the updated requirements in docs.

### Refactoring

- **Add version selector and refine initial zero-major handling** (`611b56f`)
  Refactors release/version selection logic by adding a version selector and updating handling for the initial “zero major” state in SemVer-like workflows. This matters for projects starting at `0.x.y` where the rules for next versions can be unintuitive. User impact: more correct and predictable version selection in the release tooling. Compatibility: behavior change in version calculation; migration may not be required, but users relying on previous selection semantics should be aware that outputs can shift for early-stage versioning.

- **Refine test and class method chaining and exception handling** (`1d1dce8`)
  Refactors for more concise method chaining and updates exception handling patterns across tests and classes. Goal: reduce verbosity while improving clarity and consistent error behavior. User impact should be limited to more predictable failure modes and cleaner internal code; no API changes intended. Compatibility: if exception types/messages surface, tests or integrations might need updates. Migration: none expected for typical usage.

- **Refactor tests and classes for concise object instantiation** (`ff0124a`)
  Refactors code to simplify object instantiation patterns in tests and classes, improving readability while keeping behavior intended to remain the same. User impact: none directly. Compatibility/migration: none expected.

- **Introduce JsonTemplateFormatter for consistent JSON formatting** (`6b3ef64`)
  Adds `JsonTemplateFormatter` to ensure consistent JSON formatting across configuration contexts. This matters because reliable JSON formatting improves diffability, test stability, and downstream parsing expectations. User impact: more consistent JSON output/formatting where templates are used. Compatibility: if consumers depended on a specific formatting style, they may see different whitespace/ordering behavior. Migration: none unless you do strict string comparisons of formatted JSON.

- **Refactor MaintainerConfiguration and ComposerManifestTest; update rector/phpstan config and composer.lock** (`dc084ce`)
  Improves robustness/simplicity in `MaintainerConfiguration` and `ComposerManifestTest`. Also removes unused paths (`public`, `resources`, `routes`) from `rector.php` and `phpstan.neon`, and updates `composer.lock` with additional dependencies to enhance functionality (including packages like `fruitcake/php-cors`, `guzzlehttp/uri-template`, `iamcal/sql-parser`, `larastan/larastan`). User impact: improved internal configuration handling and potentially better static analysis/inspection coverage. Compatibility: dependency set changes can affect tooling; runtime behavior changes are not claimed but new dependencies are introduced for package operations/testing. Migration: run `composer install` to pick up lock changes; review any local static analysis config overrides if you customize them.

- **Refactor bootstrap and namespace structure** (`fdc3c32`)
  Refactors bootstrap and namespace structure to improve project organization. Why it matters: cleaner initialization and namespacing reduces complexity and can prevent autoloading issues. User impact: minimal if autoloading works correctly. Compatibility: if external references to old namespaces/classes exist, they may break. Migration: update any custom integrations that rely on previous namespace structure.

### Tests

- **Centralize temporary directory deletion helper** (`071c465`)
  Refactors tests by centralizing a helper that deletes temporary directories. This improves test maintainability and reduces duplication, without intended functional changes to production code. User impact: none. Compatibility/migration: none.

- **Enable fileinfo extension in tests workflow for PHP 8.5** (`1df3f47`)
  Updates the GitHub Actions test workflow to enable the `fileinfo` PHP extension for PHP 8.5. Why it matters: some code paths or dependencies require `fileinfo`, and CI must mirror requirements. User impact: more reliable CI/test execution on PHP 8.5. Compatibility/migration: none beyond CI environment.

### Build

- **Add default PHPUnit configuration for Laravel package projects** (`4c4c7d7`)
  Adds a default `phpunit.xml` configuration suited for Laravel package projects. This improves out-of-the-box test configuration consistency for new/standard environments. User impact: easier contributor setup and more consistent test execution. Compatibility: may change test defaults (e.g., bootstrap/files). Migration: if you have custom PHPUnit config, reconcile it with the new defaults as needed.

- **Add Pest support and configure PHPStan memory limit** (`d5af994`)
  Adds Pest testing support and introduces PHPStan memory limit configuration. Why it matters: supports projects that prefer Pest while ensuring PHPStan has enough memory for analysis. User impact: improved test tooling flexibility and more stable static analysis runs in constrained CI environments. Compatibility/migration: if you rely on PHPUnit-only workflows, you may need to update CI scripts to use Pest (or keep PHPUnit as applicable).

- **Add release:create workflow with versionable class inspection** (`62d6f9a`)
  Adds a `release:create` workflow that includes versionable class inspection. This matters for ensuring the release tooling understands what can be versioned and updated. User impact: improved automation coverage for release creation. Compatibility: workflow addition only. Migration: none for runtime, but CI/release automation might now perform additional inspection steps.

- **Add tests.yml GitHub Actions workflow for cross-platform automation** (`bb87379`)
  Introduces a GitHub Actions workflow `tests.yml` to run the test suite across `ubuntu-latest` and `windows-latest`, installing PHP 8.5 (with `fileinfo`), dependencies via Composer, and executing tests via `composer exec pest`. User impact: improved cross-platform validation; PRs can now fail due to platform differences that previously went unnoticed. Compatibility: primarily CI behavior. Migration: ensure tests pass on both Ubuntu and Windows.

- **Add maintainer ASCII banner, init workflow, and tests; update docs/menu** (`0f3f3d8`)
  Adds the maintainer ASCII banner, an `init` workflow, and supporting tests, plus updates to documentation and workflow/menu items. Why it matters: improves onboarding (init command/workflow) and clarifies available tooling. User impact: new initialization and improved UX visuals; docs and workflow navigation changes. Compatibility: new CLI capabilities; no breaking runtime changes implied. Migration: none.

- **Add Rector, Pint, PHPStan, and testing dependencies** (`84c9f80`)
  Adds dependencies for code formatting and quality tooling: Rector, Pint, PHPStan, and related testing components. Why it matters: ensures consistent enforcement of standards and more reliable static analysis. User impact: contributors/CI may now enforce additional checks. Compatibility: tooling dependencies only. Migration: run `composer install`; ensure your development environment supports the new tooling.

### Maintenance

- **Prepare 1.0.0-beta.2 release** (`b54f95b`)
  Runs release preparation work for the 1.0.0-beta.2 development cycle. This is internal workflow/release bookkeeping and is not intended to change user-facing functionality. User impact is indirect (enables the release artifacts to be produced). No migration is expected.

- **Prepare 1.0.0-beta.1 release** (`865040c`)
  Performs internal release preparation for the 1.0.0-beta.1 cycle. This typically involves release workflow state and artifact readiness rather than code behavior changes. User impact is indirect (enables the beta release). No migration is expected.

- **Rename application executable to maintainer and update config/tests/docs** (`08be173`)
  Renames the application executable to `maintainer`, updates `box.json` configuration, and adjusts related tests and documentation. Why it matters: correct binary naming ensures the documented command works and reduces confusion for users. User impact: command name changes. Compatibility: existing instructions using the previous executable name may break. Migration: update your usage from the old executable to `maintainer` (and any automation scripts referencing the old name).

- **Update README badges for consistency and clarity** (`206e8ae`)
  Adjusts README badges to be more consistent and clearer. This is documentation-only work that improves presentation. User impact: none for runtime. Migration: none.

- **Add AI tools and configuration support** (`abd0956`)
  Adds AI-related tools and configuration support needed by the AI-assisted release/versioning features. Why it matters: without configuration support, AI features cannot be enabled reliably. User impact: enables AI functionality in workflows that depend on it. Compatibility: may require environment variables/credentials for AI providers if you use AI modes. Migration: configure AI provider settings as required by the new AI tooling.

- **Initial commit for Artisan Toolbox Maintainer application** (`72735e4`)
  Introduces the initial version of the Artisan Toolbox Maintainer application. This is the foundational baseline for subsequent features. User impact: provides the starting CLI/tooling skeleton. Compatibility: sets the initial structure. Migration: none.

- **Add `quality` workflow to run Pint, Rector, and PHPStan with project-specific configurations:** (`1203720`)
  Includes the changes introduced by commit 1203720: Add `quality` workflow to run Pint, Rector, and PHPStan with project-specific configurations:.

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
