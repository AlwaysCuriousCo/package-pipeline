# Dependency confusion, and how to be safe from it

A project that installs from this registry almost always installs from
packagist.org as well — Composer adds packagist.org implicitly, so listing one
private repository is enough to be using both. When two repositories can answer
for one package name, something has to decide which one wins. Get that decision
wrong and an attacker who has never touched your infrastructure can put code
into your build, by publishing a package on packagist.org under a name they
guessed you use privately.

This is **dependency confusion** (or dependency hijacking), the attack class
[Alex Birsan demonstrated against Apple, Microsoft and dozens of others in
2021](https://medium.com/@alex.birsan/dependency-confusion-4a5d60fec610). The
Composer team's own response, published two days later, is still the definitive
PHP-specific guidance: [Preventing Dependency Confusion in PHP with
Composer](https://blog.packagist.com/preventing-dependency-hijacking/).

Defending against it takes two halves, and **the half that actually stops the
attack is the consumer's**. This registry can make sure a vendor prefix is
served from exactly one place *here*; only a consuming project's `composer.json`
can say that this registry is where `acme/*` comes from, full stop.

## The server half: reserving vendors

Open **Composer repositories → (a repository) → Reserved vendors** and add the
vendor prefixes that repository owns — `acme`, not `acme/widgets`.

From then on, no other repository in this installation may introduce a package
name under that vendor. The rule is enforced everywhere a name is decided: the
create wizard, `POST /api/v1/packages`, `php artisan package:add`, an artifact
upload to `POST /upload/{vendor}/{package}`, and a sync adopting the name a
repository's `composer.json` declares. A vendor may be reserved by one
repository only — two claims on one prefix is exactly the ambiguity a
reservation exists to remove.

Two things it deliberately does not do:

- **It does not break packages that predate it.** Reserving `acme` while
  another repository already serves `acme/widgets` leaves that package
  publishing as before. Reservations govern what may be *introduced*, and a
  namespace policy that took a running pipeline down would not be worth having.
- **It says nothing about who may publish.** Authorisation is still the grant
  system's answer: a principal needs write access to the owning repository as
  well. A reservation bounds *where* a name may appear.

What this buys you is that a mistake — or a credential with more reach than it
should have — cannot quietly start serving `acme/anything` from the wrong
repository here. What it cannot do is stop `acme/anything` being published on
packagist.org. That is the next section, and it is the important one.

## The consumer half: making this registry canonical

Everything below goes in the **consuming project's** `composer.json`. There is
nothing to configure on this registry for any of it.

### 1. Composer 2 already resolves in your favour — know why

> When Composer resolves dependencies, it will look up a given package in the
> topmost repository. If that repository does not contain the package, it goes
> on to the next one, until one repository contains it and the process ends.
>
> By default in Composer 2.x all repositories are canonical. Composer 1.x
> treated all repositories as non-canonical.
>
> — [Repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md)

"Canonical" is what makes this safe rather than merely ordered. From the same
page:

> Let's say you have a private repository which is not canonical, and you
> require your private package `foo/bar ^2.0` for example. Now if someone
> publishes `foo/bar 2.999` to packagist.org, suddenly Composer will pick that
> package as it has a higher version than your latest release (say 2.4.3), and
> you end up installing something you may not have meant to. However, if the
> private repository is canonical, that 2.999 version from packagist.org will
> not be considered at all.

So on Composer 2, for a package this registry **already serves**, a higher
version on packagist.org does not win. That is the default and you get it for
free. Two caveats make it insufficient on its own:

- Never set `"canonical": false` on this registry's entry. It exists for
  genuinely additive mirrors, and it is precisely the setting that reopens the
  hole above.
- It only protects names this registry *answers for*. A name you have not
  published yet, or a typo, or a package that has been deleted here, falls
  through to packagist.org — where an attacker is free to have registered it.
  That is what the next step closes.

### 2. Cut your vendor prefixes out of packagist.org

`exclude` filters what a repository is allowed to answer for:

> Both `only` and `exclude` should be arrays of package names, which can also
> contain wildcards (`*`), which will match any character.
>
> — [Repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md)

Point it at packagist.org and name your own vendors. This is the configuration
the Composer team recommends:

```json
{
    "repositories": {
        "package-pipeline": {
            "type": "composer",
            "url": "https://registry.example.com"
        },
        "packagist.org": {
            "type": "composer",
            "url": "https://repo.packagist.org",
            "exclude": ["acme/*"]
        }
    }
}
```

Now `acme/anything` can only ever resolve from your registry. If it is not
there, the install fails — which is the correct outcome, and the whole point:
a build that fails loudly beats one that succeeds with somebody else's code.

Note the shape. Naming the entries (`"package-pipeline"`, `"packagist.org"`) is
the object form, which is also what `composer config repositories.<name>
composer <url>` writes; `"packagist.org"` must be spelled exactly that way for
the second entry to override Composer's implicit one rather than add a third
repository beside it. Entries are consulted in the order they appear in the
file, and packagist.org is implicitly last unless you list it — so listing it
explicitly, below your registry, changes nothing about priority and everything
about what it may answer for.

Keep the `exclude` list in step with the vendors reserved on the registry.
There is no mechanism that syncs the two: the registry cannot reach into a
consuming project's `composer.json`, which is why this half is a policy your
projects have to carry.

### 3. If you install nothing from packagist.org, say so

For a project whose every dependency comes from this registry — an internal
service with vendored dependencies, an air-gapped build — turn packagist.org
off entirely:

```json
{
    "repositories": [
        { "type": "composer", "url": "https://registry.example.com" },
        { "packagist.org": false }
    ]
}
```

Or globally, for a machine that should never reach it:

```bash
composer config -g repo.packagist.org false
```

This is the strongest form of the defence and the least often applicable. Most
projects need packagist.org and should use `exclude` instead.

### 4. Claim your vendor prefix on packagist.org anyway

Belt and braces, and free:

> If your company has at least one public package on packagist.org with your
> vendor prefix (even an empty one works), e.g. `my-company/dummy-pkg`,
> attackers cannot create packages there which would match any of your internal
> package names using your vendor prefix.
>
> — [Preventing Dependency Confusion in PHP with Composer](https://blog.packagist.com/preventing-dependency-hijacking/)

Packagist.org reserves a vendor prefix for whoever publishes under it first.
Publishing one throwaway package under `acme/` denies the whole namespace to
everyone else — including every project of yours that has not got its
`exclude` right yet. This is the one step that protects projects you do not
control and cannot audit.

### 5. Commit the lock file, and `install` rather than `update` in CI

> We strongly recommend you commit the lock file to a version control system
> and only use `composer install` during build steps, which will only ever
> install the exact versions listed in the lock file.
>
> — [Preventing Dependency Confusion in PHP with Composer](https://blog.packagist.com/preventing-dependency-hijacking/)

`composer.lock` pins the exact version *and the URL it came from*, so a build
running `composer install` resolves nothing and cannot be redirected. It moves
the decision to the one place a human reviews it: the pull request that changes
the lock file. Nothing above helps a pipeline that runs `composer update` on
every build.

## The whole thing, in one file

```json
{
    "repositories": {
        "package-pipeline": {
            "type": "composer",
            "url": "https://registry.example.com"
        },
        "packagist.org": {
            "type": "composer",
            "url": "https://repo.packagist.org",
            "exclude": ["acme/*", "acme-labs/*"]
        }
    },
    "require": {
        "acme/widgets": "^1.4"
    }
}
```

Plus, once: a placeholder package under `acme/` on packagist.org, and
`composer.lock` in version control with `composer install` in CI.

## Checking it worked

The `dist` URL of an installed package is the repository that answered for it,
and `composer.lock` records the same thing — so grepping the lock file for your
registry's host tells you, per package, where it actually came from:

```bash
composer show acme/widgets --all | grep -E '^(dist|source)'
```

The blunter check is to prove the exclusion bites. Ask for a name under your
vendor that this registry does not serve:

```bash
composer require acme/definitely-not-real:^1.0 --dry-run
```

With `exclude` in place this fails to find the package at all. Without it, and
with somebody squatting the name, it finds one — which is the failure this
whole document exists to prevent, and the only way to see it is to go looking.

## Further reading

- [Repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md) — canonical repositories, `only`, `exclude`, and how lookup order works.
- [Repositories](https://getcomposer.org/doc/05-repositories.md) — repository types and disabling packagist.org.
- [Preventing Dependency Confusion in PHP with Composer](https://blog.packagist.com/preventing-dependency-hijacking/) — the Composer team's own guidance, February 2021.
- [Dependency Confusion](https://medium.com/@alex.birsan/dependency-confusion-4a5d60fec610) — Alex Birsan's original research, February 2021.
