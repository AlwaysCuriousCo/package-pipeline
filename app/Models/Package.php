<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\PageBadges;
use App\Enums\PageBodySource;
use App\Enums\PageDownloads;
use App\Enums\SourceProvider;
use App\Enums\WebhookCoverage;
use App\Exceptions\NameCollision;
use App\Exceptions\VendorReserved;
use App\Http\Controllers\Pages\PackageBadgeController;
use App\Jobs\RefreshPackagePage;
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
use App\Support\AuditedBelongsToMany;
use Database\Factories\PackageFactory;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['repository_id', 'source_id', 'ecosystem', 'repository', 'subdirectory', 'latest_version', 'name', 'description', 'type', 'token', 'last_synced_at', 'sync_error', 'webhook_enabled', 'abandoned', 'replacement_package', 'page_enabled', 'page_downloads', 'page_badges', 'page_install', 'page_versions', 'page_type', 'page_source', 'page_body_source', 'page_body_path', 'page_body', 'page_image'])]
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
        // Restated for the same reason: which protocol serves a package is
        // asked of records built in memory — by the endpoints scoping their
        // queries, by the factories — before the column default exists.
        'ecosystem' => Ecosystem::Composer,
        // The page columns, restated for the same reason `abandoned` is: the
        // form reads them off a record it has just built, and a null where
        // the column says false is a toggle that renders indeterminate and an
        // audit entry for a change nobody made.
        'page_enabled' => false,
        'page_downloads' => PageDownloads::None,
        'page_badges' => PageBadges::None,
        'page_install' => true,
        'page_versions' => true,
        'page_type' => true,
        'page_source' => false,
        'page_body_source' => PageBodySource::Auto,
        // The column's default, restated for the same reason: a package built
        // in memory is asked where in its repository it lives — by the wizard
        // reading a composer.json, by the client factories — before it has
        // ever been read back from the database.
        'subdirectory' => '',
    ];

    /**
     * The repository this package is answering as part of, when a request
     * resolved it inside one. Not an attribute: it is a property of the
     * request rather than of the package, and it is never stored.
     *
     * @see servingRepository()
     */
    protected ?Repository $servingRepository = null;

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
            'name', 'repository', 'subdirectory', 'repository_id', 'source_id', 'type',
            'abandoned', 'replacement_package', 'webhook_enabled',
            // Publishing a page is a publishing decision like any other, and
            // the one on this list a reader can see without a token — so an
            // audit that recorded who made this package private again, and
            // when, is worth more here than anywhere else on the row. The
            // bodies are left off: they are content, and the log is a record
            // of decisions, not a revision history for markdown.
            'page_enabled', 'page_downloads', 'page_badges', 'page_install', 'page_versions',
            // Audited with the rest rather than treated as cosmetic: this is
            // the switch that publishes the repository URL of a private
            // package to anonymous readers.
            'page_source',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_enabled' => 'boolean',
            'abandoned' => 'boolean',
            'last_synced_at' => 'datetime',
            'webhook_received_at' => 'datetime',
            'page_enabled' => 'boolean',
            'page_install' => 'boolean',
            'page_versions' => 'boolean',
            'page_type' => 'boolean',
            'page_source' => 'boolean',
            'page_downloads' => PageDownloads::class,
            'page_badges' => PageBadges::class,
            'page_body_source' => PageBodySource::class,
            'page_source_synced_at' => 'datetime',
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

            // Likewise the one spelling of the subdirectory, since it is half
            // of the (repository_id, repository, subdirectory) unique index:
            // "packages/foo", "/packages/foo/" and "packages//foo" are the
            // same place, and stored as written they would be three packages
            // publishing the same tree from the same repository.
            $package->normalizeSubdirectory();

            // After the repository is settled, because which repository the
            // package lands in is half of the question this asks.
            $package->guardReservedVendor();

            $package->guardServedName();

            $package->linkSource();

            // Derived after the source is linked, not before: the parse is
            // provider-dependent, and the source decides the provider. Written
            // on every save rather than only when the URL is dirty, so a
            // package that gains (or loses) a source later has its path
            // re-derived under the provider that now applies.
            $package->repository_path = $package->normalizedRepositoryPath();
        });

        // The pivot that says where this package is served is derived from
        // this row in one direction only — the home repository and the name
        // are decided here, and every serving row has to agree with them.
        // Anything else is a package reachable under a name it no longer has,
        // or served from a repository it was moved out of.
        static::saved(fn (self $package) => $package->reconcileServingRepositories());

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

            // A page switched on has nothing to show until the repository's
            // README has been read, and the next sync may be an hour away —
            // so the read is queued the moment the switch is saved. Only in
            // the off → on direction, and only when the body has never been
            // read: syncs keep it current afterwards, and re-reading it every
            // time an admin saves an unrelated field would spend a provider
            // request per edit.
            if ($package->wasChanged('page_enabled') && $package->page_enabled && $package->page_source_synced_at === null) {
                RefreshPackagePage::dispatch($package);
            }
        });

        // A repository hook left behind on GitHub would keep posting to a URL
        // that resolves to nothing. Removing it is best effort: the package is
        // gone here either way.
        static::deleted(fn (self $package) => app(WebhookRegistrar::class)->deregister($package));
    }

    /**
     * Write columns that record what happened *to* this package rather than
     * what it publishes, leaving its timestamp where it was.
     *
     * `packages.updated_at` has two jobs and they pull against each other. It
     * is this row's history, and it is the fingerprint every `/p2` response's
     * Last-Modified and ETag — and so the rendered payload cache's key — is cut
     * from. The resolution is that only what this registry *publishes* moves
     * it: content changes are loud, bookkeeping is quiet.
     *
     * Bookkeeping is otherwise the loudest writer in the app. The hourly sync
     * stamps `last_synced_at` on every package whether or not a single ref
     * moved, so left alone it moves every ETag in the registry on the hour —
     * capping every 304 and every cached payload at one hour, and stranding a
     * superseded cache entry per package per hour in a store that only evicts
     * on read.
     *
     * Whatever moves the version rows already moves this timestamp on its own
     * (see PackageVersion::touchPackage), so nothing that a consumer could
     * observe is lost by keeping these quiet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordBookkeeping(array $attributes): void
    {
        self::withoutTimestamps(fn () => $this->forceFill($attributes)->save());
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
     * The sponsorship tiers this package's page offers: plans like any other,
     * each selling its own prices — one-time and recurring alike. A tier that
     * grants nothing is a pure donation; one with entitlements is a perk, and
     * the billing layer treats both as ordinary plans.
     *
     * @return BelongsToMany<Plan, $this>
     */
    public function sponsorPlans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'package_sponsor_plan');
    }

    /**
     * The tiers the page's sponsor section renders, in the plans' own sort
     * order, each with its buyable prices loaded. A tier nobody could buy
     * right now is left out rather than shown as a dead button.
     *
     * @return Collection<int, Plan>
     */
    public function pageSponsorPlans(): Collection
    {
        if (! config('registry.billing.enabled')) {
            return new Collection;
        }

        return $this->sponsorPlans()
            ->where('active', true)
            ->orderBy('sort')
            ->with('activePrices')
            ->get()
            ->filter(fn (Plan $plan): bool => $plan->activePrices->isNotEmpty())
            ->values();
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
     * Every Composer repository this package is served from: the home
     * repository above, plus every other one it has been added to.
     *
     * This is the relation the serving paths read from — `/p2`, `/dist`, the
     * page, the upload endpoint and the API all resolve a package *within a
     * repository*, and all of them do it through Repository::packages(),
     * which is the other side of this. A package added to two repositories is
     * one row, one sync, one set of archives and one download counter,
     * answering under both mounts.
     *
     * Reads only. Every write goes through serveFrom()/stopServingFrom()
     * below, because a serving row carries the name it was written with and
     * because the checks that guard it — a name the target repository already
     * serves, a vendor another repository has reserved — have to run before
     * the row exists rather than as a unique-index failure afterwards.
     *
     * @return BelongsToMany<Repository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class)->withPivot('package_name');
    }

    /**
     * Narrow to the packages that live in the repository mounted at the given
     * path — the console commands' disambiguator, so it reads a path the way
     * their repo arguments do: "root" and the empty string name the registry
     * root, whose path is null and could otherwise not be picked at all.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLivingIn(Builder $query, string $path): Builder
    {
        $path = trim($path);

        return $query->whereHas('composerRepository', fn (Builder $repositories) => $repositories->where(
            'path',
            $path === '' || $path === 'root' ? null : $path,
        ));
    }

    /**
     * The repository this package is being served through right now, which is
     * its home unless a request said otherwise.
     *
     * One package can be reached under several mounts, and almost everything
     * a page prints about it is a property of the mount rather than of the
     * package: the URL to configure, the install commands, whether an
     * anonymous visitor may download an archive at all. A page rendered under
     * /r/internal that printed the public repository's `composer config` line
     * because that is where the package happens to live would be telling the
     * visitor to fetch it from somewhere they were not asking about.
     *
     * @see servedFrom() for how a request sets it
     */
    public function servingRepository(): Repository
    {
        return $this->servingRepository ?? $this->composerRepository;
    }

    /**
     * Answer as the copy of this package that the given repository serves.
     *
     * Set by the controllers that resolve a package inside a mount, and by
     * nothing else: a package read from the panel, a notification or an export
     * has no mount to speak of and falls back to its home.
     */
    public function servedFrom(Repository $repository): static
    {
        $this->servingRepository = $repository;

        return $this;
    }

    /**
     * Add this package to the given repositories, on top of wherever it is
     * already served.
     *
     * Silent about repositories it is already served from — adding a package
     * where it already is, is not a change and not an error — and refuses the
     * two things that would leave a repository unable to answer for a name:
     * another package already publishing it there, and a vendor prefix some
     * other repository has reserved.
     *
     * @param  iterable<int, Repository|int>  $repositories
     *
     * @throws VendorReserved
     * @throws NameCollision
     */
    public function serveFrom(iterable $repositories): void
    {
        $ids = $this->repositoryKeys($repositories);
        $added = array_values(array_diff($ids, $this->servingRepositoryIds()));

        if ($added === []) {
            return;
        }

        foreach ($added as $id) {
            $this->guardServingIn($id);
        }

        $timestamp = $this->freshTimestamp();

        DB::table('package_repository')->insert(array_map(fn (int $id): array => [
            'package_id' => $this->getKey(),
            'repository_id' => $id,
            'package_name' => (string) $this->name,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $added));

        $this->recordServingChange('serving_added', $added);

        $this->unsetRelation('repositories');
    }

    /**
     * Stop serving this package from the given repositories.
     *
     * The home repository is never one of them: a package is *somewhere*, and
     * a row detached from its home would be a package with no canonical page
     * and no repository its name is unique within. Moving a package is
     * changing `repository_id`, which the panel offers and which carries the
     * old home out with it.
     *
     * @param  iterable<int, Repository|int>  $repositories
     */
    public function stopServingFrom(iterable $repositories): void
    {
        $removed = array_values(array_diff(
            array_intersect($this->repositoryKeys($repositories), $this->servingRepositoryIds()),
            [(int) $this->repository_id],
        ));

        if ($removed === []) {
            return;
        }

        DB::table('package_repository')
            ->where('package_id', $this->getKey())
            ->whereIn('repository_id', $removed)
            ->delete();

        $this->recordServingChange('serving_removed', $removed);

        $this->unsetRelation('repositories');
    }

    /**
     * Serve this package from exactly these repositories, adding and removing
     * to get there — what a multi-select on a form means when it is saved.
     *
     * The home repository is added to whatever is asked for rather than
     * validated against it, so a form that leaves it out cannot detach a
     * package from the repository it lives in.
     *
     * @param  iterable<int, Repository|int>  $repositories
     */
    public function syncServingRepositories(iterable $repositories): void
    {
        $wanted = array_values(array_unique(array_merge(
            [(int) $this->repository_id],
            $this->repositoryKeys($repositories),
        )));

        $this->stopServingFrom(array_diff($this->servingRepositoryIds(), $wanted));
        $this->serveFrom($wanted);
    }

    /**
     * The ids of the repositories serving this package, home first.
     *
     * Read from the pivot rather than through the relation, because every
     * caller here is comparing ids and loading the repository rows to compare
     * their keys is a join for nothing.
     *
     * @return list<int>
     */
    public function servingRepositoryIds(): array
    {
        if ($this->getKey() === null) {
            return [(int) $this->repository_id];
        }

        return DB::table('package_repository')
            ->where('package_id', $this->getKey())
            ->orderByRaw('repository_id = ? desc', [(int) $this->repository_id])
            ->orderBy('repository_id')
            ->pluck('repository_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether the given repository may serve this package under its name.
     *
     * @throws VendorReserved
     * @throws NameCollision
     */
    private function guardServingIn(int $repositoryId): void
    {
        $reservation = ReservedVendor::conflictFor((string) $this->name, $repositoryId);

        throw_if($reservation instanceof ReservedVendor, fn (): VendorReserved => new VendorReserved($reservation, (string) $this->name));

        throw_if(
            self::nameServedElsewhere((string) $this->name, $repositoryId, $this->getKey()),
            new NameCollision(self::collisionRefusal((string) $this->name)),
        );
    }

    /**
     * Why a repository cannot serve this name, in the words a form field and
     * an action's error notification both show — or null when it can.
     *
     * The same two questions serveFrom() throws over, asked where an answer is
     * still useful: a select that greys nothing out and then fails on save is
     * a worse screen than one that says why while the admin is looking at it.
     */
    public static function servingRefusal(string $name, int $repositoryId, ?int $except = null): ?string
    {
        $reservation = ReservedVendor::conflictFor($name, $repositoryId);

        if ($reservation instanceof ReservedVendor) {
            return $reservation->refusal($name);
        }

        return self::nameServedElsewhere($name, $repositoryId, $except)
            ? self::collisionRefusal($name)
            : null;
    }

    /**
     * Whether some other package already answers for this name in the given
     * repository.
     */
    private static function nameServedElsewhere(string $name, int $repositoryId, ?int $except): bool
    {
        return DB::table('package_repository')
            ->where('repository_id', $repositoryId)
            ->where('package_name', $name)
            ->when($except !== null, fn ($query) => $query->where('package_id', '!=', $except))
            ->exists();
    }

    private static function collisionRefusal(string $name): string
    {
        return "The \"{$name}\" name is already served by that Composer repository, so this package "
            .'cannot be added to it as well. One repository answers for one package per name.';
    }

    /**
     * The keys behind whatever a caller passed — models, ids, or a mix.
     *
     * @param  iterable<int, Repository|int>  $repositories
     * @return list<int>
     */
    private function repositoryKeys(iterable $repositories): array
    {
        $keys = [];

        foreach ($repositories as $repository) {
            $keys[] = $repository instanceof Repository ? (int) $repository->getKey() : (int) $repository;
        }

        return array_values(array_unique($keys));
    }

    /**
     * File the change where grants are filed.
     *
     * Where a package is served from is not a permission, but it is read the
     * same way and asked about for the same reasons — "when did this land in
     * the public repository, and who put it there" — and the attribute diff
     * that catches every other change to a package cannot see a pivot row.
     *
     * @see AuditedBelongsToMany for the same problem one pivot over
     *
     * @param  list<int>  $ids
     */
    private function recordServingChange(string $event, array $ids): void
    {
        $names = Repository::query()->whereKey($ids)->pluck('name')->all();

        activity('audit')
            ->performedOn($this)
            ->event($event)
            ->withProperties(['repositories' => $names])
            ->log($event);
    }

    /**
     * Make the serving rows agree with this row, after every save.
     *
     * Three things can put them out of step, and all three are ordinary edits:
     * a package created (which has no serving row yet), a package moved to
     * another home repository, and a package renamed — including the rename a
     * sync adopts on a package that has never been synced, which is where a
     * stale `package_name` would go unnoticed longest.
     */
    private function reconcileServingRepositories(): void
    {
        $rows = fn (): \Illuminate\Database\Query\Builder => DB::table('package_repository')->where('package_id', $this->getKey());

        // Out of the repository it left. A move is not a share: what the panel
        // offers is one repository the package lives in, and leaving the old
        // row behind would go on serving it from both.
        if ($this->wasChanged('repository_id')) {
            $rows()->where('repository_id', $this->getOriginal('repository_id'))->delete();
        }

        if ($this->wasChanged('name')) {
            $rows()->update([
                'package_name' => (string) $this->name,
                'updated_at' => $this->freshTimestamp(),
            ]);
        }

        if (! $rows()->where('repository_id', $this->repository_id)->exists()) {
            $timestamp = $this->freshTimestamp();

            DB::table('package_repository')->insert([
                'package_id' => $this->getKey(),
                'repository_id' => (int) $this->repository_id,
                'package_name' => (string) $this->name,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * Narrow to one protocol's packages — applied by every serving endpoint,
     * so `composer` never resolves an npm package or the other way round.
     *
     * Qualified, because the Composer endpoints reach packages through the
     * serving pivot and an unqualified `ecosystem` is ambiguous there.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfEcosystem(Builder $query, Ecosystem $ecosystem): Builder
    {
        return $query->where($query->qualifyColumn('ecosystem'), $ecosystem);
    }

    /**
     * Narrow to the packages the presenting token may see — the one place
     * the Composer endpoints' access control lives.
     *
     * No token sees public repositories only. A user's personal token sees
     * exactly what its owner does. A deploy token sees public repositories
     * plus whatever it was granted — or everything, when it holds no grants.
     *
     * `$through` is the repository the request is addressed to, and it is what
     * a package served from several of them is judged by. A package is
     * readable *through a mount*, never in the abstract: one added to both a
     * public repository and a private one is public under the first and
     * private under the second, and asking whether any repository serving it
     * is public would publish the private mount's copy to anyone who found the
     * URL. The Composer endpoints and the pages therefore always pass their
     * mount; the panel-wide readers pass nothing, where the question really is
     * "may this person see this package at all".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?Token $token, ?Repository $through = null): Builder
    {
        $principal = $token?->tokenable;

        if ($principal instanceof User) {
            return $query->visibleToUser($principal, $through);
        }

        if ($principal instanceof DeployToken && ! $principal->isScoped()) {
            return $query;
        }

        // A mount everything on it may be read from asks nothing of the
        // database — which is the common case, and cheaper than the subquery
        // this scope ran before there was anything to be through.
        if ($through instanceof Repository && $this->readableWholesale($principal, $through)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($principal, $through): void {
            $query->whereHas('repositories', fn (Builder $repositories) => $repositories
                ->when($through instanceof Repository, fn (Builder $one) => $one->whereKey($through->getKey()))
                ->where(function (Builder $readable) use ($principal): void {
                    $readable->where('public', true);

                    if ($principal instanceof DeployToken) {
                        $readable->orWhereIn('repositories.id', $principal->repositories()->select('repositories.id'));
                    }
                }));

            if ($principal instanceof DeployToken) {
                $query->orWhereIn('packages.id', $principal->packages()->select('packages.id'));
            }
        });
    }

    /**
     * Whether this mount is readable in full by the principal, whatever it
     * happens to serve.
     */
    private function readableWholesale(mixed $principal, Repository $through): bool
    {
        return $through->public
            || ($principal instanceof DeployToken && $principal->repositories()->whereKey($through->getKey())->exists());
    }

    /**
     * Narrow to the packages a panel user may see: everything for a user
     * holding Unscoped:Package, otherwise public repositories plus every
     * package and repository grant they hold — their own, and their teams'.
     *
     * Applied by the package table, the dashboard widgets, the licence report
     * and the SBOM export, and — through visibleTo() — the user's own personal
     * access tokens. It is the single place all of that is decided, which is
     * what makes it the one method here where a mistake widens access
     * everywhere at once and shows up nowhere.
     *
     * Three branches, exactly as before teams existed. Team grants did not
     * become two more `orWhereIn` clauses on this query: they were folded into
     * the two grant subqueries themselves, because this runs once per Composer
     * metadata request and once per dist download, and the shape of it is a
     * property of every install's hot path.
     *
     * @see User::packageGrants()
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToUser(Builder $query, User $user, ?Repository $through = null): Builder
    {
        if ($user->hasUnscopedAccess()) {
            return $query;
        }

        // As in visibleTo(): a mount this person reaches wholesale settles the
        // question without a subquery, and the grant is asked for as one
        // indexed existence check rather than as a union per row.
        if ($through instanceof Repository && ($through->public || $user->isGrantedRepository($through))) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user, $through): void {
            $query->whereHas('repositories', fn (Builder $repositories) => $repositories
                ->when($through instanceof Repository, fn (Builder $one) => $one->whereKey($through->getKey()))
                ->where(fn (Builder $readable) => $readable
                    ->where('public', true)
                    ->orWhereIn('repositories.id', $user->repositoryGrants())))
                ->orWhereIn('packages.id', $user->packageGrants());
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
     * is exactly as broken as it was, and hasUnserveableName() below is what
     * keeps a human looking at it.
     */
    public function normalizeName(): void
    {
        if ($this->isDirty('name')) {
            $this->name = mb_strtolower((string) $this->name);
        }
    }

    /**
     * Whether this package's own name is one Composer will never ask for.
     *
     * True only for a row the normalization above could not reach: the
     * migration that folded every stored name to lowercase leaves behind
     * exactly those whose lowercase spelling another package in the same
     * Composer repository already publishes, because renaming one would collide
     * on the unique index and deleting either would unpublish somebody's
     * versions. Such a row goes on looking healthy in the panel while `/p2` and
     * `/dist` answer 404 for it, and it stays that way until a person picks
     * which of the pair to keep.
     *
     * A property of the row rather than of any sync, which is the point of
     * asking it here: PackageSynchronizer re-asserts the notice on every run
     * off this, so nothing clears the flag except fixing the name.
     *
     * @see PackageSynchronizer::syncError()
     */
    public function hasUnserveableName(): bool
    {
        return $this->name !== mb_strtolower((string) $this->name);
    }

    /**
     * What to tell an admin about such a name.
     *
     * Shared with the migration that first finds these, so the panel shows one
     * explanation of the row rather than the migration's until the next sync and
     * a different one after it. Deliberately does not assert the collision it
     * almost certainly is: a plain rename is the fix whenever there is no
     * sibling, and being told to go looking for one that is not there is worse
     * than being told to try.
     */
    public static function unserveableNameNotice(string $name): string
    {
        $normalized = mb_strtolower($name);

        return "This package is named \"{$name}\", but Composer only ever asks for \"{$normalized}\" — so it "
            .'cannot be fetched from /p2 or /dist. Rename it here to fix that, unless another package in the '
            .'same Composer repository already publishes the lowercase name, in which case delete whichever '
            .'of the two is obsolete.';
    }

    /**
     * Fold the subdirectory down to a bare relative path — no leading or
     * trailing slashes, no empty segments — and refuse one that climbs out of
     * the repository.
     *
     * The value is interpolated into provider API paths and matched against
     * archive entry names, so a `..` segment is the one input here that could
     * make this registry publish a tree the package was never given. The panel
     * and the API both validate the field with a pattern that admits no such
     * thing and report it as a field error; this is the backstop for the path
     * that forgot, and it throws for the same reason guardReservedVendor()
     * does — quietly rewriting a traversal into something innocuous would hide
     * the attempt rather than stop it.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSubdirectory(): void
    {
        $segments = array_values(array_filter(
            preg_split('#[\\\\/]+#', trim((string) $this->subdirectory)) ?: [],
            fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        throw_if(in_array('..', $segments, true), new InvalidArgumentException(
            "The subdirectory [{$this->subdirectory}] climbs out of the repository."
        ));

        $this->subdirectory = implode('/', $segments);
    }

    /**
     * A typed subdirectory folded to what would be stored, and to the
     * repository root when it is something normalizeSubdirectory() refuses.
     *
     * The panel builds a package it has not saved and reads a repository's
     * manifest through it, so the value has to be folded before the save as
     * well as during it. Answering with the root rather than with what was
     * typed is the whole point: normalizeSubdirectory() throws *before* it
     * assigns, so a caller that swallowed the exception would be left holding
     * the very traversal it refused — and would go on to interpolate it into a
     * provider API path, with the source's credential attached.
     *
     * The refusal proper belongs to the form and the API, which report it on
     * the field that has to change. This is what the paths downstream of that
     * refusal are handed, and it is deliberately not the input.
     */
    public static function storableSubdirectory(?string $typed): string
    {
        $package = new self(['subdirectory' => (string) $typed]);

        try {
            $package->normalizeSubdirectory();
        } catch (InvalidArgumentException) {
            return '';
        }

        return (string) $package->subdirectory;
    }

    /**
     * Whether this package is published from somewhere inside its repository
     * rather than from the repository root — the monorepo case, which every
     * path that reads a composer.json or stores an archive has to handle
     * differently.
     */
    public function hasSubdirectory(): bool
    {
        return (string) $this->subdirectory !== '';
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

        // Every repository serving this package, not only the one it lives
        // in: a rename lands the new name under every mount at once, and a
        // vendor reserved by one of the others is refused there for exactly
        // the reason it is refused here.
        foreach ($this->servingRepositoryIds() as $repositoryId) {
            $conflict = ReservedVendor::conflictFor((string) $this->name, $repositoryId);

            if ($conflict instanceof ReservedVendor) {
                throw new VendorReserved($conflict, (string) $this->name);
            }
        }
    }

    /**
     * Refuse a name that some other package already publishes on a mount this
     * one will be served from — the guarantee the packages table's
     * (repository_id, name) unique index cannot see, because a share lives on
     * the pivot alone.
     *
     * The same shape as guardReservedVendor() above, for the same reasons:
     * only when the name or the home is actually moving, and a throw rather
     * than a silent correction. Every serving row is rewritten from these two
     * columns the moment the save lands (see reconcileServingRepositories),
     * so a collision left unasked here surfaces there as a bare unique-index
     * error instead of a refusal anybody can act on.
     *
     * Checked against every repository the save will write a row into: the
     * current mounts, which a rename lands the new name on all at once, plus
     * the home being moved into, which is not on the pivot yet.
     *
     * @throws NameCollision
     */
    public function guardServedName(): void
    {
        if (! $this->isDirty('name') && ! $this->isDirty('repository_id')) {
            return;
        }

        foreach (array_unique([...$this->servingRepositoryIds(), (int) $this->repository_id]) as $repositoryId) {
            throw_if(
                self::nameServedElsewhere((string) $this->name, $repositoryId, $this->getKey()),
                new NameCollision(self::collisionRefusal((string) $this->name)),
            );
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
     * The other packages published from this package's repository — the rest
     * of the monorepo, wherever in the registry they are served from.
     *
     * Deliveries are dispatched by repository path rather than by Composer
     * repository (see allForRepositoryPath), so these are exactly the packages
     * a push to this one's repository also syncs.
     *
     * @return Builder<self>
     */
    public function siblings(): Builder
    {
        // An unsaved package, or one whose URL names no repository, has no
        // siblings rather than "every package with a null path".
        if ($this->repository_path === null || ! $this->exists) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()
            ->where('repository_path', $this->repository_path)
            ->whereKeyNot($this->getKey());
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

        // A hook another package already carries on the same repository is
        // just as concrete, and delivers here just as surely: the controllers
        // sync every package published from the repository a delivery names,
        // not only the one the hook was created for. So a monorepo needs one
        // hook rather than one per package — which matters beyond tidiness,
        // since GitHub caps a repository at 20 of them.
        if ($this->coveringSibling() instanceof self) {
            return WebhookCoverage::Sibling;
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
     * The sibling package whose repository hook also delivers for this one, or
     * null when none does.
     *
     * The provider is compared rather than trusted from the path alone:
     * `repository_path` is "owner/repo" with the host discarded, so a package
     * on github.com/acme/mono and one on gitlab.com/acme/mono share it while
     * sharing nothing else — and a hook on one delivers nothing for the other.
     *
     * Filtered in PHP because the provider is not a column: it comes from the
     * linked source, falling back to the URL's host. The set being filtered is
     * the packages on one repository path that carry a hook, which is at most
     * a handful and usually none.
     */
    private function coveringSibling(): ?self
    {
        return $this->siblings()
            ->whereNotNull('webhook_id')
            ->with('source')
            ->get()
            ->first(fn (self $sibling): bool => $sibling->provider() === $this->provider());
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
     *
     * A package in a subdirectory takes its name from that directory rather
     * than from the repository: "owner/repo" is the monorepo, and every
     * package in it would otherwise be guessed the same name — which is not
     * merely unhelpful but unusable, since names are unique per Composer
     * repository and the second package would be refused.
     */
    public function suggestedName(): ?string
    {
        try {
            // Composer names are lowercase, GitHub paths need not be.
            $path = mb_strtolower($this->repositoryPath());
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $this->hasSubdirectory()) {
            return $path;
        }

        $vendor = strtok($path, '/');
        $directory = mb_strtolower(basename((string) $this->subdirectory));

        return $vendor === false ? $path : "{$vendor}/{$directory}";
    }

    /**
     * The commands a consuming project runs to install this package, keyed by
     * a short label describing each step: point the client at this registry,
     * then require the package — whichever client this package's ecosystem
     * means.
     *
     * @return array<string, string>
     */
    public function installCommands(): array
    {
        $repository = $this->servingRepository();

        if ($this->ecosystem === Ecosystem::Npm) {
            // A scoped package only needs its scope pointed here, which is
            // the configuration to prefer: everything else keeps resolving
            // from npmjs.org. An unscoped one can only be reached by moving
            // the whole registry.
            $scope = str_starts_with((string) $this->name, '@')
                ? Str::before((string) $this->name, '/').':'
                : '';

            return [
                'repository' => "npm config set {$scope}registry ".$repository->url('/npm/'),
                'require' => "npm install {$this->name}",
            ];
        }

        if ($this->ecosystem === Ecosystem::Pypi) {
            return [
                // extra-index-url rather than index-url, so pypi.org stays
                // beside this registry instead of being replaced by it.
                'repository' => 'pip config set global.extra-index-url '.$repository->url('/pypi/simple/'),
                'require' => "pip install {$this->name}",
            ];
        }

        $require = $this->name;

        if ($constraint = $this->suggestedConstraint()) {
            $require .= ":{$constraint}";
        }

        return [
            // Which entry in the consumer's composer.json this repository
            // claims, and what it is pointed at, is the repository's own
            // decision — the same line its landing page prints.
            'repository' => $repository->configureCommand(),
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
     * The filenames a package page's body is looked for in, in order.
     *
     * `package-page.md` first, because a project that has written one has
     * written it for this: a README is addressed to contributors — how to run
     * the test suite, how to open a pull request — where a registry page is
     * addressed to whoever is deciding whether to install the thing. The
     * README is the fallback because almost every repository has one and
     * almost none has the other.
     *
     * The case variants are not decoration: a case-sensitive provider serves
     * "Readme.md" and "README.md" as different files, and which one a project
     * committed is not something an admin should have to find out by
     * publishing a blank page.
     *
     * @var list<string>
     */
    public const PAGE_FILES = [
        'package-page.md',
        'PACKAGE-PAGE.md',
        'README.md',
        'readme.md',
        'Readme.md',
    ];

    /**
     * Whether this package answers at a public URL.
     *
     * The switch alone decides it. A package in a private repository still
     * gets a page when one is enabled — it just cannot hand out archives (see
     * pageDownloads()) — because "here is what this is, ask us for access" is
     * exactly the page a private package wants, and because a registry that
     * refused to publish anything about a private package would have nowhere
     * to put the access request that follows.
     */
    public function hasPage(): bool
    {
        // The page routes address /p/{vendor}/{package}, so a name with no
        // slash — an unscoped npm package, any Python project — has no URL a
        // page could answer at. The switch is kept rather than refused, so a
        // scoped rename later publishes what the admin already chose.
        return (bool) $this->page_enabled && str_contains((string) $this->name, '/');
    }

    /**
     * The public URL of this package's page.
     *
     * Inside the repository's own mount, exactly as its Composer endpoints
     * are — "/p/vendor/name" at the root, "/r/internal/p/vendor/name" for a
     * named repository. Package names are unique per repository rather than
     * per registry, so a single rooted /p/ namespace would be ambiguous the
     * first time two repositories on one installation published the same
     * name, which is a thing repositories exist to allow.
     *
     * Absolute, and built from the configured application URL rather than
     * from the request, because this is what goes in a canonical link, a
     * sitemap and an og:url — all three read by machines that will not be
     * visiting from the host that generated them.
     */
    public function pageUrl(): string
    {
        [$vendor, $name] = explode('/', (string) $this->name, 2) + ['', ''];

        return $this->servingRepository()->url('/p/'.$vendor.'/'.$name);
    }

    /**
     * How this page displays its own badges, if at all.
     */
    public function pageBadges(): PageBadges
    {
        return $this->page_badges ?? PageBadges::None;
    }

    /**
     * The markdown for pasting this package's badges into a README, one per
     * kind, each linking back to the page.
     */
    public function badgeMarkdown(): string
    {
        $page = $this->pageUrl();

        return implode(' ', array_map(
            fn (string $kind): string => "[![{$kind}]({$page}/badge/{$kind}.svg)]({$page})",
            PackageBadgeController::KINDS,
        ));
    }

    /**
     * The markdown this page renders, and where it came from.
     *
     * `source` is for the panel to describe the page with, and it is a
     * sentence rather than a value because the useful answer differs by
     * case: which file was read, that nothing was found, or that the body was
     * typed here.
     *
     * @return array{body: ?string, source: string}
     */
    public function pageContent(): array
    {
        if ($this->pageBodySource() === PageBodySource::Custom) {
            return filled($this->page_body)
                ? ['body' => (string) $this->page_body, 'source' => 'panel']
                : ['body' => null, 'source' => 'empty'];
        }

        return filled($this->page_source_body)
            ? ['body' => (string) $this->page_source_body, 'source' => (string) $this->page_source_path]
            : ['body' => null, 'source' => 'none'];
    }

    /**
     * Where this page's body is configured to come from.
     */
    public function pageBodySource(): PageBodySource
    {
        return $this->page_body_source ?? PageBodySource::Auto;
    }

    /**
     * The files a sync should look for, in order: the one file this package
     * names, or the conventional list.
     *
     * Empty for a page whose body is written in the panel — there is nothing
     * to read, and a sync should not spend a provider request discovering
     * that.
     *
     * @return list<string>
     */
    public function pageBodyCandidates(): array
    {
        return match ($this->pageBodySource()) {
            PageBodySource::Custom => [],
            PageBodySource::File => filled($this->page_body_path) ? [(string) $this->page_body_path] : [],
            PageBodySource::Auto => self::PAGE_FILES,
        };
    }

    /**
     * What the page may actually offer, as against what it was configured to.
     *
     * The configured value is capped by the repository's own public flag,
     * because an archive is the package's content and handing it to an
     * anonymous visitor is precisely what a private repository exists to
     * refuse. A page on a private repository therefore reads as "no
     * downloads" however it was set — and the setting is kept rather than
     * cleared, so making the repository public again restores what the admin
     * chose rather than silently leaving it off.
     */
    public function pageDownloads(): PageDownloads
    {
        if (! $this->servingRepository()->public) {
            return PageDownloads::None;
        }

        return $this->page_downloads ?? PageDownloads::None;
    }

    /**
     * Whether the page prints install commands a visitor can run as they
     * stand.
     *
     * The same cap, for the same reason with a smaller consequence: the two
     * lines a private package needs are the ones with a token in them, and
     * printing the tokenless pair to an anonymous visitor sends them to a 401
     * that reads as the registry being broken. The page shows the access
     * notice in their place instead.
     */
    public function pageShowsInstall(): bool
    {
        return (bool) $this->page_install && $this->servingRepository()->public;
    }

    /**
     * Whether this page is one where the visitor has to ask for access before
     * anything on it is usable — the notice that stands in for the download
     * buttons and the install commands, and where a token request will go.
     */
    public function pageRequiresAccess(): bool
    {
        return ! $this->servingRepository()->public;
    }

    /**
     * Where a relative link in the page's markdown points: the repository's
     * file browser, or null for a package with no repository to point at —
     * one published entirely by artifact upload.
     */
    public function pageLinkBase(): ?string
    {
        return $this->pageRepositoryBase($this->browseResolver());
    }

    /**
     * Where a relative image in the page's markdown points: back at this
     * registry, which fetches the file from the repository and serves it.
     *
     * Not at the provider's raw host, which is where the obvious answer leads
     * and where it breaks. A private repository's raw URLs need a GitHub
     * credential, and the visitor reading the page is exactly the person who
     * has none — so every screenshot in the README of a private package
     * renders as a broken image. This registry holds the credential already;
     * it is the only party in the exchange that can fetch the file at all.
     *
     * It is the answer for public repositories too, and deliberately the same
     * one: the page stops depending on raw.githubusercontent.com being
     * reachable from wherever the reader is, and the bytes are cached here
     * rather than fetched from the provider per visitor.
     *
     * Absolute URLs in a README — a shields.io badge, an image on a CDN — are
     * left exactly as they were written. This is only for the relative ones,
     * which are files in the repository and nothing else.
     *
     * The package's subdirectory is folded into the *base* rather than
     * applied by the controller, so that the path always arrives
     * repository-root-relative and the root-relative form below is the same
     * route with a shorter base. @see pageImageRootBase()
     */
    public function pageImageBase(): ?string
    {
        $base = $this->pageAssetBase();

        if ($base === null || ! $this->hasSubdirectory()) {
            return $base;
        }

        return $base.'/'.trim((string) $this->subdirectory, '/');
    }

    /**
     * The same two, without the package's subdirectory — the repository root.
     *
     * Where a link written with a leading slash points: in a README, and so
     * on every provider that renders one, `/docs/install.md` means the
     * repository root rather than the directory the file sits in. For a
     * package that is the whole repository the two bases are identical; for a
     * monorepo package they are not, and resolving a root-relative link
     * against the subdirectory base produces a link to a path that does not
     * exist.
     */
    public function pageLinkRootBase(): ?string
    {
        return $this->pageRepositoryBase($this->browseResolver(), subdirectory: false);
    }

    public function pageImageRootBase(): ?string
    {
        return $this->pageAssetBase();
    }

    /**
     * Where this package's repository files are re-served from, or null for a
     * package with no repository to fetch them out of — one published by
     * artifact upload, whose page body was typed in the panel and can only
     * carry absolute image URLs.
     */
    private function pageAssetBase(): ?string
    {
        if (blank($this->repository) || blank($this->name)) {
            return null;
        }

        [$vendor, $name] = explode('/', (string) $this->name, 2) + ['', ''];

        return $this->servingRepository()->url('/p/'.$vendor.'/'.$name.'/asset');
    }

    /**
     * The ref a page's content is read at: the release the package's own
     * metadata came from, the version that arrived last for a package with no
     * release, or null — meaning the default branch — for one with no stored
     * versions at all.
     *
     * One answer, asked by the body reader and by the asset route, so that a
     * README and the screenshots inside it are always the same commit's.
     */
    public function pageRef(): ?string
    {
        if ($this->latest_version !== null) {
            $reference = $this->versions()
                ->where('version', $this->latest_version)
                ->value('reference');

            if (filled($reference)) {
                return (string) $reference;
            }
        }

        $reference = $this->versions()->orderByDesc('id')->value('reference');

        return filled($reference) ? (string) $reference : null;
    }

    /**
     * @return callable(SourceProvider, string): ?string
     */
    private function browseResolver(): callable
    {
        return fn (SourceProvider $provider, string $url): ?string => $provider->browseUrl($url);
    }

    /**
     * The subdirectory-aware base for either of the two above. A monorepo
     * package's README lives in its own directory, and its relative links are
     * relative to that directory rather than to the repository root — except
     * for the root-relative ones, which is what $subdirectory: false is for.
     *
     * @param  callable(SourceProvider, string): ?string  $resolve
     */
    private function pageRepositoryBase(callable $resolve, bool $subdirectory = true): ?string
    {
        if (blank($this->repository)) {
            return null;
        }

        $base = $resolve($this->provider(), (string) $this->repository);

        if ($base === null) {
            return null;
        }

        return $subdirectory && $this->hasSubdirectory()
            ? $base.'/'.trim((string) $this->subdirectory, '/')
            : $base;
    }

    /**
     * The image a social platform shows when this page's URL is pasted into a
     * post: the package's own, or the registry-wide default, or none.
     *
     * Absolute, because that is what og:image requires — a relative one is
     * dropped by every platform that reads it.
     */
    public function pageImageUrl(): ?string
    {
        $image = (string) $this->page_image;

        if ($image !== '') {
            if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                return $image;
            }

            // Anything else is a file in the package's repository, served
            // through the asset route — which is the only shape that works
            // for a private repository. A card image is fetched anonymously
            // by Slack, X and every other unfurler from their own
            // infrastructure, so a raw provider URL answers them 404 and the
            // link goes out with no card at all. @see pageImageBase()
            $base = str_starts_with($image, '/') ? $this->pageImageRootBase() : $this->pageImageBase();

            return $base === null ? null : $base.'/'.ltrim($image, '/');
        }

        // The registry-wide default is a file this app serves rather than one
        // any package's repository holds — it stands in for every page, and
        // no single repository could supply it.
        $fallback = (string) config('registry.page_image', '');

        if ($fallback === '') {
            return null;
        }

        return str_starts_with($fallback, 'http://') || str_starts_with($fallback, 'https://')
            ? $fallback
            : $this->servingRepository()->pageRootUrl().'/'.ltrim($fallback, '/');
    }

    /**
     * The one-line summary the page's <meta name="description"> and its
     * og:description carry.
     *
     * Composed rather than taken from the description column alone, because
     * a third of packages describe themselves in three words and a preview
     * card with three words in it tells a reader nothing. The name and the
     * current version are facts this app always has.
     */
    public function pageSummary(): string
    {
        $described = trim((string) $this->description);

        $summary = $described !== ''
            ? $described
            : "The {$this->name} package.";

        if ($this->latest_version !== null) {
            $summary .= " Latest release: {$this->latest_version}.";
        }

        // Search engines truncate around 160 characters and social cards
        // sooner; cut on a word so the result reads as a sentence that ended
        // rather than one that was severed.
        return Str::limit($summary, 155, preserveWords: true);
    }

    /**
     * The versions this page lists, newest first.
     *
     * Capped, because a package that has been released weekly for five years
     * has several hundred and a page is not an API — the whole history is
     * what /p2 is for, and what the version table exists to summarize.
     *
     * @return Collection<int, PackageVersion>
     */
    public function pageVersions(int $limit = 50): Collection
    {
        return $this->versions()
            ->orderByDesc('order')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The release this page's "latest" download hands over: the stable
     * version the package names as current, and nothing when it has none.
     * A page will not quietly offer a dev branch as though it were a release.
     */
    public function pageLatestVersion(): ?PackageVersion
    {
        if ($this->latest_version === null) {
            return null;
        }

        return $this->versions()
            ->where('version', $this->latest_version)
            ->whereNotNull('archive_path')
            ->first();
    }

    /**
     * The packages with pages, for the sitemap and a repository's own page.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithPage(Builder $query): Builder
    {
        // The slash for the reason hasPage() asks for one: a name without it
        // has no page URL, and the sitemap must not emit a link to nowhere.
        return $query->where('page_enabled', true)->whereLike('name', '%/%');
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
