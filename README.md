<div align="center">
    <h1>Artisan Toolbox Maintainer</h1>
</div>

<p align="center">
    A command-line application for maintaining, validating, and releasing Artisan Toolbox packages.
</p>

## About

Artisan Toolbox Maintainer provides a single entry point for the repetitive tasks involved in maintaining the projects in the Artisan Toolbox organization.

The application is intended to coordinate tasks such as:

- running automated tests, linters, formatters, and static analysis;
- validating packages before changes are committed or released;
- creating consistent commits, tags, changelogs, and GitHub releases;
- reducing duplicated maintenance scripts across repositories;
- providing a predictable release workflow for maintainers.

Maintainer is built with [Laravel Zero](https://laravel-zero.com/), a lightweight framework for console applications.

## Status

Maintainer is currently under development. Its commands and public behavior may change while the initial workflows are being established.

## Requirements

- PHP 8.3 or later
- Composer
- Git
- GitHub CLI for workflows that interact with GitHub

Additional tools may be required by the package being validated.

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
php application app:build
```

The compiled application is written to the `builds` directory.

## Contributing

Contributions should include automated tests for observable behavior and keep the documentation synchronized with any new or changed commands.

## License

Artisan Toolbox Maintainer is open-sourced software licensed under the MIT license.
