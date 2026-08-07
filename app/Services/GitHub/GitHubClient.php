<?php

namespace App\Services\GitHub;

use App\Models\Package;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubClient
{
    private const PER_PAGE = 100;

    private const MAX_PAGES = 10;

    public function __construct(
        private readonly string $repositoryPath,
        private readonly ?string $token,
    ) {}

    public static function for(Package $package): self
    {
        return new self(
            $package->repositoryPath(),
            $package->token ?? config('services.github.token'),
        );
    }

    /**
     * All tags on the repository, as [name => commit sha].
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        return $this->paginate('tags');
    }

    /**
     * All branches on the repository, as [name => commit sha].
     *
     * @return array<string, string>
     */
    public function branches(): array
    {
        return $this->paginate('branches');
    }

    /**
     * The decoded composer.json at the given ref, or null when the file is
     * missing or not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function composerJson(string $ref): ?array
    {
        $response = $this->request()
            ->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
            ->get("/repos/{$this->repositoryPath}/contents/composer.json", ['ref' => $ref]);

        if ($response->status() === 404) {
            return null;
        }

        $decoded = json_decode($response->throw()->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Download the zipball for the given ref to a local file, streaming the
     * response so that archive size is not bounded by the memory limit.
     */
    public function downloadZipball(string $ref, string $destination): void
    {
        $this->request()
            ->sink($destination)
            ->get("/repos/{$this->repositoryPath}/zipball/{$ref}")
            ->throw();
    }

    /**
     * @return array<string, string>
     */
    private function paginate(string $endpoint): array
    {
        $refs = [];
        $page = 1;

        do {
            $items = $this->request()
                ->get("/repos/{$this->repositoryPath}/{$endpoint}", [
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            foreach ($items as $item) {
                $refs[$item['name']] = $item['commit']['sha'];
            }

            $page++;
        } while (count($items) === self::PER_PAGE && $page <= self::MAX_PAGES);

        return $refs;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when($this->token, fn (PendingRequest $request) => $request->withToken($this->token))
            ->acceptJson();
    }
}
