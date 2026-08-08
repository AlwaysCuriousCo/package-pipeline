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

Every package gets one of these two, and it is arranged when the package is
created — nothing has to be set up first:

- **The GitHub App's own webhook**, if the app has one. It covers every
  repository in every installation at once, so a package under an installed
  app is already covered before it is created.
- **A webhook on the repository**, otherwise. This is the fallback and the
  general case: it works for a token-based source and for an app-installed one
  whose registry has no app webhook set up.

The app's webhook is the better of the two where it is available — one thing to
configure instead of one per repository, no repository permission beyond the
read-only ones syncing already needs, and repositories added to an installation
later are covered without anyone doing anything. Nothing waits on it, though;
a registry that never sets it up still auto-syncs, one repository hook at a
time.

## The GitHub App's webhook

This webhook lives on the **app**, not on any repository — a repository's
Settings → Webhooks page stays empty, which is the point of it. Its deliveries
are listed under the app's own **Advanced** tab.

Set it up once, on the app's settings page. The panel will hand you both
values: open any App-connected source and look under **Auto-sync**, which shows
the payload URL and generates a secret to use.

Setting `GITHUB_APP_WEBHOOK_SECRET` is not on its own enough, and the panel
does not pretend otherwise: it asks GitHub (`GET /app/hook/config`) whether the
app really has a webhook and whether it posts *here*, and reports one of

| State | Meaning |
| --- | --- |
| **Delivering here** | GitHub confirms it posts to this registry |
| **Not switched on** | The app has no webhook — Webhook → Active was never ticked |
| **Pointing elsewhere** | Its payload URL is another environment's |
| **No secret set** | Nothing here to verify a delivery with |
| **Configured, unverified** | GitHub could not be reached; the secret is trusted meanwhile |

Only **Delivering here** counts as coverage. In the other cases packages fall
back to a webhook on their own repository, so a half-finished setup costs
nothing except the extra hooks. The answer is cached for five minutes;
**Re-check** on the source page asks again straight away.

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

Until the secret is set, deliveries to `/incoming/github` are refused with a
503 rather than trusted: the secret is the whole of the authentication on an
incoming request, so an unverifiable delivery is not something to act on.
Packages created in the meantime fall back to a webhook of their own.

## The per-repository webhook

A package that has no app webhook to ride on gets a hook on the repository
itself, created when the package is created. The panel also offers **Create
webhook** on the package page when one has to be made again.

That hook posts to `<APP_URL>/incoming/github/{package}` and is signed with a
secret generated for that package alone, stored encrypted. Deleting the package
removes the hook from GitHub.

Creating one needs **write access to the repository's webhooks**, which not
every credential has:

| Credential | What it needs |
| --- | --- |
| GitHub App installation | **Webhooks: Read and write** on the app — or skip it and set the app's own webhook up instead |
| Fine-grained token | **Webhooks: Read and write** |
| Classic token | `admin:repo_hook` |

When GitHub refuses, the package is still created — it simply syncs when asked
rather than when pushed, and the reason plus the way out is shown on the
package page under **Auto-sync**.

An app-installed package that has already made its own hook keeps using it even
if the app's webhook is set up later. The two would otherwise deliver the same
push twice; the debounce below means that costs one extra delivery rather than
one extra sync, but the hook is still worth removing on GitHub if you want the
app's webhook to be the only path.

## Switching it off for one package

**Sync automatically on push** on the package's edit page controls this per
package. It is on for new packages.

Turning it off removes the package's webhook from the repository, and — for a
package the app's webhook covers, where there is no hook to remove — makes the
delivery endpoint ignore that repository. The app's webhook itself is left
alone, because it belongs to every other package too. Either way the package
stops syncing on push and still syncs when asked, from **Sync** in the panel or
`php artisan packages:sync`.

Turning it back on sets the webhook up again, and clears any earlier failure so
the retry starts clean.

## What a delivery does

1. The signature (`X-Hub-Signature-256`) is checked against the raw body. No
   match, no further reading of the payload.
2. `ping` is answered `204`. GitHub sends one when a webhook is created.
3. The repository named in the payload is matched to a package. A repository
   that is not a package here — or whose packages have all switched auto-sync
   off — is acknowledged and dropped; an app webhook hears from every
   repository the installation can see, and most are not packages.
4. The package's sync is queued, **15 seconds late on purpose**. `git push
   --tags` over ten tags is ten deliveries in about a second; the delay lets
   the pending job's uniqueness lock fold the whole burst into the one sync
   that reads the entire repository anyway.

A queue worker has to be running for any of this to happen — `php artisan
queue:work`. Without one the deliveries are accepted and nothing syncs.

## Knowing it works

The package page shows **Auto-sync** (which delivery path covers it, what is
missing if none does, or **Off** if it was switched off) and **Last delivery**
(when GitHub last posted about it). On GitHub, **Recent Deliveries** shows each payload and the response it got,
with a **Redeliver** button — a rejected delivery carries the reason in its
response body. For a repository hook that is the repository's Settings →
Webhooks; for the app's webhook it is the app's own **Advanced** tab, since no
repository lists it.

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
