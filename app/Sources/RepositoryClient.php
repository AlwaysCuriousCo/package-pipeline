<?php

namespace App\Sources;

use Carbon\CarbonImmutable;

/**
 * Everything syncing needs from a VCS provider, scoped to one repository.
 *
 * PackageSynchronizer, the webhook registrar and the create wizard depend on
 * this contract alone; Package::client() resolves the implementation from
 * the package's provider, which is what makes the rest of the app
 * provider-agnostic.
 */
interface RepositoryClient
{
    /**
     * All tags on the repository, as [name => commit sha].
     *
     * @return array<string, string>
     */
    public function tags(): array;

    /**
     * All branches on the repository, as [name => commit sha].
     *
     * @return array<string, string>
     */
    public function branches(): array;

    /**
     * The decoded composer.json at the given ref — or at the default branch
     * when no ref is given. Null when the file is missing, out of reach, or
     * not valid JSON.
     *
     * @param  string  $directory  where in the repository to read it from,
     *                             empty for the root. One repository may hold
     *                             several packages, each with a manifest of
     *                             its own — so which manifest is being asked
     *                             for is the caller's to say, not a property
     *                             of the repository this client speaks to.
     * @return array<string, mixed>|null
     */
    public function composerJson(?string $ref = null, string $directory = ''): ?array;

    /**
     * The raw contents of a text file in the repository, at the given ref or
     * at the default branch when none is given. Null when the file is
     * missing, out of reach, or is not text this app can render.
     *
     * Added for the public page, which reads a package-page.md or a README
     * out of the repository — so unlike composerJson() the caller names the
     * file, and unlike a zipball it wants one file rather than the tree.
     *
     * @param  string  $path  the file's path relative to $directory, e.g. "README.md"
     * @param  string  $directory  the package's subdirectory, empty for the root
     */
    public function file(string $path, ?string $ref = null, string $directory = ''): ?string;

    /**
     * The commit date of the given ref, or null when the provider does not
     * report one.
     */
    public function commitDate(string $ref): ?CarbonImmutable;

    /**
     * Download the zipball for the given ref to a local file.
     */
    public function downloadZipball(string $ref, string $destination): void;

    /**
     * Create a webhook on the repository, returning the provider's id for it.
     */
    public function createWebhook(string $url, string $secret): int;

    /**
     * Remove a webhook from the repository. A hook already gone is not an
     * error — the desired state is the same either way.
     */
    public function deleteWebhook(int $id): void;
}
