<?php

namespace Tests\Feature;

use App\Jobs\ImportVersion;
use App\Services\GitHub\GitHubClient;
use App\Services\GitLab\GitLabClient;
use App\Support\HttpTimeouts;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * An archive download is the one outbound request whose length is set by the
 * repository rather than by the provider's health, and the one that Laravel's
 * 30-second default quietly made impossible for a large package on a slow
 * link. These hold the two budgets apart: the download long enough to finish
 * inside the import job, every other call short enough to leave it room.
 */
class HttpTimeoutTest extends TestCase
{
    /**
     * @var list<array{url: string, options: array<string, mixed>}>
     */
    private array $sent = [];

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = tempnam(sys_get_temp_dir(), 'archive');

        // The fake's second argument is the request's Guzzle options, which is
        // where a timeout is observable at all.
        Http::fake(function (Request $request, array $options): PromiseInterface {
            $this->sent[] = ['url' => $request->url(), 'options' => $options];

            return str_contains($request->url(), 'zipball') || str_contains($request->url(), 'archive.zip')
                ? Http::response('zip-bytes', 200, ['Content-Type' => 'application/zip'])
                : Http::response([]);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->destination);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(string $fragment): array
    {
        foreach ($this->sent as $request) {
            if (str_contains($request['url'], $fragment)) {
                return $request['options'];
            }
        }

        $this->fail("No request was sent to a URL containing [{$fragment}].");
    }

    public function test_github_reads_metadata_briefly_and_downloads_at_length(): void
    {
        $client = new GitHubClient('acme/widgets', 'token');

        $client->tags();
        $client->composerJson();
        $client->downloadZipball(str_repeat('a', 40), $this->destination);

        $this->assertSame(HttpTimeouts::API, $this->optionsFor('/tags')['timeout']);
        $this->assertSame(HttpTimeouts::API, $this->optionsFor('/contents/composer.json')['timeout']);
        $this->assertSame(HttpTimeouts::ARCHIVE, $this->optionsFor('/zipball/')['timeout']);
        $this->assertSame(HttpTimeouts::CONNECT, $this->optionsFor('/zipball/')['connect_timeout']);
    }

    public function test_gitlab_reads_metadata_briefly_and_downloads_at_length(): void
    {
        $client = new GitLabClient('acme/widgets', 'token');

        $client->tags();
        $client->downloadZipball(str_repeat('a', 40), $this->destination);

        $this->assertSame(HttpTimeouts::API, $this->optionsFor('/repository/tags')['timeout']);
        $this->assertSame(HttpTimeouts::ARCHIVE, $this->optionsFor('/archive.zip')['timeout']);
        $this->assertSame(HttpTimeouts::CONNECT, $this->optionsFor('/archive.zip')['connect_timeout']);
    }

    /**
     * The download is only ever made by an import job, so its budget has to be
     * spendable within that job's — with enough left over for the zip to be
     * unpacked and stored once it lands.
     */
    public function test_the_download_budget_fits_inside_the_import_job(): void
    {
        $job = (new ReflectionClass(ImportVersion::class))->getDefaultProperties()['timeout'];

        $this->assertLessThan($job, HttpTimeouts::ARCHIVE);
        $this->assertGreaterThan(HttpTimeouts::API, HttpTimeouts::ARCHIVE);
    }
}
