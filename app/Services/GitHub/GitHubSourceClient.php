<?php

namespace App\Services\GitHub;

use App\Models\Source;
use App\Sources\Project;
use App\Sources\SourceClient;
use App\Support\HttpTimeouts;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Browses the repositories a GitHub source can reach, for onboarding.
 *
 * An installed app lists what the installation was granted; a token source
 * lists the account's repositories. GitHub offers no search parameter on
 * either endpoint, so the term filters the fetched list instead.
 */
class GitHubSourceClient implements SourceClient
{
    private const PER_PAGE = 100;

    /**
     * Browsing is for picking a handful of projects, not mirroring an estate;
     * five pages covers 500 repositories before search has to narrow it.
     */
    private const MAX_PAGES = 5;

    public function __construct(private readonly Source $source) {}

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
            ),
            $repositories,
        );

        if (filled($search)) {
            $projects = array_filter(
                $projects,
                fn (Project $project): bool => Str::contains($project->fullName, $search, ignoreCase: true),
            );
        }

        usort($projects, fn (Project $a, Project $b): int => strcasecmp($a->fullName, $b->fullName));

        return array_values($projects);
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
