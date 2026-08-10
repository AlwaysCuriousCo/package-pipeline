# The management API

Creating a package, triggering a sync and listing what a repository serves are
all things the panel does well and a CI job cannot do at all. The management
API is the same operations behind an HTTP contract: `/api/v1`, JSON in and JSON
out, authenticated with the access tokens this registry already issues.

It is not the Composer protocol. `composer install` talks to `/packages.json`,
`/p2/...` and `/dist/...`, which are shaped by Composer and documented by
Composer; nothing here changes them. This API describes and administers the
registry rather than serving it, and the two surfaces deliberately share a
credential store and nothing else.

## Authentication

Every request carries an access token — the same `pp_…` token created under
**API tokens** in the panel, or from the console:

```bash
php artisan token:add "release pipeline" --deploy=ci \
  --ability=api:read --ability=api:write
```

```bash
curl -H "Authorization: Bearer $PP_TOKEN" https://registry.example.com/api/v1/packages
```

The token may also arrive as the HTTP Basic password with any username, so the
`auth.json` entry a Composer client already holds works from `curl` unchanged:

```bash
curl -u token:$PP_TOKEN https://registry.example.com/api/v1/packages
```

There is no anonymous access, even to a public repository. A repository being
public is a decision about who may *install* from it; it says nothing about who
may enumerate the registry's administration or change it.

## Abilities

A token's abilities decide what it may do. The five come in two families that
never imply each other:

| Ability | Grants |
| --- | --- |
| `repository:read` | Resolving metadata and downloading dists — what `composer install` needs |
| `repository:write` | Publishing an artifact to `POST /upload/...` |
| `api:read` | `GET` on everything below |
| `api:write` | Creating packages and triggering syncs |
| `api:delete` | Deleting packages |

Keeping the families apart is the point. The token pasted into every
developer's `auth.json` so `composer install` works holds `repository:read`,
and that must not, by the same act, let it enumerate the registry's
administration or delete a package out from under the projects depending on it.

`api:delete` is separate from `api:write` for the same reason one step further
in: creating a package and syncing one are additive and repeatable, while
deleting unpublishes something a consuming project may already have pinned in a
lock file, and no amount of syncing brings it back. A release pipeline needs
`api:write`; almost nothing needs `api:delete`.

Nothing is implied by anything else. A token holding only `api:write` cannot
`GET` a package, exactly as a token holding only `repository:write` can upload
an artifact but cannot install one.

## What a token can see

Abilities say what a token may do; **grants** say what it may do it *to*, and
they are the same grants everything else in this registry obeys — the panel's
package list, the Composer endpoints, the artifact upload.

- A **personal access token** sees exactly what the user who owns it sees.
- A **deploy token** sees the public repositories plus whatever it was granted,
  or the whole registry when it holds no grants at all.

A package outside a token's reach is answered `404`, never `403`: "you may not
see this" and "this does not exist" have to be indistinguishable, or the 403
confirms a name the caller could never have fetched.

Writing is narrower than reading, and being able to read a repository never
implies being able to write to it. To create a package in a repository a token
needs that repository granted (or no grants at all); to sync or delete an
existing package it needs the repository or that package granted.

## Errors

Failures are Laravel's standard JSON shapes, which is what the Composer
endpoints already answer with.

```json
{ "message": "The access token is invalid, expired or revoked." }
```

Validation failures add the per-field detail and are always `422`:

```json
{
  "message": "The name must be a Composer package name, like \"acme/widgets\".",
  "errors": { "name": ["The name must be a Composer package name, like \"acme/widgets\"."] }
}
```

| Status | Means |
| --- | --- |
| `200` / `201` / `202` / `204` | Done. `202` means queued — see the sync endpoint |
| `401` | No token, or one that is unknown, expired or revoked |
| `403` | The token lacks the ability, or its grants do not reach the target |
| `404` | No such record — or one this token may not see |
| `422` | The request was understood and refused; `errors` says why |
| `429` | Rate limited; `Retry-After` says for how long |

## Rate limits

Sixty requests a minute, counted per credential rather than per address — a CI
fleet and an office both arrive from one egress address, and one token's loop
must not spend another's budget. Requests carrying no credential are counted
against the address, which is what bounds guessing at tokens.

A `429` carries `Retry-After` in seconds. Provisioning runs longer than sixty
creations a minute should wait on it rather than treat it as a failure.

---

## Endpoints

Everything is under `/api/v1`. Responses are wrapped in `data`; listings add
Laravel's `links` and `meta` pagination blocks.

Packages are addressed by numeric id, because a Composer name is only unique
*within* a Composer repository — one installation may serve `acme/widgets` from
two repositories, and a URL that could not tell them apart would be a coin toss
on every delete. `GET /packages?name=…` resolves a name to an id in one call.

### `GET /packages`

| Parameter | Meaning |
| --- | --- |
| `name` | Exact Composer name |
| `q` | Name prefix — `acme/` lists one vendor |
| `type` | Composer package type, e.g. `library` |
| `repository` | The Composer repository's URL path. Pass it empty (`?repository=`) for the root repository, which has no path |
| `per_page` | 1–100, default 25 |

```bash
curl -H "Authorization: Bearer $PP_TOKEN" \
  "https://registry.example.com/api/v1/packages?repository=internal&per_page=50"
```

```json
{
  "data": [
    {
      "id": 12,
      "name": "acme/widgets",
      "description": "Widgets for Acme.",
      "type": "library",
      "url": "https://github.com/acme/widgets",
      "provider": "github",
      "latest_version": "1.4.0",
      "abandoned": false,
      "replacement_package": null,
      "downloads": 1841,
      "repository": {
        "id": 2,
        "name": "Internal",
        "path": "internal",
        "description": "Packages we do not publish.",
        "public": false,
        "url": "https://registry.example.com/r/internal",
        "created_at": "2026-03-01T09:12:00+00:00",
        "updated_at": "2026-03-01T09:12:00+00:00"
      },
      "sync": {
        "last_synced_at": "2026-08-09T18:22:41+00:00",
        "error": null,
        "webhook_enabled": true
      },
      "versions_count": 14,
      "created_at": "2026-03-01T09:14:22+00:00",
      "updated_at": "2026-08-09T18:22:41+00:00"
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "from": 1, "last_page": 1, "per_page": 50, "to": 1, "total": 1 }
}
```

`url` is the VCS repository the package syncs from — `null` for one published
by artifact upload. `repository` is the Composer repository serving it. The
model carries the same collision and names the relation `composerRepository()`
for the same reason.

### `GET /packages/{id}`

The package above, plus every stored version newest first, and whether a sync
is running right now.

```json
{
  "data": {
    "id": 12,
    "name": "acme/widgets",
    "sync": {
      "last_synced_at": "2026-08-09T18:22:41+00:00",
      "error": null,
      "webhook_enabled": true,
      "running": false
    },
    "versions": [
      {
        "version": "1.4.0",
        "is_dev": false,
        "reference": "9f1c2b7e5a0d4c3b8e6f1a2d3c4b5a6978e0f1a2",
        "shasum": "3a7bd3e2360a3d29eea436fcfb7e44c735d117c4",
        "released_at": "2026-08-09T17:58:03+00:00"
      },
      {
        "version": "dev-main",
        "is_dev": true,
        "reference": "1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e",
        "shasum": "d0be2dc421be4fcd0172e5afceea3970e2f3d940",
        "released_at": "2026-08-09T17:58:03+00:00"
      }
    ]
  }
}
```

A version's full `composer.json` is not here. Composer's own `/p2` endpoint
serves it, in the minified form Composer expects, and a second rendering of it
would be a second thing to keep true.

### `POST /packages`

Creates a package from a VCS repository URL — the create wizard's job,
scriptable, and the same three steps it takes: store the package, arrange a way
to hear about its pushes, queue its first sync. Requires `api:write`.

| Field | | |
| --- | --- | --- |
| `url` | required | The VCS repository URL |
| `name` | optional | Composer name; guessed from the URL, then replaced by whatever the first sync reads from the repository's `composer.json` |
| `repository` | optional | The Composer repository's URL path; the root repository when omitted |
| `webhook` | optional, default `true` | Arrange for pushes to sync this package |
| `sync` | optional, default `true` | Queue the first sync |

```bash
curl -X POST -H "Authorization: Bearer $PP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://github.com/acme/widgets","repository":"internal"}' \
  https://registry.example.com/api/v1/packages
```

`201`, with the detail representation plus two extra keys:

```json
{
  "data": { "id": 12, "name": "acme/widgets", "…": "…" },
  "sync_queued": true,
  "warnings": [
    "No webhook covers this repository, so pushes will not sync it. The credential this package authenticates with needs write access to the repository's webhooks…"
  ]
}
```

`warnings` is what the panel raises as a persistent notice. A package that
cannot auto-sync looks exactly like one that can, until a release fails to
appear weeks later — CI logs are where this gets read, so it is said out loud
rather than left to be discovered.

The package's name is not read from the repository's `composer.json` here; that
is a round trip to the provider, and the first sync does it properly a moment
later.

### `POST /packages/{id}/sync`

Queues a sync — what CI calls after pushing a tag when no webhook covers the
repository. Requires `api:write`.

| Field | | |
| --- | --- | --- |
| `force` | optional, default `false` | Rebuild: re-import every version, trusting nothing stored |

Answers `202` with the package as it was *before* the sync ran, and
`sync_queued`. That flag is `false` when a sync was already queued for this
package — not an error: the run already waiting will read the same refs this
one would have.

A package published by artifact upload has no source to sync from and is
refused with `422`.

### `DELETE /packages/{id}`

Deletes the package, its versions and its stored archives. Requires
`api:delete`. Answers `204`.

### `GET /repositories`, `GET /repositories/{id}`

The Composer repositories this installation serves, with `packages_count` and
the `url` a consuming project configures.

Read-only. Creating a repository decides a URL every consuming project has to
be told about and an access rule for everything inside it — an operator's
decision, made once, in the panel.

---

## A release pipeline, end to end

Publishing a built artifact is the Composer protocol's job, not this API's.
What this API adds is everything around it:

```bash
set -euo pipefail

REGISTRY=https://registry.example.com
AUTH="Authorization: Bearer $PP_TOKEN"

# Resolve the package by name, creating it the first time this pipeline runs.
ID=$(curl -fsS -H "$AUTH" "$REGISTRY/api/v1/packages?name=acme/widgets&repository=internal" \
     | jq -r '.data[0].id // empty')

if [ -z "$ID" ]; then
  ID=$(curl -fsS -X POST -H "$AUTH" -H 'Content-Type: application/json' \
       -d '{"url":"https://github.com/acme/widgets","repository":"internal"}' \
       "$REGISTRY/api/v1/packages" | jq -r '.data.id')
fi

# Pull in the tag that was just pushed.
curl -fsS -X POST -H "$AUTH" "$REGISTRY/api/v1/packages/$ID/sync" > /dev/null

# Wait for the worker to finish importing it, but not forever.
for _ in $(seq 60); do
  [ "$(curl -fsS -H "$AUTH" "$REGISTRY/api/v1/packages/$ID" | jq -r '.data.sync.running')" = "false" ] && break
  sleep 5
done

# Fail the build if the release did not land.
curl -fsS -H "$AUTH" "$REGISTRY/api/v1/packages/$ID" \
  | jq -e --arg v "$CI_TAG" '.data.versions[] | select(.version == $v)' > /dev/null
```

The polling loop is worth a word. A sync is a job batch, and `sync.running`
answers for the batch that exists — which is `false` both before a worker has
picked the job up and after the batch has finished. So the loop above is not a
completion signal on its own, and the check after it is the one that decides
the build: `sync.running` is how you wait, the version being present is how you
know. Give the loop a ceiling too; a queue with no worker behind it would
otherwise spin forever.

## Versioning

The `v1` in the path is a promise about response *shape*: fields are added
inside `v1`, never removed and never given a new meaning. A change that would
break a caller gets a `v2` path, and `v1` keeps answering.
