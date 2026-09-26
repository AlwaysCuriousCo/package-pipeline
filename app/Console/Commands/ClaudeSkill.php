<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Token;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Packages this registry as a Claude Skill, so Claude's code execution
 * sandbox (claude.ai's "create and edit files") knows the registry exists,
 * how to find what it serves and how to install from it.
 *
 * The sandbox has no secret store, so the skill carries its own credential:
 * a token issued here for a deploy token, holding repository:read to install
 * and api:read to list — never anything that writes. Whatever the deploy
 * token is granted is what Claude sees.
 *
 * @see docs/claude.md
 */
class ClaudeSkill extends Command
{
    protected $signature = 'claude:skill
        {--deploy=claude : The deploy token to issue for, creating it if missing}
        {--expires-days=90 : Expire the token after this many days; 0 for never}
        {--path= : Where to write the zip; package-pipeline-skill.zip in the current directory when omitted}';

    protected $description = 'Build a Claude Skill zip that teaches Claude to find and install packages from this registry';

    public function handle(): int
    {
        $deploy = DeployToken::query()->firstOrCreate(['name' => (string) $this->option('deploy')]);

        $days = (int) $this->option('expires-days');

        $new = Token::issue(
            $deploy,
            'Claude skill',
            [TokenAbility::RepositoryRead, TokenAbility::ApiRead],
            $days > 0 ? now()->addDays($days)->endOfDay() : null,
        );

        $path = $this->option('path') ?: getcwd().'/package-pipeline-skill.zip';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->components->error("Could not open [{$path}] for writing.");

            return self::FAILURE;
        }

        // claude.ai expects the skill's folder inside the zip, not its files.
        $zip->addFromString('package-pipeline/SKILL.md', $this->skill($new->plainText));
        $zip->close();

        $this->components->info("Wrote {$path}. It contains a live token — treat the file as a secret.");

        if (! $deploy->isScoped()) {
            $this->components->warn("Deploy token \"{$deploy->name}\" has no grants, so Claude can see the whole registry. Grant it only what Claude needs under Deploy tokens in the panel.");
        }

        $this->components->bulletList([
            'Allow '.parse_url((string) config('app.url'), PHP_URL_HOST).' under Organization settings → Capabilities → Package managers and specific domains',
            'Upload the zip under Settings → Capabilities → Skills',
        ]);

        return self::SUCCESS;
    }

    private function skill(string $token): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $name = (string) config('app.name');

        return strtr(<<<'MD'
            ---
            name: package-pipeline
            description: Find and install private Composer, npm and Python packages from the {name} registry at {host}. Use when a task needs a package the organisation publishes privately, names a package that is not on Packagist, npmjs.org or pypi.org, or asks which internal packages exist.
            ---

            # {name} package registry

            {url} is this organisation's private package registry. It serves
            Composer, npm and Python (PyPI) packages. Use this token for every
            request to it — it can list and install, nothing else:

            ```
            TOKEN={token}
            ```

            ## Find a package

            ```bash
            curl -fsS -H "Authorization: Bearer $TOKEN" "{url}/api/v1/packages?per_page=100" \
              | jq '.data[] | {id, name, ecosystem, latest_version, description, repository: .repository.url}'
            ```

            - `?q=acme/` lists names starting with a prefix, `?name=acme/widgets` is an exact match.
            - Follow `links.next` while it is not null to see every page.
            - `GET {url}/api/v1/packages/{id}` lists every version of one package.

            `repository` is the base URL to install that package from — the
            registry has several repositories, and a package is only served
            from its own.

            ## Install it

            Replace `$REPO` with the package's `repository` URL.

            **Composer**

            ```bash
            composer config repositories.private composer $REPO
            composer config http-basic.{host} token $TOKEN
            composer require acme/widgets
            ```

            **npm** — point the package's scope at the registry:

            ```bash
            npm config set @acme:registry $REPO/npm/
            npm config set "//${REPO#https://}/npm/:_authToken" $TOKEN
            npm install @acme/widgets
            ```

            **Python**

            ```bash
            pip install --extra-index-url "https://__token__:$TOKEN@${REPO#https://}/pypi/simple/" widgets
            ```

            Public packages still come from Packagist, npmjs.org and pypi.org as usual.

            ## When it fails

            - A connection refused or a proxy `403` before the registry answers means
              the sandbox may not reach {host}. Tell the user an organisation owner
              must add {host} under Organization settings → Capabilities →
              "Package managers and specific domains". Do not retry.
            - `401` from the registry means the token expired or was revoked; ask the
              user for a new skill from `php artisan claude:skill`.
            - `404` on a package means it does not exist or this token is not granted it.
            MD, [
            '{name}' => $name,
            '{host}' => $host,
            '{url}' => $url,
            '{token}' => $token,
        ]);
    }
}
