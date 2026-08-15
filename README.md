<div align="center">
    <h1>Maintainer</h1>
</div>

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

The initial configuration is an empty JSON object. Configuration options will be added as their corresponding workflows are introduced:

```json
{}
```

Maintainer commands and services can read configuration values with dot notation and optional defaults:

```php
$level = maintainer_config('quality.phpstan.level', 8);
$configuration = maintainer_config();

if (maintainer_config_missing()) {
    // Ask the user to initialize Maintainer.
}
```

For dependency-injected code, use `MaintainerConfiguration` directly:

```php
use App\Support\MaintainerConfiguration;

final readonly class QualityWorkflow
{
    public function __construct(
        private MaintainerConfiguration $configuration,
    ) {}

    public function run(): void
    {
        $level = $this->configuration->get('quality.phpstan.level', 8);
    }
}
```

Configuration values are cached for the lifetime of the process. Call `refresh()` when a workflow changes `maintainer.json` and needs to read the updated values immediately. Missing files are treated as an empty configuration, while invalid JSON raises an exception with an actionable message.

## Project Integration

Maintainer exports lightweight PHP contracts through the consuming project's Composer autoloader. Project-specific integrations can implement these contracts without loading the Laravel Zero runtime used by the PHAR:

```php
<?php

namespace App\Maintainer;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

final class ApplicationVersion implements Versionable
{
    // Project-specific behavior will be defined by the contract.
}
```

Contract implementations are designed to run within the consuming project and communicate structured data to the isolated Maintainer process. Objects and framework services will not cross the process boundary. The bridge transport that discovers and invokes implementations will be introduced with the first project operation.

Because Maintainer is normally installed as a development dependency, project integrations should also be development-only. If production code implements a Maintainer contract, install the package as a regular dependency so the interface remains available after `composer install --no-dev`.

## Development

Clone the repository and install its dependencies:

```bash
composer install
```

List the available commands:

```bash
php application list
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
php application app:build maintainer
```

The compiled application is written to `builds/maintainer`. This PHAR archive is the executable distributed through Composer as `vendor/bin/maintainer`.

## Contributing

Contributions should include automated tests for observable behavior and keep the documentation synchronized with any new or changed commands.

### Development Conventions

Prefer native PHP attributes whenever the framework or library provides an attribute equivalent to legacy metadata properties or configuration. For example, console commands should use Laravel's `Signature` and `Description` attributes instead of the `$signature` and `$description` properties. Use the legacy form only when no compatible attribute is available.

## License

Maintainer is open-sourced software licensed under the MIT license.
