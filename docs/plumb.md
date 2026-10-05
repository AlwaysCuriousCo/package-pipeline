# Plumb scores

[Plumb](https://plumbphp.dev) scores PHP packages from 0 to 100 on security,
maintenance and ecosystem health, with a weighted composite. Turned on, this
registry asks Plumb about each of its Composer packages every night and shows
the answer in the panel, beside the package and beside each version Plumb has
scanned.

Plumb is a hosted service. Nothing runs here and nothing is uploaded: the
registry sends a package's name and reads back scores Plumb worked out from the
package's public source.

## Turning it on

```dotenv
PLUMB_ENABLED=true
```

Then either wait for the nightly run or fetch straight away:

```sh
php artisan migrate
php artisan plumb:refresh                 # every Composer package
php artisan plumb:refresh acme/widgets    # one
```

It is off by default because **asking is telling**. Each lookup sends a
package's name to plumbphp.dev, private packages included, and the name of a
private package is something a private registry otherwise never says out loud.

## What it can and cannot score

- **Composer packages only.** npm and PyPI packages are never asked about.
- **Only packages Plumb can see.** Plumb scans what is on Packagist, plus
  private packages their owner has registered with Plumb — whose scores are
  then public on plumbphp.dev. Everything else shows **Not scored**. For a
  registry of purely private, unregistered packages that is every package, and
  there is no point turning this on.
- **Mirrored packages are not scored.** An upstream's packages are a cache, not
  this registry's inventory, and they are not in the package list to show a
  score beside.
- **A version is scored only if Plumb scanned it.** Plumb always scans a
  package's latest stable release; it cannot be asked about a version. What
  makes scores per version possible is its scan history: every scan names the
  release it ran against, so the newest scan of each release becomes that
  version's score. A release that was superseded before Plumb got to it, a
  release older than Plumb's first scan of the package, and every dev version
  have none.
- **The newest hundred scans.** That is one page of Plumb's history, and all
  that is read.

## Where it shows

- **Packages** — a **Plumb** column, linking to the package's page on Plumb,
  where the individual checks behind the number are.
- **A package's page** — the score with its three category scores and the
  release it was scanned against.
- **Versions** — a **Plumb** column, and the breakdown in a version's detail.

The colours follow the ratings plumbphp.dev prints beside its scores: green
from 86, blue from 70, amber from 50, red below.

Scores are not shown on public package pages or served through any API. Plumb's
[usage guidelines](https://plumbphp.dev/api-usage) require a publicly displayed
score to carry "Powered by Plumb", linked to Plumb; the panel carries that
line and link anyway.

## The nightly run

`plumb:refresh` is scheduled at 04:30. It makes one request per package name,
half a second apart, which keeps it under Plumb's limit of 120 reads a minute
whatever the size of the registry. A score never moves a package's Composer
metadata: clients revalidating `/p2` are not sent back for a document that has
not changed.

If Plumb answers `429` the run stops and reports failure; the next night picks
up where the limit fell. A package Plumb stops answering for loses its score.
