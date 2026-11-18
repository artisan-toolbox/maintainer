---
name: maintainer-development
description: Use Artisan Toolbox Maintainer in an existing Composer project to configure its CLI, run local or CI quality checks, or integrate its versioning contracts. Apply to release and deployment workflows only when the user requests Maintainer for that task.
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

## Choose a workflow

- [Install and verify the CLI](https://atb.dev.br/docs/maintainer/installation)
- [Publish project configuration](https://atb.dev.br/docs/maintainer/configuration/publishing)
- [Run quality checks](https://atb.dev.br/docs/maintainer/ci/quality)
- [Integrate version and README metadata](https://atb.dev.br/docs/maintainer/versioning/project-integration)
- [Look up command options](https://atb.dev.br/docs/maintainer/commands)

Check the exact command help before preparing a release or deploying. Keep publication, remote deployment, credentials, and target selection within the user's requested scope.
