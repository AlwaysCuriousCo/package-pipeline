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
| **Show the package type** | What Composer calls it — library, project, metapackage. |
| **Show the source repository** | A link to the repository the package is built from. **Off by default**: it is the one field on a page that names infrastructure rather than describing a package, and on a private package it publishes the organisation and repository name to anyone who opens the page. Worth switching on for anything open source. |
| **Social preview image** | The card image shown when the page is linked — an absolute URL, or a path to a file in the repository (see [Images](#images)). Empty uses `PAGE_IMAGE`. |
| **Page content** | Where the body comes from: the repository's page file or README, one file you name, or content written here. |

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

**Page content** is a choice, not an inference — pick one of three:

| Choice | What it publishes |
| --- | --- |
| **The repository's page file or README** (default) | The first of `package-page.md`, `PACKAGE-PAGE.md`, `README.md`, `readme.md`, `Readme.md` that the repository has, looked for in the package's own directory. |
| **A specific file in the repository** | One file you name — `docs/registry.md`, say — for a repository whose registry page is not its README. The field offers the conventional names and takes any path. |
| **Content written here** | Markdown written in the panel. Nothing is read from the repository, and no provider request is spent on it. The only option for a package published by artifact upload. |

`package-page.md` is preferred over a README because a project that has written
one has written it for this: a README is addressed to contributors — how to run
the test suite, how to open a pull request — where a registry page is addressed
to whoever is deciding whether to install the thing. There is an example in this
repository at [`package-page.md`](../package-page.md).

A package with none of them publishes a page of metadata and install commands
alone, which is a perfectly good page.

The file is read **at sync time**, at the ref of the release the package's
metadata came from, and stored on the package. Enabling a page queues that read
straight away so you do not have to wait for the next sync. Nothing is fetched
while somebody is looking at the page: an anonymous visitor must not put your
GitHub rate limit or GitHub's uptime in front of the page rendering.

A package with no page enabled costs nothing — the files are never looked for.

**Refresh page** on a package's view page re-reads the file straight away and
tells you which one it found. It is for the gap between syncs: somebody has just
edited the README, usually because the published page was wrong, and the
alternative is a full sync that re-reads every ref or an hour's wait. It reads at
the ref of the release the page describes, which is exactly what the next sync
would store — so the answer does not flip between the two. It is not offered for a page whose content is
written in the panel, since nothing is read for one.

### How the markdown is rendered

The body is somebody else's file, published at your origin, so:

- **Raw HTML is escaped, not passed through.** A README is allowed to contain a
  `<script>`, and the origin it would run at is the one holding your panel's
  session cookie. Escaping rather than stripping is deliberate: it is visible.
- `javascript:` and `data:` URLs are refused.
- **Relative links are resolved against the repository**, so a README's
  `docs/install.md` links to that file on GitHub. A leading slash means the
  repository root, as it does on the provider that rendered the README first;
  a monorepo package's other relative links resolve against its own
  subdirectory.
- **Relative images are re-served by the registry** — see [Images](#images).
- External links carry `rel="nofollow noopener noreferrer"` — the content is not
  yours, and the registry should not lend its ranking to whatever it links to.
- A body past `PAGE_MAX_BODY_KB` is cut on a line and marked as truncated.

## Images

A README's screenshots are relative paths, and pointing them at the provider
works only for a repository the reader can already see. For a **private** one,
`raw.githubusercontent.com` answers 404 to anyone without a GitHub credential —
which is every visitor a public page exists for — so the images would be broken
exactly where the page matters most.

So relative images are rewritten to `/p/vendor/name/asset/…` and fetched by the
registry using the package's own credentials. That is the answer for public
repositories too: the page stops depending on the provider's raw host being
reachable from wherever the reader is, and the bytes are cached here rather than
fetched per visitor.

Absolute URLs in a README — a shields.io badge, an image on a CDN — are left
exactly as written.

What keeps that route from being a way into a private repository:

- only packages that publish a page are reachable at all, and only through the
  repository mount that serves them;
- **only image extensions are served** (`png`, `jpg`, `jpeg`, `gif`, `webp`,
  `avif`, `ico`, `svg`), so it cannot be used to read source, a `.env.example`
  or CI configuration — anything else is a 404, and no provider request is made;
- the path is confined to the repository: no scheme, no host, no `..`;
- the ref is the page's own, so a URL cannot be edited into reading an
  unreleased branch;
- responses are size-bounded and cached, misses included;
- SVGs are served with `X-Content-Type-Options: nosniff` and a
  `default-src 'none'; sandbox` CSP, because an SVG is a document and this
  origin is the one holding the panel's session cookie.

### The social preview image

The same route is what makes a card image work for a private package. Slack, X
and every other link unfurler fetch an `og:image` **anonymously, from their own
infrastructure** — a raw provider URL answers them 404 and the link goes out with
no card at all.

So **Social preview image** takes either:

- an absolute URL (`https://cdn.example.com/card.png`), used as written; or
- a path to a file in the repository (`art/social-card.png`), served through the
  asset route — relative to the package's directory, or to the repository root
  with a leading slash.

`PAGE_IMAGE`, the registry-wide fallback, is a file this app serves rather than
one any repository holds: absolute, or a path under the app's public root.

## Private packages

A page on a package whose repository is **not** public still renders — that is
the point of publishing one — but everything on it that would need a credential
is withheld:

- no download links, whatever **Downloads** is set to;
- no install commands, because the tokenless pair sends a visitor to a `401`
  that reads as the registry being broken;
- an **Access required** notice in their place — its heading and body are
  `PAGE_ACCESS_HEADING` and `PAGE_ACCESS_BODY`, so a registry that sells can say
  what access costs and link at where it is bought.

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

## Presentation Mode

A page can be shown as a slide deck instead, rendered with
[SlideWire](https://github.com/WendellAdriel/slidewire). Switch on **Show the
page as a presentation** under **Presentation Mode**, inside the package's
**Public page** section. It only exists while the page itself is published.

While it is on, the deck replaces the page at the same URL, on every mount and
on a repository's own host. If there is no deck to show (no file found, nothing
pasted, no slides in it), visitors get the ordinary page and the panel says why.

### Where the deck comes from

- **A file in the repository**: the path you name, or the first of
  `PRESENTATION.md`, `presentation.md`, `slides.md`, `SLIDES.md`. It is read at
  sync time, at the latest release, exactly like a page body. **Refresh page**
  re-reads it now.
- **Markdown pasted here**, for a repository without one or a package with no
  repository.

### Writing a deck

The format is a subset that Marp, Slidev and SlideWire decks share:

```markdown
---
marp: true          # a leading frontmatter block is ignored; the panel decides how the deck looks
---

# Widgets

The fastest widgets in the west.

<!-- Any other HTML comment is a speaker note. -->

---
transition: fade    # per-slide frontmatter: background and transition only
---

## Install

![bg](art/hero.png)  <!-- Marp's background image; relative paths come from the repository -->
```

- Slides are separated by `---` on its own line, after a blank line or a `#`
  heading, or by SlideWire's `<!-- slide -->`. A `---` directly under a line of
  text is that line's heading underline, and one inside a code fence is code.
- Per slide, only a **background image** (`background:` frontmatter, Marp's
  `<!-- _backgroundImage: url(...) -->`, or `![bg](...)`) and a **transition**
  are read. Layouts, Vue components and themes are ignored.
- Raw HTML is removed, not sanitized. Code fences are syntax-highlighted.
- Speaker notes are never sent to visitors. They appear only in the presenter
  view.

At most 200 slides and `PAGE_MAX_BODY_KB` of markdown. Every slide's HTML is
in the deck's Livewire state, which is posted back on each slide change, so a
smaller deck is a faster one.

### How it looks

Every display option is set in the panel, and frontmatter in the deck never
overrides them: theme, transition, speed and duration, auto-advance, the
controls, progress bar and fullscreen button, and an **install slide**. The
install slide is generated at the end with the install commands, latest release
and license. On a private package it shows the access notice instead.

The default theme is **Always Curious**, the [brand
kit](https://brandkit.alwayscurious.app/b/alwayscurious) colours with Sofia
Sans Extra Condensed and Noto Sans. SlideWire's own seven themes can be chosen
too. Fonts are bundled from Bunny by the Vite build, never Google. Themes live
in `config/slidewire.php`.

### Presenting remotely

**Present remotely** on the package's view page starts a session from 30
minutes to a day long and gives you two links, each with a QR code:

- **Presenter**: a signed link that drives the deck and opens the presenter
  view (the slide's notes, what comes next, a clock). Treat it as a password.
- **Audience**: the page URL with the session key. Anyone who opens it follows
  the presenter.

A package has one session at a time: starting another ends the last.
**End remote session** stops both links immediately. Starting and ending a
session are written to the audit log. Viewers poll the cache every two seconds,
so a registry whose default cache store is the database should set
`SLIDEWIRE_REMOTE_CACHE_STORE=redis`.

### Search engines

The deck's page carries the same title, description, preview card and
`SoftwareSourceCode` data as the ordinary page. Every slide is in the first
HTML response, and a `<noscript>` copy shows the whole deck as one readable
page. `/p/{vendor}/{package}/slides.txt` serves the deck as plain markdown,
without notes and with the install slide. It is linked from the page head and
listed in the sitemap, and it only exists while Presentation Mode is on.

### Nothing on disk

SlideWire normally reads decks from files. Here they are read from the
database, and the compiled slides are cached for `PAGE_MARKDOWN_CACHE_MINUTES`.
Nothing is written into the app directory, so a git deploy stays clean and a
replaced app folder or a new container loses nothing.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PAGE_IMAGE` | Social preview image for every page that has not set one of its own. An absolute URL or a path relative to the app root. Around 1200×630. |
| `PAGE_SITEMAP` | Publish `/sitemap.xml` and an indexing `/robots.txt` (default `true`). |
| `PAGE_FOOTER` | The line at the bottom of every public page. Inline markdown, so it can carry a link — `PAGE_FOOTER="Built by [Acme](https://acme.example)"`. Raw HTML is escaped and `javascript:` URLs dropped, as in a package body. Empty leaves no footer. Defaults to `Served by <app name> — a private Composer registry.` |
| `PAGE_ACCESS_HEADING` / `PAGE_ACCESS_BODY` | The notice a private package's page shows in place of its install commands and download buttons (defaults: `Access required`, and a line telling the visitor to ask an administrator for a token). The body is inline markdown, so a registry that sells can link at a pricing page or a form. Both empty removes the notice. |
| `PAGE_MARKDOWN_CACHE_MINUTES` | How long a rendered body is reused (default `1440`). Keyed by a hash of the markdown, so a sync that changes the file changes the key. `0` turns it off. |
| `PAGE_MAX_BODY_KB` | Largest body stored or rendered (default `512`). |
| `PAGE_ASSET_CACHE_MINUTES` | How long an image fetched from a repository is kept (default `1440`). Keyed by ref and path, so a release that changes an image publishes the new one. `0` means one provider request per image per visitor. |
| `PAGE_MAX_ASSET_KB` | Largest image served or cached (default `4096`). |
| `SLIDEWIRE_REMOTE_CACHE_STORE` | The cache store Presentation Mode's remote sessions live in. Empty uses the default store. Viewers poll it, so `redis` is worth it where the default is `database`. |

Public pages are rate limited to 120 requests a minute per address.

## What a page never does

- It never widens Composer access. The repository's `public` flag and the tokens
  granted against it decide what installs; a page can only ever offer less.
- It never lists a package that does not publish a page.
- It never renders HTML from a repository, and a deck never shows anyone its
  speaker notes except through the presenter's signed link.
- It never serves anything from a repository but an image, and never a page
  body while somebody is waiting for a page.
