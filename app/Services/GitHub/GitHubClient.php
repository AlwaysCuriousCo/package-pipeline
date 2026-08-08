<?php

namespace App\Services\GitHub;

use App\Models\Package;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubClient
{
    private const PER_PAGE = 100;

    /**
     * Safety bound on pagination, not an expected limit: 100 pages is 10,000
     * refs. Reaching it means GitHub was still advertising a next page, so
     * stopping here would silently drop refs — we fail loudly instead.
     */
    private const MAX_PAGES = 100;

    /**
     * The events a repository webhook subscribes to. Kept next to the code
     * that creates one so the app's own webhook can be documented to match.
     */
    public const WEBHOOK_EVENTS = ['push', 'create', 'delete'];

    public function __construct(
        private readonly string $repositoryPath,
        private readonly ?string $token,
        private readonly string $apiUrl = 'https://api.github.com',
    ) {}

    /**
     * A client for one package, authenticated with whatever credential the
     * package resolves to — its connected source first of all.
     */
    public static function for(Package $package): self
    {
        return new self(
            $package->repositoryPath(),
            $package->accessToken(),
            $package->apiUrl(),
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
     * The decoded composer.json at the given ref — or at the default branch
     * when no ref is given, which is all that is known of a repository before
     * it has ever been synced. Null when the file is missing, the repository
     * is out of reach, or the contents are not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function composerJson(?string $ref = null): ?array
    {
        $response = $this->request()
            ->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
            ->get("/repos/{$this->repositoryPath}/contents/composer.json", filled($ref) ? ['ref' => $ref] : []);

        if ($response->status() === 404) {
            return null;
        }

        $decoded = json_decode($response->throw()->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The commit date of the given ref, or null when GitHub does not report
     * one. Composer publishes this as a version's `time`.
     *
     * The committer date is used rather than the author date: it is when the
     * commit landed on the ref, which is what a release date means here.
     */
    public function commitDate(string $ref): ?CarbonImmutable
    {
        $response = $this->request()->get("/repos/{$this->repositoryPath}/commits/{$ref}");

        if ($response->status() === 404) {
            return null;
        }

        $date = $response->throw()->json('commit.committer.date');

        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;
    }

    /**
     * Download the zipball for the given ref to a local file, streaming the
     * response so that archive size is not bounded by the memory limit.
     */
    public function downloadZipball(string $ref, string $destination): void
    {
        $response = $this->request()
            ->sink($destination)
            ->get("/repos/{$this->repositoryPath}/zipball/{$ref}")
            ->throw();

        // A 200 that is not a zip — a proxy's HTML error page, say — must not
        // be stored and served to Composer as an archive.
        $contentType = strtolower($response->header('Content-Type'));

        throw_unless(str_contains($contentType, 'zip'), new RuntimeException(
            "GitHub returned '{$contentType}' instead of a zip archive for {$this->repositoryPath}@{$ref}."
        ));
    }

    /**
     * Create a webhook on the repository, returning GitHub's id for it.
     *
     * Only the three events that can change what a version resolves to are
     * subscribed: a new tag or branch (`create`), a moved one (`push` — which
     * is also what a new commit on an existing branch is), and a removed one
     * (`delete`). Anything wider would be deliveries we throw away.
     */
    public function createWebhook(string $url, string $secret): int
    {
        $id = $this->request()
            ->post("/repos/{$this->repositoryPath}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => self::WEBHOOK_EVENTS,
                'config' => [
                    'url' => $url,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ])
            ->throw()
            ->json('id');

        throw_unless(is_int($id), new RuntimeException(
            "GitHub accepted the webhook on {$this->repositoryPath} but returned no id for it."
        ));

        return $id;
    }

    /**
     * Remove a webhook from the repository. A hook already gone is not an
     * error — the desired state is the same either way.
     */
    public function deleteWebhook(int $id): void
    {
        $response = $this->request()->delete("/repos/{$this->repositoryPath}/hooks/{$id}");

        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    /**
     * @return array<string, string>
     */
    private function paginate(string $endpoint): array
    {
        $refs = [];
        $page = 1;

        do {
            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    "{$this->repositoryPath} has more than ".self::MAX_PAGES * self::PER_PAGE
                    ." {$endpoint}; refusing to sync from a partial list."
                );
            }

            $response = $this->request()
                ->get("/repos/{$this->repositoryPath}/{$endpoint}", [
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ])
                ->throw();

            $items = $response->json();

            foreach ($items as $item) {
                $refs[$item['name']] = $item['commit']['sha'];
            }

            $page++;
        } while ($this->hasNextPage($response, count($items)));

        return $refs;
    }

    /**
     * Whether GitHub has another page of results for the current request.
     */
    private function hasNextPage(Response $response, int $itemsOnPage): bool
    {
        // GitHub advertises further pages through the Link header. Falling back
        // to "the page came back full" keeps pagination working if that header
        // is ever missing.
        if (filled($link = $response->header('Link'))) {
            return str_contains($link, 'rel="next"');
        }

        return $itemsOnPage === self::PER_PAGE;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when($this->token, fn (PendingRequest $request) => $request->withToken($this->token))
            ->acceptJson();
    }
}
