<?php

namespace App\Models;

use App\Enums\SourceProvider;
use App\Enums\WebhookCoverage;
use App\Exceptions\VendorReserved;
use App\Models\Concerns\LogsAuditableChanges;
use App\Notifications\PackageAbandoned;
use App\Services\AdminNotifier;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\WebhookRegistrar;
use App\Services\GitLab\GitLabClient;
use App\Services\PackageSynchronizer;
use App\Sources\RepositoryClient;
use App\Sources\StubClient;
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

#[Fillable(['repository_id', 'source_id', 'repository', 'latest_version', 'name', 'description', 'type', 'token', 'last_synced_at', 'sync_error', 'webhook_enabled', 'abandoned', 'replacement_package'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, LogsAuditableChanges;

    /**
     * The column default alone would leave a freshly created package holding
     * null in memory until it is read back, and the webhook is set up in that
     * window — so the default is stated here as well.
     *
     * `abandoned` is here for the same reason with a different symptom: the
     * table's badge reads it off the record it just created, and the audit
     * log filed a spurious "abandoned: null → false" on the package's next
     * save, when the null in memory finally met the column's default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'webhook_enabled' => true,
        'abandoned' => false,
    ];

    /**
     * Publishing decisions, and who the package authenticates as. Sync
     * bookkeeping is left out on purpose: it changes hourly, is written by a
     * worker rather than a person, and would bury these.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return [
            'name', 'repository', 'repository_id', 'source_id', 'type',
            'abandoned', 'replacement_package', 'webhook_enabled',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_enabled' => 'boolean',
            'abandoned' => 'boolean',
            'last_synced_at' => 'datetime',
            'webhook_received_at' => 'datetime',
        ];
    }

    /**
     * How Composer wants this package's abandonment stated: a replacement name
     * when there is one, a bare `true` when there is not, and nothing at all
     * while the package is still supported.
     *
     * Composer reads this per version rather than per package, so it is
     * rendered into every version object /p2 serves.
     */
    public function abandonment(): string|bool|null
    {
        if (! $this->abandoned) {
            return null;
        }

        return filled($this->replacement_package) ? (string) $this->replacement_package : true;
    }

    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            // Every package is served from a repository; anything created
            // without choosing one lands in the default repository at the
            // registry root.
            $package->repository_id ??= Repository::default()->id;

            // Before every check below, so all of them see the one spelling
            // this package will actually be served under.
            $package->normalizeName();

            // After the repository is settled, because which repository the
            // package lands in is half of the question this asks.
            $package->guardReservedVendor();

            $package->linkSource();

            // Derived after the source is linked, not before: the parse is
            // provider-dependent, and the source decides the provider. Written
            // on every save rather than only when the URL is dirty, so a
            // package that gains (or loses) a source later has its path
            // re-derived under the provider that now applies.
            $package->repository_path = $package->normalizedRepositoryPath();
        });

        // Announced from `saved` rather than from the panel action that usually
        // raises the flag, because it is not the only one: the API, a console
        // edit and a future importer all set the same column, and an
        // abandonment consumers are being told about is worth saying once
        // wherever it came from.
        //
        // `wasChanged` and not `isDirty`, and only in the false → true
        // direction: the flag is re-saved unchanged on every hourly sync, and
        // un-abandoning a package is good news nobody needs paging for.
        //
        // An insert leaves `wasChanged` empty, so a package created already
        // abandoned announces nothing — which is right twice over. It told
        // consumers nothing different to begin with, and a bulk import of dead
        // packages would otherwise page everyone once per row.
        static::saved(function (self $package): void {
            if ($package->wasChanged('abandoned') && $package->abandoned) {
                app(AdminNotifier::class)->send(new PackageAbandoned($package));
            }
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
     * Known vulnerabilities in this package, served to `composer audit`.
     *
     * @return HasMany<PackageAdvisory, $this>
     */
    public function advisories(): HasMany
    {
        return $this->hasMany(PackageAdvisory::class);
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
     * exactly what its owner does. A deploy token sees public repositories
     * plus whatever it was granted — or everything, when it holds no grants.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?Token $token): Builder
    {
        $principal = $token?->tokenable;

        if ($principal instanceof User) {
            return $query->visibleToUser($principal);
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
     * Narrow to the packages a panel user may see: everything for a user
     * holding Unscoped:Package, otherwise public repositories plus their
     * explicit package and repository grants.
     *
     * Applied by the package table, the dashboard widgets, and — through
     * visibleTo() — the user's own personal access tokens.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if ($user->hasUnscopedAccess()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereHas('composerRepository', fn (Builder $repositories) => $repositories->where('public', true))
                ->orWhereIn('packages.id', $user->packages()->select('packages.id'))
                ->orWhereIn('packages.repository_id', $user->repositories()->select('repositories.id'));
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
     * Fold the name down to the one spelling Composer will ever ask for.
     *
     * A Composer package name is lowercase — the grammar in composer.json's own
     * schema admits no other case, and every client lowercases a name before
     * requiring it. So a name stored as `Acme/Internal` is not a stylistic
     * choice this registry should preserve; it is a name that cannot be
     * fetched. `/p2` and `/dist` look a package up by an equality on the
     * column, and on SQLite or PostgreSQL — where that equality is
     * case-sensitive — such a package is unserveable through its own metadata
     * endpoint while looking perfectly healthy in the panel. MySQL's default
     * collation hides the whole thing, which is worse: the bug then only exists
     * on the two engines an operator is most likely to deploy on.
     *
     * The rest of the app already assumed this. The API lowercases what it is
     * given, the upload endpoint lowercases the URL it was addressed to, and
     * the mirror's dependency-confusion guard has to compare on `lower(name)`
     * precisely because the sync path did not. This is where that assumption is
     * made true, once, for every path that decides a name.
     *
     * Only when the name is actually moving, which is the same condition the
     * reservation guard below applies and for a related reason. A registry
     * migrated from before this existed may still hold two rows whose names
     * differ only in case — the unique index is `(repository_id, name)`, so on
     * a case-sensitive engine both fit. Normalizing on *every* save would have
     * an unrelated write (a sync stamping `last_synced_at`, a webhook delivery
     * stamping `webhook_received_at`) collide with the sibling row and fail,
     * turning a dormant data problem into an hourly one. Left alone, such a row
     * is exactly as broken as it was, and the migration that accompanies this
     * flags it for a human.
     */
    public function normalizeName(): void
    {
        if ($this->isDirty('name')) {
            $this->name = mb_strtolower((string) $this->name);
        }
    }

    /**
     * Refuse to introduce a name under a vendor another repository has
     * reserved — the backstop behind every path that creates or renames a
     * package: the panel, the API, an artifact upload, `package:add`, and a
     * sync adopting the name a repository's composer.json declares.
     *
     * Each of those checks first and reports the refusal in its own idiom, so
     * this throwing is the case somebody forgot. It is deliberately a throw
     * rather than a silent correction: a registry that quietly reassigns which
     * repository publishes a vendor is the shape of the attack the reservation
     * exists to stop.
     *
     * Only when the name or the repository is actually moving. Reserving a
     * vendor must not retroactively wedge packages already published under it
     * elsewhere: their next sync writes `last_synced_at` and nothing else, and
     * failing that would take a registry down rather than protect one.
     *
     * @see ReservedVendor
     * @see PackageSynchronizer::nameAfterSync() the rename guard this composes with
     *
     * @throws VendorReserved
     */
    public function guardReservedVendor(): void
    {
        if (! $this->isDirty('name') && ! $this->isDirty('repository_id')) {
            return;
        }

        $conflict = ReservedVendor::conflictFor((string) $this->name, (int) $this->repository_id);

        if ($conflict instanceof ReservedVendor) {
            throw new VendorReserved($conflict, (string) $this->name);
        }
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

        $this->source_id = Source::forRepositoryPath($path, $this->provider())?->id;

        // Anything resolved through the relation from here on must see the
        // source just linked, not a null cached while it was being decided.
        $this->unsetRelation('source');
    }

    /**
     * The provider this package's repository lives on: the connected source's
     * word for it, or the repository URL's host as the honest guess.
     *
     * The relation is only touched when a source is actually linked: this
     * runs inside linkSource() itself (via repositoryPath), and lazy-loading
     * there would cache the relation as null before source_id is decided.
     */
    public function provider(): SourceProvider
    {
        if ($this->source_id !== null && $this->source instanceof Source) {
            return $this->source->provider;
        }

        return SourceProvider::forHost($this->parsedRepository()['host']);
    }

    /**
     * The client that speaks this package's provider — the one seam through
     * which syncing, webhooks and the wizard reach any VCS API.
     */
    public function client(): RepositoryClient
    {
        return match ($provider = $this->provider()) {
            SourceProvider::Github => GitHubClient::for($this),
            SourceProvider::Gitlab => GitLabClient::for($this),
            default => new StubClient($provider),
        };
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
            // The environment fallback is a GitHub credential; handing it to
            // another provider's API would only leak it.
            ?? ($this->provider() === SourceProvider::Github ? config('services.github.token') : null);
    }

    /**
     * The API root to reach this package's repository through, which a
     * self-hosted source overrides.
     */
    public function apiUrl(): string
    {
        return $this->source?->apiUrl() ?? $this->provider()->defaultApiUrl();
    }

    /**
     * The provider-side path of the repository: "owner/repo" on GitHub,
     * the full (possibly nested) namespace path on GitLab.
     *
     * Accepts a full URL (https, ssh, or git@, with or without .git) from
     * any provider, as well as a bare path.
     */
    public function repositoryPath(): string
    {
        $parsed = $this->parsedRepository();

        $path = $parsed['path'];

        if ($path === null) {
            throw new InvalidArgumentException(blank($this->repository)
                ? 'This package has no VCS repository URL; it is published by artifact upload.'
                : "Unable to determine the repository path from [{$this->repository}].");
        }

        // GitHub repositories are exactly owner/repo; anything after that in
        // a pasted URL (/tree/main, /issues) is browser chrome, not path.
        if ($this->provider() === SourceProvider::Github) {
            return implode('/', array_slice(explode('/', $path), 0, 2));
        }

        return $path;
    }

    /**
     * The stored repository reduced to the one spelling every form of it
     * agrees on — lowercased "owner/repo", or the full namespace path on
     * GitLab — and null when the column names no repository at all.
     *
     * This is what the `repository_path` column holds, maintained by the
     * saving hook above; the method exists so the column is never derived
     * anywhere but here.
     */
    public function normalizedRepositoryPath(): ?string
    {
        try {
            return mb_strtolower($this->repositoryPath());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The host and repository path the stored URL names, each null when the
     * URL does not yield one.
     *
     * @return array{host: ?string, path: ?string}
     */
    private function parsedRepository(): array
    {
        $repository = trim((string) $this->repository);

        if (preg_match('#^(?:https?://|ssh://git@|git@)([^/:]+)[:/](.+?)(?:\.git)?/?$#i', $repository, $matches)) {
            $path = trim($matches[2], '/');

            return [
                'host' => $matches[1],
                'path' => str_contains($path, '/') ? $path : null,
            ];
        }

        if (preg_match('#^[\w.-]+(?:/[\w.-]+)+$#', $repository)) {
            return ['host' => null, 'path' => preg_replace('/\.git$/i', '', $repository)];
        }

        return ['host' => null, 'path' => null];
    }

    /**
     * Every package published from a given "owner/repo" path.
     *
     * Answered from the derived `repository_path` column, which holds exactly
     * the form this is asked in. The `repository` column holds whatever URL
     * was typed — a browser URL, an SSH remote, a bare path — so this used to
     * narrow with a leading-wildcard LIKE and confirm each candidate by
     * re-parsing it in PHP. A leading wildcard cannot use an index, and this
     * runs for every push, create and delete an installed GitHub App delivers
     * — for every repository in the installation, most of which publish
     * nothing here.
     *
     * "acme/widgets" must still not answer for "acme/widgets-pro", which the
     * equality does on its own where the LIKE could not.
     *
     * The URL column is unique as typed, not as parsed, so the same repository
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

        return self::query()->where('repository_path', $path)->get();
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
     * Where the provider posts this package's own deliveries. Only meaningful
     * for a package carrying a repository hook — an app-covered one is
     * delivered to the account-wide URL instead.
     */
    public function webhookUrl(): string
    {
        return $this->provider() === SourceProvider::Gitlab
            ? route('webhooks.gitlab.package', $this)
            : route('webhooks.github.package', $this);
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
     * Recompute latest_version from the versions actually stored: the highest
     * stable tag, or null when only pre-releases and branches exist.
     *
     * The synchronizer maintains the column during syncs; this is for the
     * paths that change versions without one — artifact uploads, rebuilds.
     */
    public function refreshLatestVersion(): void
    {
        $latest = $this->versions()
            ->where('is_dev', false)
            ->pluck('version')
            ->map(strval(...))
            ->reject(fn (string $version): bool => (bool) preg_match('/(alpha|beta|rc|dev)/i', $version))
            ->sort(fn (string $a, string $b): int => version_compare(ltrim($a, 'vV'), ltrim($b, 'vV')))
            ->last();

        $this->forceFill(['latest_version' => $latest])->save();
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
