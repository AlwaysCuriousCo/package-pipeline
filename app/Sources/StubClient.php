<?php

namespace App\Sources;

use App\Enums\SourceProvider;
use Carbon\CarbonImmutable;

/**
 * The client for providers the enum names but nothing implements yet.
 *
 * Every method refuses with the same message, so wiring Gitea or Bitbucket
 * in later is mechanical: write the real client, point the factories at it,
 * and the contract-shaped hole closes.
 */
class StubClient implements RepositoryClient, SourceClient
{
    public function __construct(private readonly SourceProvider $provider) {}

    public function projects(?string $search = null): array
    {
        throw $this->unsupported();
    }

    public function tags(): array
    {
        throw $this->unsupported();
    }

    public function branches(): array
    {
        throw $this->unsupported();
    }

    public function composerJson(?string $ref = null, string $directory = ''): ?array
    {
        throw $this->unsupported();
    }

    public function file(string $path, ?string $ref = null, string $directory = ''): ?string
    {
        throw $this->unsupported();
    }

    public function commitDate(string $ref): ?CarbonImmutable
    {
        throw $this->unsupported();
    }

    public function downloadZipball(string $ref, string $destination): void
    {
        throw $this->unsupported();
    }

    public function createWebhook(string $url, string $secret): int
    {
        throw $this->unsupported();
    }

    public function deleteWebhook(int $id): void
    {
        throw $this->unsupported();
    }

    private function unsupported(): UnsupportedProviderException
    {
        return new UnsupportedProviderException(
            "{$this->provider->getLabel()} sources are not supported yet: the provider is declared, but no client implements it."
        );
    }
}
