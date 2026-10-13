# BrightMigrate `.bmx` format (version 1)

A `.bmx` file is one part of a BrightMigrate archive. Parts are independent: each has its own header, manifest, payload, and trailer. Splitting happens between entries. An entry that is larger than the split size stays in one part.

Nothing in this format is loaded as a single string. Readers and writers move data in 512 KB buffers (`524288` bytes).

## Layout

| Region | Size | Offset |
| --- | --- | --- |
| Header | 256 bytes | 0 |
| Manifest | `manifest_stored` bytes | 256 |
| Payload | `payload_length` bytes | `entries_offset` |
| Trailer | 64 bytes | `trailer_offset` |

The header records those offsets. A reader does not scan for them.

On disk the payload is a sequence of entry headers followed by that entry's stored bytes. The absolute offset of an entry header is `entries_offset` plus the entry's position inside the payload.

### Header (256 bytes, little-endian)

| Offset | Size | Field |
| --- | --- | --- |
| 0 | 8 | Magic `BMXARCH` plus version byte `0x01` |
| 8 | 2 | Format version (`1`) |
| 10 | 2 | Header length (`256`) |
| 12 | 4 | Flags |
| 16 | 8 | Created time, Unix seconds |
| 24 | 2 | Part index, starting at 1 |
| 26 | 2 | `1` when this is the last part |
| 28 | 4 | PBKDF2 iterations, or `0` when the part has no password |
| 32 | 16 | Salt, or zeros when the part has no password |
| 48 | 8 | Manifest offset (`256`) |
| 56 | 8 | Manifest stored length |
| 64 | 8 | Manifest plaintext length |
| 72 | 8 | Payload offset |
| 80 | 8 | Trailer offset |
| 88 | 8 | Payload length |
| 96 | 160 | Reserved, zeros |

Flags:

| Bit | Meaning |
| --- | --- |
| 1 | Manifest is encrypted |
| 2 | Database entries are encrypted |
| 4 | File payloads are encrypted |
| 8 | This file is one part of a split archive |

Earlier parts are not rewritten when a later part is created. Each part's header is final when that part is sealed.

### Entry header

| Offset | Size | Field |
| --- | --- | --- |
| 0 | 4 | Magic `BMXE` |
| 4 | 2 | Format version |
| 6 | 2 | Entry flags |
| 8 | 8 | Plaintext size |
| 16 | 8 | Stored size |
| 24 | 4 | CRC32 of the plaintext, raw |
| 28 | 32 | SHA-256 of the plaintext, raw |
| 60 | 2 | Path length |
| 62 | path length | Relative path |

The fixed prefix is 62 bytes. Entry flags: `1` file, `2` database, `4` payload encrypted. A database entry is `2` or `6`. A file entry is `1` or `5`.

The stored bytes follow the header immediately. When the payload is not encrypted, stored size equals plaintext size.

### Trailer (64 bytes)

| Offset | Size | Field |
| --- | --- | --- |
| 0 | 7 | Magic `BMXEND` plus version byte `0x01` |
| 7 | 1 | Reserved, zero |
| 8 | 8 | Total file size, including this trailer |
| 16 | 4 | CRC32 of every byte before the trailer, raw |
| 20 | 32 | SHA-256 of every byte before the trailer, raw |
| 52 | 2 | Part index |
| 54 | 2 | `1` when this is the last part |
| 56 | 8 | Reserved, zeros |

## Manifest

The manifest is newline-delimited JSON. The first line is the summary. Each following line is one entry. Callers decode one line at a time and do not decode the manifest as a single document.

Sizes that are not known until the manifest length is known (`archive_size`, entry `size`, `stored_size`, and `offset`) are 20-digit strings. The writer emits the manifest twice. The second pass fills in the real numbers without changing the length.

Summary fields: `format` (`bmx`), `version`, `type` (`full`, `content`, or `themekit`), `source_url`, `home_url`, `wp_version`, `php_version`, `plugin_version`, `created_at`, `archive_size`, `table_prefix`, `contents` (`files`, `database_parts`, `bytes`), `part` (`index`, `final`), `encrypted` (`manifest`, `database`, `files`).

Entry fields: `path`, `kind` (`file` or `database`), `size`, `stored_size`, `offset`, `crc32` (8 hex characters), `sha256` (64 hex characters), `encrypted`.

`offset` is the absolute offset of that entry's header in this part.

## Encryption

A password encrypts the manifest and every database entry with AES-256-GCM. File bytes stay plaintext unless `encrypt_files` is set, which also requires a password. The password is never written, logged, or kept after the key is derived.

The key is PBKDF2-HMAC-SHA256, 100000 iterations, 16-byte salt, 32-byte output. Readers reject iteration counts outside 100000–1000000.

Ciphertext is a sequence of chunks, each at most 512 KB of plaintext:

| Field | Size |
| --- | --- |
| Plaintext length | 4 |
| Ciphertext length | 4 |
| Nonce | 12 |
| Tag | 16 |
| Ciphertext | plaintext length |

A wrong password or a damaged chunk fails with `Could not decrypt this archive. Check the password.` The message does not contain the password.

## Paths and executables

Archive paths are relative, use `/`, and cannot contain `..`, a drive letter, a stream wrapper, a null, or another control character. Extraction resolves each path inside a chosen directory and refuses to follow a symlink.

A full-site extract may write PHP anywhere in that directory. A content-pack extract may write `.php`, `.phtml`, `.phar`, and the other executable extensions only under `wp-content/themes/`, `wp-content/plugins/`, and `wp-content/mu-plugins/`. A content archive refuses those files on the way in as well.

## Split names and 32-bit PHP

With no split, the file keeps the requested path. With a split, `site.bmx` becomes `site.001.bmx`, `site.002.bmx`, and so on. At most 999 parts.

Offsets are unsigned 64-bit little-endian. On 64-bit PHP, `pack('P')` is used. On 32-bit PHP, values above 2147483647 are rejected and the dashboard already warns that files larger than 2 GB are unsupported. There is no smaller hard size cap in the format.

Duplicate paths are rejected. The writer keeps a set of path hashes in memory for that check; the file bytes themselves are not kept in memory.

## Failure

If `finish()` is not called, the writer deletes its temporary files and does not leave a `.bmx` behind. A checksum mismatch during extract deletes the partial destination file.
