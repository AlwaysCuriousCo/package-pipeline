# Monorepo packages

One repository, several packages. An organisation that keeps `acme/widgets`,
`acme/gadgets` and `acme/core` in a single `acme/mono` repository can publish
all three from this registry, each with its own name, versions, dist archives
and access rules.

Add a package as usual and fill in **Subdirectory** with the directory holding
its `composer.json` — `packages/widgets`. Leave it empty and the package is
published from the repository root, which is what every package was before this
existed and what most of them still are.

## What it changes

| | Root package | Subdirectory package |
| --- | --- | --- |
| Manifest read from | `composer.json` | `packages/widgets/composer.json` |
| Versions discovered from | the repository's tags and branches | the same |
| Dist archive | the provider's zipball, stored byte for byte | that zipball cut down to `packages/widgets`, re-rooted |
| A push syncs | this package | every package published from the repository |

Versions come from the repository's tags and branches, because that is all a
git repository has. Every package in a monorepo therefore shares its version
numbers — tag `v1.4.0` and all three packages gain a `1.4.0`. This is how
monorepo publishing works generally (it is what `symfony/symfony` and Laravel's
own component split do), and it is why release tooling for monorepos tags the
whole repository rather than one directory of it.

A ref whose subdirectory holds no `composer.json` is skipped rather than
failed, exactly as a root package's ref without one is: the package simply has
no version at that tag. That is what makes it safe to add a package for a
directory introduced half way through a repository's history.

## The dist archive

This is the part worth understanding, because it is where a subdirectory
package could quietly go wrong.

Composer installs a dist by extracting the zip and, if everything in it sits
under a single directory, treating that directory as the package root. Neither
GitHub nor GitLab will archive part of a repository in that shape — GitHub's
zipball is the whole tree, and GitLab's `path` parameter keeps the directory
nested where it was. So an unmodified zipball offered as the dist for
`packages/widgets` would install a `packages/` folder into the consumer's
`vendor/acme/widgets/` and no `composer.json` where one has to be.

The archive is therefore **re-rooted** after it is downloaded and before it is
stored: everything outside `packages/widgets/` is dropped, and everything
inside it moves up to sit under one directory named for the package and
version. What Composer downloads is the package's own tree and nothing else.

Nothing is unpacked to do it. The download is edited in place through the zip's
central directory — entries outside the subtree are deleted, entries inside are
renamed — and libzip copies the compressed data of an entry it was not asked to
change byte for byte. No file in the archive is ever decompressed, recompressed
or held in memory, which is what keeps the property the archive path has always
had: a large repository costs disk, not RAM. The cost is one rewrite of the
temporary file, so peak disk is roughly twice the archive and peak memory is
unchanged. See `App\Services\ArchiveSubtree`.

Two consequences worth knowing:

- **The `shasum` is of the re-rooted archive**, not of the provider's zipball.
  It has to be — it is what Composer verifies the download against.
- **An archive that does not contain the directory fails that version's
  import**, rather than storing whatever it did contain. A dist holding the
  wrong tree is worse than a version that is missing: the missing one is
  obvious and the next sync retries it, while the wrong one installs.

## Webhooks

A push to a monorepo syncs **every** package published from it. The registry
cannot tell which packages a commit touched without reading every manifest in
the repository, and a push that silently updated only one of them would be
worse than a little extra work.

That also means one webhook is enough for the whole repository. A package added
to a repository that already has a hook on it reports its auto-sync as
**Shared repository webhook** and creates nothing — which matters beyond
tidiness, since GitHub caps a repository at 20 hooks and a monorepo can easily
hold more packages than that.

If you connect a GitHub App source, none of this arises: the app's own webhook
covers every repository in the installation, monorepos included. See
[webhooks.md](webhooks.md).

## Names

The package name comes from the subdirectory's `composer.json`, as it always
does. Where the registry has to guess — a private repository it cannot read on
the create wizard — it guesses from the directory rather than the repository:
`acme/mono` + `packages/widgets` guesses `acme/widgets`. Guessing `acme/mono`
for every package in the repository would not merely be unhelpful, it would be
unusable, since names are unique per Composer repository.

## Serving the same repository twice

A repository URL is claimed once per subdirectory per Composer repository. So:

- `acme/mono` at `packages/widgets` and `acme/mono` at `packages/gadgets` in
  one Composer repository — fine, that is the feature.
- `acme/mono` at `packages/widgets` twice in one Composer repository — refused.
- `acme/mono` at `packages/widgets` in two different Composer repositories —
  fine, as it always was. The two are separate registries.

## From the command line and the API

```bash
php artisan package:add https://github.com/acme/mono --subdirectory=packages/widgets
```

```bash
curl -X POST https://packages.example.com/api/v1/packages \
  -H "Authorization: Bearer $TOKEN" \
  -d url=https://github.com/acme/mono \
  -d subdirectory=packages/widgets
```

Both refuse a path containing `..`, as does the panel. The value is
interpolated into provider API paths and matched against archive entry names,
so it is one of the few inputs here that is refused rather than sanitised.
