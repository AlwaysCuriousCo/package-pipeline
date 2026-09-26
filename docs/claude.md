# Using the registry from Claude

When Claude [creates and edits files](https://support.claude.com/en/articles/12111783-create-and-edit-files-with-claude)
on claude.ai, it runs code in a sandbox. The sandbox can reach Packagist,
npmjs.org and pypi.org, but it doesn't know about this registry, and on the
default network setting it can't reach it either. You need two things:

1. **Let the sandbox reach the registry.** An organisation owner goes to
   **Organization settings → Capabilities** and picks **Package managers and
   specific domains**, then adds the registry's host (the host in `APP_URL`).
   Only Team and Enterprise plans have this setting. On other plans the sandbox
   can only reach the built-in package managers.
2. **Tell Claude the registry exists.** Build a Claude Skill for it:

   ```bash
   php artisan claude:skill
   ```

   Upload the `package-pipeline-skill.zip` it writes under **Settings →
   Capabilities → Skills**. Claude then uses the skill whenever a task needs a
   private package: it lists what the registry serves through the
   [management API](api.md), and it installs with Composer, npm or pip.

## The skill carries a token

The sandbox has no secret store, so the skill has its own credential inside it.
`claude:skill` issues a token for the deploy token `claude`, and creates that
deploy token if it doesn't exist yet. The token holds `repository:read` so
Claude can install packages and `api:read` so it can list them. It can't
publish, sync or delete anything.

- **Scope it.** A deploy token with no grants sees the whole registry, and the
  command warns you when that's the case. Grant `claude` only the repositories
  or packages Claude should know about, under **Deploy tokens** in the panel.
- **It expires.** The token expires after 90 days by default. Run the command
  again and re-upload the new zip; `--expires-days=0` turns expiry off.
- **Treat the zip as a secret.** Anyone who has it can install everything the
  token can reach. To cut off access, revoke the token with `php artisan token:revoke`.

| Option | Default | |
| --- | --- | --- |
| `--deploy=` | `claude` | The deploy token to issue for |
| `--expires-days=` | `90` | `0` never expires |
| `--path=` | `./package-pipeline-skill.zip` | Where to write the zip |
