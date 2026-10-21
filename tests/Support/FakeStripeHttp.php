<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Stripe's API, as a test can arrange it.
 *
 * Installed as the SDK's HTTP client, so the real driver builds and sends
 * every request and only the wire is fake. A test answers a method and path
 * with a JSON object; anything it did not answer fails the request loudly,
 * which is how a test notices the driver asking for something unexpected.
 */
final class FakeStripeHttp implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $answers = [];

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    public static function install(): self
    {
        $fake = new self;

        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    /**
     * Hand the SDK back its own client, so no fake outlives the test that
     * installed it.
     */
    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function answer(string $method, string $path, array $body): self
    {
        $this->answers[strtolower($method).' '.$path] = $body;

        return $this;
    }

    /**
     * A list endpoint's answer: Stripe's collection envelope around $data.
     *
     * @param  list<array<string, mixed>>  $data
     */
    public function answerList(string $path, array $data): self
    {
        return $this->answer('get', $path, [
            'object' => 'list',
            'url' => $path,
            'has_more' => false,
            'data' => $data,
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);

        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params ?: $query];

        $body = $this->answers[strtolower($method).' '.$path] ?? null;

        if ($body === null) {
            return [(string) json_encode(['error' => ['message' => "No answer for {$method} {$path}"]]), 404, []];
        }

        return [(string) json_encode($body), 200, []];
    }
}
