# Decisions

These are the choices made where the specification was silent or where a WordPress rule and another instruction had to be reconciled. Later phases should follow them unless a decision is revised here.

## Repository layout

The plugin files live at the repository root. When the plugin is installed, the folder must be named `brightmigrate-pro` and the bootstrap file is `brightmigrate-pro.php`.

## Namespace and coding standards

Classes use the namespace `BrightIdeas\Migrate` and Composer PSR-4 (`src/Plugin.php`). PHP_CodeSniffer rule `WordPress.Files.FileName` is excluded because it expects `class-plugin.php`, which cannot autoload under PSR-4.

Global names use `brightmigrate_` or `bmx_` / `BMX_`. The short prefix `bmx` is not used on its own because PHPCS rejects prefixes shorter than four characters. The capability check is not filterable, so another plugin cannot widen access to archives.

Guard files, logs, and later archive IO use `fopen`, `fread`, and `fwrite`. `WordPress.WP.AlternativeFunctions` is excluded for `Storage.php`, `Logger.php`, and the test suite because `WP_Filesystem` can demand FTP credentials and is the wrong tool for streaming multi-gigabyte files. The test suite also excludes `WordPress.PHP.DevelopmentFunctions` because it runs outside WordPress.

## Who can use the plugin

Site admin (`wp-admin`) requires `manage_options`. Network admin (`wp-admin/network`) requires `manage_network_options`. A subsite administrator can open BrightMigrate for that site. A super admin uses the network screen for network-wide work. No other role is accepted, including on REST routes added in later phases.

## Tables

Custom tables are per site: `{$wpdb->prefix}brightmigrate_jobs`, `brightmigrate_job_log`, `brightmigrate_backups`, and `brightmigrate_id_map`. Network activation installs them in batches of 5 sites, stopping after 15 seconds, then continues with WP-Cron (`brightmigrate_install_sites_batch`) or the next admin request. Action Scheduler is not bundled until the engine phase, so this queue uses WP-Cron.

`id_map` has a surrogate `id` plus a unique key on `(job_id, object_type, old_id)`. Job columns use the names from the specification (`type`, `options`, `error`). Backup size is `bigint(20) unsigned` so the index is not capped at 2 GB. Datetime defaults use `0000-00-00 00:00:00` because `dbDelta` expects that pattern; WordPress removes `NO_ZERO_DATE` from the session SQL mode.

## Storage

The default folder is `wp-content/brightmigrate-{16 hex}/`, not inside the plugin and not inside `uploads`. A random suffix is stored in `brightmigrate_storage_suffix`. Each site has its own suffix.

A custom storage location must be an absolute path. It is rejected when it contains `..`, a null byte, a stream wrapper, a UNC path, or an illegal character; when it is the WordPress root, the `wp-content` root, or anything inside plugins, themes, must-use plugins, or uploads; and when it is inside `ABSPATH` but outside `wp-content`. A path outside the web root is allowed. The parent directory must already exist. Symlinks are not used as the storage directory and are not followed when a folder is deleted.

The folder gets `index.php`, `.htaccess` (`Require all denied` and `Deny from all`), and `web.config`. Nginx ignores `.htaccess`, so the server block must deny the folder as well.

Dashboard storage size stats at most 100 entries and two directory levels. It does not walk a multi-gigabyte tree during a page view. Jobs will keep an exact counter later.

## Settings

All settings live in one array option, `brightmigrate_settings`, with autoload off. Keys are allow-listed:

- `storage_location` (empty means the default private folder)
- `upload_chunk_mb` (`0` means auto, otherwise clamped to 2–10)
- `retention_count` (`0` means keep all, maximum 10000)
- `notify_email`
- `delete_archives` (`1` or `0`, default `0`)

The clamps limit one request. They are not an archive size cap. Site admin saves through the Settings API (`options.php`). Network admin posts to `network_admin_edit_brightmigrate-settings` because `options.php` is not available there. Both paths use the same sanitizer. Invalid email or path values keep the previous value.

Retention cleanup and notification email are stored now and enforced when jobs exist. Uninstall already honors `delete_archives`.

## Front end and upgrades

The `plugins_loaded` callback returns immediately on front-end requests. No assets, queries, or hooks are added. Admin, WP-Cron, and WP-CLI boot the plugin. REST routes are not registered in this phase; they will boot on `rest_api_init` when they are added.

Schema upgrades run for the current site on admin, cron, and CLI requests by comparing `brightmigrate_db_version`. The database version may autoload because it is a short string. Large settings do not.

## Uninstall

`uninstall.php` drops the four tables, deletes options and transients whose names start with `brightmigrate_`, and clears the install cron hook. Archive files are deleted only when `delete_archives` is `1`. Deletion refuses filesystem roots and does not follow symlinks. Multisite uninstall loops sites in pages of 50. WordPress only runs `uninstall.php` once, so the loop has to finish in that request; it drops tables and unlinks files, and it does not read archive contents.

## Errors

Exceptions on admin screens are logged as one JSON line in the protected `logs` folder and the screen shows a reference ID (`bmx-` plus 12 hex characters). The log is append-only. Context keys `password`, `pass`, `pwd`, `archive_password`, `secret`, and `token` are redacted. If the log folder cannot be written, PHP's error log receives the reference only, not the original message.

## Updates and compatibility

The plugin header `Update URI` is `https://updates.example.com/brightmigrate-pro`. `example.com` is a placeholder. Replace it with the real update server before release. The update client is a later phase and does not call the network yet.

`readme.txt` says tested up to WordPress 7.1, which is the current stable series (7.1.3 as of 6 October 2026). This phase has not been activated inside a WordPress install yet. Requires at least remains 6.2. Requires PHP remains 7.4.

`Network: true` is set so the plugin can be network-activated. New sites on a network-activated install get tables through `wp_initialize_site`.

## Deactivation

Deactivation clears the install cron hook and leaves tables, settings, and archives in place.

## Byte counts

Sizes are handled as floats. A 32-bit PHP build can display a size above 2 GB. Archive offsets above 2 GB stay unsupported on 32-bit PHP; the dashboard warns when `PHP_INT_SIZE` is 4.

## Archive format

Version 1 is documented in `docs/archive-format.md`. The manifest and database dump use AES-256-GCM in 512 KB chunks. The key is PBKDF2-HMAC-SHA256 with 100000 iterations and a 16-byte salt stored in the header. The password is discarded after the key is derived and is never written into the archive.

File payloads stay plaintext unless the caller sets `encrypt_files`. Encrypting multi-gigabyte theme and upload trees with a per-chunk GCM frame is optional because the streaming copy is simpler and the sensitive export is the database. The manifest is always encrypted when a password is set, so paths and checksums are covered either way.

Manifest sizes that depend on the manifest's own length are 20-digit strings. The writer streams the manifest twice; the second pass does not change its length. The manifest is newline-delimited JSON: one summary line, then one entry per line. It is never decoded as a single document.

Split archives break between entries. A single entry larger than the split size stays in one part. Part files are `name.001.bmx`. Earlier parts are not reopened to store a final part count.

Executable blocking is a content-pack rule. A full-site extract may contain PHP. A content pack may contain it only under themes, plugins, and must-use plugins.

The writer remembers a hash of each archive path so the same path cannot be added twice. That set is the one place path identity stays in memory. File bytes are still copied in 512 KB buffers.

## Export screen

The full-site screen follows the same flow as a one-file migration tool: find and replace, exclude checkboxes, one Export to File button, a progress dialog, then download. A new export is a `.wpress` file, the same container All-in-One WP Migration uses: a 4377-byte header per file, then the raw bytes, then an empty end block. Older `.bmx` files still download, delete, and import. Cloud storage, SQLite, and another product's branding are not part of this plugin.

Each browser request works for about eight seconds, then the writer stops and the next request continues the same `.wpress` file. The archive password is not written into a `.wpress` file or into `state.json`. Job files live under `storage/exports/{id}/` and are removed when the export finishes, fails, or is stopped. The published `.wpress` stays in the storage root. An unfinished `.wpress.partial` file is deleted if the export fails or is stopped.

A full export is this site's prefixed tables plus `wp-content`. The database is stored as `database.sql`, and `package.json` records the site address. WordPress core directories are not copied. Cache directories, `node_modules`, `.git`, and known backup folders are always skipped, as is BrightMigrate's own storage folder. BrightMigrate's own tables are left out of the database dump.

Import accepts a `.wpress` or `.bmx` upload in steps sized from the server and the upload setting, and shows the archive summary. It does not replace the current site. A failed step is tried up to three times. The finished archive has no size cap: `Limits::archive_cap()` is 0. One HTTP step is at most 10 MB, and smaller when the server cannot accept that much in one request.

## Development tools

Runtime code has no Composer dependencies. `vendor/autoload.php` is used when present; otherwise `Autoloader` loads `src/`. PHPUnit 9.6 keeps PHP 7.4 coverage. `composer.json` sets `platform.php` to 7.4.33 so development packages stay compatible with the minimum PHP version.
