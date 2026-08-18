# Public pages

A package or a repository can publish a page anyone can read — no account, no
token, no Composer. It shows what the package is, the `README.md` (or a
`package-page.md` written for the purpose) out of its repository, the two
commands that install it, and, if you switch it on, a download for the latest
release or for every version.

Nothing is published until you enable a page. A registry upgraded to this
release serves exactly what it served before.

## What a page is for

The URL you hand a project — `composer config repositories.acme composer
https://packages.example.com` — is the first thing anyone pastes into a
browser, and before this it answered a redirect to a login form for an account
they do not have. Now it can answer a page describing what is behind it.

Three cases it covers:

- **An open-source package published from your own registry.** The page is the
  package's home on the web: readable, linkable, indexable, with the install
  commands right there.
- **A private package somebody has been told about.** The page describes it and
  says access is required, rather than 404ing at a colleague who was sent the
  name.
- **A repository landing page**, listing the packages it publishes pages for.

## Enabling one

**A package:** open it in the panel, **Edit → Public page**, and turn
**Publish a public page** on. Four settings appear:

| Setting | What it does |
| --- | --- |
| **Downloads** | `No downloads` (default), `Latest release only`, or `Every version`. The middle one hands out the current artifact without publishing the release history with it. |
| **Show install commands** | The `composer config` and `composer require` lines, exactly as the panel shows them. |
| **Show the version history** | A table of released versions and their dates. |
| **Social preview image** | The card image shown when the page is linked. Empty uses `PAGE_IMAGE`. |
| **Page content** | Markdown written here, which wins over anything in the repository. Leave it empty to publish the repository's own file. |

The page answers at the package's own URL inside its repository's mount:

```
https://packages.example.com/p/acme/widgets              # default repository
https://packages.example.com/r/internal/p/acme/widgets   # a named repository
```

Package names are unique per repository rather than per registry, which is why
the page lives under the mount rather than at one rooted `/p/` namespace.

**A repository:** **Repositories → (the repository) → Public page**. Its page is
served at the URL its Composer endpoints already hang off — `/` for the default
repository, `/r/{path}` for a named one — and lists the packages that publish
pages of their own. A package with no page is not named: naming one the registry
will not describe is how a private package's existence leaks out of a public
landing page.

Turning the default repository's page off puts the root back to redirecting to
`/admin/login`, which is what it has always done.

## Where the content comes from

In order:

1. **Page content** written in the panel, if you have written any.
2. **`package-page.md`** in the repository, at the package's subdirectory. A
   project that has written one has written it for this — a README is addressed
   to contributors, a registry page to whoever is deciding whether to install
   the thing.
3. **`README.md`** (also `readme.md`, `Readme.md`).
4. Nothing, in which case the page is the package's metadata and install
   commands alone.

The file is read **at sync time**, at the ref of the release the package's
metadata came from, and stored on the package. Enabling a page queues that read
straight away so you do not have to wait for the next sync. Nothing is fetched
while somebody is looking at the page: an anonymous visitor must not put your
GitHub rate limit or GitHub's uptime in front of the page rendering.

A package with no page enabled costs nothing — the files are never looked for.

### How the markdown is rendered

The body is somebody else's file, published at your origin, so:

- **Raw HTML is escaped, not passed through.** A README is allowed to contain a
  `<script>`, and the origin it would run at is the one holding your panel's
  session cookie. Escaping rather than stripping is deliberate: it is visible.
- `javascript:` and `data:` URLs are refused.
- **Relative links and images are resolved against the repository**, so a
  README's `docs/install.md` links to that file on GitHub and
  `![](art/logo.png)` renders the image from the repository rather than 404ing
  here. Monorepo packages resolve against their own subdirectory.
- External links carry `rel="nofollow noopener noreferrer"` — the content is not
  yours, and the registry should not lend its ranking to whatever it links to.
- A body past `PAGE_MAX_BODY_KB` is cut on a line and marked as truncated.

## Private packages

A page on a package whose repository is **not** public still renders — that is
the point of publishing one — but everything on it that would need a credential
is withheld:

- no download links, whatever **Downloads** is set to;
- no install commands, because the tokenless pair sends a visitor to a `401`
  that reads as the registry being broken;
- an **Access required** notice in their place.

The **Downloads** setting is kept rather than cleared, so making the repository
public later restores what you chose.

> A token-request form belongs in that notice and is not built yet. The page,
> the block and the wording are shaped for it.

## Downloads

A page's download link is a third route to the archive, alongside the Composer
dist endpoint and the panel's own:

```
/p/acme/widgets/download            # the current release — a link worth pasting anywhere
/p/acme/widgets/download/v1.0.0     # one version, only when "Every version" is set
```

With **Latest release only**, any other version is a `404`: the history cannot be
walked by editing the URL. Downloads are counted exactly like every other
archive that leaves the registry, so the numbers mean one thing across all three
routes. `HEAD` is not counted.

## Search and social previews

Every page carries what a machine reads about it, built from facts the registry
already holds:

- a canonical URL;
- Open Graph and Twitter card tags — `og:title`, `og:description`, `og:url`,
  `og:image`, and `twitter:card` set to `summary_large_image` when there is an
  image and `summary` when there is not, since asking for the large card without
  one renders as a broken panel;
- JSON-LD: `SoftwareSourceCode` for a package (with its version, license and
  source repository), `CollectionPage` for a repository, with its packages as an
  `ItemList`.

`/sitemap.xml` lists every published page and `/robots.txt` points at it, keeping
crawlers off `/admin`, `/p2/` and `/dist/`. Set `PAGE_SITEMAP=false` to publish
neither — pages stay reachable and unlisted, which is the right answer for a
registry whose pages are for people who were sent the link. Both documents still
answer in that case, robots.txt with a blanket disallow, because a `404` there is
read by some crawlers as permission to crawl everything.

> [!NOTE]
> Laravel ships a static `public/robots.txt`, which would shadow this route. It
> is removed in this release. If your deployment restores it, the file wins and
> the setting does nothing.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PAGE_IMAGE` | Social preview image for every page that has not set one of its own. An absolute URL or a path relative to the app root. Around 1200×630. |
| `PAGE_SITEMAP` | Publish `/sitemap.xml` and an indexing `/robots.txt` (default `true`). |
| `PAGE_MARKDOWN_CACHE_MINUTES` | How long a rendered body is reused (default `1440`). Keyed by a hash of the markdown, so a sync that changes the file changes the key. `0` turns it off. |
| `PAGE_MAX_BODY_KB` | Largest body stored or rendered (default `512`). |

Public pages are rate limited to 120 requests a minute per address.

## What a page never does

- It never widens Composer access. The repository's `public` flag and the tokens
  granted against it decide what installs; a page can only ever offer less.
- It never lists a package that does not publish a page.
- It never renders HTML from a repository.
- It never fetches from a provider while answering a request.
