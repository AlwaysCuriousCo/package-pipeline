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
environment](#connecting-the-same-account-from-a-second-environment).

The app is this registry's identity on GitHub, registered once per instance;
connecting an account (step 3) installs it there, once per account.

On a fresh install there is nothing to do here separately: the first **Connect
GitHub account** registers the app and carries straight on to installing it. To
register it on its own, use **Sources → Connect GitHub → Register GitHub App**.
Either way, choose who owns the app — your own account, or an organisation,
which is usual because the app then outlives anyone's account, or an
enterprise — then who can install it, and give it a name, which has to be
unique across GitHub.

**Who can install it** is GitHub's app visibility. **Only this account** (the
default, GitHub's private app) can be installed only on the account that owns
it: right when every repository you serve lives there. **Any account** (a
public app) can be installed by other accounts and organisations too, each
still choosing its own repositories; pick it when one registry serves several
accounts. An enterprise's app is always private, which for an enterprise
means installable on any organisation inside it and nowhere else, so that
option is the only one offered. Neither choice makes repositories or the
registry public, and the visibility can be changed later in the app's settings
on GitHub. **Continue** takes you to GitHub
with the app already described: the setup URL, the webhook, the permissions and
the events below. Confirm it there, and GitHub sends you back with the app's
ID, private key and webhook secret, which are stored encrypted. The next screen
is GitHub's install picker, which is [step 3](#3-connect-a-source).

Before anything is stored, the registry asks GitHub which app the key belongs
to, so a key from the wrong app is refused there rather than failing every sync
later. **Forget** on the same page removes the stored app; it says first how
many sources are connected through it, and keeps them: connecting their
accounts through a new app updates them in place. The app itself stays on
GitHub until you delete it there.

### For an enterprise

GitHub does not accept app manifests for enterprises, so the enterprise option
is three numbered steps on the GitHub App page, each ticked off as it is done:
**Open GitHub** on the enterprise's **New GitHub App** form, with every field
filled in, and create the app; generate a private key at the bottom of the
app's settings page; then **Enter App ID and key** and upload the `.pem`. The
key is read from the upload and never kept as a file. No URL parameter can set
a webhook secret, so the registry sets one itself through the app's API once
the key is verified — if that fails, the page says why and **Configure webhook**
tries again. The next screen is GitHub's install picker, as for any other owner.

### Registering by hand

If the environment already names an app — `GITHUB_APP_ID` or
`GITHUB_APP_PRIVATE_KEY` is set — that app is used, the page above is read-only,
and nothing about the deployment changes. That is also the way to use an app
registered by hand, and the only way on GitHub Enterprise Server, where the
page's links to github.com do not apply.

To register one by hand, go to **Settings → Developer settings → GitHub Apps →
New GitHub App** (on the organisation that will own it):

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
something is pushed.

This is optional, and can be done later — the panel shows the payload URL and
generates a secret for you under **Auto-sync** on any connected source. Without
it, each package instead gets a webhook on its own repository when it is
created, which needs the app to also have **Webhooks: Read and write**. See
[webhooks.md](webhooks.md) for both paths.

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

Only for an app registered by hand; one registered from the panel is already
configured. Two values, both on the app's settings page, and the webhook's
secret:

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

In the admin: **Sources → Connect GitHub → Connect an account**. There is no
form — GitHub asks which account to install onto and which repositories to
share, and the source is created from what it hands back: the account, its
type, and the repository count. Connecting an account that already has a source
updates that one instead of adding a second.

Until the app is registered, connecting registers it first, as described in
[step 1](#1-register-the-app); **Add manually** is the token-based fallback
described at the end of this page.

Connecting also claims any package already pointing at that account, so sources
can be added after the packages they cover.

**Test connection** re-checks the credentials and refreshes the reachable
repository count. **Disconnect** drops the stored credentials but keeps the
source and its package links intact — revoking access on GitHub's side is a
separate uninstall from the organisation's settings.

## Connecting the same account from a second environment

A GitHub App has exactly one Setup URL, so an app registered against the
deployed site can only ever hand its callback back to the deployed site. Point
a local install at the same `GITHUB_APP_ID` and **Connect an account**
appears to work but never finishes: the account already has the app, so GitHub
skips the install screen and redirects to the Setup URL, which is production.
The deployed app receives the callback; the local one waits forever.

### Register a second app (preferred)

Treat each environment as its own deployment and repeat step 1 from its own
panel — e.g. an `Acme Package Pipeline (Local)` app, whose Setup URL is then
`http://localhost:8000/sources/github/callback` — and install it on the same
account. (Registered by hand, that app's id and `.pem` go in the environment's
`.env`.) This is
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
