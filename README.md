# DruxtJS

[![Pipeline](https://git.drupalcode.org/project/druxt/badges/1.2.x/pipeline.svg)](https://git.drupalcode.org/project/druxt/-/pipelines)
[![Test](https://github.com/druxt/druxt_drupal/actions/workflows/test.yml/badge.svg?branch=1.2.x)](https://github.com/druxt/druxt_drupal/actions/workflows/test.yml?query=branch%3A1.2.x)
[![Coverage](https://codecov.io/gh/druxt/druxt_drupal/branch/1.2.x/graph/badge.svg)](https://codecov.io/gh/druxt/druxt_drupal/branch/1.2.x)

A bridge between frameworks, Drupal in the back, Nuxt.js in the front.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/druxt).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/druxt).


## Table of contents

- Requirements
- Installation
- Configuration
- Features
- Cross-Origin Resource Sharing
- Maintainers


## Requirements

This module requires the following modules:

- [Decoupled Router](https://www.drupal.org/project/decoupled_router)
- [JSON:API](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module)
- [JSON:API Menu Items](https://www.drupal.org/project/jsonapi_menu_items)
- [JSON:API Views](https://www.drupal.org/project/jsonapi_views)


## Installation

DruxtJS requires a Nuxt.js frontend and a Drupal JSON:API backend.

### Drupal

1. [Install Drupal](https://www.drupal.org/docs/installing-drupal).
2. Download the Drupal [DruxtJS module](https://www.drupal.org/project/druxt):
   ```sh
   composer require drupal/druxt
   ```
3. Install the DruxtJS module.
4. Add the "**access druxt resources**" permission to a user/role.

### Nuxt.js

1. [Install Nuxt.js](https://nuxtjs.org/guide/installation/).
2. Install the Nuxt.js [DruxtJS Site module](http://npmjs.com/package/druxt-site):
   ```sh
   npm i druxt-site
   ```
3. Add the module and configuration to `nuxt.config.js`:
   ```js
   module.exports = {
     modules: [
       'druxt-site'
     ],
     druxt: {
       baseUrl: 'https://demo-api.druxtjs.org'
     }
   }
   ```


## Configuration

Once installed, DruxtJS requires no additional configuration. The "**access
druxt resources**" permission provides read-only access to all JSON:API
resources required by the DruxtJS frontend.


## Features

- A single permission for read-only access to all JSON:API resources required by DruxtJS.
- Support for Views routes via the [JSON:API Views](https://www.drupal.org/project/jsonapi_views) and [Decoupled Router](https://www.drupal.org/project/decoupled_router) modules.
- Support for Contact form routes via the [Decoupled Router](https://www.drupal.org/project/decoupled_router) module.
- Improved support for Menu items via the [JSON:API Menu Items](https://www.drupal.org/project/jsonapi_menu_items) module.
- Condition plugin bypass for Block resources.
- Enables Cross-Origin Resource Sharing (CORS) support.
- Ensures EntityViewDisplay configuration available for [DruxtSchema](https://schema.druxtjs.org) module.

## Cross-Origin Resource Sharing

A decoupled frontend runs on a different origin to Drupal, so the browser
will not read a response unless Drupal says the origin is allowed. Core ships
CORS turned off, so Druxt turns it on.

Druxt only does this when the site has not configured CORS itself. If
`cors.config.enabled` is `TRUE` in `sites/default/services.yml`, Druxt changes
nothing and the site's own values stand.

Where Druxt does apply, it fills in the three values core leaves empty:

| Setting | Druxt default |
| --- | --- |
| `enabled` | `TRUE` |
| `allowedOrigins` | core's default, `['*']` |
| `allowedHeaders` | `['*']` |
| `allowedMethods` | `['*']` |

`allowedMethods` matters more than it looks. A browser sends a preflight for any request
that is not a simple one, which means every write, and every read carrying an
`Authorization` header. Preflight asks whether the method is allowed, and an
empty list answers no, so the request never happens. A site with the list
empty works for anonymous reads and fails for everything else.

These defaults let any origin call the site. That is the right default for
getting a frontend talking to Drupal, and the wrong one for production. Set
`cors.config` in `sites/default/services.yml` to narrow it:

```yaml
parameters:
  cors.config:
    enabled: true
    allowedHeaders: ['authorization', 'content-type']
    allowedMethods: ['GET', 'POST', 'PATCH', 'DELETE']
    allowedOrigins: ['https://frontend.example.com']
    supportsCredentials: true
```

Setting `enabled: true` takes the whole thing out of Druxt's hands, so every
value above is then yours to maintain.

### Upgrading

There is nothing to migrate. The defaults are applied to the service
container when it is built, not stored in configuration, so no update hook
touches them and nothing you have saved changes.

They do need the container rebuilt to take effect, which the 1.3.0 update
does anyway: `druxt_update_10301()` means `drush updatedb` or `update.php`
has to run, and that rebuilds the container.

One case is not fixed by upgrading. A site that already set
`cors.config.enabled: true` with an empty `allowedMethods` is skipped by
Druxt, before and after, so its preflighted requests keep failing. Add
`allowedMethods` to that site's own `services.yml`.


## Maintainers

- Stuart Clark - [Deciphered](https://www.drupal.org/u/deciphered)
