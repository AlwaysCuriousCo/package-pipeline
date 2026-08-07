# Connecting GitHub sources

A **source** is a connected GitHub account — an organisation or user — that
packages are pulled from. Packages under that account authenticate through the
source, so no repository needs a token of its own.

Sources authenticate with a **GitHub App** rather than a personal access token.
An installed app issues tokens that expire after an hour and only reach the
repositories chosen at install time, and it belongs to the organisation rather
than to a person, so access does not disappear when someone leaves.

## 1. Register the app

Once per deployment — a local or staging site needs its own app, for the reason
in [Connecting the same account from a second
environment](#connecting-the-same-account-from-a-second-environment) — at
**Settings → Developer settings → GitHub Apps → New GitHub App** (on the
organisation that will own it):

| Field | Value |
| --- | --- |
| GitHub App name | e.g. `Acme Package Pipeline` |
| Homepage URL | your `APP_URL` |
| Setup URL | `<APP_URL>/sources/github/callback` |
| Redirect on update | ✅ checked |
| Webhook → Active | ✅ checked |
| Webhook URL | `<APP_URL>/incoming/github` |
| Webhook secret | a long random string, also set as `GITHUB_APP_WEBHOOK_SECRET` |

Subscribe the app to **Push**, **Branch or tag creation** and **Branch or tag
deletion** under **Permissions & events**. That one webhook covers every
repository in every installation, so packages sync themselves the moment
something is pushed — see [webhooks.md](webhooks.md) for the whole picture.

Repository permissions — read-only is enough for syncing and serving dists:

- **Contents**: Read-only
- **Metadata**: Read-only (mandatory)

These are not optional polish. GitHub only offers a repository picker for apps
that ask for at least one repository permission, so an app registered without
them installs onto an account sharing nothing, and every source connected
through it reads zero repositories. If that has already happened, add the
permissions under **Permissions & events** on the app's settings, then accept
the permission request on each installation — GitHub does not apply new
permissions to an existing install until its owner approves them.

Then **Generate a private key** and download the `.pem`.

## 2. Configure this app

Two values, both on the app's settings page:

```dotenv
GITHUB_APP_ID=123456
GITHUB_APP_PRIVATE_KEY=/secure/path/acme-package-pipeline.private-key.pem
GITHUB_APP_WEBHOOK_SECRET=the-same-secret-set-on-the-app-s-webhook
```

These identify *this registry* to GitHub — the equivalent of an OAuth
`client_id` and `client_secret`. They are set once by whoever runs the app, and
are what make the one-click **Connect** flow possible; connecting an account
after that needs nothing typed.

`GITHUB_APP_PRIVATE_KEY` also accepts the key inline, with its newlines escaped
as `\n`, which is what platforms that only take single-line secrets need:

```dotenv
GITHUB_APP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\nMIIEow...\n-----END RSA PRIVATE KEY-----\n"
```

The app's slug (the `github.com/apps/<slug>` part) is read from GitHub and
cached, so it needs no configuration. Set `GITHUB_APP_SLUG` only to skip that
lookup, or if you rename the app and do not want to wait out the cache.

## 3. Connect a source

In the admin: **Sources → Connect GitHub account**. There is no form — GitHub
asks which account to install onto and which repositories to share, and the
source is created from what it hands back: the account, its type, and the
repository count. Connecting an account that already has a source updates that
one instead of adding a second.

Until the app is registered the button is disabled and says why; **Add
manually** is the token-based fallback described at the end of this page.

Connecting also claims any package already pointing at that account, so sources
can be added after the packages they cover.

**Test connection** re-checks the credentials and refreshes the reachable
repository count. **Disconnect** drops the stored credentials but keeps the
source and its package links intact — revoking access on GitHub's side is a
separate uninstall from the organisation's settings.

## Connecting the same account from a second environment

A GitHub App has exactly one Setup URL, so an app registered against the
deployed site can only ever hand its callback back to the deployed site. Point
a local install at the same `GITHUB_APP_ID` and **Connect GitHub account**
appears to work but never finishes: the account already has the app, so GitHub
skips the install screen and redirects to the Setup URL, which is production.
The deployed app receives the callback; the local one waits forever.

### Register a second app (preferred)

Treat each environment as its own deployment and repeat steps 1 and 2 for it —
e.g. an `Acme Package Pipeline (Local)` app whose Setup URL is
`http://localhost:8000/sources/github/callback` — then install it on the same
account and set that app's id and `.pem` in the environment's `.env`. This is
the usual way to run a GitHub App in more than one place, and it keeps the two
installations independent: revoking the local one does not touch production.

A development host that GitHub cannot reach is fine. The Setup URL is followed
by the admin's own browser, not by GitHub, so `.test` and `localhost` work.

### Or complete the callback by hand

To attach an existing installation without registering anything, visit the
callback directly. It accepts a request with no `state` — GitHub itself sends
one whenever an installation is merely updated rather than created — so it
connects whichever installation is named:

1. Find the installation id. On GitHub open the account's installed apps —
   `https://github.com/settings/installations` for a user, or
   `https://github.com/organizations/<org>/settings/installations` for an
   organisation — and click **Configure**. The URL ends in the id.
2. Sign in to the admin on the environment being connected; the callback is
   admin-only.
3. Visit `<APP_URL>/sources/github/callback?installation_id=<id>`.

That is the same code path the redirect takes, so the source is verified and
matching packages adopted exactly as a normal connect would do. An existing
source for that installation or account is updated rather than duplicated, and
the app credentials are not tied to a hostname, so this works from a laptop.

Only the install handshake is host-bound in this way. Once `installation_id` is
stored, syncing, token minting and **Test connection** work from anywhere the
app's id and private key are configured.

## Without a GitHub App

Use **Sources → Add manually**. A source can instead hold a fine-grained
personal access token with read access to Contents and Metadata: fill in
**Organisation or user** and **Access token**, then run **Test connection**.
This is the fallback for GitHub Enterprise instances or where registering an app
is not possible; the token has to be rotated by hand before it expires.

## How a package picks its credential

1. Its connected source, if it has one.
2. Its own `token`, for repositories no source covers.
3. `GITHUB_TOKEN`, as a last resort.

A package with no source gets one attached on save when a connected source owns
its repository URL. A source picked (or cleared) by hand is never overwritten.
