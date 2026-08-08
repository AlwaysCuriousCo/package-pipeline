<?php

namespace App\Models;

use App\Enums\SourceProvider;
use App\Enums\WebhookCoverage;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\WebhookRegistrar;
use Database\Factories\PackageFactory;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['repository_id', 'source_id', 'repository', 'latest_version', 'name', 'description', 'type', 'token', 'last_synced_at', 'sync_error', 'webhook_enabled'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * The column default alone would leave a freshly created package holding
     * null in memory until it is read back, and the webhook is set up in that
     * window — so the default is stated here as well.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'webhook_enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'webhook_received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            // Every package is served from a repository; anything created
            // without choosing one lands in the default repository at the
            // registry root.
            $package->repository_id ??= Repository::default()->id;

            $package->linkSource();
        });

        // A repository hook left behind on GitHub would keep posting to a URL
        // that resolves to nothing. Removing it is best effort: the package is
        // gone here either way.
        static::deleted(fn (self $package) => app(WebhookRegistrar::class)->deregister($package));
    }

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The Composer repository this package is served from.
     *
     * Named for what it is rather than `repository()`, because that name is
     * taken: the `repository` attribute is the VCS URL the package syncs from.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function composerRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'repository_id');
    }

    /**
     * Narrow to the packages the presenting token may see — the one place
     * the Composer endpoints' access control lives.
     *
     * No token sees public repositories only. A user's personal token sees
     * everything its owner does. A deploy token sees public repositories
     * plus whatever it was granted — or everything, when it holds no grants.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?Token $token): Builder
    {
        $principal = $token?->tokenable;

        if ($principal instanceof User) {
            return $query;
        }

        if ($principal instanceof DeployToken && ! $principal->isScoped()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($principal): void {
            $query->whereHas('composerRepository', fn (Builder $repositories) => $repositories->where('public', true));

            if ($principal instanceof DeployToken) {
                $query
                    ->orWhereIn('packages.id', $principal->packages()->select('packages.id'))
                    ->orWhereIn('packages.repository_id', $principal->repositories()->select('repositories.id'));
            }
        });
    }

    /**
     * The job batch currently (or last) rebuilding this package's versions,
     * or null when none has run or the batch has been pruned.
     *
     * Read through the repository rather than the Bus facade so it still
     * answers from the real job_batches table when the dispatcher is faked.
     */
    public function syncBatch(): ?Batch
    {
        return $this->sync_batch_id === null
            ? null
            : app(BatchRepository::class)->find($this->sync_batch_id);
    }

    /**
     * Whether a sync batch is still working through this package's versions.
     *
     * A batch that allows failures is never marked finished once a job has
     * failed — only its pending count ever tells the truth. "Every job has
     * run, some badly" is done, not in progress.
     */
    public function syncRunning(): bool
    {
        $batch = $this->syncBatch();

        return $batch !== null
            && ! $batch->finished()
            && ! $batch->cancelled()
            && $batch->pendingJobs > $batch->failedJobs;
    }

    /**
     * Attach the connected source that owns this package's repository.
     *
     * Runs on every save, but only fills a source in when the package has none
     * *and* the repository URL is new — so a source chosen (or cleared) by
     * hand is never overwritten, and repointing a package at another
     * organisation is a deliberate act rather than a silent re-credentialing.
     */
    public function linkSource(): void
    {
        if ($this->source_id !== null || ! $this->isDirty('repository')) {
            return;
        }

        try {
            $path = $this->repositoryPath();
        } catch (InvalidArgumentException) {
            // An unparseable repository belongs to no source.
            return;
        }

        $this->source_id = Source::forRepositoryPath($path)?->id;
    }

    /**
     * The credential to use when talking to this package's provider.
     *
     * The connected source wins, because it is the credential an admin
     * deliberately attached to the whole organisation. A per-package token
     * remains as an override for repositories no source covers, and
     * GITHUB_TOKEN is the last resort.
     */
    public function accessToken(): ?string
    {
        return $this->source?->accessToken()
            ?? $this->token
            ?? config('services.github.token');
    }

    /**
     * The API root to reach this package's repository through, which a
     * self-hosted source overrides.
     */
    public function apiUrl(): string
    {
        return $this->source?->apiUrl() ?? SourceProvider::Github->defaultApiUrl();
    }

    /**
     * The "owner/repo" path of the GitHub repository.
     *
     * Accepts a full GitHub URL (https or git@, with or without .git) as well
     * as a bare "owner/repo" value.
     */
    public function repositoryPath(): string
    {
        $repository = trim($this->repository);

        if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repository, $matches)) {
            return "{$matches[1]}/{$matches[2]}";
        }

        if (preg_match('#^([\w.-]+)/([\w.-]+?)(?:\.git)?$#', $repository, $matches)) {
            return "{$matches[1]}/{$matches[2]}";
        }

        throw new InvalidArgumentException("Unable to determine the GitHub repository from [{$repository}].");
    }

    /**
     * Every package published from a given "owner/repo" path.
     *
     * The column holds whatever URL was typed — a browser URL, an SSH remote,
     * a bare path — so candidates are narrowed in SQL and then confirmed
     * against the parsed path, which is the only form the two agree on.
     * "acme/widgets" must not answer for "acme/widgets-pro".
     *
     * The column is unique as typed, not as parsed, so the same repository
     * stored two ways is two packages. A caller resolving a delivery has to
     * reach them all — picking one would sync an arbitrary package and
     * silently starve the rest.
     *
     * @return Collection<int, self>
     */
    public static function allForRepositoryPath(string $repositoryPath): Collection
    {
        $path = mb_strtolower(trim($repositoryPath, '/'));

        if ($path === '') {
            return new Collection;
        }

        return static::query()
            ->whereLike('repository', "%{$path}%", caseSensitive: false)
            ->get()
            ->filter(function (self $package) use ($path): bool {
                try {
                    return mb_strtolower($package->repositoryPath()) === $path;
                } catch (InvalidArgumentException) {
                    return false;
                }
            })
            ->values();
    }

    /**
     * How events for this package's repository reach the app.
     *
     * An installed GitHub App delivers for every repository it can see through
     * one webhook on the app itself, so those packages need nothing created
     * and nothing stored. Everything else — a token-based source, a package
     * with only its own token — has to carry a hook on the repository.
     */
    public function webhookCoverage(): WebhookCoverage
    {
        // Switched off deliberately, which is not the same as uncovered and
        // must not read like something left undone.
        if (! $this->webhook_enabled) {
            return WebhookCoverage::Disabled;
        }

        // A hook on the repository is the concrete thing. If one exists it is
        // what delivers, whatever else may also be configured — so it is
        // checked first, and a registry that later turns the app's webhook on
        // does not start describing this package as covered by something else.
        if ($this->webhook_id !== null) {
            return WebhookCoverage::Repository;
        }

        // Confirmed with GitHub rather than assumed from a secret sitting in
        // this app's environment: an app whose webhook was never switched on
        // delivers nothing, and a package told it is covered by one would
        // never get the repository hook that would have worked.
        if ($this->source?->usesInstallation() && app(GitHubApp::class)->hasWebhook()) {
            return WebhookCoverage::Application;
        }

        return filled($this->webhook_error) ? WebhookCoverage::Failed : WebhookCoverage::None;
    }

    /**
     * Where GitHub posts this package's own deliveries. Only meaningful for a
     * package carrying a repository hook — an app-covered one is delivered to
     * the account-wide URL instead.
     */
    public function webhookUrl(): string
    {
        return route('webhooks.github.package', $this);
    }

    /**
     * The Composer name this repository most likely publishes under.
     *
     * A guess for the create wizard, used only when the repository's own
     * composer.json cannot be read; the first sync replaces it with the real
     * name. Null when the repository URL names no GitHub repository at all.
     */
    public function suggestedName(): ?string
    {
        try {
            // Composer names are lowercase, GitHub paths need not be.
            return mb_strtolower($this->repositoryPath());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The commands a consuming project runs to install this package, keyed by
     * a short label describing each step.
     *
     * @return array<string, string>
     */
    public function installCommands(): array
    {
        $repository = $this->composerRepository;

        // A package in a named repository is reached through that mount, and
        // the config key carries the path so two repositories on the same
        // registry never fight over one entry in the consumer's composer.json.
        $repositoryKey = Str::slug(config('app.name')) ?: 'private';

        if (! $repository->isDefault()) {
            $repositoryKey .= "-{$repository->path}";
        }

        $repositoryUrl = rtrim($repository->url(), '/');

        $require = $this->name;

        if ($constraint = $this->suggestedConstraint()) {
            $require .= ":{$constraint}";
        }

        return [
            'repository' => "composer config repositories.{$repositoryKey} composer {$repositoryUrl}",
            'require' => "composer require {$require}",
        ];
    }

    /**
     * The version constraint to suggest in an install command: a caret on the
     * latest release, the default dev branch for unreleased packages, or
     * nothing when no versions have been synced yet.
     */
    private function suggestedConstraint(): ?string
    {
        if ($this->latest_version !== null) {
            return '^'.ltrim($this->latest_version, 'vV');
        }

        $devVersions = $this->versions()->where('is_dev', true)->pluck('version');

        foreach (['dev-main', 'dev-master'] as $preferred) {
            if ($devVersions->contains($preferred)) {
                return $preferred;
            }
        }

        return $devVersions->first();
    }

    /**
     * The distinct package types currently in use, keyed by value.
     *
     * `type` is a free-text column, so this powers the form's suggestions and
     * the table filter without hard-coding a vocabulary.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return static::query()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type', 'type')
            ->all();
    }
}
