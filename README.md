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

Maintainer is open-sourced software licensed under the MIT license.
