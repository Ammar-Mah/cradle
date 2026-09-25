# Cradle archive format, version 1

Status: implemented by `cradle-migration` 0.1.0 (`Cradle\Migration\Archive_Writer`,
`Cradle\Migration\Archive_Reader`, `Cradle\Migration\Manifest`).
This is an open, documented format. It is **not** compatible with `.wpress` or any
other vendor format, and Cradle makes no such claim.

## 1. Files that make up one archive

An archive is a set of sibling files that share an archive ID:

```
<archiveId>.manifest.json       UTF-8 JSON manifest (this document, §3)
<archiveId>.part-0001.zip       ZIP part 1 (PHP ZipArchive / libzip, deflate)
<archiveId>.part-0002.zip       ...
```

- `archiveId` matches `^[A-Za-z0-9][A-Za-z0-9-]{7,63}$`, e.g. `cradle-20260924T103500Z-3f9c2a1b`.
- Each part is an **independent, complete ZIP file** (not a spanned/split ZIP), so a damaged
  part never makes the others unreadable. A part's uncompressed payload is bounded by the
  export option `partSize` (default 64 MiB, allowed 256 KiB … 2 GiB); entries are never split
  across parts except through file segments (§4.2).
- The manifest is kept **outside** the parts because it carries the parts' own checksums.
- A part that would otherwise be empty contains a single zero-byte entry `.cradle-empty`
  (libzip does not write empty archives); importers ignore exactly that entry.
- No custom compression is used. Encryption is not part of format version 1 (no audited
  native implementation is bundled); protect archives with storage and transport controls.

## 2. Trust model

Checksums (SHA-256) detect corruption and truncation. They do **not** prove who made the
archive: anyone who can edit an archive can also recompute its hashes. Every importer check in
§6 is therefore enforced independently of the checksums. Restoring theme/plugin code from an
archive is a privileged code deployment and requires `cradle_import_site`.

## 3. Manifest

Top-level keys (all required unless marked optional). Unknown top-level keys are rejected by
the importer.

| Key | Type | Meaning |
| --- | --- | --- |
| `format` | string | Always `cradle-archive`. |
| `formatVersion` | int | `1`. Importers refuse newer major versions. |
| `archiveId` | string | See §1; must equal the file-name prefix. |
| `createdAt`, `completedAt` | string | UTC ISO-8601 (`2026-09-24T10:35:00Z`). |
| `generator` | object | `name` (`cradle-migration`), `version`, `coreVersion`, `coreApi`. |
| `mode` | string | `full` (database + all selected file categories) or `selective`. |
| `scope` | object | `database` (bool), `uploads` (bool), `themes` (list of theme directory slugs), `plugins` (list of plugin slugs), `rootFiles` (list of allowlisted root file names). |
| `redacted` | bool | `false` in format 1 (a redacted, potentially non-restorable mode is not offered yet). |
| `consistency` | object | `strategy` = `write-quiescence` or `none`; `windowStart`/`windowEnd` (UTC, optional); `notes` (plain text describing the limitation). |
| `source` | object | §3.1 |
| `packages` | object | §3.2 |
| `database` | object | §5 |
| `files` | list | §4 |
| `exclusions` | list | `{ "category", "path", "reason" }` for every excluded path class/instance (secrets, caches, private storage, symlinks, …). |
| `unsupported` | list | `{ "kind", "name", "detail" }` — views, triggers, routines, events, unsupported engines, generated/functional/spatial definitions, special files. Their presence is always reported during export preflight and import preflight. |
| `notMigrated` | list of strings | Things this system never migrates (DNS, email accounts, TLS certificates, server packages, hosting users, account directories, WordPress core files, `wp-config.php`). |
| `warnings` | list of strings | Non-blocking notes (e.g. files that changed while being read). |
| `parts` | list | `{ "name": "<archiveId>.part-0001.zip", "index": 1, "size": <bytes>, "sha256": "<hex>" }` in index order, contiguous from 1. |
| `totals` | object | `files`, `fileBytes`, `tables`, `rows`, `chunks`, `parts`, `partBytes`. |

### 3.1 `source`

`siteUrl`, `homeUrl`, `abspath`, `contentDir`, `uploadsDir`, `uploadsUrl`, `pluginsDir`,
`themesDir` (paths normalized with forward slashes, no trailing slash except `abspath`),
`tablePrefix`, `multisite` (always `false`; multisite export is refused), `wordpress`
(version), `dbVersion` (WordPress `db_version` option), `php`, `dbServer`, `dbCharset`,
`locale`, `template`, `stylesheet`, `activePlugins` (list of plugin basenames).

These values drive URL/path/prefix transformation on import (§7). Absolute paths in the
manifest are informational; they are never used as write destinations.

### 3.2 `packages`

- `themes`: `{ "slug", "name", "version", "parent" (slug or null), "active" (bool), "included" (bool) }`
- `plugins`: `{ "basename", "slug", "name", "version", "active", "included" }`
- `muPlugins`, `dropins`: names only; they are special cases and are never transplanted.
- `core`: `{ "version", "restore": "Install a trusted WordPress <version> package from wordpress.org; Cradle does not restore core files from archives." }`

## 4. Files

Each `files[]` entry:

```json
{
  "category": "uploads",
  "path": "2026/09/photo.jpg",
  "size": 48213,
  "sha256": "…64 hex…",
  "mtime": 1790000000,
  "segments": [ { "part": 1, "entry": "files/uploads/2026/09/photo.jpg", "size": 48213 } ]
}
```

- `category` is one of `uploads`, `themes`, `plugins`, `root`. Roots on the destination:
  uploads base directory, theme root, `WP_PLUGIN_DIR`, and `ABSPATH` (root files only from the
  explicit allowlist in §4.3).
- `path` is relative to the category root, uses `/`, and must satisfy §6.3. For `themes` and
  `plugins` the first segment is the package slug (a single-file plugin is `hello.php`).
- Only regular files are archived. Directories are implied. Symlinks, devices, FIFOs and
  sockets are never archived (they are listed in `exclusions`).

### 4.1 Entry names

ZIP entry name for a single-segment file: `files/<category>/<path>`.
The importer derives every expected entry name from the validated manifest and rejects any
ZIP entry that is not expected (§6.2); ZIP names are never used as filesystem paths.

### 4.2 Segments

Files larger than the segment size (default 32 MiB) are stored as several entries
`files/<category>/<path>.cradle-seg0001`, `…seg0002`, …; `segments[]` lists them in order with
their part and byte size. Concatenating the segments yields the file; `sha256` covers the whole
file. Segments may live in different parts.

### 4.3 Root files

Only names matching `^(?!index\.)[A-Za-z0-9._-]{1,64}\.(txt|xml|json|ico|webmanifest|html)$`
(case-insensitive) directly in `ABSPATH` may be exported, and only when the operator lists them
explicitly; importing them additionally requires the import option `root`. `index.*`,
`.htaccess`, `web.config`, `.user.ini`, `php.ini`, `wp-config.php` and any PHP file are never
root files.

## 5. Database

```json
"database": {
  "encoding": "jsonl-v1",
  "charset": "utf8mb4",
  "tables": [ {
    "name": "posts",
    "engine": "MyISAM",
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_520_ci",
    "autoIncrement": 42,
    "columns": [ { "name": "ID", "type": "bigint(20) unsigned", "nullable": false,
                   "default": null, "defaultKind": "none", "extra": "auto_increment",
                   "charset": null, "collation": null, "binary": false } ],
    "indexes": [ { "name": "PRIMARY", "unique": true, "type": "BTREE",
                   "columns": [ { "name": "ID", "subPart": null } ] } ],
    "primaryKey": [ "ID" ],
    "rowsMode": "full",
    "rows": 12,
    "chunks": [ { "index": 1, "part": 1, "entry": "db/posts/000001.jsonl",
                  "rows": 12, "bytes": 4567, "sha256": "…" } ]
  } ]
}
```

- `name` is the **logical** table name: the physical name without the source `tablePrefix`
  (`^[A-Za-z0-9_]{1,54}$`). Only tables whose physical name starts with the site prefix are
  exported; the destination prefix is applied on import.
- `columns[].type` is the MySQL column type string; the importer accepts only the grammar in
  §6.4 and builds DDL itself. `defaultKind` is `none`, `null`, `literal` (value in `default`) or
  `expression` (only `CURRENT_TIMESTAMP[(n)]` is accepted). `extra` accepts only
  `auto_increment`, `on update CURRENT_TIMESTAMP[(n)]` and `DEFAULT_GENERATED`.
- `rowsMode` is `full` or `schema-only`. Cradle's own runtime tables (`cradle_jobs`,
  `cradle_locks`, `cradle_activity`) are exported `schema-only` and are never replaced on
  import: the destination keeps its own job, lock and audit state (this is what keeps the
  running import job and its recovery context alive across cutover).
- Rows of `options` whose names start with `_transient_` or `_site_transient_` (caches) are not
  exported; this is listed in `exclusions`.

### 5.1 Row chunks (`jsonl-v1`)

Entry `db/<logical>/<nnnnnn>.jsonl`; one row per line (`\n` terminated); each line is a JSON
array with one element per column **in manifest column order**:

- `null` → SQL `NULL`
- JSON string → the exact textual value MySQL returned (numbers and dates stay strings, so no
  float or precision drift is introduced by JSON)
- `{"b64": "<base64>"}` → raw bytes; always used for binary/blob/bit columns and for any text
  value that is not valid UTF-8.

Chunks are bounded (default ≤ 2,000 rows and ≤ 4 MiB). Each chunk's `sha256` covers its exact
uncompressed bytes. Rows are read in primary-key order (keyset pagination for a single integer
key, ordered offset pagination otherwise); tables without a primary key are read in physical
order and reported as such.

No SQL statements are stored in the archive. The importer never executes archive-provided SQL.

## 6. Importer validation (normative)

### 6.1 Order

1. Permission (`cradle_import_site`), request nonce, protected private storage, no other active
   migration job, no core update lock or `.maintenance` file.
2. Manifest size ≤ 64 MiB, strict JSON, known keys, `format`/`formatVersion`, archive ID matches
   file names, destination runtime (PHP ≥ 8.3, `zip`, `mysqli`, `json`, `hash`), source WordPress
   version not newer than the destination, collations/engines available.
3. Every part present, sizes equal, per-part SHA-256 equal.
4. ZIP central directories inspected (§6.2) **before any extraction**.
5. Every manifest path validated (§6.3); limits enforced (§6.5); disk space/quota checked.
6. Every DB chunk hashed, parsed and dry-run transformed (§7); unsupported serialized shapes
   that need transformation block the import with the table/row named.
7. Preflight report shown; nothing on the destination changes before the operator approves.
8. During staging each file is re-hashed while it is written (per-file SHA-256).

### 6.2 ZIP entries

Rejected: names not expected from the manifest, duplicate names, directory entries under
`files/` or `db/` other than implied ones, entries whose Unix mode (external attributes, when
the creator OS is Unix) is a symlink, character/block device, FIFO or socket, encrypted
entries, entries whose uncompressed size differs from the manifest, and any entry with a
compression ratio above the limit.

### 6.3 Paths

A manifest `path` is rejected when it is empty, longer than 512 bytes, contains a NUL byte,
backslash, control character, a `:` (drive letters/ADS), starts with `/` or `~`, contains an
empty, `.` or `..` segment, has more than 32 segments, has a segment ending in a dot or space,
uses a Windows reserved device name (`CON`, `NUL`, `COM1`…), duplicates another path
case-insensitively, or conflicts with another path (a file that is also a parent directory).
At staging and at replacement time the canonical target is checked with
`Cradle\Core\Storage::path_within()` (which resolves existing symlinked parents) against the
staging directory and the destination category root; any existing symlink inside the path is
refused. Upload paths with executable extensions (`.php`, `.phtml`, `.phar`, `.php3-8`, `.pht`,
`.shtml`, `.cgi`, `.pl`, `.py`, `.sh`) are skipped and reported. Upload paths whose first
segment starts with `cradle-private-` are rejected (reserved for the destination's private
storage). Plugin folders `cradle` and `cradle-migration` are never replaced from an archive: the
running engine of the destination is preserved and reported.

### 6.4 Column type grammar

Integers `tinyint|smallint|mediumint|int|bigint` with optional `(n)`, `unsigned`, `zerofill`;
`decimal|numeric(p[,s])`, `float|double[(p,s)]` optionally `unsigned`; `bit(n)`;
`char|varchar|binary|varbinary(n)`; `tinytext|text|mediumtext|longtext`;
`tinyblob|blob|mediumblob|longblob`; `json`; `date`, `datetime[(n)]`, `timestamp[(n)]`,
`time[(n)]`, `year[(4)]`; `enum(...)`/`set(...)` with single-quoted literals. Anything else
(geometry, generated columns, functional indexes, spatial indexes, partitioning) is
`unsupported`.

### 6.5 Default limits (filter `cradle_migration_limits`; CLI may only lower them)

| Limit | Default |
| --- | --- |
| Parts | 2,000 |
| Part size | 2 GiB |
| ZIP entries (all parts) | 500,000 |
| Manifest files | 400,000 |
| Uncompressed total | 50 GiB and ≤ 90 % of free space (or the operator quota) |
| Single file | 4 GiB |
| DB chunk | 64 MiB uncompressed |
| Compression ratio per entry (entries ≥ 1 MiB) | 200 : 1 |
| Path depth / length | 32 segments / 512 bytes |
| Serialized value nesting | 64 levels |

## 7. Transformation rules applied on import

- URL mapping: source `homeUrl`/`siteUrl` → destination `home`/`siteurl`; the
  scheme-relative form (`//host/path`) is also mapped, so `https://` variants keep their scheme.
- Path mapping: source `abspath`, `contentDir`, `uploadsDir` → destination equivalents, in
  forward-slash, backslash, JSON-escaped and block-attribute (`\`) forms.
- Each needle is also matched in its JSON-escaped (`\/`) and URL-encoded forms.
- A match must end at a boundary (next byte not `[A-Za-z0-9_.~-]`), so `…/wp` never matches
  inside `…/wp-content` of an unrelated site or `…/wpx`.
- PHP serialized values are parsed by a bounded structural parser (`Cradle\Migration\Serialized`,
  no `unserialize()`), strings are transformed recursively (nested serialized strings included),
  and lengths are recomputed. Objects (`O:`, `C:`, `E:`) and references (`r:`, `R:`) are
  preserved byte-for-byte when they need no change; if a change would be required inside them,
  import preflight fails and names the table, key and column.
- Never transformed: `posts.guid`, binary columns, `users.user_pass`, and values whose key is in
  the opaque list (filter `cradle_migration_opaque_keys`; import option `opaque`).
- Table prefix change: `options.option_name` and `usermeta.meta_key` values that start with the
  source prefix are renamed to the destination prefix (e.g. `cw_user_roles` → `ct_user_roles`,
  `cw_capabilities` → `ct_capabilities`).

## 8. Versioning

Additive, backward-compatible manifest keys increase the minor generator version only.
Any change that an older importer could misread increases `formatVersion`; importers refuse
unknown major versions and unknown top-level keys rather than guessing.
