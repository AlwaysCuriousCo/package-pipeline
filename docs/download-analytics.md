# Exporting download statistics

Every dist download the registry serves writes a row: which package, which
version, which credential, and when. The panel charts it; this is how you get it
out as CSV, for one package or for the whole registry, over a date range.

## Two reports

**Summary** — one row per package and version, with a count and the first and
last time it was fetched. This is what you chart or paste into a spreadsheet. A
summary of ten million downloads is a few hundred rows.

```csv
package,repository,version,downloads,first_download,last_download
acme/widgets,(root),1.0.0,412,2026-06-02T09:14:51+00:00,2026-08-09T22:03:10+00:00
acme/widgets,(root),1.1.0,88,2026-08-01T11:02:33+00:00,2026-08-10T07:41:02+00:00
```

**Detail** — one row per download. This is what you reach for when the question
is which credential pulled which version, and on a busy registry it is a very
large file.

```csv
downloaded_at,package,repository,version,token_prefix
2026-08-10T07:41:02+00:00,acme/widgets,(root),1.1.0,pp_9f2c
2026-08-10T07:44:19+00:00,acme/gadgets,internal,2.3.0,(anonymous)
```

`repository` is the Composer repository's URL path; the default repository is
served at the registry root and is written `(root)`. `token_prefix` is the
credential's prefix, which stays meaningful after the token is revoked — a
download served anonymously from a public repository is `(anonymous)`.

Dates are ISO 8601 with an offset, so they read the same whichever database the
registry is deployed on.

## From the panel

**Packages → Export downloads** (in the header) exports everything you can see;
the same action on a package's row exports that package alone. Both open a
modal for the report and the date range.

Both ends of the range are inclusive and both are optional: leaving **From**
empty starts at the first download recorded, leaving **To** empty runs up to
now.

The export is **scoped to your own grants** — the same packages the table below
it shows. An admin without unscoped access exports the public repositories plus
whatever they were granted, and nothing else.

## From the shell

```bash
# Everything, as a summary, to stdout
php artisan downloads:export

# One package, one month, one row per download, to a file
php artisan downloads:export --package=acme/widgets --detail \
    --from=2026-07-01 --to=2026-07-31 --path=storage/exports/

# Straight into a pipe
php artisan downloads:export --detail | gzip > downloads.csv.gz
```

| Option | |
| --- | --- |
| `--package=` | the Composer name, e.g. `acme/widgets` |
| `--repository=` | the repository path, when two repositories publish the same name |
| `--from=`, `--to=` | `YYYY-MM-DD`, inclusive, both optional |
| `--detail` | one row per download instead of one per version |
| `--path=` | a file to write to, or a directory to write a dated, named file into |

Without `--path` the CSV goes to stdout, which is what makes the pipe above
work. With one, the command prints how many rows it wrote instead.

The command is **not** scoped: there is no session to narrow to, and anyone
holding a shell on the app already holds the database it reads. This is what to
schedule for a nightly extract into a warehouse or an object store.

## Why not an API endpoint

There deliberately is not one. The `/api/v1` surface exists for the callers that
cannot open a browser doing publish-and-sync work — small, paginated,
resource-shaped requests under a per-minute rate limit. A bulk analytics dump is
none of those things: it is long-lived, unpaginated, and sized by how popular
the registry is. It would sit badly under that budget and would tempt a caller
into polling it.

The two shapes it would have served are covered better elsewhere. A scheduled
extract is a cron line running the command, which needs no credential, no
timeout budget and no rate limit. A live figure for a dashboard is
[the metrics endpoint](metrics.md), which answers in constant time and is
designed to be scraped.

## Memory, and why this streams

`downloads` is the fastest-growing table in the schema. It is append-only, is
never pruned, and gains a row for every zip the registry serves — so an export
that loaded it into memory would be an export that stopped working exactly when
the registry became worth measuring.

Nothing here holds more than one row at a time. Rows are yielded from a database
cursor and written out as they arrive, and the HTTP export is a plain streaming
route rather than something the admin panel hands back — Livewire delivers a
file by base64-encoding it into its response, which would put the whole export
in memory to save it.

The practical consequence: an export of any size will work, but a very large one
will hold an HTTP worker open for as long as it takes. For anything measured in
gigabytes, use the command.
