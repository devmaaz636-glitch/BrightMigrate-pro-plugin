=== BrightMigrate Pro ===
Contributors: brightideascommunication
Tags: migration, backup, export, import
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0admin
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full-site backup, content-only transfer, and theme-kit migration with resumable chunked archives and no plugin-imposed size limit.

== Description ==

BrightMigrate Pro is a WordPress migration plugin for Bright Ideas Communication.

* Full site export and import of the database and wp-content into a `.bmx` archive.
* Content packs that move pages, posts, media, and SEO data into a new theme.
* Theme kits that move the design layer onto a site where publishing continues.
* Chunked, resumable processing for large sites on shared hosting.

Only administrators can export, import, download, or change settings. On a network admin screen the required capability is `manage_network_options`. On a site admin screen it is `manage_options`.

Archives are stored in a private folder with a random suffix. Downloads are not served directly from that folder.

== Installation ==

1. Place this plugin in `wp-content/plugins/brightmigrate-pro/`. The main file is `brightmigrate-pro.php`.
2. Activate it from Plugins, or network-activate it on multisite.
3. Open BrightMigrate in the admin menu.

PHP 7.4 or newer, WordPress 6.2 or newer, and the `zlib`, `json`, and `mysqli` extensions are required. `zip`, `mbstring`, and `openssl` are recommended.

Development checks use Composer and npm:

`composer install`
`composer test`
`composer phpcs`
`npm install`
`npm run lint:js`
`npm run lint:css`

== Frequently Asked Questions ==

= Who can run an export or import? =

Only administrators. No other role can see the menu or trigger an action.

= Is there a file size limit? =

The plugin does not set one. The limits are disk space and the host.

= Are archives deleted on uninstall? =

Only if Settings has "Delete stored archives when this plugin is uninstalled" turned on. Tables and settings are always removed.

== Changelog ==

= 1.0.0 =
* Phase 1 scaffold: lifecycle, protected storage, administrator-only admin screens, and settings.

== Upgrade Notice ==

= 1.0.0 =
Initial development build.
