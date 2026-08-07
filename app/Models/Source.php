<?php

namespace App\Models;

use App\Enums\SourceProvider;
use App\Services\GitHub\GitHubApp;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * A connected version control account that packages are sourced from.
 *
 * A source holds the credentials for one owner — a GitHub organisation or user
 * — so that every package under that owner authenticates through it instead of
 * carrying its own token.
 */
#[Fillable(['name', 'provider', 'base_url', 'account', 'account_type', 'installation_id', 'token', 'metadata'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SourceProvider::class,
            'token' => 'encrypted',
            'metadata' => 'array',
            'installation_id' => 'integer',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * The source that owns a given "owner/repo" path, or null when that owner
     * has not been connected.
     */
    public static function forRepositoryPath(string $repositoryPath): ?self
    {
        $owner = strtok($repositoryPath, '/');

        if ($owner === false || $owner === '') {
            return null;
        }

        return static::query()
            ->where('provider', SourceProvider::Github)
            ->forAccount($owner)
            ->first();
    }

    /**
     * The source already holding an account for a provider, ignoring the given
     * one so that editing a source does not collide with itself.
     *
     * A provider may only reach an owner through a single source — the unique
     * index says so — and both the form and the install callback ask this
     * before writing, so a duplicate reads as an error rather than a crash.
     */
    public static function accountHolder(string $account, SourceProvider|string|null $provider, ?self $except = null): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->when($except?->exists, fn (Builder $query): Builder => $query->whereKeyNot($except->getKey()))
            ->forAccount($account)
            ->first();
    }

    /**
     * The source a finished installation belongs to.
     *
     * Reconnecting an account, or connecting one that was first added by hand,
     * lands on the existing row; anything else returns a new unsaved source
     * named after the account, so connecting is all it takes to add one.
     *
     * @param  array<string, mixed>  $installation
     */
    public static function forInstallation(int $installationId, array $installation): self
    {
        $login = $installation['account']['login'] ?? null;

        $existing = static::query()
            ->where('provider', SourceProvider::Github)
            ->where(fn (Builder $query): Builder => $query
                ->where('installation_id', $installationId)
                ->when($login, fn (Builder $query): Builder => $query->orWhere(
                    fn (Builder $query): Builder => $query->forAccount($login),
                )))
            ->first();

        return $existing ?? new self([
            'name' => static::availableName($login ?? "GitHub installation {$installationId}"),
            'provider' => SourceProvider::Github,
            'account' => $login,
        ]);
    }

    /**
     * A source other than the given one that already holds this installation
     * or account — which the unique indexes would otherwise reject with a
     * database error rather than something an admin can act on.
     */
    public static function conflicting(self $except, int $installationId, ?string $login): ?self
    {
        return static::query()
            ->where('provider', SourceProvider::Github)
            ->whereKeyNot($except->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->where('installation_id', $installationId)
                ->when($login, fn (Builder $query): Builder => $query->orWhere(
                    fn (Builder $query): Builder => $query->forAccount($login),
                )))
            ->first();
    }

    /**
     * Narrow to the sources owned by an account.
     *
     * GitHub logins are case insensitive, so the match must be too — otherwise
     * "Acme" and "acme" would look like two different owners.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, string $account): Builder
    {
        return $query->whereRaw('lower(account) = ?', [mb_strtolower($account)]);
    }

    /**
     * The preferred name, suffixed until it no longer collides. Names are
     * unique and this one is chosen for the admin rather than by them.
     */
    private static function availableName(string $preferred): string
    {
        $name = $preferred;

        for ($suffix = 2; static::query()->where('name', $name)->exists(); $suffix++) {
            $name = "{$preferred} ({$suffix})";
        }

        return $name;
    }

    /**
     * Every source keyed by id, for the package form's picker.
     *
     * Sources that cannot authenticate yet are listed too, flagged rather than
     * hidden: a package may be assigned to a source before it is connected,
     * and leaving one out would blank the picker for packages already linked
     * to it.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (self $source): array => [
                $source->id => "{$source->name} ({$source->provider->getLabel()})"
                    .($source->isConnected() ? '' : ' — not connected'),
            ])
            ->all();
    }

    /**
     * Why this source reaches no repositories, or null when it reaches some.
     *
     * An installation comes back empty for two very different reasons and the
     * fix is not the same. An app registered without repository permissions
     * never offers a repository picker, so reinstalling it can only ever
     * produce another empty installation; that has to be corrected on the
     * app's own settings before any install can grant access.
     */
    public function emptyAccessReason(int $repositoryCount): ?string
    {
        if ($repositoryCount > 0 || ! $this->usesInstallation()) {
            return null;
        }

        if (blank($this->metadata['permissions'] ?? [])) {
            return 'The GitHub App itself requests no repository permissions, so GitHub does not offer any repositories to share. '
                .'Add Contents (read-only) under Permissions & events on the app\'s settings, then accept the permission request on the installation.';
        }

        return 'The app is installed on this account but no repositories were shared with it. '
            .'Choose them under Repository access on the installation, or connect again and pick them.';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->whereNotNull('connected_at');
    }

    public function isConnected(): bool
    {
        return $this->connected_at !== null;
    }

    /**
     * Whether this source authenticates through the GitHub App rather than a
     * token typed in by hand.
     */
    public function usesInstallation(): bool
    {
        return $this->installation_id !== null;
    }

    /**
     * The API root for this source, which self-hosted instances override.
     */
    public function apiUrl(): string
    {
        return rtrim($this->base_url ?: $this->provider->defaultApiUrl(), '/');
    }

    /**
     * A token authorised for this source's repositories.
     *
     * An installed app mints a fresh short-lived token; otherwise the manually
     * stored token is used. Null means the source cannot authenticate, and the
     * caller should fall back to whatever else it has.
     */
    public function accessToken(): ?string
    {
        if ($this->usesInstallation()) {
            return app(GitHubApp::class)->installationToken($this->installation_id);
        }

        return $this->token;
    }

    /**
     * Record a completed GitHub App installation against this source.
     *
     * @param  array<string, mixed>  $installation
     */
    public function completeInstallation(int $installationId, array $installation): void
    {
        $this->forceFill([
            'installation_id' => $installationId,
            'account' => $installation['account']['login'] ?? $this->account,
            'account_type' => $installation['account']['type'] ?? null,
            'metadata' => [
                'app_slug' => $installation['app_slug'] ?? null,
                'repository_selection' => $installation['repository_selection'] ?? null,
                'permissions' => $installation['permissions'] ?? [],
            ],
            'connected_at' => now(),
            'connection_error' => null,
        ])->save();
    }

    /**
     * Forget the installation, leaving the source in place but unable to
     * authenticate until it is connected again.
     */
    public function disconnect(): void
    {
        if ($this->usesInstallation()) {
            app(GitHubApp::class)->forgetInstallationToken($this->installation_id);
        }

        $this->forceFill([
            'installation_id' => null,
            'token' => null,
            'connected_at' => null,
            'connection_error' => null,
        ])->save();
    }

    /**
     * Call the provider to confirm the stored credentials still work, and
     * refresh the account details and repository count from the response.
     *
     * The failure reason is recorded on the source before it bubbles up.
     *
     * @return int the number of repositories this source can reach
     */
    public function verify(): int
    {
        try {
            $count = $this->usesInstallation()
                ? $this->verifyInstallation()
                : $this->verifyToken();
        } catch (Throwable $exception) {
            $this->forceFill([
                'connected_at' => null,
                'connection_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $this->forceFill([
            'metadata' => [...$this->metadata ?? [], 'repository_count' => $count],
            'connected_at' => now(),
            'connection_error' => null,
        ])->save();

        return $count;
    }

    /**
     * Re-read the installation and count the repositories it reaches.
     */
    private function verifyInstallation(): int
    {
        $app = app(GitHubApp::class);

        // A token minted before the app's permissions or repository selection
        // changed would still pass, so this check always starts clean.
        $app->forgetInstallationToken($this->installation_id);

        $installation = $app->installation($this->installation_id);

        $this->fill([
            'account' => $installation['account']['login'] ?? $this->account,
            'account_type' => $installation['account']['type'] ?? $this->account_type,
        ]);

        return (int) ($this->get('/installation/repositories', ['per_page' => 1])['total_count'] ?? 0);
    }

    /**
     * Confirm a manually stored token works and reaches this source's account.
     */
    private function verifyToken(): int
    {
        throw_unless($this->token, new RuntimeException(
            'This source has neither a GitHub App installation nor an access token.'
        ));

        throw_unless($this->account, new RuntimeException(
            'Set the account (the GitHub organisation or user) before testing a token-based source.'
        ));

        // Org repositories first; a source pointed at a personal account is
        // not an org, and GitHub answers 404 there.
        $repositories = $this->get("/orgs/{$this->account}/repos", ['per_page' => 100], allowMissing: true)
            ?? $this->get("/users/{$this->account}/repos", ['per_page' => 100]);

        return count($repositories);
    }

    /**
     * A GET against this source's API, authenticated as the source.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private function get(string $path, array $query = [], bool $allowMissing = false): ?array
    {
        $response = Http::baseUrl($this->apiUrl())
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($this->accessToken())
            ->acceptJson()
            ->get($path, $query);

        if ($allowMissing && $response->status() === 404) {
            return null;
        }

        return $response->throw()->json();
    }
}
