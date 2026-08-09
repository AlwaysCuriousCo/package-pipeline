<?php

namespace App\Services\GitLab;

use App\Models\Source;
use App\Sources\Project;
use App\Sources\SourceClient;
use App\Support\HttpTimeouts;
use Illuminate\Support\Facades\Http;

/**
 * Browses the projects a GitLab credential can reach, for onboarding.
 */
class GitLabSourceClient implements SourceClient
{
    public function __construct(private readonly Source $source) {}

    /**
     * @return list<Project>
     */
    public function projects(?string $search = null): array
    {
        $projects = Http::baseUrl($this->source->apiUrl())
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withHeaders(['PRIVATE-TOKEN' => $this->source->accessToken()])
            ->acceptJson()
            ->get('/projects', array_filter([
                'membership' => 'true',
                'archived' => 'false',
                'order_by' => 'path',
                'sort' => 'asc',
                'per_page' => 100,
                'search' => $search,
            ]))
            ->throw()
            ->json();

        return array_values(array_map(
            fn (array $project): Project => new Project(
                id: (string) $project['id'],
                fullName: (string) $project['path_with_namespace'],
                webUrl: (string) $project['web_url'],
            ),
            $projects,
        ));
    }
}
