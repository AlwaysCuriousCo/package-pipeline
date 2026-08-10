<?php

namespace App\Services\GitLab;

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

/**
 * GitLab's spelling of the repository contract (API v4). Projects are
 * addressed by their URL-encoded namespace path, which is what a package's
 * repository URL parses to.
 */
class GitLabClient implements RepositoryClient
{
    private const PER_PAGE = 100;

    /**
     * Safety bound on pagination, not an expected limit. Reaching it means
     * GitLab was still advertising a next page, so stopping would silently
     * drop refs — we fail loudly instead.
     */
    private const MAX_PAGES = 100;

    private readonly RefListingCache $listings;

    public function __construct(
        private readonly string $projectPath,
        private readonly ?string $token,
        private readonly string $apiUrl = 'https://gitlab.com/api/v4',
    ) {
        $this->listings = new RefListingCache("gitlab|{$apiUrl}|{$projectPath}");
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
     * @return array<string, string>
     */
    public function tags(): array
    {
        return $this->paginateRefs('tags');
    }

    /**
     * @return array<string, string>
     */
    public function branches(): array
    {
        return $this->paginateRefs('branches');
    }

    /**
     * @param  string  $directory  the package's subdirectory, empty for the root
     * @return array<string, mixed>|null
     */
    public function composerJson(?string $ref = null, string $directory = ''): ?array
    {
        // GitLab's raw-file endpoint requires a ref; "the default branch" has
        // to be asked for by name first.
        $ref ??= $this->defaultBranch();

        if ($ref === null) {
            return null;
        }

        // Unlike GitHub's, this endpoint takes the whole file path as a single
        // URL segment, so the separators are encoded along with everything
        // else — "packages/foo/composer.json" travels as one component.
        $path = rawurlencode(ltrim("{$directory}/composer.json", '/'));

        $response = $this->request()->get(
            "/projects/{$this->projectId()}/repository/files/{$path}/raw",
            ['ref' => $ref],
        );

        if ($response->status() === 404) {
            return null;
        }

        $decoded = json_decode($this->ok($response)->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function commitDate(string $ref): ?CarbonImmutable
    {
        $response = $this->request()->get("/projects/{$this->projectId()}/repository/commits/{$ref}");

        if ($response->status() === 404) {
            return null;
        }

        $date = $this->ok($response)->json('committed_date');

        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;
    }

    public function downloadZipball(string $ref, string $destination): void
    {
        $response = $this->ok($this->request()
            // Unlike every other call here, how long this takes is a property
            // of the repository, not of GitLab's health; the API budget would
            // cut a large archive off mid-stream.
            ->timeout(HttpTimeouts::ARCHIVE)
            ->sink($destination)
            ->get("/projects/{$this->projectId()}/repository/archive.zip", ['sha' => $ref]));

        // A 200 that is not a zip — a proxy's HTML error page, say — must not
        // be stored and served to Composer as an archive.
        $contentType = strtolower($response->header('Content-Type'));

        throw_unless(str_contains($contentType, 'zip'), new RuntimeException(
            "GitLab returned '{$contentType}' instead of a zip archive for {$this->projectPath}@{$ref}."
        ));
    }

    /**
     * Push and tag-push events are the ones that can change what a version
     * resolves to; the secret comes back on deliveries as X-Gitlab-Token.
     */
    public function createWebhook(string $url, string $secret): int
    {
        $id = $this->ok($this->request()
            ->post("/projects/{$this->projectId()}/hooks", [
                'url' => $url,
                'token' => $secret,
                'push_events' => true,
                'tag_push_events' => true,
                'enable_ssl_verification' => true,
            ]))
            ->json('id');

        throw_unless(is_int($id), new RuntimeException(
            "GitLab accepted the webhook on {$this->projectPath} but returned no id for it."
        ));

        return $id;
    }

    public function deleteWebhook(int $id): void
    {
        $response = $this->request()->delete("/projects/{$this->projectId()}/hooks/{$id}");

        if ($response->status() === 404) {
            return;
        }

        $this->ok($response);
    }

    /**
     * The project's default branch, or null when the project is out of reach.
     */
    private function defaultBranch(): ?string
    {
        $response = $this->request()->get("/projects/{$this->projectId()}");

        if ($response->status() === 404) {
            return null;
        }

        $branch = $this->ok($response)->json('default_branch');

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    /**
     * @return array<string, string>
     */
    private function paginateRefs(string $endpoint): array
    {
        $refs = [];
        $page = 1;

        do {
            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    "{$this->projectPath} has more than ".self::MAX_PAGES * self::PER_PAGE
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
     * One page of refs and whether another follows, asked conditionally so
     * that a repository nobody has pushed to answers 304 with no body. Every
     * push and every scheduled sync re-lists refs that have not moved since
     * the last one, which is the case this exists for.
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
            ->get("/projects/{$this->projectId()}/repository/{$endpoint}", [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

        if ($response->status() === 304 && $cached !== null) {
            return [$cached['refs'], $cached['next']];
        }

        $refs = [];

        foreach ($this->ok($response)->json() as $item) {
            $refs[$item['name']] = $item['commit']['id'];
        }

        $next = $this->hasNextPage($response);

        if (filled($etag = $response->header('ETag'))) {
            $this->listings->put($endpoint, $page, $etag, $refs, $next);
        }

        return [$refs, $next];
    }

    private function hasNextPage(Response $response): bool
    {
        return filled($response->header('X-Next-Page'));
    }

    /**
     * The response, once it is known not to be GitLab throttling us.
     *
     * throw() would turn a rate limit and an expired token into the same
     * RequestException, and the sync would treat them the same way: retry in
     * a minute, retry in five, fail. A rate limit is the one failure that
     * says when to come back, and it is worth waiting for rather than
     * spending the attempts on.
     */
    private function ok(Response $response): Response
    {
        if ($limited = RateLimited::from($response, 'GitLab')) {
            throw $limited;
        }

        return $response->throw();
    }

    private function projectId(): string
    {
        return rawurlencode($this->projectPath);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->when($this->token, fn (PendingRequest $request) => $request->withHeaders([
                'PRIVATE-TOKEN' => $this->token,
            ]))
            ->acceptJson();
    }
}
