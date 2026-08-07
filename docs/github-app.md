# Connecting GitHub sources

A **source** is a connected GitHub account — an organisation or user — that
packages are pulled from. Packages under that account authenticate through the
source, so no repository needs a token of its own.

Sources authenticate with a **GitHub App** rather than a personal access token.
An installed app issues tokens that expire after an hour and only reach the
repositories chosen at install time, and it belongs to the organisation rather
than to a person, so access does not disappear when someone leaves.

## 1. Register the app

Once per deployment, at **Settings → Developer settings → GitHub Apps → New
GitHub App** (on the organisation that will own it):

| Field | Value |
| --- | --- |
| GitHub App name | e.g. `Acme Package Pipeline` |
| Homepage URL | your `APP_URL` |
| Setup URL | `<APP_URL>/sources/github/callback` |
| Redirect on update | ✅ checked |
| Webhook | uncheck **Active** (not used yet) |

Repository permissions — read-only is enough for syncing and serving dists:

- **Contents**: Read-only
- **Metadata**: Read-only (mandatory)

Then **Generate a private key** and download the `.pem`.

## 2. Configure this app

Two values, both on the app's settings page:

```dotenv
GITHUB_APP_ID=123456
GITHUB_APP_PRIVATE_KEY=/secure/path/acme-package-pipeline.private-key.pem
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
