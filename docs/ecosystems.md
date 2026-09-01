# npm and Python packages

Package Pipeline began as a Composer repository, and that is still the surface
with the most machinery behind it (VCS syncing, mirroring, advisories). But
the core — repositories, tokens, per-package visibility, archive storage,
download accounting, public pages — is not Composer-specific, and the same
deployment also answers as an **npm registry** and a **Python package index**.
One URL, one set of deploy tokens, three package managers.

Every repository mount serves all three: the default repository at the site
root, and each named repository under its `/r/{path}` prefix. A package
belongs to exactly one ecosystem — the panel's package table has an ecosystem
column and filter, hidden until you need them.

Both surfaces are publish-only: point your CI at the endpoint and push what it
built. There is no VCS syncing for npm or Python packages (their build steps
make "the repository's tags" the wrong source of truth), and no upstream
proxying of npmjs.org or pypi.org yet.

## npm

Point a scope at the registry (preferred — everything else keeps resolving
from npmjs.org), or move the whole registry:

```bash
npm config set @acme:registry https://packages.example.com/npm/
npm config set //packages.example.com/npm/:_authToken <token>
```

For a named repository the registry URL is
`https://packages.example.com/r/internal/npm/`, and the `_authToken` line uses
that path too.

Tokens are the same access and deploy tokens the Composer endpoints use; npm
sends them as bearer credentials. Reads on a public repository need no token.

Publishing is standard `npm publish` with a write-able token. The registry
computes the tarball's `shasum` and sha512 `integrity` from the received bytes
— the client's own claims are never echoed back — and, like the Composer
artifact upload, re-publishing an existing version replaces it.

Package names follow npm's rules: lowercase, either a bare name (`widgets`)
or scoped (`@acme/widgets`). Not implemented (say so before relying on them):
`npm audit` against this registry, dist-tags beyond `latest`, `npm deprecate`,
and `npm unpublish` — the panel's package and version deletion stand in.

## Python

Add the index beside pypi.org (preferred), authenticating with the token as
the basic password — the username can be `__token__` or anything else:

```bash
pip config set global.extra-index-url https://__token__:<token>@packages.example.com/pypi/simple/
```

Publish with twine:

```ini
# .pypirc
[distutils]
index-servers = private

[private]
repository = https://packages.example.com/pypi/legacy/
username = __token__
password = <token>
```

```bash
twine upload --repository private dist/*
```

Project names are normalized as PEP 503 requires (`My_Widget.Kit` and
`my-widget-kit` are the same project). A release accumulates every file
uploaded for it — the sdist and each wheel — and the simple index serves them
all, each with the sha256 pip verifies and the file's `requires-python`. If
twine sends a `sha256_digest`, the upload is refused when the received bytes
do not match it. As with npm, re-uploading an existing filename replaces it.

The index is the PEP 503 HTML form, which pip speaks fluently; the JSON form
(PEP 691) is not served.

## Names are unique per repository, across ecosystems

Within one repository, a name identifies one package whatever its ecosystem —
so an npm `widgets` and a PyPI `widgets` cannot both live in the same
repository. A publish that collides is refused with a 409 naming the other
package. Composer names (`vendor/name`) and scoped npm names (`@scope/name`)
never collide with anything else by shape; the overlap is only between bare
npm names and Python project names. If it bites, serve the two ecosystems
from different repositories.

Reserved vendors apply to the other ecosystems too: an npm scope (`@acme`) or
a bare name is checked against reservations exactly as a Composer vendor is.

## Pages, badges, and the rest

Public pages work for any package whose name has a vendor segment — every
Composer package, every scoped npm package. A bare npm or Python name has no
`/p/{vendor}/{package}` URL, so its page switch publishes nothing until the
package is renamed into a scope. Download statistics, the SBOM export, teams
and per-package grants, and billing entitlements treat every ecosystem's
packages alike.
