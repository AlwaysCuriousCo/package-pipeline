# Auto-syncing packages from pushes

A package normally waits to be synced — from the panel, the `packages:sync`
command, or a schedule. A webhook removes the wait: GitHub posts here the
moment a tag or branch is created, moved or deleted, and the package syncs
itself within seconds.

Three events are subscribed, and each one changes what a version resolves to:

| Event | What it means | What it does here |
| --- | --- | --- |
| `push` | a new tag, or a commit on a branch | syncs — this is most releases |
| `create` | a branch or tag was created | syncs |
| `delete` | a branch or tag was removed | syncs, which prunes the version |

`push` is the one that matters most and the one that is easy to leave out. A
commit landing on `main` changes what `dev-main` resolves to, and that is a
push, not a creation — subscribe to creations alone and dev versions quietly
go stale.

## The GitHub App's webhook (nearly always what you want)

A GitHub App has **one** webhook of its own, and it delivers for every
repository in every installation. There is nothing to create per package and
nothing to create when a repository is added to an installation later — it is
covered the moment it is shared.

Set it up once, on the app's settings page:

| Field | Value |
| --- | --- |
| Webhook → Active | ✅ checked |
| Webhook URL | `<APP_URL>/incoming/github` |
| Webhook secret | a long random string |

Subscribe it to **Push**, **Branch or tag creation** and **Branch or tag
deletion** under **Permissions & events**, then put the same secret in this
app's environment:

```dotenv
GITHUB_APP_WEBHOOK_SECRET=the-same-secret
```

No repository permission has to change for this. Contents (read-only) and
Metadata (read-only) — what [github-app.md](github-app.md) already asks for —
are enough to receive these events and to sync what they announce.

Until the secret is set, deliveries are refused with a 503 rather than trusted,
and the panel says so on every package the app covers. The secret is the whole
of the authentication on an incoming request, so an unverifiable delivery is
not something to act on.

## The per-repository fallback

A package whose source is **not** an installed app — a source holding a
personal access token, or a repository with only its own token — has no app
webhook to ride on, so it needs a hook on the repository itself. This app
creates one automatically when the package is created, and the panel offers
**Create webhook** on the package page if it has to be done again.

That hook posts to `<APP_URL>/incoming/github/{package}` and is signed with a
secret generated for that package alone, stored encrypted. Deleting the package
removes the hook from GitHub.

Creating a hook needs **admin rights on the repository**, which a read-only
token does not have. When GitHub refuses, the package is still created — it
simply syncs when asked rather than when pushed, and the reason is shown on
the package page under **Auto-sync**.

## What a delivery does

1. The signature (`X-Hub-Signature-256`) is checked against the raw body. No
   match, no further reading of the payload.
2. `ping` is answered `204`. GitHub sends one when a webhook is created.
3. The repository named in the payload is matched to a package. A repository
   that is not a package here is acknowledged and dropped — an app webhook
   hears from every repository the installation can see, and most are not
   packages.
4. The package's sync is queued, **15 seconds late on purpose**. `git push
   --tags` over ten tags is ten deliveries in about a second; the delay lets
   the pending job's uniqueness lock fold the whole burst into the one sync
   that reads the entire repository anyway.

A queue worker has to be running for any of this to happen — `php artisan
queue:work`. Without one the deliveries are accepted and nothing syncs.

## Knowing it works

The package page shows **Auto-sync** (which delivery path covers it, and what
is missing if none does) and **Last delivery** (when GitHub last posted about
it). On GitHub, the app's or the repository's **Recent Deliveries** tab shows
each payload and the response it got, with a **Redeliver** button — a rejected
delivery carries the reason in its response body.

## Being told about it

A sync that publishes a tagged version raises a notification — in the admin
panel's bell, and in Slack when one is configured:

```dotenv
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-...
SLACK_BOT_USER_DEFAULT_CHANNEL=#releases
```

Dev branches are deliberately silent. They move on every commit, and a bell
that rings on every commit is a bell nobody reads — the version is still
synced, it just does not interrupt anyone. Failed syncs do notify, once the
job has used up its retries, because a package that stops receiving releases
otherwise fails silently.
