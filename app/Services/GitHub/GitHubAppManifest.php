<?php

namespace App\Services\GitHub;

use App\Enums\GitHubAppOwner;
use InvalidArgumentException;

/**
 * The GitHub App this registry needs, described once and handed to GitHub
 * in whichever form the owner's account accepts.
 *
 * It is the table in docs/github-app.md, in code: the setup URL that
 * completes a connection, the webhook that syncs on push, read access to
 * contents and metadata (without which GitHub offers no repository picker),
 * and the three events the webhook handles. Users and organisations take it
 * as a manifest, which GitHub turns into a registered app and hands back the
 * credentials for. Enterprises cannot, so for them the same fields become
 * query parameters that prefill GitHub's form.
 *
 * @see https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest
 * @see https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-using-url-parameters
 */
class GitHubAppManifest
{
    public const EVENTS = ['push', 'create', 'delete'];

    public const PERMISSIONS = ['contents' => 'read', 'metadata' => 'read'];

    public function __construct(public readonly string $name, public readonly bool $public = false) {}

    /**
     * The manifest to POST to GitHub's "new app" page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->homepage(),
            'description' => $this->description(),
            'hook_attributes' => [
                'url' => route('webhooks.github'),
                'active' => true,
            ],
            'redirect_url' => route('github-app.registered'),
            'setup_url' => route('sources.github.callback'),
            'setup_on_update' => true,
            'public' => $this->public,
            'default_permissions' => self::PERMISSIONS,
            'default_events' => self::EVENTS,
        ];
    }

    /**
     * GitHub's "new app" form for an owner, prefilled with everything but the
     * webhook secret, which no parameter can set.
     */
    public function prefillUrl(GitHubAppOwner $owner, ?string $login): string
    {
        throw_if($owner === GitHubAppOwner::Enterprise && $this->public, new InvalidArgumentException('Enterprise-owned GitHub Apps can only be installed within their enterprise.'));

        $query = http_build_query([
            'name' => $this->name,
            'description' => $this->description(),
            'url' => $this->homepage(),
            'setup_url' => route('sources.github.callback'),
            'setup_on_update' => 'true',
            'webhook_active' => 'true',
            'webhook_url' => route('webhooks.github'),
            'public' => $this->public ? 'true' : 'false',
            ...self::PERMISSIONS,
            'events' => self::EVENTS,
        ]);

        // http_build_query numbers array keys (events[0]=push); GitHub reads
        // the bare events[] form.
        return $owner->newAppUrl($login).'?'.preg_replace('/%5B\d+%5D=/', '%5B%5D=', $query);
    }

    private function homepage(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function description(): string
    {
        return 'Package registry at '.$this->homepage().'.';
    }
}
