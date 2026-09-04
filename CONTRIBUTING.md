# Contributing

Thank you for considering contributing to this project! Every contribution is welcome and helps improve the quality of the project. To ensure a smooth process and maintain high code quality, please follow the steps below.

## Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/)

## Preparation

```bash
# Clone repository
git clone https://github.com/konradmichalik/typo3-page-metrics.git
cd typo3-page-metrics

# Start the project with DDEV
ddev start

# Install dependencies
ddev composer install
```

## Run linters and static code analysis

```bash
# Everything
ddev cgl

# Specific tools
ddev cgl lint:php
ddev cgl fix:php
ddev cgl sca:php
```

## Run tests

```bash
# Unit tests
ddev composer test:unit

# Functional tests
ddev composer test:functional

# Both suites with merged code coverage (needs a coverage driver)
ddev xdebug on
ddev composer test:coverage
ddev xdebug off
```

`test:coverage` runs the unit and functional suites separately and merges
the two results, because neither alone describes the extension. It writes
`.Build/coverage/clover.xml` plus the shields.io endpoint JSON behind the
README badge, and lists every file under `Classes/` that is not fully
covered.

The README's Coverage badge is only updated by CI on a push to `main` (and
only once the `GIST_TOKEN` secret is configured) — until then, or on a
pull request, it renders as "invalid" rather than a percentage. That is
expected, not a broken pipeline.

## TYPO3 setup

For testing the extension against a real backend, you need to set up the TYPO3 instances.

```bash
# Install all TYPO3 versions supported by the extension
ddev install all

# Or install a specific version
ddev install 13
ddev install 14

# Open the overview page
ddev launch

# Run TYPO3-specific commands
ddev 13 typo3 cache:flush
ddev all typo3 database:updateschema
```

## Submit a pull request

After completing your work, **open a pull request** and provide a description of your changes. Ideally, your PR should reference an issue that explains the problem you are addressing.
