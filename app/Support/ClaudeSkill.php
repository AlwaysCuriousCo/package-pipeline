<?php

namespace App\Support;

use ZipArchive;

/**
 * A Claude Skill for this registry: a SKILL.md that tells Claude's code
 * execution sandbox (claude.ai's "create and edit files") where the registry
 * is, how to find what it serves and how to install from it.
 *
 * The sandbox has no secret store, so the skill carries its own credential.
 * Whoever issues it decides what that token is; it should only ever hold
 * repository:read and api:read.
 *
 * @see docs/claude-ai.md
 */
class ClaudeSkill
{
    public const FILENAME = 'package-pipeline-skill.zip';

    /**
     * The zipped skill, as bytes.
     */
    public static function zip(string $token): string
    {
        $path = tempnam(sys_get_temp_dir(), 'skill');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        // claude.ai expects the skill's folder inside the zip, not its files.
        $zip->addFromString('package-pipeline/SKILL.md', self::markdown($token));
        $zip->close();

        try {
            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    public static function markdown(string $token): string
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
              user to download a new skill from API tokens in the registry's panel.
            - `404` on a package means it does not exist or this token is not granted it.
            MD, [
            '{name}' => $name,
            '{host}' => $host,
            '{url}' => $url,
            '{token}' => $token,
        ]);
    }
}
