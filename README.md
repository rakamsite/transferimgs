# Media Flatten Migrator

Phase 7 provides WordPress admin and WP-CLI access to assessment, manifest,
target resolution, collision detection, migration, exact mapping-based URL
replacement, comprehensive read-only verification, pre-redirect old URL auditing,
and exact redirect export generation.

## WordPress Admin

Administrators can open **Tools > Media Flatten Migrator** to run safe,
resumable AJAX batches for scan, target resolution, file migration, exact URL
replacement, verification, old URL auditing, and redirect export generation.
Every write action requires confirmation. Dry runs do not write plugin job state
or migration data.

## Commands

```sh
wp media-flatten install
wp media-flatten scan
wp media-flatten scan --dry-run
wp media-flatten resolve-targets
wp media-flatten resolve-targets --dry-run
wp media-flatten migrate-one <attachment_id> --dry-run
wp media-flatten migrate-one <attachment_id>
wp media-flatten migrate --batch=50 --dry-run
wp media-flatten migrate --batch=50
wp media-flatten migrate --batch=50 --start-after=<attachment_id>
wp media-flatten migrate --limit=500
wp media-flatten replace-urls --dry-run
wp media-flatten replace-urls
wp media-flatten replace-urls --batch=500 --dry-run
wp media-flatten replace-urls --batch=500
wp media-flatten report
wp media-flatten verify
wp media-flatten verify --json
wp media-flatten verify --strict
wp media-flatten audit-old-urls
wp media-flatten audit-old-urls --json
wp media-flatten audit-old-urls --strict
wp media-flatten redirects --format=apache
wp media-flatten redirects --format=nginx
wp media-flatten redirects --format=csv
wp media-flatten redirects --format=apache --output=/tmp/media-flatten.conf
```

`install` creates or updates the prefixed manifest table. Plugin activation also
installs the table.

`scan` finds attachments whose `_wp_attached_file` value starts with a `YYYY/MM/`
path. It reads attachment metadata, checks referenced main files, image sizes, and
`original_image` files, reports missing files and possible flattened filename
collisions, and writes idempotent manifest rows. Add `--dry-run` to make zero
database changes.

`report` prints the complete referenced-file and collision inventories plus
manifest counts, extension and non-ASCII filename counts, and read-only attachment
usage across featured images, WooCommerce galleries, post content, and excerpts.

`resolve-targets` preserves each existing filename exactly and resolves it into the
uploads root. It marks duplicated or occupied targets as `blocked_collision` and
never auto-renames or overwrites files. Add `--dry-run` to preview without database
changes.

`migrate-one` preflights one fully resolved attachment, copies and verifies its
manifest files, then updates only `_wp_attached_file` and attachment metadata.
Old source files remain in place. Add `--dry-run` for a strictly read-only plan.

`migrate` uses the same single-attachment migration service for up to 50 eligible
attachments by default. `--limit` is an alias for `--batch`; using both is rejected.
Runs are ordered by attachment ID and may resume with `--start-after`.

`replace-urls` uses only migrated manifest mappings and updates exact URL references
in post content, post excerpts, postmeta, and options. Serialized arrays and valid
JSON are handled structurally. Add `--dry-run` for a strictly read-only preview.

`verify` performs read-only checks of migrated manifest rows, copied files,
integrity, attachment metadata, remaining URL references, filename preservation,
and WooCommerce attachment-ID references. `--json` emits structured JSON and
`--strict` exits with an error when verification errors exist.

`audit-old-urls` performs a read-only pre-redirect safety audit across posts,
post excerpts, postmeta, and options. It classifies remaining dated upload URLs
as migrated leftovers, known non-migrated leftovers, or orphan leftovers.

`redirects` exports exact mapping-based redirect rules from migrated manifest
rows only. It can print Apache, Nginx, or CSV output to STDOUT or write the
export to a file. CSV output is UTF-8 with a BOM for spreadsheet compatibility.

The plugin never modifies GUIDs or attachment IDs and never moves or deletes old
source files. File migration updates only the attachment path and metadata needed
for WordPress to use the copied root-level files.
