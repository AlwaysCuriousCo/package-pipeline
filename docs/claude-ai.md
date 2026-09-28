# Using the registry from Claude

When Claude [creates and edits files](https://support.claude.com/en/articles/12111783-create-and-edit-files-with-claude)
on claude.ai, it runs code in a sandbox. That sandbox can install from
Packagist, npmjs.org and pypi.org, but it has no idea this registry exists,
and on the default network setting it can't reach it anyway.

This page covers the two things that fix that: opening the network to the
registry, and giving Claude a **Skill** that tells it where the registry is and
how to use it. Anyone with a panel account can download their own skill in
two clicks; an organisation owner opens the network once.

## How it works

A Claude Skill is a folder holding a `SKILL.md` file. Claude reads the file's
short description on every conversation and loads the rest only when a task
matches it. The registry builds that folder, zipped, with everything Claude
needs baked in:

- the registry's URL, taken from `APP_URL`;
- a token, freshly issued, that can list and install packages and do nothing else;
- how to find packages: `GET /api/v1/packages` on the [management API](api.md),
  which returns each package's name, `ecosystem` (`composer`, `npm` or `pypi`),
  latest version and the repository URL it is served from;
- how to install each ecosystem's packages: `composer`, `npm` and `pip`
  configured against that repository URL with the token;
- what the failures mean, so Claude tells you the fix rather than retrying.

In a conversation, it goes like this:

1. You ask for something that needs a private package ("add our billing
   client to this script", "which internal Python packages do we have?").
2. Claude recognises the task from the skill's description and loads the rest
   of `SKILL.md`.
3. Claude lists packages from `/api/v1/packages`, narrowing with `?q=` or
   `?name=`, to find the name, ecosystem and repository.
4. Claude configures the matching client with the repository URL and token,
   and installs the package. Public dependencies keep coming from Packagist,
   npmjs.org and pypi.org as usual.

The skill holds no copy of the package list. Claude asks the registry every
time, so a package published five minutes ago is already visible.

## Setting it up

### 1. Let the sandbox reach the registry

An **organisation owner** does this once, on claude.ai:

1. Open **Organization settings → Capabilities**.
2. Under network access, choose **Package managers and specific domains**.
3. Add the registry's host: the host in `APP_URL`, such as `packages.example.com`.

This setting exists only on Team and Enterprise plans. On other plans the
sandbox reaches only the built-in package managers, and the skill can't help.

The registry has to be reachable from the internet over HTTPS. The sandbox runs
in Anthropic's cloud, not on your network, so a registry that only answers
inside a VPN or on `localhost` won't work.

### 2. Download your skill

In the panel, open the user menu, then **API tokens → Download Claude skill**,
and confirm. The browser saves `package-pipeline-skill.zip`.

The skill carries a new personal token of yours, so Claude sees exactly the
packages you can see in the panel and nothing more. The token is listed on the
same page as **Claude skill**, next to your other tokens.

### 3. Upload it

In claude.ai, open **Settings → Capabilities → Skills**, upload the zip and
switch the skill on. Code execution and file creation must be on too, since the
skill runs `curl`, `composer`, `npm` and `pip` in the sandbox.

### 4. Try it

Ask Claude something like "list the packages in our private registry". It
should run the `curl` from the skill and answer with names and versions. If it
reports a network error instead, see [Troubleshooting](#troubleshooting).

## One skill for the whole organisation

A downloaded skill belongs to the person who downloaded it: it stops working
if their account is removed, and it sees what they see. To hand a single
skill to everybody instead, for example by provisioning it org-wide on
claude.ai, build it from the console against a deploy token:

```bash
php artisan claude:skill
```

This writes `package-pipeline-skill.zip` in the current directory, holding a
token for the deploy token `claude`.

| Option | Default | |
| --- | --- | --- |
| `--deploy=` | `claude` | The deploy token to issue for. It is created if missing |
| `--expires-days=` | `90` | Days until the token expires; `0` never expires |
| `--path=` | `./package-pipeline-skill.zip` | Where to write the zip |

A deploy token sees the repositories and packages it is granted, **or the whole
registry when it is granted nothing**. Before sharing the skill, open
**Access Management → Deploy tokens** in the panel and grant `claude` only
what Claude should know about. The command warns you when it has no grants.
Grants apply to the existing token straight away, so you can add them
afterwards too.

Use a different `--deploy=` name to build skills with different reach, such as
one for a team that should only see its own repository.

## The token

The sandbox has no secret store, so the token travels inside the skill. That
shapes a few rules, whichever way the skill was built:

- **It is read-only.** It holds `repository:read` to install and `api:read` to
  list. It can't publish, sync, change or delete anything, whatever the owner's
  role or grants.
- **Treat the zip as a secret.** Anyone who unzips it can install everything
  the token can reach. Don't commit it, and don't share a personal one at all.
- **It expires after 90 days.** Installs then start failing with `401`.
  Download or build a new skill and upload it over the old one. Every download
  issues a new token; the old one stays valid until it expires or is revoked.
- **Revoking it.** Revoke a personal skill's token on **API tokens**, where
  it's named **Claude skill**. Revoke a deploy token's from its page in the
  panel, or by the prefix shown in the token listings:

  ```bash
  php artisan token:revoke pp_ab1cd
  ```

  Claude loses access at once. Delete the skill on claude.ai too, so it stops
  trying.

Installs Claude makes show up in the download statistics under the token's
prefix, like any other client's.

## Troubleshooting

| Claude reports | Cause | Fix |
| --- | --- | --- |
| Connection refused, or a `403` from a proxy before the registry answers | The sandbox may not reach the host | Add the host in Organization settings (step 1) |
| `401` from the registry | The token expired or was revoked | Download or build a new skill and upload it |
| `404` for a package, or a package missing from the list | It doesn't exist, or the token's owner can't see it | Grant the package or its repository to the user or deploy token |
| An empty list | The owner's grants reach no packages | Check the user's or deploy token's grants |
| Claude doesn't use the registry at all | The skill is off, or the request didn't read as needing it | Switch the skill on, or name the registry in your request |
