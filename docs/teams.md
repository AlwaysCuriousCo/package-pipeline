# Teams

Grants held once, by a name that says why.

Without teams, access is per person: onboarding a developer means opening their
account and re-granting whatever the last one was granted, and the answer to
"who can reach the internal registry?" lives nowhere but in a pivot table. A
team is the same grants, held by a group.

**Teams** in the sidebar, next to Users and Roles — which is where they belong,
because all three answer the same question from different directions: a role
says what an account may *do*, a grant says what it may *see*, and a team says
which grants it holds.

## What a team is

A team has members, and it holds the same two kinds of grant a user can be
given individually:

| Grant | What it gives every member |
| --- | --- |
| Granted repositories | Every package in the chosen repositories, present and future |
| Granted packages | Those packages, wherever they are served from |

That is the whole model. There are no team roles, no owners, and no
per-team permissions: what a member may *do* is still their own role's
business, and a team only ever widens what they may *see*.

## Who may manage them

`Create:Team` and `Update:Team` are permissions to hand out like
`Unscoped:Package`, not like `Update:Package`. Whoever holds them can put
anything they can see into a team and add themselves to it, so in practice the
permission reads "may grant themselves what they can already reach".

Which is why the two grant pickers on the Teams screen list only what the person
filling them in can see. A team may still hold grants beyond that — an unscoped
administrator's — and those are left alone by a scoped editor's save rather than
quietly revoked. So the screen is not always the whole of what a team grants;
the user edit screen's **Effective access** is.

## Effective access

A user's reach is **their own grants plus their teams'** — a union, never a
replacement.

- A user in no team is unaffected by any of this. Their access is exactly what
  it was.
- A user in a team keeps every personal grant they hold and gains the team's.
- Removing somebody from a team takes back **only what the team gave**. Their
  personal grants are untouched, as is anything a second team grants them.
- Deleting a team, or removing a grant from one, revokes it for every member at
  once, immediately.
- A role carrying `Unscoped:Package` already sees everything, so teams change
  nothing for it.
- Public repositories are readable by everyone regardless — a grant on one adds
  nothing.

The user edit screen states the result outright, under **Effective access**: how
many packages the account can actually reach and which teams contributed. It is
read from the same query the registry serves by, so it cannot drift from what
the account really sees.

## Where it applies

Everywhere, because there is only one place it is decided.

`Package::scopeVisibleToUser` is the single chokepoint every read goes through:
the panel's package table, the dashboard widgets, the license report, the SBOM
export, the Composer endpoints (`packages.json`, `list.json`, `search.json`,
`/p2`, `/dist`, the advisory endpoint), `/api/v1`, and upstream mirroring.
Teams were added there and nowhere else, so there is no surface that can be out
of step with the others.

A **personal access token sees exactly what its owner does**, teams included —
so a developer added to a team can `composer install` the team's packages
without reissuing anything.

Writing follows the same rule. A team's repository grant confers the right to
publish artifact uploads into it and the API's mutating endpoints on it, exactly
as a personal grant of the same repository does. A grant that meant one thing
held personally and another held through a team would be a second, invisible
kind of grant.

**Deploy tokens have no teams.** They authenticate a machine, which cannot be a
member of anything; a deploy token holds its own grants and only those.

## What it costs

Nothing measurable on the hot path. Team grants did not become extra clauses on
the visibility query — they were folded into the two grant subqueries it already
had, so the scope has exactly the three branches it had before teams existed:
public repository, package grant, repository grant. Each grant subquery is now a
union of two indexed lookups over pivot tables.

That matters because this query runs once per `/p2` metadata request and once
per `dist` download — which is once per dependency, per `composer update`, per
developer.

## A worked example

An organisation with an `internal` repository and a `vendor-tools` repository:

1. Create a team **Platform**, grant it the `internal` repository.
2. Create a team **Integrations**, grant it `vendor-tools` and the single
   package `acme/legacy-bridge` out of `internal`.
3. Add developers to one or both.

A developer in Integrations alone can reach everything in `vendor-tools` plus
`acme/legacy-bridge`, and nothing else in `internal`. Add them to Platform as
well and they reach all of `internal` too. Take them out of Platform when they
move on and the `internal` access goes with it, while `acme/legacy-bridge`
stays — because that is Integrations' grant, not Platform's.

## Upgrading

The Teams screen is a new Filament resource, so its permissions have to be
generated before anyone can open it:

```bash
php artisan shield:generate --all --panel=admin
php artisan admin:create --email=you@example.com   # re-syncs super_admin
```

Existing per-user grants are untouched by the migration and keep working
exactly as they did. Teams add reach; they never take any away.
