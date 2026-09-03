<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\PageBadges;
use App\Enums\PageBodySource;
use App\Enums\PageDownloads;
use App\Enums\WebhookCoverage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

/**
 * The flat package form used when editing.
 *
 * Each field is exposed on its own so that the create wizard can lay the same
 * inputs out over its steps without restating their rules.
 *
 * @see PackageWizard
 */
class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::name(),
                self::repository(),
                self::subdirectory(),
                self::composerRepository(),
                self::servingRepositories(),
                self::source(),
                self::token(),
                self::latestVersion(),
                self::type(),
                self::webhookEnabled(),
                self::abandoned(),
                self::replacementPackage(),
                self::description(),
                self::page(),
            ]);
    }

    /**
     * The public page: whether this package has one, and what it shows.
     *
     * A section rather than a handful of toggles among the sync settings,
     * because these are the only fields on this form that decide what a
     * stranger can see. Grouping them is what makes "is this package
     * published to the world" answerable at a glance rather than by reading
     * every field.
     *
     * Collapsed until the page is switched on, so the form does not grow four
     * fields for the packages — most of them — that will never have one.
     */
    public static function page(): Section
    {
        return Section::make('Public page')
            ->description('A page anyone can read, at the package\'s own URL — no account and no token.')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->columnSpanFull()
            ->schema([
                self::pageEnabled(),
                self::pageUrl(),
                self::pageDownloadsSelect(),
                self::pageBadgesSelect(),
                self::pageInstall(),
                self::pageVersions(),
                self::pageType(),
                self::pageSource(),
                self::pageImage(),
                self::sponsorPlans(),
                self::pageBodySource(),
                self::pageBodyPath(),
                self::pageBody(),
            ]);
    }

    /**
     * The switch itself. Live, because every field under it is hidden until
     * this is on — a page's options are not a decision until there is a page.
     */
    public static function pageEnabled(): Toggle
    {
        return Toggle::make('page_enabled')
            ->label('Publish a public page')
            ->live()
            ->helperText(fn (?Package $record): string => match (true) {
                $record?->composerRepository?->public === false => 'The page will describe this package and show its version history, '
                    .'but not hand out archives or print install commands — this package is served from a private repository. '
                    .'Visitors are shown how to ask for access instead.',
                default => 'The page shows the package\'s description, its README from the repository, and whatever is switched on below.',
            });
    }

    /**
     * Where the page answers, as a link — the one thing an admin wants
     * immediately after switching it on, and the thing they would otherwise
     * have to construct by hand from the repository's mount.
     */
    public static function pageUrl(): Placeholder
    {
        return Placeholder::make('page_url')
            ->label('Page URL')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->content(fn (?Package $record): string|HtmlString => $record instanceof Package && filled($record->name)
                ? new HtmlString(sprintf(
                    '<a href="%1$s" target="_blank" rel="noopener" class="underline">%1$s</a>',
                    e($record->pageUrl()),
                ))
                : 'Available once the package has been saved.');
    }

    /**
     * Which archives the page hands out. A radio rather than a select: three
     * options whose difference is the point, each carrying the sentence that
     * explains it.
     */
    public static function pageDownloadsSelect(): Radio
    {
        return Radio::make('page_downloads')
            ->label('Downloads')
            ->options(PageDownloads::class)
            ->descriptions(collect(PageDownloads::cases())
                ->mapWithKeys(fn (PageDownloads $case): array => [$case->value => $case->description()])
                ->all())
            ->default(PageDownloads::None)
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            // The setting is kept rather than disabled on a private
            // repository, so that making the repository public restores what
            // was chosen here — but it is said plainly that it does nothing
            // meanwhile, rather than being discovered on the published page.
            ->helperText(fn (?Package $record): ?string => $record?->composerRepository?->public === false
                ? 'No effect while this package is served from a private repository: the page offers no archives to anonymous visitors.'
                : null);
    }

    /**
     * Whether the page displays the package's own badges — for the README
     * that does not embed them itself.
     */
    public static function pageBadgesSelect(): Radio
    {
        return Radio::make('page_badges')
            ->label('Badges')
            ->options(PageBadges::class)
            ->descriptions(collect(PageBadges::cases())
                ->mapWithKeys(fn (PageBadges $case): array => [$case->value => $case->description()])
                ->all())
            ->default(PageBadges::None)
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'));
    }

    public static function pageInstall(): Toggle
    {
        return Toggle::make('page_install')
            ->label('Show install commands')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->helperText('The registry setup and install lines for the package\'s ecosystem — `composer config` and `composer require`, or their npm and pip equivalents — exactly as the panel shows them.');
    }

    public static function pageType(): Toggle
    {
        return Toggle::make('page_type')
            ->label('Show the package type')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->helperText('What Composer calls it — library, project, metapackage.');
    }

    /**
     * Whether the page links the repository the package is built from.
     *
     * Off by default, alone among these, because it is the one field on a
     * page that names infrastructure rather than describing a package: on a
     * private package it publishes the organisation and repository name to
     * anyone who opens the page. For anything open source it is worth
     * switching on — it is the link a reader goes looking for.
     */
    public static function pageSource(): Toggle
    {
        return Toggle::make('page_source')
            ->label('Show the source repository')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->helperText(fn (?Package $record): string => $record?->composerRepository?->public === false
                ? 'Off by default: this publishes the repository URL of a private package to anyone who opens the page.'
                : 'A link to the repository this package is built from.');
    }

    /**
     * Where the page's body comes from — the repository's own file, one file
     * this package names, or markdown written here.
     */
    public static function pageBodySource(): Radio
    {
        return Radio::make('page_body_source')
            ->label('Page content')
            ->options(PageBodySource::class)
            ->descriptions(collect(PageBodySource::cases())
                ->mapWithKeys(fn (PageBodySource $case): array => [$case->value => $case->description()])
                ->all())
            ->default(PageBodySource::Auto)
            ->live()
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'));
    }

    /**
     * Which file, when one is named. A datalist rather than a select: the
     * conventional names are offered because they are what a repository
     * usually has, but the field takes any path — `docs/registry.md` is a
     * perfectly good answer and no fixed list could have contained it.
     */
    public static function pageBodyPath(): TextInput
    {
        return TextInput::make('page_body_path')
            ->label('File')
            ->maxLength(255)
            ->datalist(Package::PAGE_FILES)
            ->placeholder('docs/registry.md')
            ->required(fn (Get $get): bool => $get('page_body_source') === PageBodySource::File->value)
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled')
                && $get('page_body_source') === PageBodySource::File->value)
            // Refused here rather than at the provider, which would answer a
            // 404 that reads as "the file is missing" for a path that was
            // never going to be asked for.
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (in_array('..', preg_split('#[\\\\/]+#', trim((string) $value)) ?: [], true)) {
                        $fail('A path cannot climb out of the repository with "..".');
                    }
                },
            ])
            ->helperText(fn (?Package $record): string => $record?->hasSubdirectory()
                ? "Relative to {$record->subdirectory}/, this package's own directory in the repository."
                : 'Relative to the repository root. Read again on every sync.');
    }

    public static function pageVersions(): Toggle
    {
        return Toggle::make('page_versions')
            ->label('Show the version history')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->helperText('A table of the released versions and their dates, most recent first.');
    }

    /**
     * The image a social platform shows when the page's URL is pasted into a
     * post. Optional, and worth being optional: an installation that sets
     * PAGE_IMAGE once gets a card on every page without touching this.
     */
    public static function pageImage(): TextInput
    {
        return TextInput::make('page_image')
            ->label('Social preview image')
            ->url()
            ->maxLength(255)
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
            ->placeholder('https://example.com/package-card.png')
            ->helperText('Shown when the page is linked in Slack, a post or a chat. Around 1200×630 works everywhere. '
                .'Empty uses the registry-wide default.');
    }

    /**
     * The plans the page offers as sponsorship tiers, each selling its own
     * prices — one-time and recurring alike — through the ordinary checkout.
     * A tier granting no entitlements is a pure donation; one with
     * entitlements is a perk (say, gold sponsors get a private package), and
     * the billing layer treats both as plans like any other.
     */
    public static function sponsorPlans(): Select
    {
        return Select::make('sponsorPlans')
            ->label('Sponsorship tiers')
            ->multiple()
            ->relationship(
                'sponsorPlans',
                'name',
                fn ($query) => $query->where('active', true)->orderBy('sort')->orderBy('name'),
            )
            ->placeholder('None — no sponsor section')
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled') && (bool) config('registry.billing.enabled'))
            ->helperText('Each plan chosen here is one tier, shown with a button per active price — '
                .'GitHub-Sponsors style. A plan with entitlements makes a perk-bearing tier; tiers appear '
                .'in the plans\' own sort order, and one with no active price is not shown.');
    }

    /**
     * The body itself, for the one source that is written rather than read.
     */
    public static function pageBody(): Textarea
    {
        return Textarea::make('page_body')
            ->label('Content')
            ->rows(12)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('page_enabled')
                && $get('page_body_source') === PageBodySource::Custom->value)
            ->helperText('Markdown. Headings, lists, tables, code fences and links; raw HTML is escaped rather than rendered.');
    }

    /**
     * Marks the package as one nobody should be depending on any more.
     *
     * Composer carries this through to the consumer: `composer audit` reports
     * abandoned packages, and since 2.9 a project can configure that to fail
     * the build outright. It is the only way this registry can tell a
     * developer to stop reaching for something without deleting it out from
     * under the projects already using it.
     */
    public static function abandoned(): Toggle
    {
        return Toggle::make('abandoned')
            ->label('Abandoned')
            ->live()
            ->helperText('Warns anyone who installs or audits this package that it is no longer maintained. Existing versions keep resolving.');
    }

    /**
     * What to use instead, which Composer names in the warning it prints.
     */
    public static function replacementPackage(): TextInput
    {
        return TextInput::make('replacement_package')
            ->label('Use instead')
            ->maxLength(255)
            ->visible(fn (Get $get): bool => (bool) $get('abandoned'))
            ->placeholder('vendor/package')
            // Deliberately unvalidated against the registry's own packages: the
            // replacement is often somewhere else entirely — a public package
            // on packagist.org, or one not imported here yet.
            ->helperText('Optional. Any package name, including one this registry does not serve. Left empty, consumers are told only that the package is abandoned.');
    }

    /**
     * The Composer repository the package is served from. Names and VCS URLs
     * are unique per repository, so this select is what the two unique rules
     * below scope themselves by.
     */
    public static function composerRepository(): Select
    {
        return Select::make('repository_id')
            ->label('Composer repository')
            ->relationship('composerRepository', 'name')
            ->default(fn (): int => Repository::default()->id)
            ->required()
            ->selectablePlaceholder(false)
            // Live so that the repositories offered below are the ones this
            // package is not already homed in.
            ->live()
            ->helperText('Where the package lives: the repository it is published into, and the mount its page URL is cut from.');
    }

    /**
     * The other repositories this package answers under.
     *
     * A package lives in one repository and can be served from any number of
     * others — the same row, the same versions and archives, under another
     * mount with its own access rules. This is the whole of that decision;
     * everything else about the package is unchanged by it.
     *
     * Not a `relationship()` select, though `repositories` is one: a serving
     * row carries the package's name for the per-repository unique index, and
     * Filament's sync would write neither that nor the two refusals below.
     * The pages hand the chosen ids to Package::syncServingRepositories()
     * instead. @see \App\Filament\Resources\Packages\Pages\EditPackage
     */
    public static function servingRepositories(): Select
    {
        return Select::make('serving_repositories')
            ->label('Also served from')
            ->multiple()
            ->options(fn (Get $get): array => Repository::query()
                ->whereKeyNot((int) ($get('repository_id') ?? Repository::default()->id))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->afterStateHydrated(fn (Select $component, ?Package $record) => $component->state(
                $record === null ? [] : array_values(array_diff(
                    $record->servingRepositoryIds(),
                    [(int) $record->repository_id],
                )),
            ))
            // Written by the page after the save rather than with it, since
            // there is no column behind this.
            ->dehydrated(false)
            ->rules([
                fn (Get $get, ?Package $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    foreach ((array) $value as $repositoryId) {
                        $refusal = Package::servingRefusal(
                            mb_strtolower((string) $get('name')),
                            (int) $repositoryId,
                            $record?->getKey(),
                        );

                        if ($refusal !== null) {
                            $fail($refusal);

                            return;
                        }
                    }
                },
            ])
            ->helperText('Optional. Every repository chosen here serves this same package under its own mount, with its own access rules.');
    }

    /**
     * Scope a unique rule to the repository chosen in the form, since both
     * package names and VCS URLs are unique per Composer repository rather
     * than globally.
     */
    private static function uniquePerRepository(Unique $rule, Get $get): Unique
    {
        return $rule->where('repository_id', $get('repository_id') ?? Repository::default()->id);
    }

    /**
     * Whether pushes to the repository sync this package.
     *
     * Saving this on decides how the package is reached — a hook is created on
     * the repository unless the GitHub App's webhook already covers it —
     * and saving it off takes that hook back down. Deliveries that arrive
     * anyway, through the app's account-wide webhook, are ignored for this
     * package, so the switch means the same thing either way.
     */
    public static function webhookEnabled(): Toggle
    {
        return Toggle::make('webhook_enabled')
            ->label('Sync automatically on push')
            ->default(true)
            ->helperText(fn (?Package $record): string => match (true) {
                ! $record instanceof Package => 'A webhook is set up on the repository when the package is created, unless the GitHub App\'s webhook already covers it.',
                $record->webhook_enabled => 'On. '.($record->webhookCoverage() === WebhookCoverage::Application
                    ? 'Delivered through the GitHub App\'s webhook; turning this off stops this package syncing on push, and leaves the app\'s webhook alone.'
                    : 'Turning this off removes the webhook from the repository.'),
                default => 'Off — this package only syncs when asked. Turning it on sets the webhook up again.',
            });
    }

    public static function name(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            // Folded as the field is left rather than on save, so the uniqueness
            // and reservation rules below are decided on the name that will
            // actually be stored — Package's saving hook lowercases it either
            // way, and a rule that judged the typed spelling would clear a
            // collision the insert then hits as a bare unique-index error.
            // Visible for the same reason a normalization should be: the admin
            // sees the name the registry will publish, not the one they typed.
            ->live(onBlur: true)
            ->afterStateUpdated(fn (TextInput $component, ?string $state) => $component->state(mb_strtolower((string) $state)))
            ->unique(
                ignoreRecord: true,
                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => self::uniquePerRepository($rule, $get),
            )
            // Package's own saving hook refuses both of these too, but by
            // throwing — which from a form is a 500 where the admin wanted a
            // field error saying what stands in the way: a vendor another
            // repository has reserved, or a name the chosen repository already
            // serves from a package that merely lives elsewhere, which the
            // unique rule above cannot see because shares live on the pivot.
            ->rules([
                fn (Get $get, ?Package $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $refusal = Package::servingRefusal(
                        mb_strtolower((string) $value),
                        (int) ($get('repository_id') ?? Repository::default()->id),
                        $record?->getKey(),
                    );

                    if ($refusal !== null) {
                        $fail($refusal);
                    }
                },
            ])
            ->placeholder('vendor/package')
            ->helperText('Taken from the composer.json on the first sync. A name the repository changes later is reported, never applied — accept it by editing it here.');
    }

    public static function repository(): TextInput
    {
        return TextInput::make('repository')
            ->label('Repository URL')
            // Required for a synced package; a package published by artifact
            // upload legitimately has none. The create wizard, whose whole
            // point is deciphering a repository, re-requires it.
            ->required(fn (?Package $record): bool => $record === null || filled($record->repository))
            ->url()
            ->maxLength(255)
            // One URL may now be claimed several times over — once per
            // subdirectory — so the rule matches the widened unique index
            // rather than the repository alone. Normalized the same way the
            // model will normalize it, or "packages/foo" and "/packages/foo/"
            // would clear this rule and collide in the index.
            ->unique(
                ignoreRecord: true,
                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => self::uniquePerRepository($rule, $get)
                    ->where('subdirectory', self::normalizedSubdirectory($get)),
            )
            ->validationMessages([
                'unique' => 'This Composer repository already serves that subdirectory of that repository URL.',
            ])
            ->placeholder('https://github.com/vendor/package')
            ->helperText(fn (?Package $record): ?string => $record !== null && blank($record->repository)
                ? 'This package is published by artifact upload; setting a repository URL turns syncing on.'
                : null);
    }

    /**
     * Where in the repository this package lives — the monorepo field.
     *
     * Empty is the repository root, which is what every package was before
     * subdirectories existed and what almost all of them still are, so the
     * field is optional and unobtrusive rather than a decision to be made.
     */
    public static function subdirectory(): TextInput
    {
        return TextInput::make('subdirectory')
            ->label('Subdirectory')
            // Drives the URL rule above, which is unique per subdirectory.
            ->live(onBlur: true)
            ->maxLength(255)
            // A bare relative path. Package::normalizeSubdirectory() refuses a
            // `..` segment by throwing, which from a form would be a 500 where
            // the admin wanted to be told what is wrong with the field.
            // Wrapped in a closure Filament evaluates, exactly as the reserved
            // vendor rule on name() is: an unwrapped one is taken for something
            // to inject the schema's own arguments into, not for the rule.
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $segments = preg_split('#[\\\\/]+#', trim((string) $value)) ?: [];

                    if (in_array('..', $segments, true)) {
                        $fail('A subdirectory cannot climb out of the repository with "..".');
                    }
                },
            ])
            // Never null: the column is part of a unique index, and no engine
            // considers two nulls equal — an emptied field has to arrive as
            // the empty string the "repository root" case is stored as.
            ->dehydrateStateUsing(fn (?string $state): string => (string) $state)
            ->placeholder('Empty — the repository root')
            ->helperText('For a repository that publishes several packages: the directory holding this one\'s composer.json. Its dist archives carry that directory alone, re-rooted, so consumers install it exactly as they would a repository of its own.');
    }

    /**
     * The subdirectory as Package will store it, so the URL's unique rule asks
     * about the value that will actually meet the index.
     */
    private static function normalizedSubdirectory(Get $get): string
    {
        // A traversal is refused by the field's own rule; here it only has to
        // not blow up while another field is being validated.
        return Package::storableSubdirectory($get('subdirectory'));
    }

    public static function source(): Select
    {
        return Select::make('source_id')
            ->label('Source')
            ->options(fn (): array => Source::options())
            ->searchable()
            ->placeholder('Match automatically from the repository URL')
            ->helperText('The connected account this package authenticates through. Left empty, a source owning the repository URL is attached on save.');
    }

    public static function token(): TextInput
    {
        return TextInput::make('token')
            ->label('Access token')
            ->password()
            ->revealable()
            ->maxLength(255)
            // The stored token is never echoed back to the browser;
            // a blank input keeps it, a new value replaces it.
            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->placeholder(fn (?Package $record): string => $record?->token ? 'Token saved — enter a new one to replace it' : 'ghp_… or glpat-…')
            // Not GitHub-only: the token is handed to whichever provider the
            // repository URL resolves to. Only the environment fallback is
            // GitHub's, which is why it is named as the narrower case.
            ->helperText('A credential for whichever provider hosts the repository. Only used for repositories no source covers — a connected source takes precedence over this. GitHub repositories fall back to GITHUB_TOKEN when both are empty.');
    }

    public static function latestVersion(): TextInput
    {
        return TextInput::make('latest_version')
            ->maxLength(255)
            ->placeholder('v1.0.0')
            ->helperText('Leave empty if the package has no release yet.');
    }

    public static function type(): TextInput
    {
        return TextInput::make('type')
            ->maxLength(255)
            // Suggests the types already in use without locking the
            // column to a fixed vocabulary.
            ->datalist(fn (): array => array_values(Package::types()));
    }

    public static function description(): Textarea
    {
        return Textarea::make('description')
            ->rows(4)
            ->columnSpanFull();
    }
}
