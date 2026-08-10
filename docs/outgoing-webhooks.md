# Outgoing webhooks

This is the other direction from [docs/webhooks.md](webhooks.md). Those are how
a VCS provider tells this registry that a ref moved; these are how the registry
tells **you** — a deploy pipeline, a chat tool that is not Slack, an incident
tracker, an internal service that wants to know when a package it depends on
publishes.

An endpoint is a URL, a set of events, and (almost always) a shared secret.
Configure them under **Outgoing webhooks** in the admin panel.

Nothing here runs until an endpoint exists, which is every installation until
somebody adds one.

## What you can subscribe to

| Event | Fired when |
| --- | --- |
| `version.published` | a sync imported one or more new **tagged** versions |
| `sync.failed` | a package's sync gave up after exhausting its retries |
| `package.abandoned` | a package was marked abandoned |

Dev branches are deliberately absent. They move on every commit, and an endpoint
that fires on every commit is one nobody can act on — this is the same decision
the panel's bell and the Slack channel already make.

`package.abandoned` fires on the transition into abandonment and only then. A
package created already abandoned announces nothing (it never told consumers
anything different), un-abandoning announces nothing, and the hourly sync
re-saving the same flag announces nothing.

There is a fourth event value, `ping`, which you cannot subscribe to. It is sent
only by the panel's **Send test delivery** button, addressed to one endpoint —
exactly as GitHub's own `ping` works.

## What a delivery looks like

A `POST` with a JSON body:

```http
POST /hooks/registry HTTP/1.1
Content-Type: application/json
User-Agent: PackagePipeline-Webhook
X-Package-Pipeline-Event: version.published
X-Package-Pipeline-Delivery: 019906e6-1f7a-7b3c-9a41-1d9a3f8c2b77
X-Hub-Signature-256: sha256=6f3e...
```

```json
{
  "event": "version.published",
  "delivery": "019906e6-1f7a-7b3c-9a41-1d9a3f8c2b77",
  "sent_at": "2026-08-10T14:31:02+00:00",
  "registry": "Package Pipeline",
  "data": {
    "package": "acme/widgets",
    "repository": "internal",
    "source_url": "https://github.com/acme/widgets",
    "releases": ["1.4.0"],
    "latest": "1.4.0",
    "initial_import": false,
    "total_versions": 27
  }
}
```

The envelope — `event`, `delivery`, `sent_at`, `registry`, `data` — is the same
for every event. Only `data` differs.

`repository` is the Composer repository's URL path, and is `null` for the
default repository at the registry root.

### `version.published`

| Key | |
| --- | --- |
| `package` | the Composer name |
| `repository` | the Composer repository's path, or `null` for the root |
| `source_url` | the VCS URL, or `null` for a package published by artifact upload |
| `releases` | the versions this sync added, oldest first |
| `latest` | the package's highest stable version afterwards |
| `initial_import` | whether this was the package's first sync |
| `total_versions` | how many versions the package now serves |

`latest` is stated separately because the highest of `releases` need not be the
package's latest — a backported `1.9.1` landing after `2.0.0` is exactly that.

### `sync.failed`

| Key | |
| --- | --- |
| `package`, `repository`, `source_url` | as above |
| `reason` | the provider's error, in full |
| `last_synced_at` | when the package last synced successfully, or `null` |

`reason` is sent whole rather than cut to a line as the panel and Slack show it:
a receiver is as likely to be a log sink as a chat message.

### `package.abandoned`

| Key | |
| --- | --- |
| `package`, `repository`, `source_url` | as above |
| `replacement` | the package consumers are pointed at, or `null` when none was named |
| `latest` | the package's highest stable version |

## Verifying a delivery

Deliveries are signed exactly the way GitHub signs the ones this app *receives*,
so a receiver you have already written for GitHub needs no new code:
`X-Hub-Signature-256` carries `sha256=` followed by an HMAC-SHA256 of the **raw
request body**, keyed with the endpoint's secret.

Two things matter, and both are the usual way this is got wrong:

- **Hash the raw body**, not the decoded payload re-encoded. Those are different
  strings, and only one of them was signed.
- **Compare in constant time.** A `==` on a signature is a signature oracle.

```php
$expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

abort_unless(hash_equals($expected, $request->header('X-Hub-Signature-256')), 401);
```

```python
import hmac, hashlib

expected = "sha256=" + hmac.new(secret.encode(), request.data, hashlib.sha256).hexdigest()

if not hmac.compare_digest(expected, request.headers.get("X-Hub-Signature-256", "")):
    abort(401)
```

An endpoint saved with no secret is sent unsigned deliveries and carries no
signature header at all. That is only reasonable for an endpoint nothing else
can reach, because without a signature anyone who learns the URL can post to it
claiming to be this registry.

`X-Package-Pipeline-Delivery` is a UUID that stays the same across every retry
of one event. Use it to make your receiver idempotent — a delivery arriving
twice is normal, and a deploy running twice usually is not.

## Delivery, retries and failure

Every delivery is queued, so a release is never waiting on your endpoint. **A
dead endpoint cannot fail a sync, slow a sync, or leave a failed job behind** —
that is the whole arrangement, and it is why the outcome shows up in the panel
rather than as an exception somewhere.

- Any 2xx is success.
- Anything else — a 500, a 404, a connection refused, a timeout — is retried
  twice, after 30 seconds and then 2 minutes.
- After the third attempt the delivery is dropped and the failure is recorded on
  the endpoint. It is not queued forever and it is not replayed later.

So an endpoint that is down for a deploy window loses the events that fired
during it. If you need those, the events are all reconstructible from the
registry itself: the API lists packages with their versions and sync state, and
a receiver that reconciles on startup will always beat one that assumes it saw
everything.

Requests are given 10 seconds to connect and 20 to complete.

## Watching an endpoint

The **Outgoing webhooks** table shows each endpoint's health: **Delivering**,
**Untried**, or a failure count, with the last receiver's response in the
tooltip. The failure count is consecutive and is reset by any delivery that gets
through, so it answers "has this been broken all week or did it blink once?".

The navigation badge counts active endpoints that are currently failing — the
only reason to open the page.

**Send test delivery** posts a `ping` to one endpoint, which is how you find out
the URL is wrong now rather than the next time a release depends on it. It is
queued like everything else, so refresh in a moment to see the outcome.

Every failed delivery is also logged with its reason, its attempt number and its
delivery id.

## Switching one off

The **Active** toggle stops deliveries and drops any already sitting on the
queue, keeping the URL and the secret. Deleting the endpoint is the other way,
and there is no undo for the secret.

## Rotating a secret

The panel never renders a stored secret back — it will tell you whether one is
set, not what it is. Type a new one to replace it; leaving the field empty keeps
what is stored. There is no overlap window, so a rotation means the receiver has
to be updated in the same breath.
