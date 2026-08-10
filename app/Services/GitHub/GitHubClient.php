<?php

namespace App\Services\GitHub;

use App\Exceptions\RateLimited;
use App\Models\Package;
use App\Sources\RepositoryClient;
use App\Support\HttpTimeouts;
use App\Support\RefListingCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubClient implements RepositoryClient
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

    private readonly RefListingCache $listings;

    public function __construct(
        private readonly string $repositoryPath,
        private readonly ?string $token,
        private readonly string $apiUrl = 'https://api.github.com',
    ) {
        $this->listings = new RefListingCache("github|{$apiUrl}|{$repositoryPath}");
    }

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
     * @param  string  $directory  the package's subdirectory, empty for the root
     * @return array<string, mixed>|null
     */
    public function composerJson(?string $ref = null, string $directory = ''): ?array
    {
        // The contents endpoint takes the file path in the URL, so the
        // segments are encoded individually — a path is not one component.
        $path = implode('/', array_map(rawurlencode(...), array_filter([
            ...explode('/', $directory),
            'composer.json',
        ], fn (string $segment): bool => $segment !== '')));

        $response = $this->request()
            ->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
            ->get("/repos/{$this->repositoryPath}/contents/{$path}", filled($ref) ? ['ref' => $ref] : []);

        if ($response->status() === 404) {
            return null;
        }

        $decoded = json_decode($this->ok($response)->body(), true);

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

        $date = $this->ok($response)->json('commit.committer.date');

        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;
    }

    /**
     * Download the zipball for the given ref to a local file, streaming the
     * response so that archive size is not bounded by the memory limit.
     */
    public function downloadZipball(string $ref, string $destination): void
    {
        $response = $this->ok($this->request()
            // Unlike every other call here, how long this takes is a property
            // of the repository, not of GitHub's health; the API budget would
            // cut a large archive off mid-stream.
            ->timeout(HttpTimeouts::ARCHIVE)
            ->sink($destination)
            ->get("/repos/{$this->repositoryPath}/zipball/{$ref}"));

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
        $id = $this->ok($this->request()
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
            ]))
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

        $this->ok($response);
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

            [$items, $hasNext] = $this->refPage($endpoint, $page);

            // Merged a key at a time rather than spread: a tag named "2" is
            // an integer key, and unpacking would renumber it.
            foreach ($items as $name => $sha) {
                $refs[$name] = $sha;
            }

            $page++;
        } while ($hasNext);

        return $refs;
    }

    /**
     * One page of refs and whether another follows, asked conditionally: a
     * repository nobody has pushed to answers 304 with no body, which GitHub
     * does not count against the primary rate limit at all. That is the usual
     * answer here — every push and every scheduled sync re-lists refs that
     * have not moved since the last one.
     *
     * @return array{array<string, string>, bool}
     */
    private function refPage(string $endpoint, int $page): array
    {
        $cached = $this->listings->get($endpoint, $page);

        $response = $this->request()
            ->when($cached !== null, fn (PendingRequest $request): PendingRequest => $request->withHeaders([
                'If-None-Match' => $cached['etag'],
            ]))
            ->get("/repos/{$this->repositoryPath}/{$endpoint}", [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

        if ($response->status() === 304 && $cached !== null) {
            return [$cached['refs'], $cached['next']];
        }

        $items = $this->ok($response)->json();

        $refs = [];

        foreach ($items as $item) {
            $refs[$item['name']] = $item['commit']['sha'];
        }

        $next = $this->hasNextPage($response, count($items));

        if (filled($etag = $response->header('ETag'))) {
            $this->listings->put($endpoint, $page, $etag, $refs, $next);
        }

        return [$refs, $next];
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

    /**
     * The response, once it is known not to be GitHub throttling us.
     *
     * throw() turns a rate limit and an expired token into the same
     * RequestException, and the sync then treats them the same way: retry in
     * a minute, retry in five, fail. A rate limit has to be recognised before
     * that, because it is the one failure that says when to come back — and
     * because a secondary limit arrives as a 403, indistinguishable in a
     * sync_error from bad credentials.
     */
    private function ok(Response $response): Response
    {
        if ($limited = RateLimited::from($response, 'GitHub')) {
            throw $limited;
        }

        return $response->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when($this->token, fn (PendingRequest $request) => $request->withToken($this->token))
            ->acceptJson();
    }
}
