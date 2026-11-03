# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## Overview

DruxtJS is a Drupal module that bridges Drupal's JSON:API backend with a
[Nuxt.js](https://nuxtjs.org) frontend. It provides a permission for
read-only access to all JSON:API resources required by the
[DruxtJS](https://druxtjs.org) frontend framework, plus route translation
support for Views, Contact forms, and wildcard paths via the Decoupled Router
module.

## Development Commands

**HARD RULE - use the provided command wrappers, never the tool binaries directly.** When `make` exposes a command for a task, use that command; do not call the underlying binary directly. Each wrapper runs the tool where CI runs it, in `build/` for most tools, with the config, plugins, and environment that CI uses, so a raw invocation from the repository root silently diverges from CI - it can pass locally while CI fails (or vice versa), or crash outright when a relative path resolves against the wrong directory. If no wrapped command covers what you need, extend the `make` target rather than making a one-off raw call; if that is not feasible, stop and ask.

**HARD RULE - never modify `scripts/eddy-tooling`.** It comes from Eddy as-is, and a scaffold update replaces it with the release's copy. To change what a tooling command does, declare a patch for the `drevops/eddy-tooling` package in `composer.dev.json`.

Run each tool through its `make` wrapper, never the binary directly:

- **PHPCS / PHPCBF**: `make lint` / `make lint-fix` - never `vendor/bin/phpcs` or `vendor/bin/phpcbf`.
- **PHPStan**: `make lint` - never `vendor/bin/phpstan`.
- **Rector**: `make lint` (dry-run) / `make lint-fix` - never `vendor/bin/rector`.
- **ESLint / Stylelint**: `make lint` / `make lint-fix` - never `npx eslint` or `npx stylelint`.
- **CSpell**: `make lint` - never `npx cspell`.
- **PHPUnit**: `make test` / `make test-unit` / `make test-kernel` / `make test-functional` - never `vendor/bin/phpunit`.
- **Jest**: `make test-javascript` - never `npx jest`.
- **Drush**: `make drush <command>` - never `build/vendor/bin/drush` directly.

### Build and Environment Management

**Using Make:**
- `make build` - Complete build (assemble → start → provision)
- `make assemble` - Assemble codebase with dependencies
- `make start` - Start PHP development server
- `make stop` - Stop development server
- `make provision` - Install/provision Drupal site
- `make reset` - Clean build directory, logs and installed tooling (aliases: `make delete`, `make destroy`)

### Code Quality

**Linting:**
- `make lint` - Run all linting tools
- `make lint-fix` - Auto-fix coding standards violations

**Testing:**
- `make test` - Run all tests
- `make test-unit` - Run unit tests only
- `make test-kernel` - Run kernel tests only
- `make test-functional` - Run functional tests only
- `make test-javascript` - Run JavaScript unit tests with Jest (alias: `make test-js`)

### Drupal Commands

- `make drush <command>` - Run Drush commands. Put `--` before the arguments when they include an option, such as `make drush -- config:get system.site --format=json`, because `make` reads an option on its command line as its own. An argument can't hold a space or name a `make` target, such as `help`, unless it's an option value joined with `=`
- `make login` - Get one-time login link

### Diagnostics

- `make info` - Print a read-only summary of PHP/Drupal/Composer/Drush/Node/npm versions, webserver host/port (with source), site URL, XDebug state, build directory, database path, and active profile. (alias: `make describe`)

## Project Structure

**Key Files:**
- `druxt.module` - Hook implementations (permissions, CORS)
- `druxt.install` - Install/uninstall hooks (CORS defaults)
- `druxt.services.yml` - Service definitions (event subscribers)
- `src/DruxtServiceProvider.php` - Service provider overrides
- `src/EventSubscriber/ViewsPathTranslatorSubscriber.php` - Views route translation
- `src/EventSubscriber/WildcardPathTranslatorSubscriber.php` - Wildcard route translation
- `src/EventSubscriber/ContactPathTranslatorSubscriber.php` - Contact form route translation
- `src/Plugin/Condition/DruxtRequestPath.php` - Condition plugin for Druxt requests
- `tests/src/Functional/` - Functional tests (condition bypass, CORS, permissions, EntityViewDisplay)
- `build/` - Assembled Drupal codebase (symlinked extension, gitignored)
- `vendor/` - The installed `drevops/eddy-tooling` package, whose commands (`vendor/bin/eddy-*`) assemble, start, provision and deploy the extension. Not committed and not part of the extension
- `scripts/eddy-tooling` - Installs the tooling package into `vendor/` from the constraint in `composer.dev.json`. The command wrappers and CI run it before the tooling commands
- `scripts/` - Custom post-start (`start-*.sh`), post-provision (`provision-*.sh`), and pre-stop (`stop-*.sh`) hooks. Run automatically at the end of each phase in lexicographic order. A non-zero exit aborts the parent. Excluded from distribution archives via `.gitattributes`

## Architecture

- **Route translation**: Event subscribers integrate with the Decoupled Router
  module to translate Views, Contact form, and wildcard paths for the DruxtJS
  frontend.
- **Resource access**: The `access druxt resources` permission gates
  read-only access to all JSON:API resources the frontend needs.
- **Condition bypass**: Block condition plugins are bypassed for Druxt requests
  so all blocks are available to the frontend.
- **CORS**: Cross-Origin Resource Sharing is enabled with sensible defaults
  for frontend consumption.
- **EntityViewDisplay**: Ensures configuration is available for the
  DruxtSchema module.

## Environment Variables

- `DRUPAL_VERSION` - Target Drupal version (e.g., `10`, `11`, `11@alpha`, `12@beta`). Defaults to `extra.eddy.drupal-version` in `composer.dev.json`
- `WEBSERVER_HOST` - Development server host (default: localhost)
- `WEBSERVER_PORT` - Development server port. Auto-discovered from range 8000-8099 and written to `.env` if not already set
- `WEBSERVER_WAIT_TIMEOUT` - Seconds that `start` waits for the development server to accept connections (default: 5)
- `DRUPAL_PROFILE` - Install profile that `provision` installs the site from (default: `standard`)
- `XDEBUG` - Set to `1` to start the development server with XDebug step-debugging
- `CLOUDFLARE_TUNNEL` - Set to `1` to expose the development server through a public Cloudflare quick tunnel. `start` writes the tunnel URL to `.env` as `TUNNEL_URL`
- `LOGIN_QRCODE` - Set to `1` to also render the `login` link as a terminal QR code (needs `qrencode`)
- `GITHUB_TOKEN` - GitHub API token to avoid rate limits
- `DEBUG` - Set to `1` to stream the full output of the underlying commands (Composer, npm, Drush). By default this output is suppressed and shown only when a command fails

## Development Workflow

1. Build environment: `make build`
2. Develop DruxtJS code in `src/`
3. Check standards: `make lint`
4. Run tests: `make test`
5. Access site at the URL that `make info` reports

## Code Quality Tools

- **CSpell**: Spell checking across the codebase (config at `.cspell.json`)
- **PHPCS**: Drupal, DrupalPractice, DrevOps and PHPCompatibility standards
- **PHPStan**: Static analysis with Drupal extensions
- **Rector**: Automated refactoring and deprecation fixes
- **ESLint**: JavaScript linting with Prettier formatting (config at `.eslintrc.json`)
- **Stylelint**: CSS linting (config at `.stylelintrc.js`)

## CI/CD Support

- **GitHub Actions**: `.github/workflows/test.yml` (lint + matrix test)
- **GitLab CI**: `.gitlab-ci.yml` for Drupal.org CI
- **Matrix testing**:
  - Drupal 10 on PHP 8.3-8.4
  - Drupal 11 on PHP 8.3-8.5

## Important Notes

- The `build/` directory contains the assembled Drupal site
- Extension files are symlinked from root into `build/web/modules/custom/`
- SQLite database created in `/tmp/site_druxt.sqlite`
- The PHP and JavaScript quality tools run from within the `build/` directory
- CSpell runs from the project root

## Updating the scaffold

When the user asks to update this project's scaffold (e.g. "update scaffold" or "update Eddy"), fetch the update skill from GitHub into the local `.claude/skills/` directory, then invoke it:

1. Create the target directory if it does not exist:

   ```bash
   mkdir -p .claude/skills/update-consumer-eddy
   ```

2. Download the skill:

   ```bash
   curl -fsSL -o .claude/skills/update-consumer-eddy/SKILL.md \
     https://raw.githubusercontent.com/drevops/eddy/main/.eddy/skills/update-consumer-eddy/SKILL.md
   ```

3. Invoke the `update-consumer-eddy` skill and follow its steps.

The skill directory is git-ignored - it is fetched on demand and not committed to the project.
