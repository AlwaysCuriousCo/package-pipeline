<?php

namespace App\Services\GitHub;

use App\Models\Source;
use App\Sources\Project;
use App\Sources\ReadsComposerManifests;
use App\Sources\SourceClient;
use App\Support\HttpTimeouts;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Browses the repositories a GitHub source can reach, for onboarding.
 *
 * An installed app lists what the installation was granted; a token source
 * lists the account's repositories. GitHub offers no search parameter on
 * either endpoint, so the term filters the fetched list instead.
 */
class GitHubSourceClient implements ReadsComposerManifests, SourceClient
{
    private const PER_PAGE = 100;

    /**
     * Browsing is for picking a handful of projects, not mirroring an estate;
     * five pages covers 500 repositories before search has to narrow it.
     */
    private const MAX_PAGES = 5;

    /**
     * How many repositories are checked for a composer.json. One request
     * each, so the cap is what keeps opening the import dialog cheap on an
     * account with hundreds of repositories; the rest are still listed.
     */
    public const MANIFEST_CAP = 100;

    /**
     * How many of those requests are in flight at once.
     */
    private const MANIFEST_CONCURRENCY = 10;

    public function __construct(private readonly Source $source) {}

    /**
     * Read the root composer.json of each project, on its default branch.
     *
     * The import dialog re-renders on every keystroke, so the answer — list
     * and manifests both — is cached for a few minutes per source: long
     * enough to pick from, short enough that a composer.json pushed a moment
     * ago is soon noticed.
     *
     * @return array<string, array{name: ?string, type: ?string, fork: bool}>
     */
    public function composerManifests(): array
    {
        return Cache::remember(
            $this->manifestsCacheKey(),
            now()->addMinutes(5),
            fn (): array => $this->readComposerManifests(array_slice($this->projects(), 0, self::MANIFEST_CAP)),
        );
    }

    /**
     * Read the manifests afresh next time — once the app has been given a
     * repository it could not see when they were cached, say.
     */
    public function forgetComposerManifests(): void
    {
        Cache::forget($this->manifestsCacheKey());
    }

    private function manifestsCacheKey(): string
    {
        return "sources.{$this->source->id}.composer-manifests";
    }

    /**
     * @param  list<Project>  $projects
     * @return array<string, array{name: ?string, type: ?string, fork: bool}>
     */
    private function readComposerManifests(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (Project $project) => $pool->as($project->webUrl)
                ->baseUrl($this->source->apiUrl())
                ->timeout(HttpTimeouts::API)
                ->connectTimeout(HttpTimeouts::CONNECT)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'Accept' => 'application/vnd.github.raw+json'])
                ->withToken($this->source->accessToken())
                ->get("/repos/{$project->fullName}/contents/composer.json"),
            $projects,
        ), concurrency: self::MANIFEST_CONCURRENCY);

        $forks = array_column(array_map(fn (Project $project): array => [$project->webUrl, $project->fork], $projects), 1, 0);
        $manifests = [];

        foreach ($responses as $webUrl => $response) {
            // A 404 is a repository with no composer.json at its root; a
            // failed request is one nobody can say anything about. Neither is
            // offered first, and neither stops the rest being read.
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $composer = json_decode($response->body(), true);

            if (is_array($composer)) {
                $manifests[(string) $webUrl] = [
                    'name' => is_string($composer['name'] ?? null) ? $composer['name'] : null,
                    'type' => is_string($composer['type'] ?? null) ? $composer['type'] : null,
                    'fork' => $forks[$webUrl] ?? false,
                ];
            }
        }

        return $manifests;
    }

    /**
     * @return list<Project>
     */
    public function projects(?string $search = null): array
    {
        $repositories = $this->source->usesInstallation()
            ? $this->paginate('/installation/repositories', 'repositories')
            : $this->accountRepositories();

        $projects = array_map(
            fn (array $repository): Project => new Project(
                id: (string) $repository['id'],
                fullName: (string) $repository['full_name'],
                webUrl: (string) ($repository['html_url'] ?? 'https://github.com/'.$repository['full_name']),
                fork: (bool) ($repository['fork'] ?? false),
            ),
            $repositories,
        );

        if (filled($search)) {
            $projects = array_filter(
                $projects,
                fn (Project $project): bool => Str::contains($project->fullName, $search, ignoreCase: true),
            );
        }

        // usort() reindexes, so the filtered array is a list again.
        usort($projects, fn (Project $a, Project $b): int => strcasecmp($a->fullName, $b->fullName));

        return $projects;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountRepositories(): array
    {
        // Org repositories first; a source pointed at a personal account is
        // not an org, and GitHub answers 404 there.
        $response = $this->request()->get("/orgs/{$this->source->account}/repos", ['per_page' => self::PER_PAGE]);

        if ($response->status() === 404) {
            $response = $this->request()->get("/users/{$this->source->account}/repos", ['per_page' => self::PER_PAGE]);
        }

        return $response->throw()->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginate(string $endpoint, string $key): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->request()
                ->get($endpoint, ['per_page' => self::PER_PAGE, 'page' => $page])
                ->throw();

            $batch = $response->json($key) ?? [];
            $items = [...$items, ...$batch];

            if (count($batch) < self::PER_PAGE) {
                break;
            }
        }

        return $items;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->source->apiUrl())
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($this->source->accessToken())
            ->acceptJson();
    }
}
