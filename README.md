# Integrate Rybbit

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/integrate-rybbit?style=flat-square)](https://wordpress.org/plugins/integrate-rybbit/)
[![WordPress Plugin Required PHP Version](https://img.shields.io/wordpress/plugin/required-php/integrate-rybbit?style=flat-square)](https://wordpress.org/plugins/integrate-rybbit/)
[![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/integrate-rybbit?style=flat-square)](https://wordpress.org/plugins/integrate-rybbit/)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/maki-it/rybbit-wordpress-plugin?style=flat-square)](https://github.com/maki-it/rybbit-wordpress-plugin/releases/latest)
[![CI](https://img.shields.io/github/actions/workflow/status/maki-it/rybbit-wordpress-plugin/ci.yaml?branch=main&style=flat-square&label=CI)](https://github.com/maki-it/rybbit-wordpress-plugin/actions/workflows/ci.yaml)

WordPress plugin to add and manage the [Rybbit](https://rybbit.com) tracking script on your sites.
Rybbit is an open source and privacy-focused web analytics platform.

Get the plugin from the [WordPress.org plugin directory](https://wordpress.org/plugins/integrate-rybbit/).

## Features

- Works with Rybbit Cloud and with self-hosted Rybbit instances.
- Do not track selected user roles. Administrators are excluded by default.
- Optional tracking inside wp-admin. It is off by default.
- Load the script with `defer` (recommended) or `async`.
- Skip patterns and mask patterns for URL paths, with wildcards (`*`, `**`) and regular expressions (`re:` prefix).
- Add a custom tag to all events.
- Optional user identification for logged-in users, in pseudonymized or full mode.
  User ID strategies: WordPress scoped ID, keyed hash (HMAC), user ID, login, email or a user meta field.
- Session replay privacy settings: block, ignore and mask elements, mask inputs, sampling and DOM slimming.
- Debug tab with the effective settings and the generated script attributes.
- Developer filters: `integrate_rybbit_should_track`, `integrate_rybbit_script_attributes`,
  `integrate_rybbit_identify_user_id` and `integrate_rybbit_identify_traits`.
  See [readme.txt](integrate-rybbit/readme.txt) for details and an example.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer

## Installation

The recommended way is the WordPress.org plugin directory:

1. In wp-admin, go to `Plugins` > `Add New`.
2. Search for "Integrate Rybbit", then click `Install Now` and `Activate`.

To install a release from GitHub:

1. Download `integrate-rybbit.zip` from the [latest release](https://github.com/maki-it/rybbit-wordpress-plugin/releases/latest).
2. In wp-admin, go to `Plugins` > `Add New` > `Upload Plugin`.
3. Select the zip file, click `Install Now` and then `Activate Plugin`.

## Configuration

1. Go to `Settings` > `Integrate Rybbit`.
2. Enter your Rybbit Site ID.
3. If you host Rybbit yourself, set the Script URL to `https://your-rybbit-domain/api/script.js`.
4. Save the settings.

All other settings are optional.

## Screenshots

![Tracking tab](assets/screenshot-1.png)

More screenshots are in the [assets](assets/) folder.

## Migration from Rybbit Analytics

If you previously used this plugin under the name "Rybbit Analytics", you can safely uninstall that version after activating this new plugin.
You need to re-enter your Site ID in the new plugin settings, as the configuration does not carry over automatically.

## Development

All PHP and WordPress tools run in Docker. You only need Docker (with Compose), `make` and `zip` on your computer.

```sh
make help          # Show all targets.
make check         # Run all checks: lint, phpcs and plugin-check.
make dev           # Start WordPress with the plugin active.
make down          # Stop WordPress.
```

| Target              | Description                                                                          |
|---------------------|--------------------------------------------------------------------------------------|
| `make check`        | Runs `lint`, `phpcs` and `plugin-check`. CI runs the same targets.                   |
| `make lint`         | Checks the PHP syntax with PHP 7.4 to 8.5. Use `PHP_VERSIONS="8.4"` for one version. |
| `make phpcs`        | Checks the coding standards (WordPress Coding Standards and PHPCompatibilityWP).     |
| `make phpcbf`       | Fixes coding standard issues that can be fixed automatically.                        |
| `make dev`          | Starts WordPress at http://localhost:8080 with the plugin active (`admin` / `admin`). |
| `make plugin-check` | Runs the official [Plugin Check](https://wordpress.org/plugins/plugin-check/).       |
| `make pot`          | Generates `build/integrate-rybbit.pot` to review the translatable strings.          |
| `make screenshots`  | Retakes the screenshots in `assets/` with a headless browser in Docker.             |
| `make logs`         | Shows the WordPress debug log.                                                       |
| `make down`         | Stops the development environment.                                                   |
| `make clean`        | Stops the development environment and deletes all data and build files.             |
| `make zip`          | Builds `integrate-rybbit.zip` for a manual upload.                                   |

Notes:

- The plugin folder is mounted read-only into the WordPress container. Changes are visible after a page reload.
- Use `WP_PORT=8081 make dev` if port 8080 is in use.
- Use `WP_IMAGE_TAG=6.5 make dev` to test with an older WordPress version.
  Start with `make clean` when you change the WordPress version.
- `make screenshots` overwrites the plugin settings of the development site with example values.
- The settings are stored with the option prefix `rybbit_`.
  The plugin migrates old settings on update (option `integrate_rybbit_db_version`).

### Project structure

```
integrate-rybbit/        The plugin. Only this folder is deployed to WordPress.org.
assets/                  Icons and screenshots for WordPress.org.
tools/                   Docker setup for the checks and the development environment.
phpcs.xml.dist           PHPCS rules.
.github/workflows/       CI and release workflows.
```

## Release process

1. Make sure that `Tested up to` in [readme.txt](integrate-rybbit/readme.txt) is the current WordPress version.
   Plugin Check fails when a new major WordPress version is available and this value is older.
2. Publish a GitHub release with a new tag `vX.Y.Z`. The release notes become the changelog on WordPress.org.
3. The [release workflow](.github/workflows/release.yaml) does these steps:
   - Sets the version in the plugin header, the `INTEGRATE_RYBBIT_VERSION` constant and the `Stable tag`.
   - Writes the notes of the last 10 GitHub releases into the changelog of `readme.txt`.
   - Runs all checks.
   - Deploys the plugin and the assets to WordPress.org.
   - Attaches `integrate-rybbit.zip` to the GitHub release.

Pre-release tags (for example `v1.8.0-rc.1`) are not deployed to WordPress.org. Mark these releases as pre-release on GitHub.

The version in the repository is always `0.0.0`. Do not change it manually.

## Legal

This is a free and open-source WordPress plugin developed independently for integration with Rybbit
and licensed under the [GNU General Public License v3 or later](LICENSE).

The use of the Rybbit name and logo in this plugin has been explicitly approved by the owner of Rybbit.
All Rybbit trademarks, logos, and brand assets remain the property of their respective owner.

This plugin is not an official Rybbit product and is not otherwise affiliated with or endorsed by Rybbit.
