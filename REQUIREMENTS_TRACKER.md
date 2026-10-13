# Requirements tracker

Status values: `todo`, `done`, `tested`.

`tested` means an automated test covers the behaviour, or a WordPress runtime check was recorded. Phase 1 tests cover path rules, schema identifiers, settings sanitizing, requirement codes, and the plugin header. They do not boot WordPress.

## M1 — Full site export and import

| ID | Priority | Requirement | Status |
| --- | --- | --- | --- |
| M1-01 | Must | Export all site-prefix tables and wp-content into one .bmx | done |
| M1-02 | Must | Exclude checkboxes (media, themes, inactive themes, plugins, inactive plugins, must-use plugins, cache, spam comments, revisions, database) | done |
| M1-03 | Must | Custom exclusion list | todo |
| M1-04 | Should | Find and replace pairs at export, serialisation-safe | tested |
| M1-05 | Must | Always auto-exclude own storage, other backup folders, node_modules, .git, cache directories | done |
| M1-06 | Must | Live progress with stage, percentage, files, MB, time remaining, aria-live | done |
| M1-07 | Must | Cancel deletes partial files | done |
| M1-08 | Must | Download, copy expiring signed link, keep on server | done |
| M1-09 | Should | Optional AES-256 password for the database dump and manifest | done |
| M1-10 | Must | Import from chunked upload, storage file, or remote URL | todo |
| M1-11 | Must | Show the manifest before import | done |
| M1-12 | Must | Overwrite warning with typed or ticked confirmation | todo |
| M1-13 | Should | Safety database snapshot before overwrite, can be disabled | todo |
| M1-14 | Must | Serialisation-safe URL and path replacement, including JSON-escaped variants | todo |
| M1-15 | Must | Preserve the admin session, then prompt to save permalinks | todo |
| M1-16 | Must | Regenerate Elementor CSS, flush rewrite rules, clear known caches | todo |
| M1-17 | Could | Read-only import of All-in-One WP Migration .wpress files | tested |

Full-site export runs from the Export screen in short requests. It writes one `.wpress` under storage and lists it on Backups. Older `.bmx` files stay in that list. Find/replace is covered by `SearchReplaceTest`. The `.wpress` header and a short write/read round trip are covered by `WpressTest`. Suspend and resume of an unfinished `.bmx` archive is covered by `ArchiveRoundTripTest`. There is no WordPress boot test for the database dump, so M1-01 is `done` rather than `tested`.

Chunked upload stores a `.wpress` or `.bmx` file and shows its summary. Choosing a file already in storage, a remote URL, and replacing this site are not built yet, so M1-10 and M1-12 stay `todo`. Content packs and theme kits do not export.

## M2 — Content pack

| ID | Priority | Requirement | Status |
| --- | --- | --- | --- |
| M2-01 | Must | Selection screen with filters and select all / individually | todo |
| M2-02 | Must | Include all pages and all posts in one click | todo |
| M2-03 | Must | Collect only used media; thumbnails or originals | todo |
| M2-04 | Should | Option to include the entire media library | todo |
| M2-05 | Must | Detect the active SEO plugin, package its meta, and store a neutral copy | todo |
| M2-06 | Must | Store the full source permalink of every item | todo |
| M2-10 | Must | Never overwrite the active theme, plugins, menus, widgets, or site settings | todo |
| M2-11 | Must | Same slug and hierarchy; Skip / Overwrite / Duplicate | todo |
| M2-12 | Must | Preserve publish and modified dates | todo |
| M2-13 | Must | Import media on the original year/month path and file name; dedupe by hash | todo |
| M2-14 | Must | ID maps and reference rewriting | todo |
| M2-15 | Must | Serialisation-aware domain replacement in content, meta, and Elementor JSON | todo |
| M2-16 | Should | SEO plugin mapping and an unmapped-fields report | todo |
| M2-17 | Must | Missing page template falls back to default and is listed | todo |
| M2-18 | Must | Dry-run before writing | todo |
| M2-19 | Must | SEO Integrity Report as CSV | todo |

## M3 — Theme kit

| ID | Priority | Requirement | Status |
| --- | --- | --- | --- |
| M3-01 | Must | Export checkboxes per component and a design-page picker | todo |
| M3-02 | Must | Exclude other pages, all posts, and non-design media by default | todo |
| M3-03 | Must | Never delete or modify existing pages and posts that are not in the kit | todo |
| M3-04 | Must | Design-page conflicts: replace design only, draft, or skip | todo |
| M3-05 | Must | Re-apply Elementor display conditions and Theme Builder assignments | todo |
| M3-06 | Must | Offer to set the front page and posts page when Home is imported | todo |
| M3-07 | Must | Re-assign menu locations and fix items by slug | todo |
| M3-08 | Should | Required-plugin check with install/activate from WordPress.org | todo |
| M3-09 | Must | Regenerate Elementor CSS and clear caches | todo |
| M3-10 | Could | Preview thumbnail and component list | todo |

## M4 — Unlimited size engine

| ID | Priority | Requirement | Status |
| --- | --- | --- | --- |
| M4-01 | Must | No hard-coded file size cap | tested |
| M4-02 | Must | Pre-flight check with plain-language results | done |
| M4-03 | Must | Upload chunk size auto-detected and overridable in Settings | done |
| M4-04 | Must | Retry a failed chunk up to 3 times with back-off | done |
| M4-05 | Must | WP-CLI export, import, list, restore | todo |
| M4-06 | Should | Optional split archives | todo |
| M4-07 | Must | Database export uses primary-key pagination | done |
| M4-08 | Should | 5 GB export in under 30 minutes on a 2 vCPU SSD VPS | todo |

M4-03's setting is stored (`upload_chunk_mb`, 0 = automatic, otherwise 2–10). The import step uses that size, and shrinks it when `post_max_size` or `upload_max_filesize` is smaller, so a large site can still upload. `Limits::archive_cap()` is 0. There is no maximum archive size. A failed export step or upload chunk is tried up to three times. Before an export, the screen reports free disk, the step size, and any PHP or WordPress failure. Keeping the last N archives runs only when the retention setting is greater than zero, so existing backups stay until that number is set.

## M5 — Backups manager

These items have no IDs in the specification. They are tracked so they are not dropped.

| ID | Requirement | Status |
| --- | --- | --- |
| M5-LIST | List archives with name, type, size, date, and source URL | todo |
| M5-DOWNLOAD | Secure, signed, expiring download | todo |
| M5-RESTORE | Restore a stored archive | todo |
| M5-VERIFY | Verify CRC32 and SHA-256 | todo |
| M5-DELETE | Delete an archive | todo |
| M5-HISTORY | Job history | todo |
| M5-LOGS | Downloadable logs | todo |
| M5-REPORTS | SEO Integrity Reports | todo |
| M5-RETENTION | Keep the last N archives | done |
| M5-EMAIL | Email notifications | todo |
| M5-UNINSTALL | Delete archives on uninstall only when the setting is on | done |

M5-UNINSTALL is implemented in `uninstall.php`. It is not marked tested because the test suite does not boot WordPress.

## Archive format (phase 2)

The format, writer, and reader are covered by PHPUnit without booting WordPress. Export, import, and the job runner do not call them yet, so the M1–M4 rows above stay `todo`.

| Item | Status |
| --- | --- |
| Version 1 header, entry, trailer, and newline-delimited manifest | tested |
| Streaming writer and reader, 512 KB buffer, no whole-file load | tested |
| CRC32 and SHA-256 of the part and of each plaintext entry | tested |
| AES-256-GCM manifest and database, optional file encryption, password not stored | tested |
| Split between entries (`name.001.bmx`) | tested |
| Path guard, content-pack executable rule, symlink refusal | tested |
| 32-bit PHP refuses offsets above 2 GB | tested |
| `docs/archive-format.md` | done |

Symlink creation is skipped in PHPUnit when Windows does not allow `symlink()`. The guard still rejects a symlink path when one is present.

## Scaffold and standards (phase 1)

| Item | Status |
| --- | --- |
| Plugin header, GPL, Update URI, readme.txt | done |
| Composer PSR-4 and fallback autoloader | tested |
| PHPCS (WordPress-Extra, WordPress-Docs, PHPCompatibilityWP 7.4+) and ESLint / Stylelint config | done |
| Activation: dbDelta, storage, db version, requirements abort | done |
| Upgrade on admin / cron / CLI | done |
| Deactivation clears the install cron hook and keeps data | done |
| Protected storage folder, path checks, guard files | tested |
| Administrator capability on every admin screen | done |
| Admin menu and screen shells | done |
| Settings API, allow-listed sanitizer | tested |
| Admin assets only on BrightMigrate screens | done |
| No front-end boot | done |
| ABSPATH guard on PHP files | tested |
| i18n wrappers and `wp_set_script_translations` | done |
| aria-live region on admin screens | done |
| Friendly error with a log reference, secrets redacted | tested |
