# Serving one package from several repositories

A package **lives in** one Composer repository and can be **served from** any
number of others. The same package: one row, one sync, one set of versions and
archives, one download counter — answering under every mount that serves it,
each with its own access rules.

The alternative, before this existed, was a second package pointing at the same
VCS URL. That is two syncs against the same provider, two sets of archives on
the dist disk, two rows to abandon or rename, and two chances for them to drift
apart while claiming to be the same library.

## What it is for

- **An internal build a public audience also gets.** The package lives in the
  private repository your team publishes into; the public repository serves it
  too, so an outside consumer resolves it without a token and without a second
  copy of anything.
- **Repositories per audience rather than per package.** A registry that serves
  `/r/platform` to one department and `/r/apps` to another can put the handful
  of shared libraries in both, without either department's mount listing the
  other's packages.
- **Moving a package without a flag day.** Add it to the new repository, let
  consumers migrate their `composer.json` at their own pace, then stop serving
  it from the old one.

It is *not* mirroring. [Mirroring](mirroring.md) is how a repository answers for
packages published somewhere else entirely — packagist.org, a corporate proxy —
by fetching and caching their metadata over the network. This is one
installation's own package, served from more than one of its own mounts, with
no network involved and nothing cached.

## Doing it

**In the panel**, from either side:

- The package's own form has **Also served from** beside the repository it
  lives in. Everything chosen there serves the same package.
- A repository's **Packages** list has **Serve an existing package**, and a
  **Stop serving** action on the rows that live somewhere else.

**Over the API** (`api:write`), naming the repository by its mount path:

```bash
curl -X POST -H "Authorization: Bearer $PP_TOKEN" \
  -d 'repository=internal' \
  https://registry.example.com/api/v1/packages/12/repositories

curl -X DELETE -H "Authorization: Bearer $PP_TOKEN" \
  -d 'repository=internal' \
  https://registry.example.com/api/v1/packages/12/repositories
```

**From a shell**:

```bash
php artisan package:serve acme/widgets internal
php artisan package:serve acme/widgets internal --remove
php artisan package:serve acme/widgets root          # the registry root
```

## The rules

**One repository serves one package per name.** Everything on the Composer
surface — `/p2`, `/dist`, the page, the upload endpoint — resolves a name
*inside* a repository, so two packages sharing a name there would be a lookup
with no right answer. A repository that already answers for the name refuses
the addition, and says so. The same rule catches a rename afterwards: renaming a
package moves the name under every mount serving it at once, and one of them
refusing is the whole rename refused.

**Reserved vendors apply per mount.** A repository that has
[reserved a vendor prefix](dependency-confusion.md) is the only one that may
introduce names under it, and adding a package is introducing one. A package
whose vendor another repository has reserved cannot be served here, for exactly
the reason it could not have been created here.

**Access is decided by the mount, never by the package.** A package added to a
private repository is private *there* even if it is public where it lives, and
a package added to a public repository is readable there even if it lives
somewhere private. This is the point of the feature and the sharpest edge on it:
**adding a package to a public repository publishes it.** There is no second
switch — the repository's `public` flag is the switch, and it was already the
one deciding this for everything else the repository serves.

**Publishing stays with the repository the package lives in.** A share serves
what a package publishes; it does not confer the right to publish into it. An
artifact upload addressed to a mount that serves a package from elsewhere adds
a version to that package — but only for a credential that could have published
into the package's own repository. A grant on the serving repository alone
reads, and does not write.

**The page belongs to the mount it was reached through.** A package served from
two repositories has a page under each, and each prints its own mount's
`composer config` line, its own canonical URL, and its own answer to whether an
anonymous visitor may download an archive. Both are listed in `/sitemap.xml`.

**Where it lives is still one repository.** That is what `repository_id` on the
package says, and it decides three things: which repository's mount its
canonical page URL is cut from, which repository a reserved vendor is measured
against when the package is created or renamed, and who may publish into it.
Changing it is a *move* — the package stops being served from the old
repository — and it is offered on the package's form rather than from the
repository's side, because it changes the URL Composer resolves the package at.
Removing a package from the repository it lives in is refused everywhere.

## What a consumer sees

Nothing new. Each mount is a Composer repository like any other, and a package
served from two of them is two `repositories` entries a project could point at —
resolving to the same package, the same versions and the same zipballs.

A project that lists both mounts resolves the name from whichever it is told to
prefer, exactly as it would for two unrelated registries; Composer's own rules
apply, and [dependency-confusion.md](dependency-confusion.md) covers what to
tell a consuming project when a name matters.

## Audit

Adding and removing a serving repository is filed in the audit log against the
package, as `serving_added` and `serving_removed`, with the repositories named.
Where a package is served from is not a permission, but it is asked about the
same way — "when did this land in the public repository, and who put it there" —
and an attribute diff cannot see a pivot row.
